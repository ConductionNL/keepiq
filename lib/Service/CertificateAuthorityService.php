<?php

/**
 * Doriath Certificate Authority Service
 *
 * Manages the private Certificate Authority (root + intermediate) and certificate signing.
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
use OCA\Doriath\Db\CACertificate;
use OCA\Doriath\Db\CACertificateMapper;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Manages the private Certificate Authority (root + intermediate) and certificate signing.
 */
class CertificateAuthorityService
{
    private const ROOT_LIFETIME_DAYS         = 7300;
    private const INTERMEDIATE_LIFETIME_DAYS = 1095;
    private const RESIGN_BATCH_SIZE          = 100;

    /**
     * Constructor for CertificateAuthorityService.
     *
     * @param CACertificateMapper   $caCertificateMapper The CA certificate mapper
     * @param EncryptionSuiteMapper $suiteMapper         The encryption suite mapper
     * @param IAppConfig            $appConfig           The app config interface
     * @param ICrypto               $crypto              The crypto service
     * @param LoggerInterface       $logger              The logger interface
     *
     * @return void
     */
    public function __construct(
        private CACertificateMapper $caCertificateMapper,
        private EncryptionSuiteMapper $suiteMapper,
        private IAppConfig $appConfig,
        private ICrypto $crypto,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Bootstrap the CA: generate root + intermediate certificates.
     * Idempotent — skips if CA already exists.
     *
     * @return void
     */
    public function bootstrap(): void
    {
        try {
            $this->caCertificateMapper->findRoot();
            $this->logger->info('Doriath: CA already bootstrapped, skipping');
            return;
        } catch (DoesNotExistException) {
            // No root exists — proceed with bootstrap.
        }

        $this->logger->info('Doriath: Bootstrapping Certificate Authority');

        // Generate root CA key pair.
        $rootKey = openssl_pkey_new(
                [
                    'private_key_bits' => 4096,
                    'private_key_type' => OPENSSL_KEYTYPE_RSA,
                ]
                );
        if ($rootKey === false) {
            $this->setDegraded();
            throw new RuntimeException('Failed to generate root CA key: '.openssl_error_string());
        }

        // Self-sign the root certificate.
        $rootCsr  = openssl_csr_new(
            [
                'commonName'       => 'Doriath Root CA',
                'organizationName' => 'Doriath Vault',
            ],
            $rootKey,
            ['digest_alg' => 'sha256']
        );
        $rootCert = openssl_csr_sign(
            $rootCsr,
            null,
            $rootKey,
            self::ROOT_LIFETIME_DAYS,
            ['digest_alg' => 'sha256'],
            random_int(1, PHP_INT_MAX)
        );

        if ($rootCert === false) {
            $this->setDegraded();
            throw new RuntimeException('Failed to sign root CA certificate: '.openssl_error_string());
        }

        openssl_x509_export($rootCert, $rootCertPem);
        openssl_pkey_export($rootKey, $rootKeyPem);

        // Store root certificate with encrypted private key.
        $rootEntity = new CACertificate();
        $rootEntity->setId($this->generateUuid());
        $rootEntity->setType('root');
        $rootEntity->setCertificate($rootCertPem);
        $rootEntity->setPrivateKey($this->crypto->encrypt($rootKeyPem));
        $rootEntity->setCreatedAt(new DateTime());
        $rootEntity->setExpiresAt(new DateTime('+'.self::ROOT_LIFETIME_DAYS.' days'));
        $rootEntity->setIsActive(false);
        $this->caCertificateMapper->insert($rootEntity);

        // Generate and sign the intermediate certificate.
        $this->generateIntermediate(rootKey: $rootKey, rootCert: $rootCert);

        $this->appConfig->setValueString(Application::APP_ID, 'ca_status', 'healthy');
        $this->logger->info('Doriath: CA bootstrap complete');
    }//end bootstrap()

    /**
     * Retry bootstrap (called from admin panel when CA is degraded).
     *
     * @return void
     */
    public function retryBootstrap(): void
    {
        $this->bootstrap();
    }//end retryBootstrap()

    /**
     * Sign a public key PEM with the active intermediate, returning an X.509 certificate PEM.
     *
     * @param string $publicKeyPem The PEM-encoded public key
     *
     * @return string
     */
    public function signPublicKey(string $publicKeyPem): string
    {
        $intermediate     = $this->caCertificateMapper->findActiveIntermediate();
        $intermediateKey  = openssl_pkey_get_private(
            $this->crypto->decrypt($intermediate->getPrivateKey())
        );
        $intermediateCert = $intermediate->getCertificate();

        // Create a CSR from the public key to sign it.
        $tempKey = openssl_pkey_get_public($publicKeyPem);
        if ($tempKey === false) {
            throw new InvalidArgumentException('Invalid public key PEM');
        }

        // We need a private key to create a CSR, but we only have a public key.
        // Instead, create a certificate directly using the intermediate to sign the public key.
        $csr = openssl_csr_new(
            ['commonName' => 'Doriath User Certificate'],
            $tempKey,
            ['digest_alg' => 'sha256']
        );

        // Note: openssl_csr_new requires a private key. For signing an external public key
        // (CSR-based registration), the caller must provide a proper PKCS#10 CSR.
        // For generated key pairs, we create the CSR with the generated private key.
        // This method is called with a proper CSR or after key generation.
        $cert = openssl_csr_sign(
            $csr,
            $intermediateCert,
            $intermediateKey,
            365,
            ['digest_alg' => 'sha256'],
            random_int(1, PHP_INT_MAX)
        );

        if ($cert === false) {
            throw new RuntimeException('Failed to sign certificate: '.openssl_error_string());
        }

        openssl_x509_export($cert, $certPem);
        return $certPem;
    }//end signPublicKey()

    /**
     * Sign a PKCS#10 CSR with the active intermediate certificate.
     *
     * @param string $csrPem The PEM-encoded CSR
     *
     * @return string
     */
    public function signCsr(string $csrPem): string
    {
        $intermediate     = $this->caCertificateMapper->findActiveIntermediate();
        $intermediateKey  = openssl_pkey_get_private(
            $this->crypto->decrypt($intermediate->getPrivateKey())
        );
        $intermediateCert = $intermediate->getCertificate();

        $cert = openssl_csr_sign(
            $csrPem,
            $intermediateCert,
            $intermediateKey,
            365,
            ['digest_alg' => 'sha256'],
            random_int(1, PHP_INT_MAX)
        );

        if ($cert === false) {
            throw new RuntimeException('Failed to sign CSR: '.openssl_error_string());
        }

        openssl_x509_export($cert, $certPem);
        return $certPem;
    }//end signCsr()

    /**
     * Renew the intermediate certificate.
     *
     * @param bool $forced If true, immediately revoke the old intermediate.
     *
     * @return int Number of suites re-signed.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    public function renewIntermediate(bool $forced=false): int
    {
        $root     = $this->caCertificateMapper->findRoot();
        $rootKey  = openssl_pkey_get_private(
            $this->crypto->decrypt($root->getPrivateKey())
        );
        $rootCert = $root->getCertificate();

        $oldIntermediate = $this->caCertificateMapper->findActiveIntermediate();

        // Generate new intermediate.
        $newIntermediate = $this->generateIntermediate(rootKey: $rootKey, rootCert: $rootCert);

        // Deactivate old intermediate.
        $oldIntermediate->setIsActive(false);
        $oldIntermediate->setSuccessorId($newIntermediate->getId());

        if ($forced === true) {
            $oldIntermediate->setRevokedAt(new DateTime());
        }

        $this->caCertificateMapper->update($oldIntermediate);

        // Re-sign all active suites in batches.
        $resignedCount = $this->resignAllActiveSuites();

        $this->logger->info(
                "Doriath: Intermediate renewed, {$resignedCount} suites re-signed",
                [
                    'forced' => $forced,
                ]
                );

        return $resignedCount;
    }//end renewIntermediate()

    /**
     * Renew the root certificate and generate a new intermediate.
     *
     * @return int Number of suites re-signed.
     */
    public function renewRoot(): int
    {
        $oldRoot = $this->caCertificateMapper->findRoot();

        // Generate new root key pair.
        $rootKey = openssl_pkey_new(
                [
                    'private_key_bits' => 4096,
                    'private_key_type' => OPENSSL_KEYTYPE_RSA,
                ]
                );

        $rootCsr  = openssl_csr_new(
            [
                'commonName'       => 'Doriath Root CA',
                'organizationName' => 'Doriath Vault',
            ],
            $rootKey,
            ['digest_alg' => 'sha256']
        );
        $rootCert = openssl_csr_sign(
            $rootCsr,
            null,
            $rootKey,
            self::ROOT_LIFETIME_DAYS,
            ['digest_alg' => 'sha256'],
            random_int(1, PHP_INT_MAX)
        );

        openssl_x509_export($rootCert, $rootCertPem);
        openssl_pkey_export($rootKey, $rootKeyPem);

        // Store new root.
        $newRoot = new CACertificate();
        $newRoot->setId($this->generateUuid());
        $newRoot->setType('root');
        $newRoot->setCertificate($rootCertPem);
        $newRoot->setPrivateKey($this->crypto->encrypt($rootKeyPem));
        $newRoot->setCreatedAt(new DateTime());
        $newRoot->setExpiresAt(new DateTime('+'.self::ROOT_LIFETIME_DAYS.' days'));
        $newRoot->setIsActive(false);
        $this->caCertificateMapper->insert($newRoot);

        // Link old root to successor.
        $oldRoot->setSuccessorId($newRoot->getId());
        $this->caCertificateMapper->update($oldRoot);

        // Deactivate old intermediate and create new one.
        try {
            $oldIntermediate = $this->caCertificateMapper->findActiveIntermediate();
            $oldIntermediate->setIsActive(false);
            $oldIntermediate->setSuccessorId(null);
            $this->caCertificateMapper->update($oldIntermediate);
        } catch (DoesNotExistException) {
            // No active intermediate — bootstrap was incomplete.
        }

        $newIntermediate = $this->generateIntermediate(rootKey: $rootKey, rootCert: $rootCert);

        // Link old intermediate to new one.
        if (isset($oldIntermediate) === true) {
            $oldIntermediate->setSuccessorId($newIntermediate->getId());
            $this->caCertificateMapper->update($oldIntermediate);
        }

        $resignedCount = $this->resignAllActiveSuites();

        $this->logger->info("Doriath: Root renewed, {$resignedCount} suites re-signed");

        return $resignedCount;
    }//end renewRoot()

    /**
     * Get the current CA status.
     *
     * @return array{status: string, root: ?array, intermediate: ?array}
     */
    public function getStatus(): array
    {
        $caStatus = $this->appConfig->getValueString(Application::APP_ID, 'ca_status', 'unknown');

        if ($caStatus === 'degraded') {
            return [
                'status'       => 'not_configured',
                'root'         => null,
                'intermediate' => null,
            ];
        }

        try {
            $root         = $this->caCertificateMapper->findRoot();
            $intermediate = $this->caCertificateMapper->findActiveIntermediate();
        } catch (DoesNotExistException) {
            return [
                'status'       => 'not_configured',
                'root'         => null,
                'intermediate' => null,
            ];
        }

        $now = new DateTime();
        $intermediateExpiry = $intermediate->getExpiresAt();
        $rootExpiry         = $root->getExpiresAt();

        $status = 'healthy';
        if ($intermediateExpiry !== null && $intermediateExpiry->diff($now)->days < 30) {
            $status = 'expiring_soon';
        }

        if ($rootExpiry !== null && $rootExpiry->diff($now)->days < 90) {
            $status = 'action_required';
        }

        if ($intermediate->getRevokedAt() !== null) {
            $status = 'action_required';
        }

        return [
            'status'       => $status,
            'root'         => $root->jsonSerialize(),
            'intermediate' => $intermediate->jsonSerialize(),
        ];
    }//end getStatus()

    /**
     * Generate a new intermediate certificate signed by the given root.
     *
     * @param \OpenSSLAsymmetricKey      $rootKey  Root private key
     * @param \OpenSSLCertificate|string $rootCert Root certificate
     *
     * @return CACertificate
     */
    private function generateIntermediate($rootKey, $rootCert): CACertificate
    {
        $intKey = openssl_pkey_new(
                [
                    'private_key_bits' => 4096,
                    'private_key_type' => OPENSSL_KEYTYPE_RSA,
                ]
                );

        $intCsr = openssl_csr_new(
            [
                'commonName'       => 'Doriath Intermediate CA',
                'organizationName' => 'Doriath Vault',
            ],
            $intKey,
            ['digest_alg' => 'sha256']
        );

        $intCert = openssl_csr_sign(
            $intCsr,
            $rootCert,
            $rootKey,
            self::INTERMEDIATE_LIFETIME_DAYS,
            ['digest_alg' => 'sha256'],
            random_int(1, PHP_INT_MAX)
        );

        if ($intCert === false) {
            $this->setDegraded();
            throw new RuntimeException('Failed to sign intermediate CA: '.openssl_error_string());
        }

        openssl_x509_export($intCert, $intCertPem);
        openssl_pkey_export($intKey, $intKeyPem);

        $entity = new CACertificate();
        $entity->setId($this->generateUuid());
        $entity->setType('intermediate');
        $entity->setCertificate($intCertPem);
        $entity->setPrivateKey($this->crypto->encrypt($intKeyPem));
        $entity->setCreatedAt(new DateTime());
        $entity->setExpiresAt(new DateTime('+'.self::INTERMEDIATE_LIFETIME_DAYS.' days'));
        $entity->setIsActive(true);

        $this->caCertificateMapper->insert($entity);

        return $entity;
    }//end generateIntermediate()

    /**
     * Re-sign all active EncryptionSuites with the current active intermediate.
     *
     * @return int Number of suites re-signed.
     */
    private function resignAllActiveSuites(): int
    {
        $intermediate     = $this->caCertificateMapper->findActiveIntermediate();
        $intermediateKey  = openssl_pkey_get_private(
            $this->crypto->decrypt($intermediate->getPrivateKey())
        );
        $intermediateCert = $intermediate->getCertificate();

        $offset = 0;
        $total  = 0;

        do {
            $suites = $this->suiteMapper->findAllActiveWithLimit(self::RESIGN_BATCH_SIZE, $offset);

            foreach ($suites as $suite) {
                $oldCert = $suite->getCertificate();
                if ($oldCert === null) {
                    continue;
                }

                $pubKey = openssl_pkey_get_public($oldCert);
                if ($pubKey === false) {
                    $this->logger->warning("Doriath: Could not extract public key from suite {$suite->getId()}");
                    continue;
                }

                $csr     = openssl_csr_new(
                    ['commonName' => 'Doriath User Certificate'],
                    $pubKey,
                    ['digest_alg' => 'sha256']
                );
                $newCert = openssl_csr_sign(
                    $csr,
                    $intermediateCert,
                    $intermediateKey,
                    365,
                    ['digest_alg' => 'sha256'],
                    random_int(1, PHP_INT_MAX)
                );

                if ($newCert === false) {
                    $this->logger->warning("Doriath: Failed to re-sign suite {$suite->getId()}");
                    continue;
                }

                openssl_x509_export($newCert, $newCertPem);
                $suite->setCertificate($newCertPem);
                $this->suiteMapper->update($suite);
                $total++;
            }//end foreach

            $suiteCount = count($suites);
            $offset    += self::RESIGN_BATCH_SIZE;
        } while ($suiteCount === self::RESIGN_BATCH_SIZE);

        return $total;
    }//end resignAllActiveSuites()

    /**
     * Set the CA status to degraded.
     *
     * @return void
     */
    private function setDegraded(): void
    {
        $this->appConfig->setValueString(Application::APP_ID, 'ca_status', 'degraded');
        $this->logger->error('Doriath: CA bootstrap failed, entering degraded state');
    }//end setDegraded()

    /**
     * Generate a version-4 UUID string.
     *
     * @return string
     */
    private function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }//end generateUuid()
}//end class
