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

use OCA\Doriath\AppInfo\Application;
use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Business logic for EncryptionSuite lifecycle: create, revoke, reinstate.
 */
class EncryptionSuiteService
{
    /**
     * Constructor for EncryptionSuiteService.
     *
     * @param EncryptionSuiteMapper       $mapper    The encryption suite mapper
     * @param CertificateAuthorityService $caService The CA service
     * @param IAppConfig                  $appConfig The app config interface
     * @param LoggerInterface             $logger    The logger interface
     *
     * @return void
     */
    public function __construct(
        private EncryptionSuiteMapper $mapper,
        private CertificateAuthorityService $caService,
        private IAppConfig $appConfig,
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
     */
    public function createSuite(
        string $ownerType,
        string $ownerId,
        string $publicKeyPem,
        string $encryptedPrivateKey,
    ): EncryptionSuite {
        $caStatus = $this->appConfig->getValueString(Application::APP_ID, 'ca_status', 'unknown');
        if ($caStatus !== 'healthy') {
            throw new \RuntimeException('Cannot create EncryptionSuite: CA is not healthy (status: '.$caStatus.')');
        }

        $certificate = $this->caService->signPublicKey($publicKeyPem);

        $suite = new EncryptionSuite();
        $suite->setId(Uuid::uuid4()->toString());
        $suite->setOwnerType($ownerType);
        $suite->setOwnerId($ownerId);
        $suite->setCertificate($certificate);
        $suite->setPrivateKey($encryptedPrivateKey);
        $suite->setStatus('active');
        $suite->setCreatedAt(new \DateTime());

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
     */
    public function revokeSuite(string $id, string $reason, string $revokedBy): EncryptionSuite
    {
        $suite = $this->mapper->findById($id);

        if ($suite->getStatus() === 'compromised') {
            throw new \InvalidArgumentException('Cannot revoke a compromised suite — it has already been replaced');
        }

        $suite->setStatus('revoked');
        $suite->setRevokedAt(new \DateTime());
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
     */
    public function reinstateSuite(string $id, string $reinstatedBy): EncryptionSuite
    {
        $suite = $this->mapper->findById($id);

        if ($suite->getStatus() !== 'revoked') {
            throw new \InvalidArgumentException(
                'Only revoked suites can be reinstated (current status: '.$suite->getStatus().')'
            );
        }

        // Re-sign the existing public key with the active intermediate.
        $publicKey = openssl_pkey_get_public($suite->getCertificate());
        if ($publicKey === false) {
            throw new \RuntimeException('Could not extract public key from suite certificate');
        }

        openssl_pkey_export_public($publicKey, $publicKeyPem);
        $newCertificate = $this->caService->signPublicKey($publicKeyPem);

        $suite->setCertificate($newCertificate);
        $suite->setStatus('active');
        $suite->setReinstatedAt(new \DateTime());
        $suite->setReinstatedBy($reinstatedBy);

        $this->mapper->update($suite);

        $this->logger->info("Doriath: EncryptionSuite {$id} reinstated by {$reinstatedBy}");

        return $suite;
    }//end reinstateSuite()

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
}//end class
