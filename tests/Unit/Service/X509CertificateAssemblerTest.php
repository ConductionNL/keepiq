<?php

/**
 * Unit tests for X509CertificateAssembler (certificate-lifecycle).
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Doriath\Tests\Unit\Service;

use OCA\Doriath\Service\CertificateIssuanceService;
use OCA\Doriath\Service\X509CertificateAssembler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the phpseclib certificate assembler.
 *
 * WHY THIS CLASS EXISTS — TWO REASONS, BOTH MEASURED.
 *
 * 1. CORRECTNESS. This assembler is the fallback that prevents the silent
 *    vault-data-loss defect: ext-openssl's CSR path cannot bind a public-only
 *    key and on some builds silently substitutes a throwaway keypair, so
 *    CertificateIssuanceService::signPublicKey reroutes here. The assembler
 *    had no test class at all, meaning the guard that catches the worst
 *    failure mode in the app was itself unverified.
 *
 * 2. DETERMINISM. Before this file, every line in the assembler was covered
 *    only INCIDENTALLY — via whichever branch signPublicKey happened to take,
 *    which depends on how the local OpenSSL build handles a public-only key.
 *    The class docblock says so outright: "on some OpenSSL builds a public-only
 *    key works here; on others openssl_csr_new() SILENTLY generates a throwaway
 *    keypair." That makes the assembler's coverage a property of the runtime
 *    rather than of the test suite.
 *
 *    Measured consequence: PR #207 changed no PHP whatsoever, yet the coverage
 *    ratchet failed with an identical denominator and six fewer covered
 *    statements than its merge base (7758/12838 -> 7752/12838) on PHP 8.3
 *    while PHP 8.4 passed. A ratchet that can fail on a diff containing no PHP
 *    is not measuring the diff.
 *
 *    Exercising the assembler DIRECTLY pins those lines on every run, on every
 *    build, whichever branch signPublicKey takes. This is added coverage, not
 *    a relaxed threshold.
 */
class X509CertificateAssemblerTest extends TestCase
{
    /**
     * The assembler under test.
     *
     * @var X509CertificateAssembler
     */
    private X509CertificateAssembler $assembler;

    /**
     * The signing intermediate certificate PEM.
     *
     * @var string
     */
    private string $intermediateCertPem = '';

    /**
     * The intermediate private key PEM.
     *
     * @var string
     */
    private string $intermediatePrivPem = '';

    /**
     * Build a real intermediate CA and the assembler.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (function_exists('openssl_pkey_new') === false) {
            $this->markTestSkipped('ext-openssl is required to build the test CA');
        }

        $key = openssl_pkey_new(
            [
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]
        );

        $dn = array_merge(
            CertificateIssuanceService::DEFAULT_DN,
            ['commonName' => 'Doriath Test Intermediate']
        );

        $csr  = openssl_csr_new($dn, $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 3650, ['digest_alg' => 'sha256'], 1);

        openssl_x509_export($cert, $this->intermediateCertPem);
        openssl_pkey_export($key, $this->intermediatePrivPem);

        $this->assembler = new X509CertificateAssembler($this->createMock(LoggerInterface::class));

    }//end setUp()

    /**
     * Generate an RSA public key PEM of the given size.
     *
     * @param int $bits The modulus size in bits
     *
     * @return string
     */
    private function publicKeyPem(int $bits=4096): string
    {
        $key = openssl_pkey_new(
            [
                'private_key_bits' => $bits,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]
        );

        return openssl_pkey_get_details($key)['key'];

    }//end publicKeyPem()

    /**
     * The whole point of the assembler: bind the SUBMITTED public key
     * deterministically, which is what ext-openssl's CSR path cannot do.
     *
     * @return void
     */
    public function testIssuedCertificateCarriesTheSubmittedPublicKey(): void
    {
        $pub = $this->publicKeyPem(4096);

        $certPem = $this->assembler->issueForPublicKey(
            $pub,
            [
                'id-at-countryName'      => 'NL',
                'id-at-organizationName' => 'Conduction',
                'id-at-commonName'       => 'assembler@example.com',
            ],
            $this->intermediateCertPem,
            $this->intermediatePrivPem,
        );

        $expected = openssl_pkey_get_details(openssl_pkey_get_public($pub))['rsa']['n'];
        $actual   = openssl_pkey_get_details(openssl_pkey_get_public($certPem))['rsa']['n'];

        $this->assertSame(
            bin2hex($expected),
            bin2hex($actual),
            'the assembler did not bind the submitted public key — this is the fallback that '
            . 'exists precisely to stop that happening'
        );

        $this->assertSame(4096, openssl_pkey_get_details(openssl_pkey_get_public($certPem))['bits']);

    }//end testIssuedCertificateCarriesTheSubmittedPublicKey()

    /**
     * The SPKI must carry the plain rsaEncryption OID. phpseclib's PSS default
     * would emit an id-RSASSA-PSS SPKI that WebCrypto and openssl consumers
     * reject — a certificate that parses here but is unusable in the browser.
     *
     * openssl_pkey_get_public() succeeding above already proves openssl accepts
     * it; this asserts the OID directly so a padding regression names itself.
     *
     * @return void
     */
    public function testIssuedCertificateUsesThePlainRsaEncryptionOid(): void
    {
        $certPem = $this->assembler->issueForPublicKey(
            $this->publicKeyPem(2048),
            ['id-at-commonName' => 'oid@example.com'],
            $this->intermediateCertPem,
            $this->intermediatePrivPem,
        );

        $der = base64_decode(
            preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $certPem)
        );

