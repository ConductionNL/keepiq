<?php

/**
 * Which issuing path CertificateIssuanceService::signPublicKey takes, and its refusals.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Keepiq\Db\CACertificate;
use OCA\Keepiq\Db\CACertificateMapper;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Service\CertificateIssuanceService;
use OCA\Keepiq\Service\X509CertificateAssembler;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The openssl path, the assembler fallback and the final refusal of signPublicKey.
 */
class CertificateIssuancePathsTest extends TestCase {

	private string $caCert = '';

	private string $caKey = '';

	private X509CertificateAssembler&MockObject $assembler;

	/**
	 * Set up a self-signed intermediate and the service over it.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if (function_exists('openssl_pkey_new') === false) {
			$this->markTestSkipped('ext-openssl is required for certificate issuance tests');
		}

		[$this->caKey, $key] = $this->keypair();
		$csr = openssl_csr_new(array_merge(CertificateIssuanceService::DEFAULT_DN, ['commonName' => 'Test CA']), $key, ['digest_alg' => 'sha256']);
		openssl_x509_export(openssl_csr_sign($csr, null, $key, 3650, ['digest_alg' => 'sha256'], 1), $this->caCert);
		$this->assembler = $this->createMock(X509CertificateAssembler::class);
	}//end setUp()

	/**
	 * A fresh RSA-2048 private key PEM and its key handle.
	 *
	 * @return array{0:string,1:mixed}
	 */
	private function keypair(): array {
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		openssl_pkey_export($key, $pem);

		return [$pem, $key];
	}//end keypair()

	/**
	 * The public key PEM of a private key PEM.
	 *
	 * @param string $privatePem The private key
	 *
	 * @return string
	 */
	private function publicOf(string $privatePem): string {
		return openssl_pkey_get_details(openssl_pkey_get_private($privatePem))['key'];
	}//end publicOf()

	/**
	 * The service over the test intermediate and the assembler double.
	 *
	 * @return CertificateIssuanceService
	 */
	private function service(): CertificateIssuanceService {
		$intermediate = new CACertificate();
		$intermediate->setCertificate($this->caCert);
		$intermediate->setPrivateKey('sealed');
		$mapper = $this->createMock(CACertificateMapper::class);
		$mapper->method('findActiveIntermediate')->willReturn($intermediate);
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturnCallback(fn (string $value): string => $value === 'sealed' ? $this->caKey : '');

		return new CertificateIssuanceService(
			$mapper,
			$this->createMock(EncryptionSuiteMapper::class),
			$crypto,
			$this->createMock(LoggerInterface::class),
			$this->assembler,
		);
	}//end service()

	/**
	 * With the matching private key openssl issues and the assembler is not asked.
	 *
	 * @return void
	 */
	public function testMatchingPrivateKeyIssuesThroughOpenssl(): void {
		[$priv] = $this->keypair();
		$this->assembler->expects($this->never())->method('issueForPublicKey');

		$cert = $this->service()->signPublicKey($this->publicOf($priv), 'alice', $priv);

		$this->assertSame($this->publicOf($priv), openssl_pkey_get_details(openssl_pkey_get_public($cert))['key']);
		$this->assertSame('alice', openssl_x509_parse($cert)['subject']['CN']);
	}//end testMatchingPrivateKeyIssuesThroughOpenssl()

	/**
	 * When openssl's certificate carries another key the assembler issues, with the subject and the intermediate.
	 *
	 * @return void
	 */
	public function testForeignKeyCertificateFallsBackToTheAssembler(): void {
		[$priv] = $this->keypair();
		[$other] = $this->keypair();
		$public = $this->publicOf($priv);
		$real = new X509CertificateAssembler($this->createMock(LoggerInterface::class));

		$this->assembler->expects($this->once())->method('issueForPublicKey')
			->with(
				$public,
				['id-at-countryName' => 'NL', 'id-at-organizationName' => CertificateIssuanceService::DEFAULT_DN['organizationName'], 'id-at-commonName' => 'bob'],
				$this->caCert,
				$this->caKey
			)
			->willReturnCallback(static fn (...$args): string => $real->issueForPublicKey(...$args));

		$cert = $this->service()->signPublicKey($public, 'bob', $other);

		$this->assertSame($public, openssl_pkey_get_details(openssl_pkey_get_public($cert))['key']);
	}//end testForeignKeyCertificateFallsBackToTheAssembler()

	/**
	 * When no path yields a certificate for the submitted key the issuance is refused.
	 *
	 * @return void
	 */
	public function testNoCertificateForTheSubmittedKeyIsRefused(): void {
		[$priv] = $this->keypair();
		[$other] = $this->keypair();
		$foreign = '';
		$otherKey = openssl_pkey_get_private($other);
		$csr = openssl_csr_new(['commonName' => 'x'], $otherKey);
		openssl_x509_export(openssl_csr_sign($csr, null, $otherKey, 1), $foreign);
		$this->assembler->method('issueForPublicKey')->willReturn($foreign);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Refusing to issue a certificate that does not carry the submitted public key');

		$this->service()->signPublicKey($this->publicOf($priv), 'carol', $other);
	}//end testNoCertificateForTheSubmittedKeyIsRefused()

	/**
	 * The two malformed-key refusals carry their messages.
	 *
	 * @return void
	 */
	public function testMalformedKeysAreRefusedByName(): void {
		[$priv] = $this->keypair();
		$messages = [];
		foreach ([['not a key', null], [$this->publicOf($priv), 'not a key']] as [$public, $private]) {
			try {
				$this->service()->signPublicKey($public, 'dave', $private);
			} catch (InvalidArgumentException $e) {
				$messages[] = $e->getMessage();
			}
		}

		$this->assertSame(['Invalid public key PEM', 'Invalid private key PEM'], $messages);
	}//end testMalformedKeysAreRefusedByName()
}//end class
