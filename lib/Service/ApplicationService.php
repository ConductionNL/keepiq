<?php

/**
 * Doriath Application Service
 *
 * Smallest scaffold for the registered-application capability — provides
 * register / approve / reject / delete / list methods over Application
 * rows. The full flow (JWT-Bearer auth + EncryptionSuite provisioning +
 * CSR validation + admin notification + cross-app secret-writing) ships
 * with the dedicated implement-application-mgmt build cycle.
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
use OCA\Doriath\Event\Audit\AuditEvent;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCA\Doriath\Support\SuppressesDiagnostics;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Business logic for the registered-application lifecycle (scaffold).
 *
 * Authorization at this layer is intentionally minimal: admin promotion
 * (auto-approval), approve/reject and delete are gated behind the
 * caller-supplied `$isAdmin` flag. The controller resolves admin status
 * from the IUserSession + IGroupManager and forwards the flag.
 */
class ApplicationService
{
    use SuppressesDiagnostics;

    /**
     * Constructor for ApplicationService.
     *
     * @param ApplicationMapper                                 $mapper              The application mapper
     * @param IGroupManager                                     $groupManager        The group manager (admin lookups)
     * @param LoggerInterface                                   $logger              The logger
     * @param NotificationService                               $notificationService The notification service
     * @param EncryptionSuiteService                            $suiteService        The encryption suite service
     * @param IEventDispatcher                                  $eventDispatcher     The event dispatcher
     * @param \OCA\Doriath\Db\MachineLeaseMapper|null           $leaseMapper         The lease mapper (delete cascade)
     * @param \OCA\Doriath\Db\ApplicationLeasePolicyMapper|null $leasePolicyMapper   The lease-policy mapper (delete cascade)
     *
     * @return void
     */
    public function __construct(
        private ApplicationMapper $mapper,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
        private ?NotificationService $notificationService=null,
        private ?EncryptionSuiteService $suiteService=null,
        private ?IEventDispatcher $eventDispatcher=null,
        private ?\OCA\Doriath\Db\MachineLeaseMapper $leaseMapper=null,
        private ?\OCA\Doriath\Db\ApplicationLeasePolicyMapper $leasePolicyMapper=null,
    ) {
    }//end __construct()

    /**
     * Dispatch a typed audit event, fail-soft.
     *
     * @param AuditEvent $event The audit event
     *
     * @return void
     *
     * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3
     */
    private function dispatchAudit(AuditEvent $event): void
    {
        $this->eventDispatcher?->dispatchTyped($event);
    }//end dispatchAudit()

    /**
     * Register a new application.
     *
     * Admin callers auto-approve (status=active); non-admin and anonymous
     * callers create a pending row that an admin must approve. The CSR
     * is stored verbatim on pending rows and validated/processed when the
     * full build cycle ships EncryptionSuite provisioning.
     *
     * @param string      $name        The human-readable name
     * @param string|null $description Optional free-form description
     * @param string      $type        Application type (internal|external)
     * @param string|null $csr         Optional PKCS#10 CSR (stored on pending rows)
     * @param string|null $userId      The Nextcloud user creating the row
     * @param bool        $isAdmin     Whether the caller is an admin
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
        if ($name === '') {
            throw new InvalidArgumentException(message: 'name is required');
        }

        if (in_array($type, [Application::TYPE_INTERNAL, Application::TYPE_EXTERNAL], true) === false) {
            throw new InvalidArgumentException(message: 'type must be internal or external');
        }

        // Per implement-application-mgmt §3.2 / §3.3: when an admin-registered
        // application supplies a CSR (status will be `active`, suite is
        // provisioned immediately), gate on PKCS#10 format + >= 4096-bit key
        // BEFORE the row is persisted. Pending rows (non-admin / anonymous)
        // store the CSR verbatim; validation runs again when the admin
        // approves the row, so a malformed CSR is caught there too.
        if ($isAdmin === true && $csr !== null && $csr !== '') {
            $this->validateCsr(csr: $csr);
        }

        $entity = new Application();
        $entity->setId(Uuid::uuid4()->toString());
        $entity->setName($name);
        $entity->setDescription($description);
        $entity->setType($type);
        $entity->setCsr($csr);
        $entity->setRegisteredBy($userId);
        $entity->setCreatedAt(new DateTime());

        if ($isAdmin === true) {
            $entity->setStatus(Application::STATUS_ACTIVE);
            $entity->setApprovedBy($userId);
            $entity->setApprovedAt(new DateTime());
        } else {
            $entity->setStatus(Application::STATUS_PENDING);
        }

        $persisted = $this->mapper->insert($entity);

        // §9.1 — Provision an EncryptionSuite for any application that
        // becomes active immediately (admin auto-approval with CSR).
        // Pending rows wait for the admin approval path to call
        // `provisionForApplication()` below. The hook is best-effort:
        // suite provisioning failure logs + leaves the row active but
        // suite-less; the admin queue can retry via re-approval.
        if ($persisted->isActive() === true
            && $csr !== null && $csr !== ''
            && $this->suiteService !== null
        ) {
            $this->tryProvisionSuite(application: $persisted, csr: $csr);
        }

        $this->logger->info(
            'Registered application '.$persisted->getId().' ('.$name.') status='.$persisted->getStatus(),
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

        // Anonymous (null) registrants have no NC actor — record as a
        // system-actored event so the audit trail still captures the row.
        if ($userId !== null && $userId !== '') {
            $this->dispatchAudit(
                event: AuditEvent::forUser(
                    actorId: $userId,
                    eventType: AuditEventTypes::APPLICATION_REGISTERED,
                    objectType: 'application',
                    objectId: $persisted->getId(),
                    objectName: $persisted->getName(),
                )
            );
        } else {
            $this->dispatchAudit(
                event: AuditEvent::forSystem(
                    eventType: AuditEventTypes::APPLICATION_REGISTERED,
                    objectType: 'application',
                    objectId: $persisted->getId(),
                    objectName: $persisted->getName(),
                )
            );
        }

        return $persisted;
    }//end register()

    /**
     * Notify every admin that a pending application is waiting for
     * approval. Idempotent failure path — exceptions are logged and
     * swallowed; never propagate to the registration caller.
     *
     * @param Application $application  The pending application row
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
                'applicationId'   => $application->getId(),
                'applicationName' => $application->getName(),
                'registeredBy'    => $registeredBy ?? 'anonymous',
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
                'Failed to dispatch app_pending notifications: '.$exception->getMessage(),
                ['app' => 'doriath']
            );
        }//end try
    }//end dispatchAdminPendingNotification()

    /**
     * Approve a pending application.
     *
     * @param string $applicationId The application ID
     * @param string $adminUserId   The approving admin's Nextcloud user ID
     * @param bool   $isAdmin       Whether the caller is an admin
     *
     * @return Application
     *
     * @throws InvalidArgumentException
     *
     * @spec openspec/changes/implement-application-mgmt/tasks.md#task-3.4
     */
    public function approve(string $applicationId, string $adminUserId, bool $isAdmin): Application
    {
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
            'Approved application '.$applicationId.' by admin '.$adminUserId,
            ['app' => 'doriath']
        );

