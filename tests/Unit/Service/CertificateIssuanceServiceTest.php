<?php

/**
 * Unit tests for CertificateIssuanceService (certificate-lifecycle).
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

use InvalidArgumentException;
use OCA\Doriath\Db\CACertificate;
use OCA\Doriath\Db\CACertificateMapper;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Service\CertificateIssuanceService;
use OCA\Doriath\Service\X509CertificateAssembler;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the certificate issuance service.
 *
 * WHY THIS CLASS EXISTS.
 *
 * CertificateIssuanceService owns DEFAULT_DN — the single source of truth for
 * every DN in the private CA — and the guard against the most damaging failure
 * mode in the app, yet it had NO test class at all. The scenarios that would
 * have covered it were waived in openspec/specs/certificate-lifecycle/spec.md
 * as "Server-side key-generation contract — RSA key bit-length is enforced in
 * the key-generation service; covered by PHPUnit, not browser-observable".
 * There was no such PHPUnit coverage: no test named a generated key's bit
 * length, and no test class named this service.
 *
 * THE FAILURE MODE THESE TESTS PIN.
 *
 * openssl_csr_new() cannot build a CSR from a PUBLIC-ONLY key. On some builds
 * it does not fail — it SILENTLY generates a throwaway keypair and signs that
 * instead. The issued certificate then carries a public key whose private half
 * nobody holds, so every value later encrypted under it is permanently
 * undecryptable: silent vault data loss, with a success-shaped return value.
 * The service comment records this happening live on 2026-07-18, where PHP
 * 8.4/OpenSSL minted an RSA-2048 throwaway for an RSA-4096 browser key on the
 * first-run suite-creation path.
 *
 * That is exactly a defect an API-shape or DOM assertion cannot see — the call
 * returns a valid-looking PEM either way. It is only visible by comparing the
 * issued certificate's public key against the SUBMITTED one, which is what
 * these tests do, and by checking the modulus size, which is what caught it.
 *
 * These tests use real OpenSSL keys and a real self-signed intermediate rather
 * than mocks, because a mock of OpenSSL cannot reproduce the behaviour under
 * test — the bug IS in what OpenSSL does with a public-only key.
 */
class CertificateIssuanceServiceTest extends TestCase
{
    /**
     * The CA certificate mapper mock.
     *
     * @var CACertificateMapper&MockObject
     */
    private $caCertificateMapper;

    /**
     * The encryption suite mapper mock.
     *
     * @var EncryptionSuiteMapper&MockObject
     */
    private $suiteMapper;

    /**
     * The crypto service mock.
     *
     * @var ICrypto&MockObject
     */
    private $crypto;

    /**
     * The logger mock.
     *
     * @var LoggerInterface&MockObject
     */
    private $logger;

    /**
     * The service under test.
     *
     * @var CertificateIssuanceService
     */
    private CertificateIssuanceService $service;

    /**
     * The self-signed intermediate certificate PEM used as the issuer.
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
     * Build a real intermediate CA and wire the service.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (function_exists('openssl_pkey_new') === false) {
            $this->markTestSkipped('ext-openssl is required for certificate issuance tests');
        }

        [$this->intermediateCertPem, $this->intermediatePrivPem] = $this->makeSelfSignedCa();

        $this->caCertificateMapper = $this->createMock(CACertificateMapper::class);
        $this->suiteMapper         = $this->createMock(EncryptionSuiteMapper::class);
        $this->crypto              = $this->createMock(ICrypto::class);
        $this->logger              = $this->createMock(LoggerInterface::class);

        $intermediate = new CACertificate();
        $intermediate->setCertificate($this->intermediateCertPem);
        // Stored encrypted at rest; the crypto mock below is the decrypt step.
        $intermediate->setPrivateKey('encrypted:' . $this->intermediatePrivPem);

        $this->caCertificateMapper->method('findActiveIntermediate')->willReturn($intermediate);
        $this->crypto->method('decrypt')->willReturnCallback(
            static function (string $value): string {
                return preg_replace('/^encrypted:/', '', $value);
            }
        );

        $this->service = new CertificateIssuanceService(
            $this->caCertificateMapper,
            $this->suiteMapper,
            $this->crypto,
            $this->logger,
            new X509CertificateAssembler($this->logger),
        );

    }//end setUp()

    /**
     * Generate a self-signed CA certificate and its private key.
     *
     * @return array{0: string, 1: string}
     */
    private function makeSelfSignedCa(): array
    {
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

        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $privPem);

