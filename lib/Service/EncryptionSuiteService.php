<?php

/**
 * Doriath Encryption Suite Service
 *
 * Business logic for EncryptionSuite lifecycle: create, revoke, reinstate.
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
use OCA\Doriath\AppInfo\Application;
use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Event\Audit\AuditEvent;
use OCA\Doriath\Event\Audit\AuditEventFactory;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCA\Doriath\Event\EncryptionSuiteRevokedEvent;
use OCA\Doriath\Support\SuppressesDiagnostics;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
/**
 * Business logic for EncryptionSuite lifecycle: create, revoke, reinstate.
 */
class EncryptionSuiteService
{
    use SuppressesDiagnostics;

    /**
     * Constructor for EncryptionSuiteService.
     *
     * @param EncryptionSuiteMapper       $mapper          The encryption suite mapper
     * @param CertificateAuthorityService $caService       The CA service
     * @param IAppConfig                  $appConfig       The app config interface
     * @param IUserManager                $userManager     The user manager
     * @param LoggerInterface             $logger          The logger interface
     * @param IEventDispatcher|null       $eventDispatcher The event dispatcher
     * @param AuditEventFactory           $auditEvents     The audit-event factory
     *
     * @return void
     */
    public function __construct(
        private EncryptionSuiteMapper $mapper,
        private CertificateAuthorityService $caService,
        private IAppConfig $appConfig,
        private IUserManager $userManager,
        private LoggerInterface $logger,
        private ?IEventDispatcher $eventDispatcher=null,
        private AuditEventFactory $auditEvents=new AuditEventFactory(),
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
     * Create an EncryptionSuite for a user or application.
     *
     * @param string $ownerType           'user' or 'application'
     * @param string $ownerId             Nextcloud user ID or Application ID
     * @param string $publicKeyPem        PEM-encoded public key
     * @param string $encryptedPrivateKey Base64-encoded AES-GCM envelope of the private key
     *
     * @return EncryptionSuite
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-2
     */
    public function createSuite(
        string $ownerType,
        string $ownerId,
        string $publicKeyPem,
        string $encryptedPrivateKey,
    ): EncryptionSuite {
        $caStatus = $this->appConfig->getValueString(Application::APP_ID, 'ca_status', 'unknown');
        if ($caStatus !== 'healthy') {
            throw new RuntimeException('Cannot create EncryptionSuite: CA is not healthy (status: '.$caStatus.')');
        }

        $commonName  = $this->resolveCommonName(ownerType: $ownerType, ownerId: $ownerId);
        $certificate = $this->caService->signPublicKey(publicKeyPem: $publicKeyPem, commonName: $commonName);

        $suite = new EncryptionSuite();
        $suite->setId(Uuid::uuid4()->toString());
        $suite->setOwnerType($ownerType);
        $suite->setOwnerId($ownerId);
        $suite->setCertificate($certificate);
        $suite->setPrivateKey($encryptedPrivateKey);
        $suite->setStatus('active');
        $suite->setCreatedAt(new DateTime());

        $this->mapper->insert($suite);

        $this->logger->info("Doriath: EncryptionSuite created for {$ownerType}/{$ownerId}");

        return $suite;
    }//end createSuite()

    /**
     * Provision an EncryptionSuite for a registered application.
     *
     * The application supplies its public key via a PKCS#10 CSR; the
     * private key never leaves the application's possession, so the
     * stored `private_key` blob is intentionally empty (applications
     * decrypt server-issued ciphertext with their own private key).
     *
     * The CSR is parsed via OpenSSL, the public key extracted as PEM,
     * and the public key is then signed by the active CA intermediate
     * via `createSuite()`. The resulting suite is keyed to
     * `owner_type=application` / `owner_id=$applicationId`.
     *
     * @param string $applicationId The Application ID
     * @param string $csrPem        The PEM-encoded PKCS#10 CSR
     *
     * @return EncryptionSuite
     *
     * @throws RuntimeException When the CSR's public key cannot be extracted.
     *
     * @spec openspec/changes/implement-application-mgmt/tasks.md#task-9.1
     */
    public function provisionForApplication(string $applicationId, string $csrPem): EncryptionSuite
    {
        if ($applicationId === '') {
            throw new InvalidArgumentException('applicationId is required');
        }

        if ($csrPem === '') {
            throw new InvalidArgumentException('csrPem is required');
        }

        // The openssl_csr_get_public_key() call warns on a malformed CSR and
        // returns false; the false return is the condition we act on.
        $publicKeyResource = $this->withoutDiagnostics(call: static fn () => openssl_csr_get_public_key($csrPem));
        if ($publicKeyResource === false) {
            throw new RuntimeException('Could not extract public key from CSR');
        }

        $details = openssl_pkey_get_details($publicKeyResource);
        if ($details === false || isset($details['key']) === false) {
            throw new RuntimeException('Public key details unreadable from CSR');
        }

        $publicKeyPem = (string) $details['key'];

        return $this->createSuite(
            ownerType: 'application',
            ownerId: $applicationId,
            publicKeyPem: $publicKeyPem,
            // Applications hold their own private key — server stores no envelope.
            encryptedPrivateKey: '',
        );
    }//end provisionForApplication()

    /**
     * Revoke an EncryptionSuite.
     *
     * @param string $id        The suite ID
     * @param string $reason    The reason for revocation
     * @param string $revokedBy The user who revoked the suite
     *
     * @return EncryptionSuite
     *
     * @throws DoesNotExistException
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-2
     */
    public function revokeSuite(string $id, string $reason, string $revokedBy): EncryptionSuite
    {
        $suite = $this->mapper->findById($id);

        if ($suite->getStatus() === 'compromised') {
            throw new InvalidArgumentException('Cannot revoke a compromised suite — it has already been replaced');
        }

        $suite->setStatus('revoked');
        $suite->setRevokedAt(new DateTime());
        $suite->setRevokedReason($reason);
        $suite->setRevokedBy($revokedBy);

        $this->mapper->update($suite);

        $this->logger->info("Doriath: EncryptionSuite {$id} revoked by {$revokedBy}: {$reason}");

        // Implement-user-sharing §10.3 — dispatch a revocation event so
        // EncryptionSuiteRevokedListener can cascade share-target
        // cleanup and promote temporary delegations to permanent.
        if ($this->eventDispatcher !== null) {
            $this->eventDispatcher->dispatchTyped(
                new EncryptionSuiteRevokedEvent(
                    suiteId: $id,
                    ownerType: $suite->getOwnerType(),
                    ownerId: $suite->getOwnerId(),
                    revokedBy: $revokedBy,
                )
            );
        }

        $this->dispatchAudit(
            event: $this->auditEvents->forUser(
                actorId: $revokedBy,
                eventType: AuditEventTypes::SUITE_REVOKED,
                objectType: 'suite',
                objectId: $id,
                metadata: ['reason' => $reason],
            )
        );

        return $suite;
    }//end revokeSuite()

    /**
     * Reinstate a revoked EncryptionSuite. Re-signs the public key with the active intermediate.
     *
     * @param string $id           The suite ID
     * @param string $reinstatedBy The user who reinstated the suite
     *
     * @return EncryptionSuite
     *
     * @throws DoesNotExistException
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-2
     */
    public function reinstateSuite(string $id, string $reinstatedBy): EncryptionSuite
    {
        $suite = $this->mapper->findById($id);

        if ($suite->getStatus() !== 'revoked') {
            throw new InvalidArgumentException(
                'Only revoked suites can be reinstated (current status: '.$suite->getStatus().')'
            );
        }

        // Re-sign the existing public key with the active intermediate.
        $publicKey = openssl_pkey_get_public(public_key: $suite->getCertificate());
        if ($publicKey === false) {
            throw new RuntimeException('Could not extract public key from suite certificate');
        }

        $details        = openssl_pkey_get_details(key: $publicKey);
        $publicKeyPem   = $details['key'];
        $commonName     = $this->resolveCommonName(
            ownerType: $suite->getOwnerType(),
            ownerId: $suite->getOwnerId()
        );
        $newCertificate = $this->caService->signPublicKey(
            publicKeyPem: $publicKeyPem,
            commonName: $commonName
        );

        $suite->setCertificate($newCertificate);
        $suite->setStatus('active');
        $suite->setReinstatedAt(new DateTime());
        $suite->setReinstatedBy($reinstatedBy);

        $this->mapper->update($suite);

        $this->logger->info("Doriath: EncryptionSuite {$id} reinstated by {$reinstatedBy}");

        $this->dispatchAudit(
            event: $this->auditEvents->forUser(
                actorId: $reinstatedBy,
                eventType: AuditEventTypes::SUITE_REINSTATED,
                objectType: 'suite',
                objectId: $id,
            )
        );

        return $suite;
    }//end reinstateSuite()

    /**
     * Mark an EncryptionSuite as compromised. Called immediately when
     * compromise recovery is initiated — before migration begins.
     *
     * @param string $id            The suite ID
     * @param string $compromisedBy The user who reported the compromise
     *
     * @return EncryptionSuite
     *
     * @throws DoesNotExistException
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-2
     */
    public function markCompromised(string $id, string $compromisedBy): EncryptionSuite
    {
        $suite = $this->mapper->findById($id);

        $suite->setStatus('compromised');
        $suite->setRevokedAt(new DateTime());
        $suite->setRevokedReason('Master password compromised');
        $suite->setRevokedBy($compromisedBy);

        $this->mapper->update($suite);

        $this->logger->warning("Doriath: EncryptionSuite {$id} marked compromised by {$compromisedBy}");

        $this->dispatchAudit(
            event: $this->auditEvents->forUser(
                actorId: $compromisedBy,
                eventType: AuditEventTypes::SUITE_RECOVERY_STARTED,
                objectType: 'suite',
                objectId: $id,
            )
        );

        return $suite;
    }//end markCompromised()

    /**
     * Persist changes to an EncryptionSuite.
     *
     * @param EncryptionSuite $suite The suite to update
     *
     * @return EncryptionSuite
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-2
     */
    public function updateSuite(EncryptionSuite $suite): EncryptionSuite
    {
        return $this->mapper->update($suite);
    }//end updateSuite()

    /**
     * Get the active EncryptionSuite for an owner.
     *
     * @param string $ownerType The owner type
     * @param string $ownerId   The owner ID
     *
     * @return EncryptionSuite
     *
     * @throws DoesNotExistException
     */
    public function getActiveSuite(string $ownerType, string $ownerId): EncryptionSuite
    {
        return $this->mapper->findActiveByOwner($ownerType, $ownerId);
    }//end getActiveSuite()

    /**
     * Get an EncryptionSuite by ID.
     *
     * @param string $id The suite ID
     *
     * @return EncryptionSuite
     *
     * @throws DoesNotExistException
     */
    public function getSuite(string $id): EncryptionSuite
    {
        return $this->mapper->findById($id);
    }//end getSuite()

    /**
     * Get all EncryptionSuites for an owner.
     *
     * @param string $ownerType The owner type
     * @param string $ownerId   The owner ID
     *
     * @return EncryptionSuite[]
     */
    public function getSuitesByOwner(string $ownerType, string $ownerId): array
    {
        return $this->mapper->findByOwner($ownerType, $ownerId);
    }//end getSuitesByOwner()

    /**
     * Resolve the certificate common name for an owner.
     *
     * For users, returns the federated cloud ID (user@instance) if available,
     * otherwise falls back to the user ID. For applications, returns the owner ID.
     *
     * @param string $ownerType The owner type (user or application)
     * @param string $ownerId   The owner ID
     *
     * @return string
     */
    private function resolveCommonName(string $ownerType, string $ownerId): string
    {
        if ($ownerType === 'user') {
            $user = $this->userManager->get($ownerId);
            if ($user !== null) {
                return $user->getCloudId();
            }
        }

        return $ownerId;
    }//end resolveCommonName()
}//end class
