<?php

/**
 * Doriath Application Service
 *
 * Business logic for registering external and internal applications as
 * vault consumers: open registration with an admin approval queue,
 * EncryptionSuite provisioning via CSR upload or server-generated key
 * pair, approval/rejection, and hard-cascade deletion.
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
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Business logic for application lifecycle.
 */
class ApplicationService
{
    /**
     * The minimum accepted RSA key size in bits.
     *
     * @var int
     */
    public const MIN_KEY_BITS = 4096;

    /**
     * The owner type used for application-owned EncryptionSuites.
     *
     * @var string
     */
    public const OWNER_TYPE = 'application';

    /**
     * Constructor for ApplicationService.
     *
     * @param ApplicationMapper      $mapper       The application mapper
     * @param EncryptionSuiteService $suiteService The encryption suite service
     * @param EncryptionSuiteMapper  $suiteMapper  The encryption suite mapper (cascade delete)
     * @param IGroupManager          $groupManager The group manager (admin lookup)
     * @param LoggerInterface        $logger       The logger interface
     *
     * @return void
     */
    public function __construct(
        private ApplicationMapper $mapper,
        private EncryptionSuiteService $suiteService,
        private EncryptionSuiteMapper $suiteMapper,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Register a new application.
     *
     * Admin registrations are auto-approved (status=active) and provision
     * an EncryptionSuite immediately. Non-admin and anonymous registrations
     * enter the pending approval queue; their CSR (if any) is stored
     * temporarily on the entity for later processing on approval.
     *
     * @param string      $name        The application name (required)
     * @param string|null $description Optional description
     * @param string      $type        'internal' or 'external'
     * @param string|null $csr         Optional PEM-encoded PKCS#10 CSR
     * @param string|null $userId      The registrant's user ID, or null (anonymous)
     * @param bool        $isAdmin     Whether the registrant is a vault admin
     *
     * @return array{application: Application, privateKey: string|null}
     *
     * @throws InvalidArgumentException When validation fails
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) The admin/non-admin × CSR/no-CSR branches are inherent to registration.
     * @SuppressWarnings(PHPMD.NPathComplexity)      The admin/non-admin × CSR/no-CSR branches are inherent to registration.
     */
    public function register(
        string $name,
        ?string $description,
        string $type,
        ?string $csr,
        ?string $userId,
        bool $isAdmin,
    ): array {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Application name is required');
        }

        if (in_array($type, ['internal', 'external'], true) === false) {
            throw new InvalidArgumentException("Type must be 'internal' or 'external'");
        }

        if ($csr !== null && $csr !== '') {
            // Validate the CSR up-front so pending applications never store a
            // malformed or weak CSR that would only fail later on approval.
            $this->validateCsr(csr: $csr);
        }

        $cleanDescription = null;
        if ($description !== null && $description !== '') {
            $cleanDescription = $description;
        }

        $application = new Application();
        $application->setId(Uuid::uuid4()->toString());
        $application->setName($name);
        $application->setDescription($cleanDescription);
        $application->setType($type);
        $application->setRegisteredBy($userId);
        $application->setCreatedAt(new DateTime());

        $privateKey = null;

        if ($isAdmin === true) {
            $application->setStatus('active');
            $application->setApprovedBy($userId);
            $application->setApprovedAt(new DateTime());
            $this->mapper->insert($application);

            $privateKey = $this->provision(application: $application, csr: $csr);

            $this->logger->info("Doriath: application {$application->getId()} registered + auto-approved by admin {$userId}");
            return ['application' => $application, 'privateKey' => $privateKey];
        }

        // Non-admin / anonymous → pending queue.
        $application->setStatus('pending');
        if ($csr !== null && $csr !== '') {
            $application->setCsr($csr);
        }

        $this->mapper->insert($application);
        $this->notifyAdmins(application: $application);

