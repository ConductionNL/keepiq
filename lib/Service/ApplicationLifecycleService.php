<?php

/**
 * Doriath Application Lifecycle Service
 *
 * The admission half of the registered-application capability: register,
 * approve and reject. These three transitions share one body of rules —
 * CSR validation, the pending/active decision, EncryptionSuite
 * provisioning, the admin approval-queue notification and the audit
 * trail — which is why they live together here, extracted out of
 * ApplicationService so the read/delete surface no longer carries them.
 *
 * @category Service
 * @package  OCA\Doriath\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Doriath\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Doriath\Db\Application;
use OCA\Doriath\Db\ApplicationMapper;
use OCA\Doriath\Support\SuppressesDiagnostics;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Register / approve / reject for registered applications.
 *
 * Authorization at this layer is intentionally minimal: admin promotion
 * (auto-approval), approve and reject are gated behind the caller-supplied
 * `$isAdmin` flag, which the controller resolves from the IUserSession +
 * IGroupManager and forwards.
 */
class ApplicationLifecycleService {
	use SuppressesDiagnostics;

	/**
	 * The EncryptionSuite provisioner.
	 *
	 * @var ApplicationSuiteProvisioner
	 */
	private ApplicationSuiteProvisioner $suiteProvisioner;

	/**
	 * The application audit trail.
	 *
	 * @var ApplicationAuditTrail
	 */
	private ApplicationAuditTrail $auditTrail;

	/**
	 * Constructor for ApplicationLifecycleService.
	 *
	 * @param ApplicationMapper $mapper The application mapper
	 * @param IGroupManager $groupManager The group manager (admin lookups)
	 * @param LoggerInterface $logger The logger
	 * @param NotificationService|null $notificationService The notification service
	 * @param ApplicationSuiteProvisioner|null $suiteProvisioner The EncryptionSuite provisioner
	 * @param ApplicationAuditTrail|null $auditTrail The application audit trail
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; the lifecycle transitions carry the spec anchors.
	 */
	public function __construct(
		private ApplicationMapper $mapper,
		private IGroupManager $groupManager,
		private LoggerInterface $logger,
		private ?NotificationService $notificationService = null,
		?ApplicationSuiteProvisioner $suiteProvisioner = null,
		?ApplicationAuditTrail $auditTrail = null,
	) {
		$this->suiteProvisioner = ($suiteProvisioner ?? new ApplicationSuiteProvisioner(logger: $logger));
		$this->auditTrail = ($auditTrail ?? new ApplicationAuditTrail());
	}//end __construct()

	/**
	 * Register a new application.
	 *
	 * Admin callers auto-approve (status=active); non-admin and anonymous
	 * callers create a pending row that an admin must approve. The CSR
	 * is stored verbatim on pending rows and validated/processed when the
	 * full build cycle ships EncryptionSuite provisioning.
	 *
	 * @param string $name The human-readable name
	 * @param string|null $description Optional free-form description
	 * @param string $type Application type (internal|external)
	 * @param string|null $csr Optional PKCS#10 CSR (stored on pending rows)
	 * @param string|null $userId The Nextcloud user creating the row
	 * @param bool $isAdmin Whether the caller is an admin
	 *
	 * @return Application
	 *
	 * @throws InvalidArgumentException
	 *
	 * @spec openspec/changes/implement-application-mgmt/tasks.md#task-3.2
	 */
	public function register(
		string $name,
		?string $description,
		string $type,
		?string $csr,
		?string $userId,
		bool $isAdmin,
	): Application {
		$this->assertRegistrable(name: $name, type: $type, csr: $csr, isAdmin: $isAdmin);

		$persisted = $this->mapper->insert(
			$this->buildApplicationRow(
				name: $name,
				description: $description,
				type: $type,
				csr: $csr,
				userId: $userId,
				isAdmin: $isAdmin
			)
		);

		$this->runPostRegistrationHooks(persisted: $persisted, csr: $csr, userId: $userId);

		$this->auditTrail->recordRegistered(
			userId: $userId,
			applicationId: $persisted->getId(),
			applicationName: $persisted->getName(),
		);

		return $persisted;
	}//end register()

