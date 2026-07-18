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
use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\SecretTypeMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\File\X509;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

/**
 * Manages the private Certificate Authority (root + intermediate) and certificate signing.
 */
class CertificateAuthorityService
{
    private const ROOT_LIFETIME_DAYS         = 7300;
    private const INTERMEDIATE_LIFETIME_DAYS = 1095;
    private const RESIGN_BATCH_SIZE          = 100;

    private const DEFAULT_DN = [
        'countryName'            => 'NL',
        'stateOrProvinceName'    => 'Noord-Holland',
        'localityName'           => 'Amsterdam',
        'organizationName'       => 'Conduction',
        'organizationalUnitName' => 'Doriath',
    ];

    /**
     * Constructor for CertificateAuthorityService.
     *
     * @param CACertificateMapper   $caCertificateMapper The CA certificate mapper
     * @param EncryptionSuiteMapper $suiteMapper         The encryption suite mapper
     * @param IAppConfig            $appConfig           The app config interface
     * @param ICrypto               $crypto              The crypto service
     * @param LoggerInterface       $logger              The logger interface
     * @param SecretMapper|null     $secretMapper        The secret mapper (issued-cert counts)
     * @param SecretTypeMapper|null $secretTypeMapper    The type mapper (issued-cert counts)
     *
     * @return void
     */
    public function __construct(
        private CACertificateMapper $caCertificateMapper,
        private EncryptionSuiteMapper $suiteMapper,
        private IAppConfig $appConfig,
        private ICrypto $crypto,
        private LoggerInterface $logger,
        private ?SecretMapper $secretMapper=null,
        private ?SecretTypeMapper $secretTypeMapper=null,
    ) {
    }//end __construct()

