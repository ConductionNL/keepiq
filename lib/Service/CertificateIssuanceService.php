<?php

/**
 * Doriath Certificate Issuance Service
 *
 * Issues and re-issues LEAF certificates from the private CA's active
 * intermediate: signing a submitted public key, signing a PKCS#10 CSR,
 * and re-signing an EncryptionSuite's certificate onto a freshly
 * renewed intermediate. The CA HIERARCHY itself (root + intermediate
 * bootstrap and renewal) is CertificateAuthorityService's job; this
 * class only consumes the active intermediate.
 *
 * The zero-knowledge invariant is enforced here and nowhere else: an
 * issued certificate MUST carry the SUBMITTED public key, and a re-sign
 * MUST keep the suite's ORIGINAL public key, because the server never
 * holds the matching private half. A certificate bound to a key nobody
 * holds silently makes every value encrypted under it undecryptable.
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
use OCA\Doriath\Db\CACertificateMapper;
use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Support\SuppressesDiagnostics;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Issues and re-issues leaf certificates from the active CA intermediate.
 */
class CertificateIssuanceService
{
    use SuppressesDiagnostics;

    /**
     * The distinguished name every Doriath-issued certificate is built
     * from; the caller supplies only the common name. Single source of
     * truth for the whole private CA — CertificateAuthorityService
     * builds the root and intermediate DNs from it too.
     *
     * @var array<string,string>
     */
    public const DEFAULT_DN = [
        'countryName'            => 'NL',
        'stateOrProvinceName'    => 'Noord-Holland',
        'localityName'           => 'Amsterdam',
        'organizationName'       => 'Conduction',
        'organizationalUnitName' => 'Doriath',
    ];

    /**
     * How many active suites are re-signed per batch.
     *
     * @var int
     */
    private const RESIGN_BATCH_SIZE = 100;

    /**
     * Constructor for CertificateIssuanceService.
     *
     * @param CACertificateMapper      $caCertificateMapper The CA certificate mapper
     * @param EncryptionSuiteMapper    $suiteMapper         The encryption suite mapper
     * @param ICrypto                  $crypto              The crypto service
     * @param LoggerInterface          $logger              The logger interface
     * @param X509CertificateAssembler $assembler           The phpseclib certificate assembler
     *
     * @return void
     *
     * @spec exclude Constructor wiring only; no behaviour.
     */
    public function __construct(
        private CACertificateMapper $caCertificateMapper,
        private EncryptionSuiteMapper $suiteMapper,
        private ICrypto $crypto,
        private LoggerInterface $logger,
        private X509CertificateAssembler $assembler,
    ) {
    }//end __construct()

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
        // The openssl_csr_new() call warns when the key it is handed is
        // unusable for a CSR; the false return below is the branch we act on.
        $csrDn = array_merge(self::DEFAULT_DN, ['commonName' => $commonName]);
        $csr   = $this->withoutDiagnostics(
            call: static fn () => openssl_csr_new(
                distinguished_names: $csrDn,
                private_key: $csrKey,
                options: ['digest_alg' => 'sha256']
            )
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
            $certPem = $this->assembler->issueForPublicKey(
                publicKeyPem: $publicKeyPem,
                subjectDn: [
                    'id-at-countryName'      => self::DEFAULT_DN['countryName'],
                    'id-at-organizationName' => self::DEFAULT_DN['organizationName'],
                    'id-at-commonName'       => $commonName,
                ],
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
     * Re-sign all active EncryptionSuites with the current active intermediate.
     *
     * @return int Number of suites re-signed.
     *
     * @spec openspec/changes/certificate-lifecycle/specs/certificate-lifecycle/spec.md#requirement-guided-renewal
     */
    public function resignAllActiveSuites(): int
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
        $oldModulus = $this->rsaModulusOf(pemCertificate: $oldCert);
        if ($oldModulus === null) {
            return null;
        }

        $newCertPem = $this->assembler->resignPreservingSubject(
            oldCert: $oldCert,
            intermediateCert: $intermediateCert,
            intermediateKeyPem: $intermediateKeyPem
        );

        // The saveX509() helper is declared `: string`, so the only failure
        // shape left to guard is an empty export.
        if ($newCertPem === null || $newCertPem === '') {
            return null;
        }

        // Guard the zero-knowledge invariant: the issued certificate MUST carry
        // the suite's original public key — reject the certificate otherwise so
        // the caller keeps the correct existing one.
        $newModulus = $this->rsaModulusOf(pemCertificate: $newCertPem);
        if ($newModulus === null) {
            return null;
        }

        if (hash_equals($oldModulus, $newModulus) === false) {
            return null;
        }

        return $newCertPem;
    }//end resignPreservingPublicKey()

    /**
     * The RSA modulus of a PEM certificate's public key, or null when the
     * certificate cannot be parsed or carries no RSA key.
     *
     * @param string $pemCertificate The PEM-encoded certificate
     *
     * @return string|null
     */
    private function rsaModulusOf(string $pemCertificate): ?string
    {
        $publicKey = openssl_pkey_get_public(public_key: $pemCertificate);
        if ($publicKey === false) {
            return null;
        }

        $details = openssl_pkey_get_details(key: $publicKey);
        if ($details === false || isset($details['rsa']['n']) === false) {
            return null;
        }

        return (string) $details['rsa']['n'];
    }//end rsaModulusOf()
}//end class