	/**
	 * Approve a pending application.
	 *
	 * @param string $applicationId The application ID
	 * @param string $adminUserId The approving admin's Nextcloud user ID
	 * @param bool $isAdmin Whether the caller is an admin
	 *
	 * @return Application
	 *
	 * @throws InvalidArgumentException
	 *
	 * @spec openspec/changes/implement-application-mgmt/tasks.md#task-3.4
	 */
	public function approve(string $applicationId, string $adminUserId, bool $isAdmin): Application {
		if ($isAdmin === false) {
			throw new InvalidArgumentException(message: 'Only an administrator may approve applications');
		}

		$entity = $this->findOr400(applicationId: $applicationId);

		if ($entity->isPending() === false) {
			throw new InvalidArgumentException(message: 'Application is not pending');
		}

		// Per implement-application-mgmt §3.3: validate the stored CSR
		// (if any) at approval time too, so a malformed or weak CSR
		// surfaced before the EncryptionSuite signer ever runs.
		$storedCsr = $entity->getCsr();
		if ($storedCsr !== null && $storedCsr !== '') {
			$this->validateCsr(csr: $storedCsr);
		}

		$entity->setStatus(Application::STATUS_ACTIVE);
		$entity->setApprovedBy($adminUserId);
		$entity->setApprovedAt(new DateTime());

		$this->logger->info(
			'Approved application ' . $applicationId . ' by admin ' . $adminUserId,
			['app' => 'doriath']
		);

		$updated = $this->mapper->update($entity);

		// §9.1 — Provision the application's EncryptionSuite as part of
		// the approval transaction. If the CSR is missing the suite is
		// skipped; the admin queue can re-submit a CSR via re-registration.
		if ($storedCsr !== null && $storedCsr !== '') {
			$this->suiteProvisioner->provision(applicationId: $updated->getId(), csr: $storedCsr);
		}

		$this->auditTrail->recordApproved(
			adminUserId: $adminUserId,
			applicationId: $applicationId,
			applicationName: $updated->getName(),
		);

		return $updated;
	}//end approve()

	/**
	 * Reject a pending application — hard-deletes the row per spec D7.
	 *
	 * @param string $applicationId The application ID
	 * @param string $adminUserId The rejecting admin's Nextcloud user ID
	 * @param bool $isAdmin Whether the caller is an admin
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.7
	 */
	public function reject(string $applicationId, string $adminUserId, bool $isAdmin): void {
		if ($isAdmin === false) {
			throw new InvalidArgumentException(message: 'Only an administrator may reject applications');
		}

		$entity = $this->findOr400(applicationId: $applicationId);

		if ($entity->isPending() === false) {
			throw new InvalidArgumentException(message: 'Only pending applications may be rejected');
		}

		$applicationName = $entity->getName();

		$this->mapper->delete($entity);

		$this->logger->info(
			'Rejected application ' . $applicationId . ' by admin ' . $adminUserId,
			['app' => 'doriath']
		);

		$this->auditTrail->recordRejected(
			adminUserId: $adminUserId,
			applicationId: $applicationId,
			applicationName: $applicationName,
		);
	}//end reject()

	/**
	 * Reject a registration the service cannot accept.
	 *
	 * Per implement-application-mgmt §3.2 / §3.3: when an admin-registered
	 * application supplies a CSR (status will be `active`, suite is
	 * provisioned immediately), gate on PKCS#10 format + >= 4096-bit key
	 * BEFORE the row is persisted. Pending rows (non-admin / anonymous)
	 * store the CSR verbatim; validation runs again when the admin approves
	 * the row, so a malformed CSR is caught there too.
	 *
	 * @param string $name The application name
	 * @param string $type The application type
	 * @param string|null $csr The PKCS#10 CSR, when supplied
	 * @param bool $isAdmin Whether the registrant is an admin
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException On an invalid name, type or CSR.
	 */
	private function assertRegistrable(string $name, string $type, ?string $csr, bool $isAdmin): void {
		if ($name === '') {
			throw new InvalidArgumentException(message: 'name is required');
		}

		if (in_array($type, [Application::TYPE_INTERNAL, Application::TYPE_EXTERNAL], true) === false) {
			throw new InvalidArgumentException(message: 'type must be internal or external');
		}

		if ($isAdmin === true && $csr !== null && $csr !== '') {
			$this->validateCsr(csr: $csr);
		}
	}//end assertRegistrable()

	/**
	 * Build the unpersisted Application row. An admin registration is active
	 * and self-approved on the spot; every other registration is pending.
	 *
	 * @param string $name The application name
	 * @param string|null $description The application description
	 * @param string $type The application type
	 * @param string|null $csr The PKCS#10 CSR, when supplied
	 * @param string|null $userId The registering user, or null when anonymous
	 * @param bool $isAdmin Whether the registrant is an admin
	 *
	 * @return Application
	 */
	private function buildApplicationRow(
		string $name,
		?string $description,
		string $type,
		?string $csr,
		?string $userId,
		bool $isAdmin,
	): Application {
		$entity = new Application();
		$entity->setId(Uuid::uuid4()->toString());
		$entity->setName($name);
		$entity->setDescription($description);
		$entity->setType($type);
		$entity->setCsr($csr);
		$entity->setRegisteredBy($userId);
		$entity->setCreatedAt(new DateTime());

		$entity->setStatus(Application::STATUS_PENDING);
		if ($isAdmin === true) {
			$entity->setStatus(Application::STATUS_ACTIVE);
			$entity->setApprovedBy($userId);
			$entity->setApprovedAt(new DateTime());
		}

		return $entity;
	}//end buildApplicationRow()