        $updated = $this->mapper->update($entity);

        // §9.1 — Provision the application's EncryptionSuite as part of
        // the approval transaction. If the CSR is missing the suite is
        // skipped; the admin queue can re-submit a CSR via re-registration.
        if ($storedCsr !== null && $storedCsr !== ''
            && $this->suiteService !== null
        ) {
            $this->tryProvisionSuite(application: $updated, csr: $storedCsr);
        }

        $this->dispatchAudit(
            event: AuditEvent::forUser(
                actorId: $adminUserId,
                eventType: AuditEventTypes::APPLICATION_APPROVED,
                objectType: 'application',
                objectId: $applicationId,
                objectName: $updated->getName(),
            )
        );

        return $updated;
    }//end approve()

    /**
     * Provision an EncryptionSuite for an application. Failures are
     * logged + swallowed — a missing suite is recoverable via re-approval
     * and must never roll back the approval transaction.
     *
     * @param Application $application The newly active application
     * @param string      $csr         The PEM-encoded PKCS#10 CSR
     *
     * @return void
     *
     * @spec openspec/changes/implement-application-mgmt/tasks.md#task-9.1
     */
    private function tryProvisionSuite(Application $application, string $csr): void
    {
        try {
            $this->suiteService?->provisionForApplication(
                applicationId: $application->getId(),
                csrPem: $csr,
            );
            $this->logger->info(
                'Provisioned EncryptionSuite for application '.$application->getId(),
                ['app' => 'doriath']
            );
        } catch (Throwable $exception) {
            $this->logger->warning(
                'Failed to provision EncryptionSuite for application '
                .$application->getId().': '.$exception->getMessage(),
                ['app' => 'doriath']
            );
        }
    }//end tryProvisionSuite()

    /**
     * Reject a pending application — hard-deletes the row per spec D7.
     *
     * @param string $applicationId The application ID
     * @param string $adminUserId   The rejecting admin's Nextcloud user ID
     * @param bool   $isAdmin       Whether the caller is an admin
     *
     * @return void
     *
     * @throws InvalidArgumentException
     *
     * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.7
     */
    public function reject(string $applicationId, string $adminUserId, bool $isAdmin): void
    {
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
            'Rejected application '.$applicationId.' by admin '.$adminUserId,
            ['app' => 'doriath']
        );

        $this->dispatchAudit(
            event: AuditEvent::forUser(
                actorId: $adminUserId,
                eventType: AuditEventTypes::APPLICATION_REJECTED,
                objectType: 'application',
                objectId: $applicationId,
                objectName: $applicationName,
            )
        );
    }//end reject()

    /**
     * Delete an application. Admin-only. The full cascade (Secrets +
     * EncryptionSuite + SecretRequests) lands with the dedicated build
     * cycle once those services accept owner_type=application.
     *
     * @param string $applicationId The application ID
     * @param bool   $isAdmin       Whether the caller is an admin
     *
     * @return void
     *
     * @throws InvalidArgumentException
     *
     * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.7
     */
    public function delete(string $applicationId, bool $isAdmin): void
    {
        if ($isAdmin === false) {
            throw new InvalidArgumentException(message: 'Only an administrator may delete applications');
        }

        $entity = $this->findOr400(applicationId: $applicationId);

        $applicationName = $entity->getName();

        $this->mapper->delete($entity);

        // Machine-lease cascade (machine-secret-leases §1.1): lease rows
        // and the policy override die with the application.
        $this->leaseMapper?->deleteByApplication($applicationId);
        $this->leasePolicyMapper?->deleteByApplication($applicationId);

        $this->logger->info('Deleted application '.$applicationId, ['app' => 'doriath']);

        $this->dispatchAudit(
            event: AuditEvent::forSystem(
                eventType: AuditEventTypes::APPLICATION_DELETED,
                objectType: 'application',
                objectId: $applicationId,
                objectName: $applicationName,
            )
        );
    }//end delete()

    /**
     * Get the active EncryptionSuite certificate for an application.
     *
     * Used by the write-secret-for-app flow: the caller fetches the
     * application's public certificate, encrypts the secret with the
     * embedded public key client-side, and POSTs the encrypted blob to
     * the secrets API. Active applications without a suite return null
     * (admin must re-approve to provision).
     *
     * @param string $applicationId The application ID
     *
     * @return string|null The PEM-encoded certificate or null when no suite exists.
     *
     * @throws InvalidArgumentException When the application is not active.
     *
     * @spec openspec/changes/implement-application-mgmt/tasks.md#task-9.4
     */
    public function getCertificate(string $applicationId): ?string
    {
        $entity = $this->findOr400(applicationId: $applicationId);

        if ($entity->isActive() === false) {
            throw new InvalidArgumentException(message: 'Application is not active');
        }

        if ($this->suiteService === null) {
            return null;
        }

        try {
            $suite = $this->suiteService->getActiveSuite('application', $entity->getId());
            return $suite->getCertificate();
        } catch (Throwable) {
            return null;
        }
    }//end getCertificate()

    /**
     * Get a single application. Admin sees any; non-admin only sees apps
     * they registered or active apps (a baseline RBAC check the
     * controller forwards).
     *
     * @param string $applicationId The application ID
     * @param string $userId        The current Nextcloud user
     * @param bool   $isAdmin       Whether the caller is an admin
     *
     * @return Application
     *
     * @throws InvalidArgumentException
     */
    public function get(string $applicationId, string $userId, bool $isAdmin): Application
    {
        $entity = $this->findOr400(applicationId: $applicationId);

        if ($isAdmin === false
            && $entity->getRegisteredBy() !== $userId
            && $entity->isActive() === false
        ) {
            throw new InvalidArgumentException(message: 'Not authorized to view this application');
        }

        return $entity;
    }//end get()

    /**
     * List applications. Admin sees all; non-admin sees own registrations
     * + active applications.
     *
     * @param string $userId  The current Nextcloud user
     * @param bool   $isAdmin Whether the caller is an admin
     *
     * @return Application[]
     */
    public function listForUser(string $userId, bool $isAdmin): array
    {
        if ($isAdmin === true) {
            return $this->mapper->findAll();
        }

        $own    = $this->mapper->findByRegistrant($userId);
        $active = $this->mapper->findAll(status: Application::STATUS_ACTIVE);

        // Merge by id (own may overlap with active when a user-registered
        // app has been approved).
        $byId = [];
        foreach ($own as $app) {
            $byId[$app->getId()] = $app;
        }

        foreach ($active as $app) {
            $byId[$app->getId()] = $app;
        }

        return array_values($byId);
    }//end listForUser()

    /**
     * List pending applications. Admin-only.
     *
     * @param bool $isAdmin Whether the caller is an admin
     *
     * @return Application[]
     *
     * @throws InvalidArgumentException
     */
    public function listPending(bool $isAdmin): array
    {
        if ($isAdmin === false) {
            throw new InvalidArgumentException(message: 'Only an administrator may list pending applications');
        }

        return $this->mapper->findPending();
    }//end listPending()

    /**
     * Count the pending applications — exposed for the dashboard summary.
     *
     * @return int
     */
    public function countPending(): int
    {
        return $this->mapper->countPending();
    }//end countPending()

    /**
     * Convenience helper: check whether a user is in the admin group.
     *
     * @param string $userId The Nextcloud user ID
     *
     * @return bool
     */
    public function isAdmin(string $userId): bool
    {
        return $this->groupManager->isAdmin($userId);
    }//end isAdmin()

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
    private function findOr400(string $applicationId): Application
    {
        try {
            return $this->mapper->findById($applicationId);
        } catch (DoesNotExistException) {
            throw new InvalidArgumentException(message: 'Application not found');
        }
    }//end findOr400()

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
    private function validateCsr(string $csr): void
    {
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

        if ((int) $details['bits'] < 4096) {
            throw new InvalidArgumentException(
                message: 'Invalid CSR: key size '.(int) $details['bits'].' bits is below the 4096-bit minimum'
            );
        }
    }//end validateCsr()
}//end class
