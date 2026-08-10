<?php

/**
 * Doriath Application Service
 *
 * The read / delete surface of the registered-application capability —
 * get, list, count, delete plus the admin-group lookup — and the entry
 * point through which controllers reach the admission transitions.
 *
 * The admission transitions themselves (register / approve / reject, with
 * CSR validation, EncryptionSuite provisioning and the admin
 * approval-queue notification) live in ApplicationLifecycleService; the
 * suite reads live in ApplicationSuiteProvisioner and the audit
 * vocabulary in ApplicationAuditTrail.
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

use InvalidArgumentException;
use OCA\Doriath\Db\Application;
use OCA\Doriath\Db\ApplicationMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

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
     * The admission transitions (register / approve / reject).
     *
     * @var ApplicationLifecycleService
     */
    private ApplicationLifecycleService $lifecycle;

    /**
     * The EncryptionSuite provisioner (certificate reads).
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
     * Constructor for ApplicationService.
     *
     * @param ApplicationMapper                                 $mapper            The application mapper
     * @param IGroupManager                                     $groupManager      The group manager (admin lookups)
     * @param LoggerInterface                                   $logger            The logger
     * @param \OCA\Doriath\Db\MachineLeaseMapper|null           $leaseMapper       The lease mapper (delete cascade)
     * @param \OCA\Doriath\Db\ApplicationLeasePolicyMapper|null $leasePolicyMapper The lease-policy mapper (delete cascade)
     * @param ApplicationLifecycleService|null                  $lifecycle         The admission transitions
     * @param ApplicationSuiteProvisioner|null                  $suiteProvisioner  The EncryptionSuite provisioner
     * @param ApplicationAuditTrail|null                        $auditTrail        The application audit trail
     *
     * @return void
     */
    public function __construct(
        private ApplicationMapper $mapper,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
        private ?\OCA\Doriath\Db\MachineLeaseMapper $leaseMapper=null,
        private ?\OCA\Doriath\Db\ApplicationLeasePolicyMapper $leasePolicyMapper=null,
        ?ApplicationLifecycleService $lifecycle=null,
        ?ApplicationSuiteProvisioner $suiteProvisioner=null,
        ?ApplicationAuditTrail $auditTrail=null,
    ) {
        $this->suiteProvisioner = ($suiteProvisioner ?? new ApplicationSuiteProvisioner(logger: $logger));
        $this->auditTrail       = ($auditTrail ?? new ApplicationAuditTrail());
        $this->lifecycle        = ($lifecycle ?? new ApplicationLifecycleService(
            mapper: $mapper,
            groupManager: $groupManager,
            logger: $logger,
            notificationService: null,
            suiteProvisioner: $this->suiteProvisioner,
            auditTrail: $this->auditTrail,
        ));
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
        return $this->lifecycle->register(
            name: $name,
            description: $description,
            type: $type,
            csr: $csr,
            userId: $userId,
            isAdmin: $isAdmin,
        );
    }//end register()

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
        return $this->lifecycle->approve(
            applicationId: $applicationId,
            adminUserId: $adminUserId,
            isAdmin: $isAdmin,
        );
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
     *
     * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.7
     */
    public function reject(string $applicationId, string $adminUserId, bool $isAdmin): void
    {
        $this->lifecycle->reject(
            applicationId: $applicationId,
            adminUserId: $adminUserId,
            isAdmin: $isAdmin,
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

        $this->auditTrail->recordDeleted(
            applicationId: $applicationId,
            applicationName: $applicationName,
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

        return $this->suiteProvisioner->activeCertificate(applicationId: $entity->getId());
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
}//end class