	/**
	 * Best-effort work that follows a successful registration: suite
	 * provisioning, the log line and the admin approval-queue notification.
	 * None of it may block the registration.
	 *
	 * @param Application $persisted The persisted application row
	 * @param string|null $csr The PKCS#10 CSR, when supplied
	 * @param string|null $userId The registering user, or null when anonymous
	 *
	 * @return void
	 */
	private function runPostRegistrationHooks(Application $persisted, ?string $csr, ?string $userId): void {
		// §9.1 — Provision an EncryptionSuite for any application that
		// becomes active immediately (admin auto-approval with CSR).
		// Pending rows wait for the admin approval path to call
		// `provision()` above. The hook is best-effort: suite provisioning
		// failure logs + leaves the row active but suite-less; the admin
		// queue can retry via re-approval.
		if ($persisted->isActive() === true && $csr !== null && $csr !== '') {
			$this->suiteProvisioner->provision(applicationId: $persisted->getId(), csr: $csr);
		}

		$this->logger->info(
			'Registered application ' . $persisted->getId() . ' (' . $persisted->getName() . ') status=' . $persisted->getStatus(),
			['app' => 'doriath']
		);

		// §7.3 — Admin notification dispatch. When the new row is
		// pending, notify every member of the NC admin group via
		// NotificationService with subject `app_pending`. The
		// SUBJECT_SETTING_MAP entry for `app_pending` is null-keyed so
		// the notification is always sent (admins cannot opt out of
		// the approval-queue surface). Failures are swallowed so a
		// missing notification service or a single bad recipient cannot
		// block the registration.
		if ($persisted->isPending() === true && $this->notificationService !== null) {
			$this->dispatchAdminPendingNotification(application: $persisted, registeredBy: $userId);
		}
	}//end runPostRegistrationHooks()

	/**
	 * Notify every admin that a pending application is waiting for
	 * approval. Idempotent failure path — exceptions are logged and
	 * swallowed; never propagate to the registration caller.
	 *
	 * @param Application $application The pending application row
	 * @param string|null $registeredBy The user that submitted the row (null = anonymous)
	 *
	 * @return void
	 *
	 * @spec openspec/changes/implement-application-mgmt/tasks.md#task-7.3
	 */
	private function dispatchAdminPendingNotification(
		Application $application,
		?string $registeredBy,
	): void {
		try {
			$admins = $this->groupManager->get('admin');
			if ($admins === null) {
				return;
			}

			$params = [
				'applicationId' => $application->getId(),
				'applicationName' => $application->getName(),
				'registeredBy' => $registeredBy ?? 'anonymous',
			];

			foreach ($admins->getUsers() as $admin) {
				$this->notificationService->notify(
					subject: 'app_pending',
					recipientId: $admin->getUID(),
					params: $params,
					objectType: 'application',
					objectId: $application->getId(),
				);
			}
		} catch (Throwable $exception) {
			$this->logger->warning(
				'Failed to dispatch app_pending notifications: ' . $exception->getMessage(),
				['app' => 'doriath']
			);
		}//end try
	}//end dispatchAdminPendingNotification()

	/**
	 * Validate a supplied PKCS#10 CSR — format + minimum key size.
	 *
	 * Per implement-application-mgmt §3.3, when a registering caller supplies
	 * a CSR the server must:
	 *   1. parse it via `openssl_csr_get_public_key()` (rejects malformed PEM)
	 *   2. read the public-key bit length via `openssl_pkey_get_details()`
	 *   3. enforce `bits >= 4096`
	 * Any failure throws InvalidArgumentException — the registration row is
	 * never persisted and the EncryptionSuite-provisioning pipeline never
	 * sees a sub-4096 key.
	 *
	 * @param string $csr The PEM-encoded PKCS#10 CSR
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the CSR is malformed or the
	 *                                  key is below 4096 bits.
	 *
	 * @spec openspec/changes/implement-application-mgmt/tasks.md#task-3.3
	 */
	private function validateCsr(string $csr): void {
		// The openssl_csr_get_public_key() call emits a warning on malformed
		// input and returns false; the return value is the contract we check.
		$publicKey = $this->withoutDiagnostics(call: static fn () => openssl_csr_get_public_key($csr));
		if ($publicKey === false) {
			throw new InvalidArgumentException(message: 'Invalid CSR: PKCS#10 format not recognised');
		}

		$details = openssl_pkey_get_details($publicKey);
		if ($details === false || isset($details['bits']) === false) {
			throw new InvalidArgumentException(message: 'Invalid CSR: public key not readable');
		}

		if ((int)$details['bits'] < 4096) {
			throw new InvalidArgumentException(
				message: 'Invalid CSR: key size ' . (int)$details['bits'] . ' bits is below the 4096-bit minimum'
			);
		}
	}//end validateCsr()

	/**
	 * Look up a row by ID, converting DoesNotExistException to a
	 * caller-friendly InvalidArgumentException so controllers can return
	 * 400 / 404 uniformly.
	 *
	 * @param string $applicationId The application ID
	 *
	 * @return Application
	 *
	 * @throws InvalidArgumentException
	 */
	private function findOr400(string $applicationId): Application {
		try {
			return $this->mapper->findById($applicationId);
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException(message: 'Application not found');
		}
	}//end findOr400()
}//end class
