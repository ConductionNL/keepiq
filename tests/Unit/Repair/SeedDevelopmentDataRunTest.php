<?php

/**
 * Unit tests for SeedDevelopmentData::run(): when the dev seed runs at all,
 * when it reuses the existing suite, and when it mints a new one.
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

use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Repair\DevSuiteIntegrityGuard;
use OCA\Keepiq\Repair\SeedDevelopmentData;
use OCA\Keepiq\Service\CertificateAuthorityService;
use OCA\Keepiq\Service\DecryptService;
use OCA\Keepiq\Service\EncryptService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The run() gate of the development seed, over the real integrity guard.
 *
 * @covers \OCA\Keepiq\Repair\SeedDevelopmentData
 * @uses   \OCA\Keepiq\Repair\DevSuiteIntegrityGuard
 * @uses   \OCA\Keepiq\Repair\SuiteMint
 * @uses   \OCA\Keepiq\Db\EncryptionSuite
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
final class SeedDevelopmentDataRunTest extends TestCase {

	private EncryptionSuiteMapper&MockObject $suites;
	private SecretMapper&MockObject $secrets;
	private FolderMapper&MockObject $folders;
	private DecryptService&MockObject $decrypt;
	private CertificateAuthorityService&MockObject $ca;
	private EncryptService&MockObject $encrypt;
	private IConfig&MockObject $config;
	private IOutput&MockObject $output;

	/**
	 * Every info and warning the output received, in order.
	 *
	 * @var array<int,array{string,string}>
	 */
	private array $said = [];

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->suites  = $this->createMock(EncryptionSuiteMapper::class);
		$this->secrets = $this->createMock(SecretMapper::class);
		$this->folders = $this->createMock(FolderMapper::class);
		$this->decrypt = $this->createMock(DecryptService::class);
		$this->ca      = $this->createMock(CertificateAuthorityService::class);
		$this->encrypt = $this->createMock(EncryptService::class);
		$this->config  = $this->createMock(IConfig::class);
		$this->output  = $this->createMock(IOutput::class);
		$this->said    = [];
		$this->output->method('info')->willReturnCallback(function (string $m): void {
			$this->said[] = ['info', $m];
		});
		$this->output->method('warning')->willReturnCallback(function (string $m): void {
			$this->said[] = ['warning', $m];
		});
	}//end setUp()

	/**
	 * The step under test.
	 *
	 * @return SeedDevelopmentData
	 */
	private function seed(): SeedDevelopmentData {
		$logger = $this->createMock(LoggerInterface::class);
		$guard  = new DevSuiteIntegrityGuard($this->suites, $this->secrets, $this->folders, $this->decrypt, $logger);

		return new SeedDevelopmentData($this->suites, $this->ca, $this->encrypt, $guard, $this->config, $logger);
	}//end seed()

	/**
	 * Whether the instance runs in debug mode.
	 *
	 * @param bool $debug The system value.
	 *
	 * @return void
	 */
	private function debug(bool $debug): void {
		$this->config->expects($this->once())->method('getSystemValueBool')->with('debug', false)->willReturn($debug);
	}//end debug()

	/**
	 * The step names itself as a debug-only seed.
	 *
	 * @return void
	 */
	public function testItNamesItselfAsDebugOnly(): void {
		self::assertSame('Seed Keepiq development data (debug only)', $this->seed()->getName());
	}//end testItNamesItselfAsDebugOnly()

	/**
	 * Outside debug mode nothing is looked up, said or stored.
	 *
	 * @return void
	 */
	public function testOutsideDebugModeNothingHappens(): void {
		$this->debug(false);
		$this->suites->expects($this->never())->method('findActiveByOwner');
		$this->suites->expects($this->never())->method('insert');
		$this->ca->expects($this->never())->method('signPublicKey');

		$this->seed()->run($this->output);

		self::assertSame([], $this->said);
	}//end testOutsideDebugModeNothingHappens()

	/**
	 * A sound suite of the dev user is reused and nothing is minted.
	 *
	 * @return void
	 */
	public function testASoundSuiteIsReused(): void {
		$this->debug(true);
		$key = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		openssl_pkey_export($key, $privatePem);
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setCertificate(openssl_pkey_get_details($key)['key']);
		$suite->setPrivateKey('wrapped');
		$this->suites->expects($this->once())->method('findActiveByOwner')->with('user', 'admin')->willReturn($suite);
		$this->decrypt->expects($this->once())->method('decryptPrivateKey')->with('wrapped', 'Oj')->willReturn($privatePem);
		$this->suites->expects($this->never())->method('insert');
		$this->ca->expects($this->never())->method('signPublicKey');

		$this->seed()->run($this->output);

		self::assertSame(
			[
				['info', 'Seeding Keepiq development data...'],
				['info', 'Dev user already has a sound EncryptionSuite, skipping'],
			],
			$this->said
		);
	}//end testASoundSuiteIsReused()

	/**
	 * Without a suite the dev user gets a freshly minted one under the dev password.
	 *
	 * @return void
	 */
	public function testWithoutASuiteOneIsMintedForTheDevUser(): void {
		$this->debug(true);
		$this->suites->method('findActiveByOwner')->willThrowException(new DoesNotExistException('none'));
		$this->ca->expects($this->once())
			->method('signPublicKey')
			->with($this->stringStartsWith('-----BEGIN PUBLIC KEY-----'), 'admin', $this->stringStartsWith('-----BEGIN PRIVATE KEY-----'))
			->willReturn('CERT');
		$this->encrypt->expects($this->once())
			->method('encryptPrivateKey')
			->with($this->stringStartsWith('-----BEGIN PRIVATE KEY-----'), 'Oj')
			->willReturn('ENC');
		$stored = null;
		$this->suites->expects($this->once())->method('insert')->willReturnCallback(
			static function (EncryptionSuite $suite) use (&$stored): EncryptionSuite {
				$stored = $suite;
				return $suite;
			}
		);

		$this->seed()->run($this->output);

		self::assertInstanceOf(EncryptionSuite::class, $stored);
		self::assertSame('admin', $stored->getOwnerId());
		self::assertSame('user', $stored->getOwnerType());
		self::assertSame('CERT', $stored->getCertificate());
		self::assertSame('ENC', $stored->getPrivateKey());
		self::assertSame('active', $stored->getStatus());
		self::assertSame(
			[
				['info', 'Seeding Keepiq development data...'],
				['info', 'Dev EncryptionSuite created for user: admin'],
			],
			$this->said
		);
	}//end testWithoutASuiteOneIsMintedForTheDevUser()

	/**
	 * A mismatched suite is discarded and then replaced, not reused.
	 *
	 * @return void
	 */
	public function testAMismatchedSuiteIsDiscardedAndReplaced(): void {
		$this->debug(true);
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setCertificate(null);
		$suite->setPrivateKey('wrapped');
		$this->suites->method('findActiveByOwner')->willReturn($suite);
		$this->secrets->expects($this->once())->method('deleteByOwnerUser')->with('admin')->willReturn(2);
		$this->folders->expects($this->once())->method('deleteByOwnerUser')->with('admin');
		$this->suites->expects($this->once())->method('deleteByOwnerUser')->with('admin');
		$this->ca->method('signPublicKey')->willReturn('CERT');
		$this->encrypt->method('encryptPrivateKey')->willReturn('ENC');
		$this->suites->expects($this->once())->method('insert');

		$this->seed()->run($this->output);

		self::assertSame(['info', 'Dev EncryptionSuite created for user: admin'], end($this->said));
		self::assertContains(['info', 'Discarded mismatched dev suite suite-1 and 2 dev secrets for rebuild'], $this->said);
	}//end testAMismatchedSuiteIsDiscardedAndReplaced()
}//end class
