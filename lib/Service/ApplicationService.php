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
use OCP\AppFramework\Db\DoesNotExistException;
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
    /**
     * Constructor for ApplicationService.
     *
     * @param ApplicationMapper $mapper       The application mapper
     * @param IGroupManager     $groupManager The group manager (admin lookups)
     * @param LoggerInterface   $logger       The logger
     *
     * @return void
     */
    public function __construct(
        private ApplicationMapper $mapper,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
        private ?NotificationService $notificationService = null,
    ) {
    }//end __construct()

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
        }
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

        $entity = $this->findOr400($applicationId);

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

        return $this->mapper->update($entity);
    }//end approve()

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
     */
    public function reject(string $applicationId, string $adminUserId, bool $isAdmin): void
    {
        if ($isAdmin === false) {
            throw new InvalidArgumentException(message: 'Only an administrator may reject applications');
        }

        $entity = $this->findOr400($applicationId);

        if ($entity->isPending() === false) {
            throw new InvalidArgumentException(message: 'Only pending applications may be rejected');
        }

        $this->mapper->delete($entity);

        $this->logger->info(
            'Rejected application '.$applicationId.' by admin '.$adminUserId,
            ['app' => 'doriath']
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
     */
    public function delete(string $applicationId, bool $isAdmin): void
    {
        if ($isAdmin === false) {
            throw new InvalidArgumentException(message: 'Only an administrator may delete applications');
        }

        $entity = $this->findOr400($applicationId);

        $this->mapper->delete($entity);

        $this->logger->info('Deleted application '.$applicationId, ['app' => 'doriath']);
    }//end delete()

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
        $entity = $this->findOr400($applicationId);

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
        // openssl_csr_get_public_key() emits a warning on malformed input
        // and returns false; we suppress the warning + check the return.
        $publicKey = @openssl_csr_get_public_key($csr);
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
