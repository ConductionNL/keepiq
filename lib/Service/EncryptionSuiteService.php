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
use OCP\AppFramework\Db\DoesNotExistException;
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
    /**
     * Constructor for EncryptionSuiteService.
     *
     * @param EncryptionSuiteMapper       $mapper      The encryption suite mapper
     * @param CertificateAuthorityService $caService   The CA service
     * @param IAppConfig                  $appConfig   The app config interface
     * @param IUserManager                $userManager The user manager
     * @param LoggerInterface             $logger      The logger interface
     *
     * @return void
     */
    public function __construct(
        private EncryptionSuiteMapper $mapper,
        private CertificateAuthorityService $caService,
        private IAppConfig $appConfig,
        private IUserManager $userManager,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

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