        // OID 1.2.840.113549.1.1.1 (rsaEncryption) DER-encoded.
        $rsaEncryption = hex2bin('2a864886f70d010101');
        // OID 1.2.840.113549.1.1.10 (id-RSASSA-PSS) DER-encoded.
        $rsassaPss = hex2bin('2a864886f70d01010a');

        $this->assertStringContainsString($rsaEncryption, $der, 'SPKI is not rsaEncryption');
        $this->assertStringNotContainsString(
            $rsassaPss,
            $der,
            'SPKI carries id-RSASSA-PSS — WebCrypto and openssl consumers reject this'
        );

    }//end testIssuedCertificateUsesThePlainRsaEncryptionOid()

    /**
     * The subject DN must be built from the supplied ordered map, and the
     * certificate chained to the intermediate.
     *
     * @return void
     */
    public function testIssuedCertificateCarriesTheSubjectDnAndIssuerChain(): void
    {
        $certPem = $this->assembler->issueForPublicKey(
            $this->publicKeyPem(2048),
            [
                'id-at-countryName'      => 'NL',
                'id-at-organizationName' => 'Conduction',
                'id-at-commonName'       => 'dn@example.com',
            ],
            $this->intermediateCertPem,
            $this->intermediatePrivPem,
        );

        $parsed = openssl_x509_parse($certPem);

        $this->assertSame('dn@example.com', $parsed['subject']['CN']);
        $this->assertSame('NL', $parsed['subject']['C']);
        $this->assertSame('Conduction', $parsed['subject']['O']);
        $this->assertSame(
            openssl_x509_parse($this->intermediateCertPem)['subject'],
            $parsed['issuer'],
            'the issued certificate is not chained to the intermediate'
        );

    }//end testIssuedCertificateCarriesTheSubjectDnAndIssuerChain()

    /**
     * A private key handed in where a public key belongs must be refused
     * rather than issued against — the guard distinguishes the two key shapes.
     *
     * @return void
     */
    public function testNonPublicSubjectKeyIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Submitted public key is not an RSA public key');

        $this->assembler->issueForPublicKey(
            $this->intermediatePrivPem,
            ['id-at-commonName' => 'wrong@example.com'],
            $this->intermediateCertPem,
            $this->intermediatePrivPem,
        );

    }//end testNonPublicSubjectKeyIsRejected()

    /**
     * An unusable intermediate private key must fail loudly. Issuing with a
     * key that is not the CA's would produce a certificate that no client can
     * chain — a failure best surfaced at issuance.
     *
     * @return void
     */
    public function testUnloadableIntermediateKeyIsRejected(): void
    {
        $this->expectException(RuntimeException::class);

        $this->assembler->issueForPublicKey(
            $this->publicKeyPem(2048),
            ['id-at-commonName' => 'badca@example.com'],
            $this->intermediateCertPem,
            'not-a-key',
        );

    }//end testUnloadableIntermediateKeyIsRejected()

    /**
     * Re-signing must preserve the old certificate's public key and subject
     * verbatim: a renewal that changed either would strand every value already
     * encrypted under the original key.
     *
     * @return void
     */
    public function testResignPreservesTheSubjectPublicKeyAndDn(): void
    {
        $pub = $this->publicKeyPem(2048);

        $original = $this->assembler->issueForPublicKey(
            $pub,
            [
                'id-at-countryName' => 'NL',
                'id-at-commonName'  => 'renew@example.com',
            ],
            $this->intermediateCertPem,
            $this->intermediatePrivPem,
        );

        $renewed = $this->assembler->resignPreservingSubject(
            $original,
            $this->intermediateCertPem,
            $this->intermediatePrivPem,
        );

        $this->assertNotNull($renewed, 're-signing returned null');

        $this->assertSame(
            bin2hex(openssl_pkey_get_details(openssl_pkey_get_public($original))['rsa']['n']),
            bin2hex(openssl_pkey_get_details(openssl_pkey_get_public($renewed))['rsa']['n']),
            're-signing changed the public key — every secret encrypted under the original '
            . 'would become undecryptable'
        );

        $this->assertSame(
            openssl_x509_parse($original)['subject'],
            openssl_x509_parse($renewed)['subject'],
            're-signing changed the subject DN'
        );

    }//end testResignPreservesTheSubjectPublicKeyAndDn()

    /**
     * Re-signing garbage must degrade to null rather than throw, since the
     * caller treats null as "this certificate could not be renewed".
     *
     * @return void
     */
    public function testResignReturnsNullForAnUnparseableCertificate(): void
    {
        $this->assertNull(
            $this->assembler->resignPreservingSubject(
                '-----BEGIN CERTIFICATE-----\nnope\n-----END CERTIFICATE-----',
                $this->intermediateCertPem,
                $this->intermediatePrivPem,
            )
        );

    }//end testResignReturnsNullForAnUnparseableCertificate()
}//end class
