<?php

/**
 * Unit tests for DevSuiteIntegrityGuard.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Repair
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

namespace OCA\Keepiq\Tests\Unit\Repair;

use Exception;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Repair\DevSuiteIntegrityGuard;
use OCA\Keepiq\Service\DecryptService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for DevSuiteIntegrityGuard: a dev suite is reused only when its
 * certificate belongs to its own private key, and is otherwise discarded with
 * everything encrypted under it.
 *
 * @covers \OCA\Keepiq\Repair\DevSuiteIntegrityGuard
 * @uses   \OCA\Keepiq\Db\EncryptionSuite
 */
class DevSuiteIntegrityGuardTest extends TestCase {

	/**
	 * Two generated key pairs, shared by every test: [privatePem, certificatePem].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private static array $pairs = [];

	private EncryptionSuiteMapper&MockObject $suites;
	private SecretMapper&MockObject $secrets;
	private FolderMapper&MockObject $folders;
	private DecryptService&MockObject $decrypt;
	private LoggerInterface&MockObject $logger;
	private IOutput&MockObject $output;

	public static function setUpBeforeClass(): void {
		for ($i = 0; $i < 2; $i++) {
			$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
			openssl_pkey_export($key, $privatePem);
			$csr = openssl_csr_new(['commonName' => 'dev-' . $i], $key);
			$cert = openssl_csr_sign($csr, null, $key, 1);
			openssl_x509_export($cert, $certPem);
			self::$pairs[$i] = [$privatePem, $certPem];
		}
	}//end setUpBeforeClass()

	protected function setUp(): void {
		$this->suites  = $this->createMock(EncryptionSuiteMapper::class);
		$this->secrets = $this->createMock(SecretMapper::class);
		$this->folders = $this->createMock(FolderMapper::class);
		$this->decrypt = $this->createMock(DecryptService::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
		$this->output  = $this->createMock(IOutput::class);
	}//end setUp()

	/**
	 * The guard under test.
	 *
	 * @return DevSuiteIntegrityGuard
	 */
	private function guard(): DevSuiteIntegrityGuard {
		return new DevSuiteIntegrityGuard($this->suites, $this->secrets, $this->folders, $this->decrypt, $this->logger);
	}//end guard()

	/**
	 * The active suite of user 'dev' holds this certificate and wrapped key.
	 *
	 * @param string|null $certificate The suite's certificate PEM.
	 * @param string|null $wrappedKey  The suite's wrapped private key.
	 *
	 * @return void
	 */
	private function activeSuite(?string $certificate, ?string $wrappedKey = 'wrapped'): void {
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setCertificate($certificate);
		$suite->setPrivateKey($wrappedKey);
		$this->suites->method('findActiveByOwner')->with('user', 'dev')->willReturn($suite);
	}//end activeSuite()

	/**
	 * Expect the suite and everything under it to be discarded.
	 *
	 * @return void
	 */
	private function expectDiscard(): void {
		$this->secrets->expects($this->once())->method('deleteByOwnerUser')->with('dev')->willReturn(5);
		$this->folders->expects($this->once())->method('deleteByOwnerUser')->with('dev');
		$this->suites->expects($this->once())->method('deleteByOwnerUser')->with('dev');
		$this->output->expects($this->once())
			->method('warning')
			->with('Dev EncryptionSuite certificate does not match its private key — rebuilding it');
		$this->output->expects($this->once())
			->method('info')
			->with('Discarded mismatched dev suite suite-1 and 5 dev secrets for rebuild');
		$this->logger->expects($this->once())
			->method('warning')
			->with('Keepiq dev seed: rebuilt mismatched EncryptionSuite for dev (deleted 5 secrets encrypted under the broken certificate)');
	}//end expectDiscard()

	/**
	 * Expect nothing to be deleted.
	 *
	 * @return void
	 */
	private function expectNoDiscard(): void {
		$this->secrets->expects($this->never())->method('deleteByOwnerUser');
		$this->folders->expects($this->never())->method('deleteByOwnerUser');
		$this->suites->expects($this->never())->method('deleteByOwnerUser');
	}//end expectNoDiscard()

