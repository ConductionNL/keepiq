<?php

/**
 * Keepiq - SuiteSetupGuard tests
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Exception\ConflictException;
use OCA\Keepiq\Service\SuiteSetupGuard;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * One active suite per owner, created under a lock.
 */
#[CoversClass(SuiteSetupGuard::class)]
#[UsesClass(ConflictException::class)]
#[UsesClass(EncryptionSuite::class)]
class SuiteSetupGuardTest extends TestCase {
	/**
	 * Without an active suite the persist callback runs and its suite is returned; the lock is taken and released.
	 *
	 * @return void
	 */
	public function testTheFirstSuiteIsPersistedUnderTheLock(): void {
		$mapper = $this->createMock(EncryptionSuiteMapper::class);
		$mapper->expects($this->once())->method('countActiveByOwner')->with('user', 'alice')->willReturn(0);
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())->method('acquireLock')->with('keepiq/suite/user/alice', ILockingProvider::LOCK_EXCLUSIVE);
		$locking->expects($this->once())->method('releaseLock')->with('keepiq/suite/user/alice', ILockingProvider::LOCK_EXCLUSIVE);

		$suite = new EncryptionSuite();
		$result = (new SuiteSetupGuard(mapper: $mapper, locking: $locking))
			->createExclusive(ownerType: 'user', ownerId: 'alice', persist: static fn (): EncryptionSuite => $suite);

		$this->assertSame($suite, $result);
	}//end testTheFirstSuiteIsPersistedUnderTheLock()

	/**
	 * A second active suite is refused, nothing is persisted and the lock is still released.
	 *
	 * @return void
	 */
	public function testASecondActiveSuiteIsRefused(): void {
		$mapper = $this->createMock(EncryptionSuiteMapper::class);
		$mapper->method('countActiveByOwner')->willReturn(1);
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())->method('releaseLock')->with('keepiq/suite/team/t1', ILockingProvider::LOCK_EXCLUSIVE);
		$persisted = false;

		try {
			(new SuiteSetupGuard(mapper: $mapper, locking: $locking))->createExclusive(
				ownerType: 'team',
				ownerId: 't1',
				persist: static function () use (&$persisted): EncryptionSuite {
					$persisted = true;
					return new EncryptionSuite();
				}
			);
			$this->fail('A second suite was accepted');
		} catch (ConflictException $exception) {
			$this->assertStringContainsString('An active EncryptionSuite already exists', $exception->getMessage());
		}

		$this->assertFalse($persisted);
	}//end testASecondActiveSuiteIsRefused()

	/**
	 * A setup already holding the lock is a conflict, and the owner's suites are not read.
	 *
	 * @return void
	 */
	public function testAConcurrentSetupIsAConflict(): void {
		$mapper = $this->createMock(EncryptionSuiteMapper::class);
		$mapper->expects($this->never())->method('countActiveByOwner');
		$locking = $this->createMock(ILockingProvider::class);
		$locking->method('acquireLock')->willThrowException(new LockedException('keepiq/suite/user/alice'));
		$locking->expects($this->never())->method('releaseLock');

		$this->expectException(ConflictException::class);
		$this->expectExceptionMessage('A vault setup for this owner is already in progress.');

		(new SuiteSetupGuard(mapper: $mapper, locking: $locking))
			->createExclusive(ownerType: 'user', ownerId: 'alice', persist: static fn (): EncryptionSuite => new EncryptionSuite());
	}//end testAConcurrentSetupIsAConflict()

	/**
	 * Without a locking provider the count still guards the setup.
	 *
	 * @return void
	 */
	public function testWithoutLockingTheCountStillGuards(): void {
		$mapper = $this->createMock(EncryptionSuiteMapper::class);
		$mapper->method('countActiveByOwner')->willReturn(2);

		$this->expectException(ConflictException::class);

		(new SuiteSetupGuard(mapper: $mapper))
			->createExclusive(ownerType: 'user', ownerId: 'bob', persist: static fn (): EncryptionSuite => new EncryptionSuite());
	}//end testWithoutLockingTheCountStillGuards()
}//end class
