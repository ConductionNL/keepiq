<?php

/**
 * Doriath X.509 Certificate Assembler
 *
 * Assembles and signs X.509 certificates with phpseclib, which — unlike
 * ext-openssl's CSR path — can bind a PUBLIC-ONLY key deterministically
 * on every build. Two shapes are needed by the private CA: issuing a
 * certificate for a submitted public key, and re-signing an existing
 * certificate onto a fresh issuer while carrying its original
 * SubjectPublicKeyInfo and subject DN verbatim. This class holds no
 * CA state: every call is handed the issuer material it must sign with,
 * so it is purely the "how do we mint this DER" half of issuance.
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
use OCA\Doriath\Support\PublicKeyLoaderAdapter;
use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\RSA\PrivateKey;
use phpseclib3\Crypt\RSA\PublicKey;
use phpseclib3\File\X509;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Builds and signs X.509 certificates with phpseclib.
 */
class X509CertificateAssembler
{
    /**
     * Constructor for X509CertificateAssembler.
     *
     * @param LoggerInterface        $logger    The logger interface
     * @param PublicKeyLoaderAdapter $keyLoader The phpseclib key loader
     *
     * @return void
     *
     * @spec exclude Constructor wiring only; no behaviour.
     */
    public function __construct(
        private LoggerInterface $logger,
        private PublicKeyLoaderAdapter $keyLoader=new PublicKeyLoaderAdapter(),
    ) {
    }//end __construct()

    /**
     * Issue an X.509 certificate carrying an arbitrary submitted public key,
     * signed by the intermediate — via phpseclib, which (unlike ext-openssl's
     * CSR path) can bind a public-only key deterministically on every build.
     *
     * @param string               $publicKeyPem        The subject public key (PEM)
     * @param array<string,string> $subjectDn           Ordered map of phpseclib DN prop => value
     * @param string               $intermediateCertPem The signing intermediate certificate (PEM)
     * @param string               $intermediatePrivPem The intermediate private key (PEM, decrypted)
     *
     * @return string The issued certificate PEM
     *
     * @throws RuntimeException When issuance fails
     *
     * @spec openspec/specs/certificate-lifecycle/spec.md
     */
    public function issueForPublicKey(
        string $publicKeyPem,
        array $subjectDn,
        string $intermediateCertPem,
        string $intermediatePrivPem,
    ): string {
        $subjectPublic = $this->keyLoader->load($publicKeyPem);
        if ($subjectPublic instanceof PublicKey === false) {
            throw new RuntimeException('Submitted public key is not an RSA public key');
        }

        $issuerPrivate = $this->keyLoader->load($intermediatePrivPem);
        if ($issuerPrivate instanceof PrivateKey === false) {
            throw new RuntimeException('Intermediate private key could not be loaded for issuance');
        }

        $issuer = new X509();
        $issuer->loadX509($intermediateCertPem);
        $issuer->setPrivateKey($issuerPrivate->withPadding(RSA::SIGNATURE_PKCS1));

        $subject = new X509();
        // PKCS1 padding on the subject key so the SPKI carries the plain
        // rsaEncryption OID — phpseclib's PSS default would emit an
        // id-RSASSA-PSS SPKI that WebCrypto/openssl consumers reject.
        $subject->setPublicKey($subjectPublic->withPadding(RSA::SIGNATURE_PKCS1));
        foreach ($subjectDn as $dnProp => $dnValue) {
            $subject->setDNProp($dnProp, $dnValue);
        }

        $signer = new X509();
        $signer->setSerialNumber((string) random_int(1, PHP_INT_MAX), 10);
        $signer->setEndDate('+365 days');
        $issued = $signer->sign($issuer, $subject);
        if ($issued === false) {
            throw new RuntimeException('phpseclib certificate issuance failed');
        }

        // The saveX509() helper is declared `: string`, so the only failure
        // shape left to guard is an empty export.
        $pem = $signer->saveX509($issued);
        if ($pem === '') {
            throw new RuntimeException('phpseclib certificate export failed');
        }

        return $pem;
    }//end issueForPublicKey()

    /**
     * Assemble and sign a new certificate that carries the old certificate's
     * SubjectPublicKeyInfo and subject DN verbatim, signed by the intermediate.
     *
     * @param string $oldCert            The current PEM certificate to re-sign
     * @param string $intermediateCert   The signing intermediate certificate (PEM)
     * @param string $intermediateKeyPem The decrypted intermediate private key (PEM)
     *
     * @return string|null The new PEM certificate, or null when signing failed.
     *
     * @spec openspec/specs/certificate-lifecycle/spec.md
     */
    public function resignPreservingSubject(
        string $oldCert,
        string $intermediateCert,
        string $intermediateKeyPem,
    ): ?string {
        try {
            $old = new X509();
            if ($old->loadX509($oldCert) === false) {
                return null;
            }

            $issuer = new X509();
            $issuer->loadX509($intermediateCert);
            $issuer->setPrivateKey($this->keyLoader->loadPrivateKey($intermediateKeyPem));

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

            return $signer->saveX509($signed);
        } catch (Throwable $exception) {
            $this->logger->warning(
                'Doriath: phpseclib re-sign failed: '.$exception->getMessage(),
                ['app' => Application::APP_ID]
            );

            return null;
        }//end try
    }//end resignPreservingSubject()
}//end class