	/**
	 * Without a suite there is nothing to reuse and nothing to discard.
	 *
	 * @return void
	 */
	public function testNoSuiteMeansSeedAFreshOne(): void {
		$this->suites->method('findActiveByOwner')->willThrowException(new DoesNotExistException('none'));
		$this->expectNoDiscard();
		$this->output->expects($this->never())->method('info');

		$this->assertFalse($this->guard()->ensureReusableSuite('dev', 'pw', $this->output));
	}//end testNoSuiteMeansSeedAFreshOne()

	/**
	 * A certificate that belongs to the unwrapped private key is reused.
	 *
	 * @return void
	 */
	public function testAMatchingSuiteIsReused(): void {
		$this->activeSuite(self::$pairs[0][1]);
		$this->decrypt->expects($this->once())->method('decryptPrivateKey')->with('wrapped', 'pw')->willReturn(self::$pairs[0][0]);
		$this->expectNoDiscard();
		$this->output->expects($this->once())->method('info')->with('Dev user already has a sound EncryptionSuite, skipping');
		$this->output->expects($this->never())->method('warning');

		$this->assertTrue($this->guard()->ensureReusableSuite('dev', 'pw', $this->output));
	}//end testAMatchingSuiteIsReused()

	/**
	 * A certificate of another key pair is discarded with its secrets and folders.
	 *
	 * @return void
	 */
	public function testACertificateOfAnotherKeyIsDiscarded(): void {
		$this->activeSuite(self::$pairs[1][1]);
		$this->decrypt->method('decryptPrivateKey')->willReturn(self::$pairs[0][0]);
		$this->expectDiscard();

		$this->assertFalse($this->guard()->ensureReusableSuite('dev', 'pw', $this->output));
	}//end testACertificateOfAnotherKeyIsDiscarded()

	/**
	 * A suite without a certificate is discarded without trying to unwrap its key.
	 *
	 * @return void
	 */
	public function testASuiteWithoutACertificateIsDiscarded(): void {
		$this->activeSuite(null);
		$this->decrypt->expects($this->never())->method('decryptPrivateKey');
		$this->expectDiscard();

		$this->assertFalse($this->guard()->ensureReusableSuite('dev', 'pw', $this->output));
	}//end testASuiteWithoutACertificateIsDiscarded()

	/**
	 * A key the master password cannot unwrap is discarded.
	 *
	 * @return void
	 */
	public function testAnUnwrappableKeyIsDiscarded(): void {
		$this->activeSuite(self::$pairs[0][1]);
		$this->decrypt->method('decryptPrivateKey')->willThrowException(new Exception('bad password'));
		$this->expectDiscard();

		$this->assertFalse($this->guard()->ensureReusableSuite('dev', 'pw', $this->output));
	}//end testAnUnwrappableKeyIsDiscarded()

	/**
	 * Key material that is not a PEM key at all is discarded.
	 *
	 * @return void
	 */
	public function testUnreadableKeyMaterialIsDiscarded(): void {
		$this->activeSuite('not a certificate');
		$this->decrypt->method('decryptPrivateKey')->willReturn('not a key');
		$this->expectDiscard();

		$this->assertFalse($this->guard()->ensureReusableSuite('dev', 'pw', $this->output));
	}//end testUnreadableKeyMaterialIsDiscarded()

	/**
	 * A matching pair that is not RSA cannot be compared, so it is discarded.
	 *
	 * @return void
	 */
	public function testANonRsaPairIsDiscarded(): void {
		$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
		openssl_pkey_export($key, $privatePem);
		$cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'dev-ec'], $key), null, $key, 1);
		openssl_x509_export($cert, $certPem);
		$this->activeSuite($certPem);
		$this->decrypt->method('decryptPrivateKey')->willReturn($privatePem);
		$this->expectDiscard();

		$this->assertFalse($this->guard()->ensureReusableSuite('dev', 'pw', $this->output));
	}//end testANonRsaPairIsDiscarded()
}//end class
