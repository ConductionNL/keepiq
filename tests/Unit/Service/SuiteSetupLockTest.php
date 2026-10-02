<?php

/**
 * Two first-time vault setups at the same moment create one suite
 * (keepiq#751): the active-suite count and the insert run under one
 * exclusive lock per owner.
 *
 * The locking provider is an in-memory one with Nextcloud's semantics: an
 * exclusive lock another request holds makes acquireLock() throw
 * LockedException rather than wait.
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

use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Exception\ConflictException;
use OCA\Keepiq\Service\CertificateAuthorityService;
use OCA\Keepiq\Service\EncryptionSuiteProvisioningService;
use OCA\Keepiq\Service\SuiteSetupGuard;
use OCP\IAppConfig;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The per-owner setup lock, through the provisioning service's createSuite().
 */
class SuiteSetupLockTest extends TestCase {

	/**
	 * Suite mapper mock.
	 *
	 * @var EncryptionSuiteMapper&MockObject
	 */
	private EncryptionSuiteMapper $mapper;

	/**
	 * The in-memory locking provider.
	 *
	 * @var ILockingProvider
	 */
	private ILockingProvider $locking;

	/**
	 * The service under test.
	 *
	 * @var EncryptionSuiteProvisioningService
	 */
	private EncryptionSuiteProvisioningService $service;

	/**
	 * Wire the service with a healthy CA and the in-memory lock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(EncryptionSuiteMapper::class);
		$ca = $this->createMock(CertificateAuthorityService::class);
		$ca->method('signPublicKey')->willReturn('-----BEGIN CERTIFICATE-----');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('healthy');

		$this->locking = new class implements ILockingProvider {
			/**
			 * Held exclusive locks by path.
			 *
			 * @var array<string,bool>
			 */
			public array $held = [];

			/**
			 * Whether a path is locked.
			 *
			 * @param string $path The path
			 * @param int $type The lock type
			 *
			 * @return bool
			 */
			public function isLocked(string $path, int $type): bool {
				return isset($this->held[$path]);
			}//end isLocked()

			/**
			 * Take the lock, or throw when another holder has it.
			 *
			 * @param string $path The path
			 * @param int $type The lock type
			 * @param string|null $readablePath A readable path
			 *
			 * @return void
			 */
			public function acquireLock(string $path, int $type, ?string $readablePath = null): void {
				if (isset($this->held[$path]) === true) {
					throw new LockedException($path);
				}

				$this->held[$path] = true;
			}//end acquireLock()

			/**
			 * Release the lock.
			 *
			 * @param string $path The path
			 * @param int $type The lock type
			 *
			 * @return void
			 */
			public function releaseLock(string $path, int $type): void {
				unset($this->held[$path]);
			}//end releaseLock()

			/**
			 * Not used.
			 *
			 * @param string $path The path
			 * @param int $targetType The target type
			 *
			 * @return void
			 */
			public function changeLock(string $path, int $targetType): void {
			}//end changeLock()

			/**
			 * Release everything.
			 *
			 * @return void
			 */
			public function releaseAll(): void {
				$this->held = [];
			}//end releaseAll()
		};

		$this->service = new EncryptionSuiteProvisioningService(
			mapper: $this->mapper,
			caService: $ca,
			appConfig: $appConfig,
			userManager: $this->createMock(IUserManager::class),
			logger: new NullLogger(),
			setupGuard: new SuiteSetupGuard(mapper: $this->mapper, locking: $this->locking),
		);
	}//end setUp()

	/**
	 * While another setup for the same owner holds the lock, this one is
	 * refused with a conflict and inserts nothing.
	 *
	 * @return void
	 */
	public function testASimultaneousSecondSetupIsRefused(): void {
		$this->locking->acquireLock('keepiq/suite/user/alice', ILockingProvider::LOCK_EXCLUSIVE);
		$this->mapper->method('countActiveByOwner')->willReturn(0);
		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(ConflictException::class);
		$this->service->createSuite('user', 'alice', 'pubkey-pem', 'encrypted-pk');
	}//end testASimultaneousSecondSetupIsRefused()

	/**
	 * The count and the insert both run while the lock is held, and the lock
	 * is released afterwards.
	 *
	 * @return void
	 */
	public function testCountAndInsertRunUnderTheLock(): void {
		$locking = $this->locking;
		$this->mapper->method('countActiveByOwner')->willReturnCallback(
			function () use ($locking): int {
				$this->assertTrue($locking->isLocked('keepiq/suite/user/alice', ILockingProvider::LOCK_EXCLUSIVE));
				return 0;
			}
		);
		$this->mapper->expects($this->once())->method('insert')->willReturnCallback(
			function (EncryptionSuite $suite) use ($locking): EncryptionSuite {
				$this->assertTrue($locking->isLocked('keepiq/suite/user/alice', ILockingProvider::LOCK_EXCLUSIVE));
				return $suite;
			}
		);

		$this->service->createSuite('user', 'alice', 'pubkey-pem', 'encrypted-pk');

		$this->assertFalse($locking->isLocked('keepiq/suite/user/alice', ILockingProvider::LOCK_EXCLUSIVE));
	}//end testCountAndInsertRunUnderTheLock()

	/**
	 * A refusal for an existing suite releases the lock too.
	 *
	 * @return void
	 */
	public function testARefusalReleasesTheLock(): void {
		$this->mapper->method('countActiveByOwner')->willReturn(1);

		try {
			$this->service->createSuite('user', 'alice', 'pubkey-pem', 'encrypted-pk');
			$this->fail('expected a conflict');
		} catch (ConflictException) {
			// Expected.
		}

		$this->assertFalse($this->locking->isLocked('keepiq/suite/user/alice', ILockingProvider::LOCK_EXCLUSIVE));
	}//end testARefusalReleasesTheLock()
}//end class