    /**
     * Bootstrap the CA: generate root + intermediate certificates.
     *
     * Idempotent in three ways:
     *  - Skips entirely when both root and an active intermediate already exist (healthy).
     *  - Recovers (creates only the missing intermediate) when a root exists but no
     *    active intermediate does — this is the half-broken state left behind by the
     *    pre-#40 Postgres SMALLINT/boolean mismatch that aborted the second insert
     *    after the root was already persisted.
     *  - Performs the full root + intermediate bootstrap when neither exists.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UndefinedVariable) PHP's openssl_x509_export / openssl_pkey_export
     *   populate $rootCertPem / $rootKeyPem via by-reference output params — PHPMD cannot trace
     *   by-ref semantics and incorrectly reports these as undefined.
     * @SuppressWarnings(PHPMD.StaticAccess)      Ramsey\Uuid\Uuid::uuid4() is a value-object factory;
     *   its static API is the standard idiomatic usage — injection is not warranted.
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
     */
    public function bootstrap(): void
    {
        try {
            $root = $this->caCertificateMapper->findRoot();
        } catch (DoesNotExistException) {
            $root = null;
        }

        if ($root !== null) {
            try {
                $this->caCertificateMapper->findActiveIntermediate();
                $this->logger->info('Doriath: CA already bootstrapped, skipping');
                // Healthy state — make sure ca_status reflects it in case a prior
                // partial-state install left it unset or degraded.
                $this->appConfig->setValueString(Application::APP_ID, 'ca_status', 'healthy');
                return;
            } catch (DoesNotExistException) {
                // Partial state: root exists but intermediate does not.
                // Recover by issuing only the missing intermediate against the existing root.
                $this->logger->warning(
                    'Doriath: detected partial CA state (root present, no active intermediate) — recovering intermediate'
                );
                $recovered = $this->recoverIntermediate(root: $root);
                $this->logger->info('Doriath: CA partial-state recovery: intermediate issued', ['id' => $recovered->getId()]);
                $this->appConfig->setValueString(Application::APP_ID, 'ca_status', 'healthy');
                $this->logger->info('Doriath: CA partial-state recovery complete');
                return;
            }
        }//end if

        $this->logger->info('Doriath: Bootstrapping Certificate Authority');

        // Generate root CA key pair.
        $rootKey = openssl_pkey_new(
            options: [
                'private_key_bits' => 4096,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]
        );
        // @codeCoverageIgnoreStart
        if ($rootKey === false) {
            $this->setDegraded();
            throw new RuntimeException('Failed to generate root CA key: '.openssl_error_string());
        }

        // @codeCoverageIgnoreEnd
        // Self-sign the root certificate.
        $rootCsr  = openssl_csr_new(
            distinguished_names: array_merge(self::DEFAULT_DN, ['commonName' => 'Doriath Root CA']),
            private_key: $rootKey,
            options: ['digest_alg' => 'sha256']
        );
        $rootCert = openssl_csr_sign(
            csr: $rootCsr,
            ca_certificate: null,
            private_key: $rootKey,
            days: self::ROOT_LIFETIME_DAYS,
            options: ['digest_alg' => 'sha256'],
            serial: random_int(1, PHP_INT_MAX)
        );

        // @codeCoverageIgnoreStart
        if ($rootCert === false) {
            $this->setDegraded();
            throw new RuntimeException('Failed to sign root CA certificate: '.openssl_error_string());
        }

        // @codeCoverageIgnoreEnd
        openssl_x509_export(certificate: $rootCert, output: $rootCertPem);
        openssl_pkey_export(key: $rootKey, output: $rootKeyPem);

        // Store root certificate with encrypted private key.
        $rootEntity = new CACertificate();
        $rootEntity->setId(Uuid::uuid4()->toString());
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
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
     */
    public function retryBootstrap(): void
    {
        $this->bootstrap();
    }//end retryBootstrap()

    /**
     * Sign a public key PEM with the active intermediate, returning an X.509 certificate PEM.
     *
     * When the matching private key is supplied (server-side key generation, e.g.
     * the dev seed), the CSR is built from that real keypair so the issued
     * certificate carries the caller's actual public key. Without it,
     * openssl_csr_new() cannot sign with a public-only key and would silently
     * generate a throwaway keypair, producing a certificate whose private key
     * nobody holds — breaking the whole encrypt/decrypt model.
     *
     * @param string      $publicKeyPem  The PEM-encoded public key
     * @param string      $commonName    The common name for the certificate (e.g. user ID or app name)
     * @param string|null $privateKeyPem The PEM-encoded matching private key, when available
     *
     * @return string
     *
     * @SuppressWarnings(PHPMD.UndefinedVariable) openssl_x509_export populates $certPem via
     *   by-reference output param — PHPMD cannot trace by-ref semantics.
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
     */
    public function signPublicKey(
        string $publicKeyPem,
        string $commonName='Doriath User',
        ?string $privateKeyPem=null,
    ): string {
        $intermediate     = $this->caCertificateMapper->findActiveIntermediate();
        $intermediatePriv = $this->crypto->decrypt($intermediate->getPrivateKey());
        $intermediateKey  = openssl_pkey_get_private(private_key: $intermediatePriv);
        $intermediateCert = $intermediate->getCertificate();
        $submittedPub     = openssl_pkey_get_public(public_key: $publicKeyPem);

        if ($submittedPub === false) {
            throw new InvalidArgumentException('Invalid public key PEM');
        }

        // Build the CSR. openssl_csr_new() needs a PRIVATE key: when the caller
        // owns the keypair (server-side generation) we use it directly so the
        // signed certificate carries the caller's real public key.
        $csrKey = $submittedPub;
        if ($privateKeyPem !== null) {
            $csrKey = openssl_pkey_get_private(private_key: $privateKeyPem);
            if ($csrKey === false) {
                throw new InvalidArgumentException('Invalid private key PEM');
            }
        }

        // On some OpenSSL builds a public-only key works here; on others
        // openssl_csr_new() SILENTLY generates a throwaway keypair. The
        // modulus guard below catches that case and reroutes to phpseclib.
        $csr = @openssl_csr_new(
            distinguished_names: array_merge(self::DEFAULT_DN, ['commonName' => $commonName]),
            private_key: $csrKey,
            options: ['digest_alg' => 'sha256']
        );

        $certPem = null;
        if ($csr !== false) {
            $cert = openssl_csr_sign(
                csr: $csr,
                ca_certificate: $intermediateCert,
                private_key: $intermediateKey,
                days: 365,
                options: ['digest_alg' => 'sha256'],
                serial: random_int(1, PHP_INT_MAX)
            );
            if ($cert !== false) {
                openssl_x509_export(certificate: $cert, output: $certPem);
            }
        }

        // Zero-knowledge invariant: the issued certificate MUST carry the
        // SUBMITTED public key. If openssl invented a throwaway keypair
        // (the public-only-key footgun documented above), every value later
        // encrypted under this certificate would be undecryptable with the
        // user's real private key — silent vault data loss. Verified live
        // 2026-07-18: PHP 8.4/OpenSSL minted an RSA-2048 throwaway for an
        // RSA-4096 browser key on the first-run suite-creation path.
        if ($certPem === null || $this->certCarriesPublicKey(certPem: $certPem, publicKeyPem: $publicKeyPem) === false) {
            $certPem = $this->issueCertificateForPublicKey(
                publicKeyPem: $publicKeyPem,
                commonName: $commonName,
                intermediateCertPem: $intermediateCert,
                intermediatePrivPem: $intermediatePriv,
            );
        }

        if ($this->certCarriesPublicKey(certPem: $certPem, publicKeyPem: $publicKeyPem) === false) {
            // Never hand out a certificate for a key nobody holds.
            throw new RuntimeException(
                'Refusing to issue a certificate that does not carry the submitted public key'
            );
        }

        return $certPem;
    }//end signPublicKey()

    /**
     * Whether an X.509 certificate carries exactly the given RSA public key.
     *
     * @param string $certPem      The PEM certificate
     * @param string $publicKeyPem The PEM public key to compare against
     *
     * @return bool
     */
    private function certCarriesPublicKey(string $certPem, string $publicKeyPem): bool
    {
        $certPub = openssl_pkey_get_public(public_key: $certPem);
        $subPub  = openssl_pkey_get_public(public_key: $publicKeyPem);
        if ($certPub === false || $subPub === false) {
            return false;
        }

        $certDetails = openssl_pkey_get_details(key: $certPub);
        $subDetails  = openssl_pkey_get_details(key: $subPub);
        if ($certDetails === false || $subDetails === false
            || isset($certDetails['rsa']['n']) === false || isset($subDetails['rsa']['n']) === false
        ) {
            return false;
        }

        return hash_equals($subDetails['rsa']['n'], $certDetails['rsa']['n']);
    }//end certCarriesPublicKey()

    /**
     * Issue an X.509 certificate carrying an arbitrary submitted public key,
     * signed by the intermediate — via phpseclib, which (unlike ext-openssl's
     * CSR path) can bind a public-only key deterministically on every build.
     *
     * @param string $publicKeyPem        The subject public key (PEM)
     * @param string $commonName          The subject common name
     * @param string $intermediateCertPem The signing intermediate certificate (PEM)
     * @param string $intermediatePrivPem The intermediate private key (PEM, decrypted)
     *
     * @return string The issued certificate PEM
     *
     * @throws RuntimeException When issuance fails
     */
    private function issueCertificateForPublicKey(
        string $publicKeyPem,
        string $commonName,
        string $intermediateCertPem,
        string $intermediatePrivPem,
    ): string {
        $subjectPublic = \phpseclib3\Crypt\PublicKeyLoader::load($publicKeyPem);
        if ($subjectPublic instanceof \phpseclib3\Crypt\RSA\PublicKey === false) {
            throw new RuntimeException('Submitted public key is not an RSA public key');
        }

        $issuerPrivate = \phpseclib3\Crypt\PublicKeyLoader::load($intermediatePrivPem);
        if ($issuerPrivate instanceof \phpseclib3\Crypt\RSA\PrivateKey === false) {
            throw new RuntimeException('Intermediate private key could not be loaded for issuance');
        }

        $issuer = new \phpseclib3\File\X509();
        $issuer->loadX509($intermediateCertPem);
        $issuer->setPrivateKey($issuerPrivate->withPadding(\phpseclib3\Crypt\RSA::SIGNATURE_PKCS1));

        $subject = new \phpseclib3\File\X509();
        // PKCS1 padding on the subject key so the SPKI carries the plain
        // rsaEncryption OID — phpseclib's PSS default would emit an
        // id-RSASSA-PSS SPKI that WebCrypto/openssl consumers reject.
        $subject->setPublicKey($subjectPublic->withPadding(\phpseclib3\Crypt\RSA::SIGNATURE_PKCS1));
        $subject->setDNProp('id-at-countryName', self::DEFAULT_DN['countryName']);
        $subject->setDNProp('id-at-organizationName', self::DEFAULT_DN['organizationName']);
        $subject->setDNProp('id-at-commonName', $commonName);

        $signer = new \phpseclib3\File\X509();
        $signer->setSerialNumber((string) random_int(1, PHP_INT_MAX), 10);
        $signer->setEndDate('+365 days');
        $issued = $signer->sign($issuer, $subject);
        if ($issued === false) {
            throw new RuntimeException('phpseclib certificate issuance failed');
        }

        $pem = $signer->saveX509($issued);
        if (is_string($pem) === false || $pem === '') {
            throw new RuntimeException('phpseclib certificate export failed');
        }

        return $pem;
    }//end issueCertificateForPublicKey()

    /**
     * Sign a PKCS#10 CSR with the active intermediate certificate.
     *
     * @param string $csrPem The PEM-encoded CSR
     *
     * @return string
     *
     * @SuppressWarnings(PHPMD.UndefinedVariable) openssl_x509_export populates $certPem via
     *   by-reference output param — PHPMD cannot trace by-ref semantics.
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
     */
    public function signCsr(string $csrPem): string
    {
        $intermediate     = $this->caCertificateMapper->findActiveIntermediate();
        $intermediateKey  = openssl_pkey_get_private(
            private_key: $this->crypto->decrypt($intermediate->getPrivateKey())
        );
        $intermediateCert = $intermediate->getCertificate();

        $cert = openssl_csr_sign(
            csr: $csrPem,
            ca_certificate: $intermediateCert,
            private_key: $intermediateKey,
            days: 365,
            options: ['digest_alg' => 'sha256'],
            serial: random_int(1, PHP_INT_MAX)
        );

        // @codeCoverageIgnoreStart
        if ($cert === false) {
            throw new RuntimeException('Failed to sign CSR: '.openssl_error_string());
        }

        // @codeCoverageIgnoreEnd
        openssl_x509_export(certificate: $cert, output: $certPem);
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
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
     */
    public function renewIntermediate(bool $forced=false): int
    {
        $root     = $this->caCertificateMapper->findRoot();
        $rootKey  = openssl_pkey_get_private(
            private_key: $this->crypto->decrypt($root->getPrivateKey())
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
     *
     * @SuppressWarnings(PHPMD.UndefinedVariable) openssl_x509_export / openssl_pkey_export
     *   populate $rootCertPem / $rootKeyPem via by-reference output params — PHPMD cannot
     *   trace by-ref semantics.
     * @SuppressWarnings(PHPMD.StaticAccess)      Ramsey\Uuid\Uuid::uuid4() is a value-object
     *   factory; its static API is the standard idiomatic usage.
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
     */
    public function renewRoot(): int
    {
        $oldRoot = $this->caCertificateMapper->findRoot();

        // Generate new root key pair.
        $rootKey = openssl_pkey_new(
            options: [
                'private_key_bits' => 4096,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]
        );

        $rootCsr  = openssl_csr_new(
            distinguished_names: array_merge(self::DEFAULT_DN, ['commonName' => 'Doriath Root CA']),
            private_key: $rootKey,
            options: ['digest_alg' => 'sha256']
        );
        $rootCert = openssl_csr_sign(
            csr: $rootCsr,
            ca_certificate: null,
            private_key: $rootKey,
            days: self::ROOT_LIFETIME_DAYS,
            options: ['digest_alg' => 'sha256'],
            serial: random_int(1, PHP_INT_MAX)
        );

        openssl_x509_export(certificate: $rootCert, output: $rootCertPem);
        openssl_pkey_export(key: $rootKey, output: $rootKeyPem);

        // Store new root.
        $newRoot = new CACertificate();
        $newRoot->setId(Uuid::uuid4()->toString());
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
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
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
            'issued'       => $this->issuedCounts(),
        ];
    }//end getStatus()

    /**
     * Issued-certificate counts (certificate-lifecycle §2.6): active
     * user/application suites plus stored certificate-type secrets and
     * how many of those expire within 30 days. Counts only — no
     * identifiers, PEM, or key material.
     *
     * @return array<string,int>
     */
    private function issuedCounts(): array
    {
        $counts = [
            'activeUserSuites'        => $this->suiteMapper->countActiveByOwnerType('user'),
            'activeApplicationSuites' => $this->suiteMapper->countActiveByOwnerType('application'),
            'storedCertificates'      => 0,
            'storedExpiringSoon'      => 0,
        ];

        if ($this->secretMapper === null || $this->secretTypeMapper === null) {
            return $counts;
        }

        try {
            $certTypeId = $this->secretTypeMapper->findByName('certificate')->getId();
            $counts['storedCertificates'] = $this->secretMapper->countByTypeId(typeId: $certTypeId);
            $counts['storedExpiringSoon'] = $this->secretMapper->countByTypeId(
                typeId: $certTypeId,
                expiresBefore: new DateTime('+30 days')
            );
        } catch (DoesNotExistException) {
            // Types not seeded yet — counts stay zero.
        }

        return $counts;
    }//end issuedCounts()

    /**
     * Re-issue one suite/application certificate from the private CA,
     * preserving its existing public key (certificate-lifecycle §2.4 /
     * D3). Never mints a new key pair — a re-sign that cannot keep the
     * original public key is rejected and the existing certificate kept.
     *
     * @param EncryptionSuite $suite The suite whose certificate to re-issue
     *
     * @return bool Whether a new certificate was issued and stored
     *
     * @spec openspec/changes/certificate-lifecycle/specs/certificate-lifecycle/spec.md#requirement-guided-renewal
     */
    public function reissueSuiteCertificate(EncryptionSuite $suite): bool
    {
        $oldCert = $suite->getCertificate();
        if ($oldCert === null || $oldCert === '') {
            return false;
        }

        try {
            $intermediate = $this->caCertificateMapper->findActiveIntermediate();
        } catch (DoesNotExistException) {
            return false;
        }

        $newCertPem = $this->resignPreservingPublicKey(
            oldCert: $oldCert,
            fallbackCn: $suite->getOwnerId(),
            intermediateCert: $intermediate->getCertificate(),
            intermediateKeyPem: $this->crypto->decrypt($intermediate->getPrivateKey()),
        );
        if ($newCertPem === null) {
            $this->logger->warning(
                "Doriath: re-issue kept existing certificate for suite {$suite->getId()} — "
                .'could not re-sign while preserving its public key'
            );

            return false;
        }

        $suite->setCertificate($newCertPem);
        $this->suiteMapper->update($suite);

        return true;
    }//end reissueSuiteCertificate()

    /**
     * Issue the intermediate certificate against an existing persisted root.
     *
     * Used by partial-state recovery in {@see self::bootstrap()} when a previous
     * bootstrap attempt persisted the root but failed to persist the intermediate
     * (the pre-#40 Postgres SMALLINT/boolean mismatch). The root row is reused
     * as-is — only the intermediate is generated and persisted.
     *
     * @param CACertificate $root The existing root certificate entity
     *
     * @return CACertificate The newly created intermediate
     */
    private function recoverIntermediate(CACertificate $root): CACertificate
    {
        $rootKey = openssl_pkey_get_private(
            private_key: $this->crypto->decrypt($root->getPrivateKey())
        );

        // @codeCoverageIgnoreStart
        if ($rootKey === false) {
            $this->setDegraded();
            throw new RuntimeException('Failed to load persisted root CA private key: '.openssl_error_string());
        }

        // @codeCoverageIgnoreEnd
        return $this->generateIntermediate(rootKey: $rootKey, rootCert: $root->getCertificate());
    }//end recoverIntermediate()

    /**
     * Generate a new intermediate certificate signed by the given root.
     *
     * @param \OpenSSLAsymmetricKey      $rootKey  Root private key
     * @param \OpenSSLCertificate|string $rootCert Root certificate
     *
     * @return CACertificate
     *
     * @SuppressWarnings(PHPMD.UndefinedVariable) openssl_x509_export / openssl_pkey_export
     *   populate $intCertPem / $intKeyPem via by-reference output params — PHPMD cannot
     *   trace by-ref semantics.
     * @SuppressWarnings(PHPMD.StaticAccess)      Ramsey\Uuid\Uuid::uuid4() is a value-object
     *   factory; its static API is the standard idiomatic usage.
     */
    private function generateIntermediate($rootKey, $rootCert): CACertificate
    {
        $intKey = openssl_pkey_new(
            options: [
                'private_key_bits' => 4096,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]
        );

        $intCsr = openssl_csr_new(
            distinguished_names: array_merge(self::DEFAULT_DN, ['commonName' => 'Doriath Intermediate CA']),
            private_key: $intKey,
            options: ['digest_alg' => 'sha256']
        );

        $intCert = openssl_csr_sign(
            csr: $intCsr,
            ca_certificate: $rootCert,
            private_key: $rootKey,
            days: self::INTERMEDIATE_LIFETIME_DAYS,
            options: ['digest_alg' => 'sha256'],
            serial: random_int(1, PHP_INT_MAX)
        );

        // @codeCoverageIgnoreStart
        if ($intCert === false) {
            $this->setDegraded();
            throw new RuntimeException('Failed to sign intermediate CA: '.openssl_error_string());
        }

        // @codeCoverageIgnoreEnd
        openssl_x509_export(certificate: $intCert, output: $intCertPem);
        openssl_pkey_export(key: $intKey, output: $intKeyPem);

        $entity = new CACertificate();
        $entity->setId(Uuid::uuid4()->toString());
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
        $intermediate       = $this->caCertificateMapper->findActiveIntermediate();
        $intermediateKeyPem = $this->crypto->decrypt($intermediate->getPrivateKey());
        $intermediateCert   = $intermediate->getCertificate();

        $offset = 0;
        $total  = 0;

        do {
            $suites = $this->suiteMapper->findAllActiveWithLimit(self::RESIGN_BATCH_SIZE, $offset);

            foreach ($suites as $suite) {
                $oldCert = $suite->getCertificate();
                if ($oldCert === null) {
                    continue;
                }

                $pubKey = openssl_pkey_get_public(public_key: $oldCert);
                // @codeCoverageIgnoreStart
                if ($pubKey === false) {
                    $this->logger->warning("Doriath: Could not extract public key from suite {$suite->getId()}");
                    continue;
                }

                // @codeCoverageIgnoreEnd
                $newCertPem = $this->resignPreservingPublicKey(
                    oldCert: $oldCert,
                    fallbackCn: $suite->getOwnerId(),
                    intermediateCert: $intermediateCert,
                    intermediateKeyPem: $intermediateKeyPem,
                );

                // A null result means it could not mint a certificate that
                // carries the suite's ORIGINAL public key. In
                // the zero-knowledge model the server never holds the suite's
                // private key, so it must never replace the certificate with one
                // bound to a different key pair — doing so silently makes every
                // value the browser encrypts under the new certificate
                // undecryptable with the user's wrapped private key (the
                // read-after-write decrypt failure). When we can't preserve the
                // key, we keep the existing certificate untouched.
                if ($newCertPem === null) {
                    $this->logger->warning(
                        "Doriath: kept existing certificate for suite {$suite->getId()} — "
                        .'could not re-sign while preserving its public key'
                    );
                    continue;
                }

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
     * Re-sign a suite certificate while preserving its original public key.
     *
     * Re-signing exists to chain a suite certificate to a freshly-renewed
     * intermediate. It MUST keep the suite's existing public key: the matching
     * private key is AES-wrapped and only the user's browser can decrypt it, so
     * the server cannot mint a new key pair for the suite.
     *
     * PHP's openssl_csr_new() CANNOT build a CSR from a public-only key — given
     * one it SILENTLY generates a throwaway key pair, and the issued certificate
     * then carries a public key nobody holds the private half of. Any value the
     * browser later encrypts under that certificate is undecryptable with the
     * user's wrapped private key (the read-after-write decrypt failure). The
     * old CSR-based implementation therefore NEVER produced a valid re-sign —
     * its modulus guard rejected every result and callers silently kept the old
     * certificate (caught live by certificate-lifecycle §2.4 verification).
     * This implementation assembles the certificate directly with phpseclib,
     * carrying the suite's original SubjectPublicKeyInfo and subject DN, signed
     * by the intermediate. The openssl modulus guard remains as belt-and-braces:
     * a result whose public key differs is still rejected.
     *
     * @param string $oldCert            The current PEM certificate to re-sign
     * @param string $fallbackCn         CN to use when the old cert has none
     * @param string $intermediateCert   The signing intermediate certificate (PEM)
     * @param string $intermediateKeyPem The decrypted intermediate private key (PEM)
     *
     * @return string|null The new PEM certificate, or null when the public key
     *   could not be preserved.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $fallbackCn is kept for
     *   signature stability; phpseclib preserves the full original subject DN.
     */
    private function resignPreservingPublicKey(
        string $oldCert,
        string $fallbackCn,
        string $intermediateCert,
        string $intermediateKeyPem,
    ): ?string {
        $oldPub = openssl_pkey_get_public(public_key: $oldCert);
        if ($oldPub === false) {
            return null;
        }

        $oldDetails = openssl_pkey_get_details(key: $oldPub);
        if ($oldDetails === false || isset($oldDetails['rsa']['n']) === false) {
            return null;
        }

        try {
            $old = new X509();
            if ($old->loadX509($oldCert) === false) {
                return null;
            }

            $issuer = new X509();
            $issuer->loadX509($intermediateCert);
            $issuer->setPrivateKey(PublicKeyLoader::loadPrivateKey($intermediateKeyPem));

            $subject = new X509();
            $subject->setPublicKey($old->getPublicKey());
            $subject->setDN($old->getDN());

            $signer = new X509();
            $signer->setStartDate('-1 day');
            $signer->setEndDate('+365 days');
            $signer->setSerialNumber((string) random_int(1, PHP_INT_MAX), 10);
            $signed = $signer->sign($issuer, $subject);
            if ($signed === false) {
                return null;
            }

            $newCertPem = $signer->saveX509($signed);
        } catch (Throwable $exception) {
            $this->logger->warning(
                'Doriath: phpseclib re-sign failed: '.$exception->getMessage(),
                ['app' => Application::APP_ID]
            );

            return null;
        }//end try

        if (is_string($newCertPem) === false || $newCertPem === '') {
            return null;
        }

        // Guard the zero-knowledge invariant: the issued certificate MUST carry
        // the suite's original public key — reject the certificate otherwise so
        // the caller keeps the correct existing one.
        $newPub = openssl_pkey_get_public(public_key: $newCertPem);
        if ($newPub === false) {
            return null;
        }

        $newDetails = openssl_pkey_get_details(key: $newPub);
        if ($newDetails === false || isset($newDetails['rsa']['n']) === false) {
            return null;
        }

        if (hash_equals($oldDetails['rsa']['n'], $newDetails['rsa']['n']) === false) {
            return null;
        }

        return $newCertPem;
    }//end resignPreservingPublicKey()

    /**
     * Set the CA status to degraded.
     *
     * @return void
     *
     * @codeCoverageIgnore
     */
    private function setDegraded(): void
    {
        $this->appConfig->setValueString(Application::APP_ID, 'ca_status', 'degraded');
        $this->logger->error('Doriath: CA bootstrap failed, entering degraded state');
    }//end setDegraded()
}//end class
