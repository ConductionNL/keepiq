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
use OCA\Keepiq\Db\EncryptionSuite;
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

	private EncryptionSuiteMapper&MockObject $suites;

	private LoggerInterface&MockObject $logger;

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
		$this->suites = $this->createMock(EncryptionSuiteMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
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
			$this->suites,
			$crypto,
			$this->logger,
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

	/**
	 * A suite whose certificate the test intermediate issued for a fresh key.
	 *
	 * @param string $id The suite id
	 *
	 * @return EncryptionSuite
	 */
	private function issuedSuite(string $id): EncryptionSuite {
		[$priv, $key] = $this->keypair();
		$csr = openssl_csr_new(['commonName' => $id], $key);
		openssl_x509_export(openssl_csr_sign($csr, $this->caCert, $this->caKey, 30), $cert);

		$suite = new EncryptionSuite();
		$suite->setId($id);
		$suite->setOwnerId('owner-' . $id);
		$suite->setCertificate($cert);

		return $suite;
	}//end issuedSuite()

	/**
	 * Re-signing all suites skips one without a certificate, keeps one it
	 * cannot re-sign and stores the rest, in one page.
	 *
	 * @return void
	 */
	public function testResignAllActiveSuitesCountsOnlyTheStoredOnes(): void {
		$good = $this->issuedSuite(id: 'good');
		$kept = $this->issuedSuite(id: 'kept');
		$bare = new EncryptionSuite();
		$bare->setId('bare');
		$goodCert = $good->getCertificate();

		$this->suites->expects($this->once())->method('findAllActiveWithLimit')->with(100, 0)->willReturn([$bare, $good, $kept]);
		$real = new X509CertificateAssembler($this->createMock(LoggerInterface::class));
		$calls = [];
		$this->assembler->method('resignPreservingSubject')->willReturnCallback(
			static function (string $oldCert, string $intermediateCert, string $intermediateKeyPem, string $fallbackCn) use ($real, $goodCert, &$calls): string {
				$calls[] = $fallbackCn;
				if ($oldCert !== $goodCert) {
					return '';
				}

				return $real->resignPreservingSubject(oldCert: $oldCert, intermediateCert: $intermediateCert, intermediateKeyPem: $intermediateKeyPem, fallbackCn: $fallbackCn);
			}
		);
		$updated = [];
		$this->suites->method('update')->willReturnCallback(static function (EncryptionSuite $suite) use (&$updated): EncryptionSuite {
			$updated[] = $suite->getId();
			return $suite;
		});
		$this->logger->expects($this->once())->method('warning')
			->with('Keepiq: kept existing certificate for suite kept — could not re-sign while preserving its public key');

		$this->assertSame(1, $this->service()->resignAllActiveSuites());
		$this->assertSame(['owner-good', 'owner-kept'], $calls);
		$this->assertSame(['good'], $updated);
		$this->assertNotSame($goodCert, $good->getCertificate());
	}//end testResignAllActiveSuitesCountsOnlyTheStoredOnes()

	/**
	 * A full page is followed by the next one.
	 *
	 * @return void
	 */
	public function testResignAllActiveSuitesPagesUntilAShortPage(): void {
		$offsets = [];
		$this->suites->method('findAllActiveWithLimit')->willReturnCallback(
			static function (int $limit, int $offset) use (&$offsets): array {
				$offsets[] = [$limit, $offset];
				if ($offset === 0) {
					return array_fill(0, 100, new EncryptionSuite());
				}

				return [];
			}
		);

		$this->assertSame(0, $this->service()->resignAllActiveSuites());
		$this->assertSame([[100, 0], [100, 100]], $offsets);
	}//end testResignAllActiveSuitesPagesUntilAShortPage()
}//end class
