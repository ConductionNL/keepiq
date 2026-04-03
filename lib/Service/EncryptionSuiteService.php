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
     * @param EncryptionSuiteMapper       $mapper         The encryption suite mapper
     * @param CertificateAuthorityService $caService      The CA service
     * @param EncryptService              $encryptService The stateless encrypt service
     * @param IAppConfig                  $appConfig      The app config interface
     * @param IUserManager                $userManager    The user manager
     * @param LoggerInterface             $logger         The logger interface
     *
     * @return void
     */
    public function __construct(
        private EncryptionSuiteMapper $mapper,
        private CertificateAuthorityService $caService,
        private EncryptService $encryptService,
        private IAppConfig $appConfig,
        private IUserManager $userManager,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create an EncryptionSuite for a user or application (Phase 1).
     *
     * The server generates the RSA-4096 key pair, signs the certificate, and
     * encrypts the private key with a random temporary passphrase. The browser
     * receives the encrypted PK + passphrase, decrypts locally, re-encrypts
     * with the master password, and sends the final blob back via storePrivateKey().
     *
     * @param string $ownerType 'user' or 'application'
     * @param string $ownerId   Nextcloud user ID or Application ID
     *
     * @return array{suite: EncryptionSuite, encryptedPrivateKey: string, passphrase: string, publicKeyPem: string}
     */
    public function createSuite(
        string $ownerType,
        string $ownerId,
    ): array {
        $caStatus = $this->appConfig->getValueString(Application::APP_ID, 'ca_status', 'unknown');
        if ($caStatus !== 'healthy') {
            throw new RuntimeException('Cannot create EncryptionSuite: CA is not healthy (status: '.$caStatus.')');
        }

        // Generate RSA-4096 key pair on the server.
        $keyPair = openssl_pkey_new(
                [
                    'private_key_bits' => 4096,
                    'private_key_type' => OPENSSL_KEYTYPE_RSA,
                ]
                );

        // @codeCoverageIgnoreStart
        if ($keyPair === false) {
            throw new RuntimeException('Failed to generate RSA key pair: '.openssl_error_string());
        }

        // @codeCoverageIgnoreEnd
        // Extract public key PEM.
        $details      = openssl_pkey_get_details(key: $keyPair);
        $publicKeyPem = $details['key'];

        // Sign the certificate with the CA using a proper CSR (has the private key).
        $commonName  = $this->resolveCommonName(ownerType: $ownerType, ownerId: $ownerId);
        $certificate = $this->caService->signKeyPair(
            privateKey: $keyPair,
            commonName: $commonName
        );

        // Export the plaintext private key PEM.
        openssl_pkey_export(key: $keyPair, output: $privateKeyPem);

        // Encrypt with a random temporary passphrase using our standard AES-GCM envelope.
        // The browser will decrypt with this passphrase and re-encrypt with the master password.
        $passphrase   = bin2hex(random_bytes(32));
        $encryptedPem = $this->encryptService->encryptPrivateKey(
            pem: $privateKeyPem,
            password: $passphrase
        );

        // Store the suite with a placeholder private key — the browser will
        // replace it with the master-password-encrypted blob in Phase 2.
        $suite = new EncryptionSuite();
        $suite->setId(Uuid::uuid4()->toString());
        $suite->setOwnerType($ownerType);
        $suite->setOwnerId($ownerId);
        $suite->setCertificate($certificate);
        $suite->setPrivateKey(null);
        $suite->setStatus('active');
        $suite->setCreatedAt(new DateTime());

        $this->mapper->insert($suite);

        $this->logger->info("Doriath: EncryptionSuite created for {$ownerType}/{$ownerId} (awaiting private key)");

        return [
            'suite'               => $suite,
            'encryptedPrivateKey' => $encryptedPem,
            'passphrase'          => $passphrase,
            'publicKeyPem'        => $publicKeyPem,
        ];
    }//end createSuite()

    /**
     * Regenerate the key pair for a suite with no private key (Phase 1).
     *
     * Returns the new key pair (encrypted with temp passphrase) and a nonce.
     * The caller must prove identity by signing the nonce with the old private key
     * via confirmRepair() before the new certificate is committed.
     *
     * The new certificate is stored immediately (needed for the public key), but
     * the private key slot remains null until confirmRepair succeeds.
     *
     * @param string $suiteId The suite ID to repair
     *
     * @return array{suite: EncryptionSuite, encryptedPrivateKey: string, passphrase: string, publicKeyPem: string, nonce: string}
     *
     * @throws DoesNotExistException
     * @throws RuntimeException
     */
    public function repairSuite(string $suiteId): array
    {
        $suite = $this->mapper->findById(id: $suiteId);

        if ($suite->getPrivateKey() !== null) {
            throw new RuntimeException('Suite already has a private key — repair not needed');
        }

        // Generate new RSA-4096 key pair.
        $keyPair = openssl_pkey_new(
                [
                    'private_key_bits' => 4096,
                    'private_key_type' => OPENSSL_KEYTYPE_RSA,
                ]
                );

        // @codeCoverageIgnoreStart
        if ($keyPair === false) {
            throw new RuntimeException('Failed to generate RSA key pair: '.openssl_error_string());
        }

        // @codeCoverageIgnoreEnd
        $details      = openssl_pkey_get_details(key: $keyPair);
        $publicKeyPem = $details['key'];

        // Re-sign the certificate.
        $commonName  = $this->resolveCommonName(
            ownerType: $suite->getOwnerType(),
            ownerId: $suite->getOwnerId()
        );
        $certificate = $this->caService->signKeyPair(
            privateKey: $keyPair,
            commonName: $commonName
        );
        $suite->setCertificate($certificate);

        // Encrypt private key with temp passphrase.
        openssl_pkey_export(key: $keyPair, output: $privateKeyPem);
        $passphrase   = bin2hex(random_bytes(32));
        $encryptedPem = $this->encryptService->encryptPrivateKey(
            pem: $privateKeyPem,
            password: $passphrase
        );

        // Generate a nonce for identity verification.
        $nonce = bin2hex(random_bytes(32));

        // Store the new certificate but keep private key null until confirmed.
        $this->mapper->update($suite);

        $this->logger->info("Doriath: EncryptionSuite {$suiteId} repair initiated (awaiting signature proof)");

        return [
            'suite'               => $suite,
            'encryptedPrivateKey' => $encryptedPem,
            'passphrase'          => $passphrase,
            'publicKeyPem'        => $publicKeyPem,
            'nonce'               => $nonce,
        ];
    }//end repairSuite()

    /**
     * Confirm suite repair by verifying a nonce signature from the old private key.
     *
     * The browser signs the nonce with the old private key (proving it knows the
     * old master password). The server verifies the signature against the old
     * suite's certificate. Only if valid, the new private key is accepted.
     *
     * @param string $suiteId             The suite ID being repaired
     * @param string $oldSuiteId          The old compromised suite ID
     * @param string $nonce               The nonce that was signed
     * @param string $signature           Base64-encoded RSA-PSS signature
     * @param string $encryptedPrivateKey The master-password-encrypted private key blob
     *
     * @return EncryptionSuite
     *
     * @throws RuntimeException
     * @throws DoesNotExistException
     */
    public function confirmRepair(
        string $suiteId,
        string $oldSuiteId,
        string $nonce,
        string $signature,
        string $encryptedPrivateKey,
    ): EncryptionSuite {
        // Get the old suite's certificate to verify the signature.
        $oldSuite   = $this->mapper->findById(id: $oldSuiteId);
        $oldCertPem = $oldSuite->getCertificate();
        $oldPubKey  = openssl_pkey_get_public($oldCertPem);

        if ($oldPubKey === false) {
            throw new RuntimeException('Could not extract public key from old suite certificate');
        }

        // Verify the signature: the browser signed the nonce with the old private key.
        $signatureRaw = base64_decode($signature, true);
        if ($signatureRaw === false) {
            throw new RuntimeException('Invalid signature encoding');
        }

        $valid = openssl_verify(
            data: $nonce,
            signature: $signatureRaw,
            public_key: $oldPubKey,
            algorithm: OPENSSL_ALGO_SHA256
        );

        if ($valid !== 1) {
            throw new RuntimeException('Signature verification failed — cannot prove ownership of old key');
        }

        // Signature valid — store the master-password-encrypted private key.
        $suite = $this->mapper->findById(id: $suiteId);
        $suite->setPrivateKey($encryptedPrivateKey);
        $this->mapper->update($suite);

        $this->logger->info("Doriath: EncryptionSuite {$suiteId} repair confirmed via signature proof");

        return $suite;
    }//end confirmRepair()

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