        return [$certPem, $privPem];

    }//end makeSelfSignedCa()

    /**
     * Generate an RSA keypair of the given size.
     *
     * @param int $bits The modulus size in bits
     *
     * @return array{0: string, 1: string} The public and private key PEMs
     */
    private function makeRsaKeypair(int $bits): array
    {
        $key = openssl_pkey_new(
            [
                'private_key_bits' => $bits,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]
        );

        openssl_pkey_export($key, $privPem);
        $details = openssl_pkey_get_details($key);

        return [$details['key'], $privPem];

    }//end makeRsaKeypair()

    /**
     * Read the modulus of the public key inside a certificate.
     *
     * @param string $certPem The certificate PEM
     *
     * @return string
     */
    private function modulusOf(string $certPem): string
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_public($certPem));

        return bin2hex($details['rsa']['n']);

    }//end modulusOf()

    /**
     * Read the modulus bit length of the public key inside a certificate.
     *
     * @param string $certPem The certificate PEM
     *
     * @return int
     */
    private function bitsOf(string $certPem): int
    {
        return openssl_pkey_get_details(openssl_pkey_get_public($certPem))['bits'];

    }//end bitsOf()

    /**
     * DEFAULT_DN is the single source of truth for every DN in the private CA,
     * so its exact contents are a contract, not an implementation detail:
     * CertificateAuthorityService builds the root and intermediate DNs from it.
     * A silent edit here re-brands every certificate the instance issues.
     *
     * @return void
     */
    public function testDefaultDnIsTheFullExpectedX509DistinguishedName(): void
    {
        $this->assertSame(
            [
                'countryName'            => 'NL',
                'stateOrProvinceName'    => 'Noord-Holland',
                'localityName'           => 'Amsterdam',
                'organizationName'       => 'Conduction',
                'organizationalUnitName' => 'Doriath',
            ],
            CertificateIssuanceService::DEFAULT_DN,
            'DEFAULT_DN is consumed by the whole CA; changing it re-brands every issued certificate'
        );

        $this->assertArrayNotHasKey(
            'commonName',
            CertificateIssuanceService::DEFAULT_DN,
            'commonName is per-certificate and must be supplied by the caller, never defaulted'
        );

    }//end testDefaultDnIsTheFullExpectedX509DistinguishedName()

    /**
     * The DN of an issued certificate must be built from DEFAULT_DN with the
     * caller's common name merged in.
     *
     * @return void
     */
    public function testIssuedCertificateCarriesTheDefaultDnAndTheCallersCommonName(): void
    {
        [$pub, $priv] = $this->makeRsaKeypair(2048);

        $certPem = $this->service->signPublicKey($pub, 'alice@example.com', $priv);
        $subject = openssl_x509_parse($certPem)['subject'];

        $this->assertSame('NL', $subject['C']);
        $this->assertSame('Conduction', $subject['O']);
        $this->assertSame('alice@example.com', $subject['CN']);

    }//end testIssuedCertificateCarriesTheDefaultDnAndTheCallersCommonName()

    /**
     * THE DATA-LOSS GUARD, server-side key generation path.
     *
     * When the caller holds the keypair, the issued certificate must carry the
     * caller's real public key. If OpenSSL substituted a throwaway keypair,
     * the modulus would differ and every value encrypted under this
     * certificate would be undecryptable.
     *
     * @return void
     */
    public function testIssuedCertificateCarriesTheSubmittedPublicKeyWithPrivateKey(): void
    {
        [$pub, $priv] = $this->makeRsaKeypair(2048);

        $certPem = $this->service->signPublicKey($pub, 'Doriath User', $priv);

        $this->assertSame(
            bin2hex(openssl_pkey_get_details(openssl_pkey_get_public($pub))['rsa']['n']),
            $this->modulusOf($certPem),
            'the issued certificate does not carry the submitted public key — '
            . 'anything encrypted under it would be undecryptable'
        );

    }//end testIssuedCertificateCarriesTheSubmittedPublicKeyWithPrivateKey()

    /**
     * THE DATA-LOSS GUARD, public-key-only path — the browser case.
     *
     * This is the exact 2026-07-18 incident: a browser submits an RSA-4096
     * PUBLIC key and never releases the private half (ADR-003 zero-knowledge),
     * so openssl_csr_new() has only a public key to work with and may silently
     * mint a throwaway RSA-2048 pair. The service must detect that and reroute
     * to the phpseclib assembler.
     *
     * Both assertions matter and neither implies the other: the modulus proves
     * the RIGHT key was used, the bit length proves the 4096-bit floor survived
     * issuance rather than being downgraded to a 2048-bit throwaway.
     *
     * @return void
     */
    public function testIssuedCertificatePreservesA4096BitBrowserKeyWithoutThePrivateHalf(): void
    {
        [$pub] = $this->makeRsaKeypair(4096);

        // No private key — exactly what the browser-generated suite path does.
        $certPem = $this->service->signPublicKey($pub, 'browser@example.com');

        $this->assertSame(
            bin2hex(openssl_pkey_get_details(openssl_pkey_get_public($pub))['rsa']['n']),
            $this->modulusOf($certPem),
            'OpenSSL substituted a throwaway keypair for the submitted public key — '
            . 'this is the silent vault-data-loss defect of 2026-07-18'
        );

        $this->assertSame(
            4096,
            $this->bitsOf($certPem),
            'the issued certificate downgraded the RSA-4096 browser key'
        );

    }//end testIssuedCertificatePreservesA4096BitBrowserKeyWithoutThePrivateHalf()

    /**
     * A malformed public key must be refused up front, not turned into a
     * certificate for a key nobody holds.
     *
     * @return void
     */
    public function testInvalidPublicKeyPemIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->signPublicKey('-----BEGIN PUBLIC KEY-----\nnot-a-key\n-----END PUBLIC KEY-----');

    }//end testInvalidPublicKeyPemIsRejected()

    /**
     * A malformed private key must be refused rather than silently ignored —
     * falling back to the public-only path here would re-open the throwaway
     * keypair footgun.
     *
     * @return void
     */
    public function testInvalidPrivateKeyPemIsRejected(): void
    {
        [$pub] = $this->makeRsaKeypair(2048);

        $this->expectException(InvalidArgumentException::class);

        $this->service->signPublicKey($pub, 'Doriath User', '-----BEGIN PRIVATE KEY-----\nnope\n-----END PRIVATE KEY-----');

    }//end testInvalidPrivateKeyPemIsRejected()

    /**
     * The issuer chain must be the active intermediate — a certificate signed
     * by anything else would not validate against the instance CA.
     *
     * @return void
     */
    public function testIssuedCertificateIsSignedByTheActiveIntermediate(): void
    {
        [$pub, $priv] = $this->makeRsaKeypair(2048);

        $certPem = $this->service->signPublicKey($pub, 'chain@example.com', $priv);

        $this->assertSame(
            openssl_x509_parse($this->intermediateCertPem)['subject'],
            openssl_x509_parse($certPem)['issuer'],
            'the issued certificate is not chained to the active intermediate'
        );

    }//end testIssuedCertificateIsSignedByTheActiveIntermediate()
}//end class