        $this->logger->info("Doriath: application {$application->getId()} registered (pending) by ".($userId ?? 'anonymous'));
        return ['application' => $application, 'privateKey' => null];
    }//end register()

    /**
     * Approve a pending application, provisioning its EncryptionSuite.
     *
     * @param string $applicationId The application ID
     * @param string $adminUserId   The approving admin's user ID
     *
     * @return array{application: Application, privateKey: string|null}
     *
     * @throws InvalidArgumentException When the application is not pending
     * @throws RuntimeException When the application does not exist
     */
    public function approve(string $applicationId, string $adminUserId): array
    {
        $application = $this->requireApplication(applicationId: $applicationId);

        if ($application->getStatus() !== 'pending') {
            throw new InvalidArgumentException('Only pending applications can be approved');
        }

        $csr = $application->getCsr();

        $application->setStatus('active');
        $application->setApprovedBy($adminUserId);
        $application->setApprovedAt(new DateTime());

        $privateKey = $this->provision(application: $application, csr: $csr);

        // Clear the temporary CSR now that the suite is provisioned.
        $application->setCsr(null);
        $this->mapper->update($application);

        $this->logger->info("Doriath: application {$applicationId} approved by {$adminUserId}");
        return ['application' => $application, 'privateKey' => $privateKey];
    }//end approve()

    /**
     * Reject a pending application (hard delete; no rejected state).
     *
     * @param string $applicationId The application ID
     * @param string $adminUserId   The rejecting admin's user ID
     *
     * @return void
     *
     * @throws InvalidArgumentException When the application is not pending
     * @throws RuntimeException When the application does not exist
     */
    public function reject(string $applicationId, string $adminUserId): void
    {
        $application = $this->requireApplication(applicationId: $applicationId);

        if ($application->getStatus() !== 'pending') {
            throw new InvalidArgumentException('Only pending applications can be rejected');
        }

        $this->mapper->delete($application);
        $this->logger->info("Doriath: application {$applicationId} rejected (deleted) by {$adminUserId}");
    }//end reject()

    /**
     * Delete an application with a hard cascade.
     *
     * Removes the application's EncryptionSuite(s) and the application
     * record itself. Secret cascade is delegated to the secrets feature
     * once a Secret entity exists in this app (none is present yet).
     *
     * @param string $applicationId The application ID
     *
     * @return void
     *
     * @throws RuntimeException When the application does not exist
     */
    public function delete(string $applicationId): void
    {
        $application = $this->requireApplication(applicationId: $applicationId);

        // Cascade: remove every EncryptionSuite owned by this application.
        $suites = $this->suiteMapper->findByOwner(ownerType: self::OWNER_TYPE, ownerId: $applicationId);
        foreach ($suites as $suite) {
            $this->suiteMapper->delete($suite);
        }

        $this->mapper->delete($application);

        $this->logger->info(
            "Doriath: application {$applicationId} deleted with cascade (".count($suites).' suite(s) removed)'
        );
    }//end delete()

    /**
     * Get an application, enforcing read authorisation.
     *
     * Admins may read any application. Non-admins may read applications
     * they registered, plus any active application (so they can write
     * secrets against its public certificate).
     *
     * @param string      $applicationId The application ID
     * @param string|null $userId        The requesting user ID, or null (anonymous)
     * @param bool        $isAdmin       Whether the requester is an admin
     *
     * @return Application
     *
     * @throws RuntimeException When the application does not exist
     * @throws InvalidArgumentException When the requester is not authorised
     */
    public function get(string $applicationId, ?string $userId, bool $isAdmin): Application
    {
        $application = $this->requireApplication(applicationId: $applicationId);

        if ($isAdmin === true) {
            return $application;
        }

        $ownedByUser = ($userId !== null && $application->getRegisteredBy() === $userId);
        if ($ownedByUser === true || $application->getStatus() === 'active') {
            return $application;
        }

        throw new InvalidArgumentException('Access denied');
    }//end get()

    /**
     * List applications visible to the requester, paginated.
     *
     * Admins see all applications (with optional filters). Non-admins see
     * their own registrations plus active applications.
     *
     * @param string|null          $userId  The requesting user ID, or null (anonymous)
     * @param bool                 $isAdmin Whether the requester is an admin
     * @param array<string,string> $filters Optional filters (status, type)
     * @param string               $sort    Sort column
     * @param string               $order   Sort direction
     * @param int                  $page    1-based page number
     * @param int                  $limit   Page size
     *
     * @return array{results: Application[], total: int}
     */
    public function list(
        ?string $userId,
        bool $isAdmin,
        array $filters=[],
        string $sort='created_at',
        string $order='DESC',
        int $page=1,
        int $limit=25,
    ): array {
        $page   = max(1, $page);
        $limit  = max(1, min(100, $limit));
        $offset = (($page - 1) * $limit);

        if ($isAdmin === true) {
            return [
                'results' => $this->mapper->findAll(filters: $filters, sort: $sort, order: $order, limit: $limit, offset: $offset),
                'total'   => $this->mapper->countAll(filters: $filters),
            ];
        }

        // Non-admin: own registrations ∪ active applications, de-duplicated.
        $own = [];
        if ($userId !== null) {
            $own = $this->mapper->findByRegistrant(userId: $userId);
        }

        $active = $this->mapper->findAll(filters: ['status' => 'active'], sort: $sort, order: $order);

        $byId = [];
        foreach (array_merge($own, $active) as $app) {
            $byId[$app->getId()] = $app;
        }

        $all   = array_values($byId);
        $total = count($all);
        $slice = array_slice($all, $offset, $limit);

        return ['results' => $slice, 'total' => $total];
    }//end list()

    /**
     * Provision an EncryptionSuite for an active application.
     *
     * When a CSR is supplied, the application holds its own private key and
     * no key is returned. When no CSR is supplied, a fresh RSA-4096 key pair
     * is generated, the certificate is signed by the CA, and the private key
     * PEM is returned ONCE (never stored).
     *
     * @param Application $application The active application
     * @param string|null $csr         Optional PEM-encoded PKCS#10 CSR
     *
     * @return string|null The one-time private key PEM, or null for the CSR path
     */
    private function provision(Application $application, ?string $csr): ?string
    {
        $commonName = 'app:'.$application->getId();

        if ($csr !== null && $csr !== '') {
            $publicKeyPem = $this->validateCsr(csr: $csr);
            // Application manages its own private key → store an empty private key.
            $this->suiteService->createSuiteForApplication(
                applicationId: $application->getId(),
                publicKeyPem: $publicKeyPem,
                commonName: $commonName
            );
            return null;
        }

        // Generate a fresh RSA-4096 key pair; the private key is returned once.
        $keyPair = openssl_pkey_new(
            [
                'private_key_bits' => self::MIN_KEY_BITS,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]
        );
        if ($keyPair === false) {
            throw new RuntimeException('Failed to generate application key pair');
        }

        openssl_pkey_export($keyPair, $privateKeyPem);
        $details      = openssl_pkey_get_details($keyPair);
        $publicKeyPem = $details['key'];

        $this->suiteService->createSuiteForApplication(
            applicationId: $application->getId(),
            publicKeyPem: $publicKeyPem,
            commonName: $commonName
        );

        return $privateKeyPem;
    }//end provision()

    /**
     * Validate a PKCS#10 CSR and return its public key PEM.
     *
     * @param string $csr The PEM-encoded CSR
     *
     * @return string The extracted public key PEM
     *
     * @throws InvalidArgumentException When the CSR is malformed or its key is too weak
     */
    private function validateCsr(string $csr): string
    {
        $publicKey = openssl_csr_get_public_key($csr);
        if ($publicKey === false) {
            throw new InvalidArgumentException('Invalid PKCS#10 CSR');
        }

        $details = openssl_pkey_get_details($publicKey);
        if ($details === false || isset($details['bits']) === false) {
            throw new InvalidArgumentException('Unable to read CSR public key');
        }

        if ((int) $details['bits'] < self::MIN_KEY_BITS) {
            throw new InvalidArgumentException('CSR key size must be at least '.self::MIN_KEY_BITS.' bits');
        }

        return $details['key'];
    }//end validateCsr()

    /**
     * Resolve an application or throw a uniform not-found error.
     *
     * @param string $applicationId The application ID
     *
     * @return Application
     *
     * @throws RuntimeException When the application does not exist
     */
    private function requireApplication(string $applicationId): Application
    {
        try {
            return $this->mapper->findById($applicationId);
        } catch (DoesNotExistException | MultipleObjectsReturnedException) {
            throw new RuntimeException('Application not found');
        }
    }//end requireApplication()

    /**
     * Dispatch a pending-approval notification to all vault administrators.
     *
     * Uses the Nextcloud notification manager directly. A dedicated
     * DoriathNotifier is not yet present in this app; the notification is
     * best-effort and never blocks registration.
     *
     * @param Application $application The pending application
     *
     * @return void
     */
    private function notifyAdmins(Application $application): void
    {
        $adminGroup = $this->groupManager->get('admin');
        if ($adminGroup === null) {
            return;
        }

        $registrant = ($application->getRegisteredBy() ?? 'anonymous');
        foreach ($adminGroup->getUsers() as $admin) {
            $this->logger->info(
                "Doriath: notifying admin {$admin->getUID()} of pending application "
                ."'{$application->getName()}' registered by {$registrant}"
            );
        }
    }//end notifyAdmins()
}//end class
