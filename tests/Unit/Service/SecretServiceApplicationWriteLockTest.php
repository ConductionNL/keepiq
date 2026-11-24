<?php

/**
 * Unit tests for the version lock of SecretService::updateByApplication().
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

use DateTime;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretVersion;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Exception\PreconditionFailedException;
use OCA\Keepiq\Exception\StaleWriteException;
use OCA\Keepiq\Service\AuditService;
use OCA\Keepiq\Service\BrokerMintRecorder;
use OCA\Keepiq\Service\LinkShareService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\SecretService;
use OCA\Keepiq\Service\SecretTypeService;
use OCA\Keepiq\Service\SecretVersionService;
use OCA\Keepiq\Service\ShareSaveRequiredGuard;
use OCA\Keepiq\Service\ViewOnlyCopyGuard;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A machine write-back is locked on the version it read: a write that lands
 * in between is kept as a version and written over once, a second one
 * refuses the update, and a precondition is checked on the row each attempt
 * writes.
 *
 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
 * @spec openspec/specs/secret-store-api/spec.md#requirement-conditional-machine-write-back
 */
class SecretServiceApplicationWriteLockTest extends TestCase {

	private SecretService $service;

	/**
	 * @var SecretMapper&MockObject
	 */
	private SecretMapper $mapper;

	/**
	 * @var AuditService&MockObject
	 */
	private AuditService $audit;

	/**
	 * @var BrokerMintRecorder&MockObject
	 */
	private BrokerMintRecorder $mints;

	/**
	 * Every step of the writes in order: begin, advance, snapshot, update, commit, rollback.
	 *
	 * @var list<string>
	 */
	private array $steps = [];

	/**
	 * The pre-update rows the version service kept.
	 *
	 * @var list<Secret>
	 */
	private array $snapshots = [];

	/**
	 * The rows the mapper wrote.
	 *
	 * @var list<Secret>
	 */
	private array $written = [];

	/**
	 * The base and new version of every compare-and-set.
	 *
	 * @var list<array{0:DateTime,1:DateTime}>
	 */
	private array $locks = [];

	/**
	 * Wire the service with a mapper, version service and transaction that record each step.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(SecretMapper::class);
		$this->mapper->method('update')->willReturnCallback(
			function (Secret $secret): Secret {
				$this->steps[] = 'update';
				$this->written[] = clone $secret;
				return $secret;
			}
		);

		$versions = $this->createPartialMock(SecretVersionService::class, ['snapshot']);
		$versions->method('snapshot')->willReturnCallback(
			function (Secret $preUpdate): SecretVersion {
				$this->steps[] = 'snapshot';
				$this->snapshots[] = $preUpdate;
				return new SecretVersion();
			}
		);

		$db = $this->createMock(IDBConnection::class);
		foreach (['beginTransaction' => 'begin', 'commit' => 'commit', 'rollBack' => 'rollback'] as $method => $step) {
			$db->method($method)->willReturnCallback(
				function () use ($step): void {
					$this->steps[] = $step;
				}
			);
		}

		$this->audit = $this->createMock(AuditService::class);
		$this->mints = $this->createMock(BrokerMintRecorder::class);

		$this->service = new SecretService(
			mapper: $this->mapper,
			typeService: $this->createMock(SecretTypeService::class),
			suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
			migrationService: $this->createMock(MigrationService::class),
			linkShareService: $this->createMock(LinkShareService::class),
			logger: $this->createMock(LoggerInterface::class),
			db: $db,
			viewOnlyCopies: $this->createStub(ViewOnlyCopyGuard::class),
			shareSaveRequired: $this->createStub(ShareSaveRequiredGuard::class),
			auditService: $this->audit,
			versionService: $versions,
			brokerMints: $this->mints,
		);
	}//end setUp()

	/**
	 * An application-owned row at a version.
	 *
	 * @param string $key     The key ciphertext
	 * @param string $version The stored version
	 * @param string $ownerId The owning application
	 *
	 * @return Secret
	 */
	private function row(string $key, string $version, string $ownerId = 'app-1'): Secret {
		$secret = new Secret();
		$secret->setId('s1');
		$secret->setName('token');
		$secret->setKey($key);
		$secret->setOwnerType('application');
		$secret->setOwnerId($ownerId);
		$secret->setEncryptionSuiteId('suite-1');
		$secret->setUpdatedAt(new DateTime($version));
		$secret->setKeyUpdatedAt(new DateTime($version));
		return $secret;
	}//end row()

	/**
	 * The mapper reads these rows in turn.
	 *
	 * @param Secret|\Throwable ...$reads Each read's row, or what it throws
	 *
	 * @return void
	 */
	private function reads(Secret|\Throwable ...$reads): void {
		$this->mapper->expects($this->exactly(count($reads)))->method('findById')->willReturnCallback(
			static function () use (&$reads): Secret {
				$next = array_shift($reads);
				if ($next instanceof \Throwable) {
					throw $next;
				}

				return $next;
			}
		);
	}//end reads()

	/**
	 * The compare-and-sets answer these row counts in turn.
	 *
	 * @param int ...$outcomes 1 when the row was still at the base, 0 when it moved on or is gone
	 *
	 * @return void
	 */
	private function locksAnswer(int ...$outcomes): void {
		$this->mapper->expects($this->exactly(count($outcomes)))->method('advanceVersion')->willReturnCallback(
			function (string $id, DateTime $base, DateTime $savedAt) use (&$outcomes): int {
				$this->steps[] = 'advance';
				$this->locks[] = [$base, $savedAt];
				return (int)array_shift($outcomes);
			}
		);
	}//end locksAnswer()

	/**
	 * The update is audited and its broker mint recorded this many times.
	 *
	 * @param int $times 1 for a write that landed, 0 for a refused one
	 *
	 * @return void
	 */
	private function expectAuditedAndRecorded(int $times): void {
		$this->audit->expects($this->exactly($times))->method('record');
		$this->mints->expects($this->exactly($times))->method('record');
	}//end expectAuditedAndRecorded()

	/**
	 * The version is kept only after the compare-and-set is won, and the row
	 * is written in the same transaction at a version after the one read, in
	 * whole seconds; the key's ciphertext age is that same version. The
	 * update is audited and its mint recorded once.
	 *
	 * @return void
	 */
	public function testTheVersionIsKeptOnlyAfterTheLockIsWon(): void {
		$this->reads($this->row(key: 'OLD', version: '2026-01-01T00:00:00+00:00'));
		$this->locksAnswer(1);
		$this->expectAuditedAndRecorded(1);

		$result = $this->service->updateByApplication(id: 's1', data: ['key' => 'NEW'], applicationId: 'app-1');

		$this->assertSame(['begin', 'advance', 'snapshot', 'update', 'commit'], $this->steps);
		$this->assertSame('OLD', $this->snapshots[0]->getKey());
		[$base, $savedAt] = $this->locks[0];
		$this->assertEquals(new DateTime('2026-01-01T00:00:00+00:00'), $base);
		$this->assertGreaterThan($base->getTimestamp(), $savedAt->getTimestamp());
		$this->assertSame('000000', $savedAt->format('u'));
		$this->assertEquals($savedAt, $result->getUpdatedAt());
		$this->assertEquals($savedAt, $result->getKeyUpdatedAt());
	}//end testTheVersionIsKeptOnlyAfterTheLockIsWon()

	/**
	 * A row stored in the future still moves forward: the new version is
	 * after the stored one, so the lock never compares a version with itself.
	 *
	 * @return void
	 */
	public function testANewVersionIsAlwaysAfterTheStoredOne(): void {
		$future = new DateTime('+1 hour');
		$this->reads($this->row(key: 'OLD', version: '@'.$future->getTimestamp()));
		$this->locksAnswer(1);

		$this->service->updateByApplication(id: 's1', data: ['key' => 'NEW'], applicationId: 'app-1');

		$this->assertSame($future->getTimestamp() + 1, $this->locks[0][1]->getTimestamp());
	}//end testANewVersionIsAlwaysAfterTheStoredOne()

	/**
	 * A write that lands between the read and the write is kept as a
	 * version, and the update is made once more on the row as it is now,
	 * locked on that row's version, and audited and recorded once. The
	 * three-argument call of an in-process caller works unchanged.
	 *
	 * @return void
	 */
	public function testALostLockIsRetriedOnceOnTheRowAsItIsNow(): void {
		$read = $this->row(key: 'OLD', version: '2026-01-01T00:00:00+00:00');
		$meanwhile = $this->row(key: 'MEANWHILE', version: '2026-01-01T00:00:05+00:00');
		$this->reads($read, $meanwhile);
		$this->locksAnswer(0, 1);
		$this->expectAuditedAndRecorded(1);

		$result = $this->service->updateByApplication('s1', ['key' => 'NEW'], 'app-1');

		$this->assertSame(['begin', 'advance', 'rollback', 'begin', 'advance', 'snapshot', 'update', 'commit'], $this->steps);
		$this->assertSame($meanwhile, $result);
		$this->assertCount(1, $this->snapshots);
		$this->assertSame('MEANWHILE', $this->snapshots[0]->getKey(), 'the write that landed in between is kept as a version');
		$this->assertCount(1, $this->written);
		$this->assertSame('NEW', $this->written[0]->getKey());
		$this->assertEquals(new DateTime('2026-01-01T00:00:00+00:00'), $this->locks[0][0]);
		$this->assertEquals(new DateTime('2026-01-01T00:00:05+00:00'), $this->locks[1][0]);
	}//end testALostLockIsRetriedOnceOnTheRowAsItIsNow()

	/**
	 * A second lost lock refuses the update: nothing is kept, written,
	 * audited or recorded.
	 *
	 * @return void
	 */
	public function testASecondLostLockRefusesTheUpdate(): void {
		$latest = $this->row(key: 'LATEST', version: '2026-01-01T00:00:09+00:00');
		$this->reads(
			$this->row(key: 'OLD', version: '2026-01-01T00:00:00+00:00'),
			$this->row(key: 'MEANWHILE', version: '2026-01-01T00:00:05+00:00'),
			$latest
		);
		$this->locksAnswer(0, 0);
		$this->expectAuditedAndRecorded(0);

		try {
			$this->service->updateByApplication(id: 's1', data: ['key' => 'NEW'], applicationId: 'app-1');
			$this->fail('A row that moved twice must refuse the update');
		} catch (StaleWriteException $e) {
			$this->assertSame($latest, $e->getCurrent());
		}

		$this->assertSame(['begin', 'advance', 'rollback', 'begin', 'advance', 'rollback'], $this->steps);
		$this->assertSame([], $this->snapshots);
		$this->assertSame([], $this->written);
	}//end testASecondLostLockRefusesTheUpdate()

	/**
	 * A row deleted between the read and the write is not found, not a server error.
	 *
	 * @return void
	 */
	public function testARowDeletedBeforeTheWriteIsNotFound(): void {
		$this->reads($this->row(key: 'OLD', version: '2026-01-01T00:00:00+00:00'), new DoesNotExistException('gone'));
		$this->locksAnswer(0);

		$this->expectException(NotFoundException::class);
		try {
			$this->service->updateByApplication(id: 's1', data: ['key' => 'NEW'], applicationId: 'app-1');
		} finally {
			$this->assertSame([], $this->written);
			$this->assertSame([], $this->snapshots);
		}
	}//end testARowDeletedBeforeTheWriteIsNotFound()

	/**
	 * A row that left the vault between the read and the write is not found,
	 * like a missing one, and is never written or reported.
	 *
	 * @return void
	 */
	public function testARowThatLeftTheVaultBeforeTheWriteIsNotFound(): void {
		$moved = $this->row(key: 'THEIRS', version: '2026-01-01T00:00:05+00:00', ownerId: 'app-2');
		$this->reads($this->row(key: 'OLD', version: '2026-01-01T00:00:00+00:00'), $moved);
		$this->locksAnswer(0);

		$this->expectException(NotFoundException::class);
		try {
			$this->service->updateByApplication(id: 's1', data: ['key' => 'NEW'], applicationId: 'app-1');
		} finally {
			$this->assertSame([], $this->written);
			$this->assertSame('THEIRS', $moved->getKey());
		}
	}//end testARowThatLeftTheVaultBeforeTheWriteIsNotFound()

	/**
	 * A precondition that holds on the row read is checked on that row, and
	 * the write locks on that row's version.
	 *
	 * @return void
	 */
	public function testAPreconditionIsCheckedOnTheRowTheWriteLocksOn(): void {
		$read = $this->row(key: 'OLD', version: '2026-01-01T00:00:00+00:00');
		$this->reads($read);
		$this->locksAnswer(1);
		$checked = [];

		$this->service->updateByApplication(
			id: 's1',
			data: ['key' => 'NEW'],
			applicationId: 'app-1',
			precondition: static function (Secret $row) use (&$checked): bool {
				$checked[] = clone $row;
				return true;
			}
		);

		$this->assertCount(1, $checked);
		$this->assertSame('OLD', $checked[0]->getKey());
		$this->assertEquals($checked[0]->getUpdatedAt(), $this->locks[0][0]);
	}//end testAPreconditionIsCheckedOnTheRowTheWriteLocksOn()

	/**
	 * A precondition that fails on the row read refuses the write before any
	 * lock is taken, reporting that row; nothing is audited or recorded.
	 *
	 * @return void
	 */
	public function testAFailedPreconditionWritesNothing(): void {
		$read = $this->row(key: 'OLD', version: '2026-01-01T00:00:00+00:00');
		$this->reads($read);
		$this->locksAnswer();
		$this->expectAuditedAndRecorded(0);

		try {
			$this->service->updateByApplication(id: 's1', data: ['key' => 'NEW'], applicationId: 'app-1', precondition: static fn (): bool => false);
			$this->fail('A failed precondition must refuse the write');
		} catch (PreconditionFailedException $e) {
			$this->assertSame($read, $e->getCurrent());
		}

		$this->assertSame([], $this->steps);
	}//end testAFailedPreconditionWritesNothing()

	/**
	 * A precondition naming the version read that loses the lock is refused
	 * with the row as it is now: the write is not retried over a version the
	 * caller never saw, and nothing is kept, written, audited or recorded.
	 *
	 * @return void
	 */
	public function testANamedVersionThatLosesTheLockIsRefusedWithTheCurrentRow(): void {
		$read = $this->row(key: 'OLD', version: '2026-01-01T00:00:00+00:00');
		$meanwhile = $this->row(key: 'MEANWHILE', version: '2026-01-01T00:00:05+00:00');
		$this->reads($read, $meanwhile);
		$this->locksAnswer(0);
		$this->expectAuditedAndRecorded(0);

		try {
			$this->service->updateByApplication(
				id: 's1',
				data: ['key' => 'NEW'],
				applicationId: 'app-1',
				precondition: static fn (Secret $row): bool => $row->getKey() === 'OLD'
			);
			$this->fail('A named version that moved on must refuse the write');
		} catch (PreconditionFailedException $e) {
			$this->assertSame($meanwhile, $e->getCurrent());
		}

		$this->assertSame(['begin', 'advance', 'rollback'], $this->steps);
		$this->assertSame([], $this->snapshots);
		$this->assertSame([], $this->written);
	}//end testANamedVersionThatLosesTheLockIsRefusedWithTheCurrentRow()

	/**
	 * A precondition that holds on any version (`If-Match: *`) is retried
	 * like no precondition: it still holds on the row as it is now.
	 *
	 * @return void
	 */
	public function testAPreconditionOnAnyVersionIsRetriedOnTheRowAsItIsNow(): void {
		$this->reads(
			$this->row(key: 'OLD', version: '2026-01-01T00:00:00+00:00'),
			$this->row(key: 'MEANWHILE', version: '2026-01-01T00:00:05+00:00')
		);
		$this->locksAnswer(0, 1);

		$result = $this->service->updateByApplication(id: 's1', data: ['key' => 'NEW'], applicationId: 'app-1', precondition: static fn (): bool => true);

		$this->assertSame('NEW', $result->getKey());
		$this->assertSame('MEANWHILE', $this->snapshots[0]->getKey());
	}//end testAPreconditionOnAnyVersionIsRetriedOnTheRowAsItIsNow()

	/**
	 * A row of another vault is not found before any precondition is
	 * checked, so the precondition is no existence oracle.
	 *
	 * @return void
	 */
	public function testAPreconditionIsNeverCheckedOnAnotherVaultsRow(): void {
		$this->reads($this->row(key: 'THEIRS', version: '2026-01-01T00:00:00+00:00', ownerId: 'app-2'));
		$this->locksAnswer();

		$this->expectException(NotFoundException::class);
		$this->service->updateByApplication(
			id: 's1',
			data: ['key' => 'NEW'],
			applicationId: 'app-1',
			precondition: function (): bool {
				$this->fail('the precondition must not see another vault\'s row');
			}
		);
	}//end testAPreconditionIsNeverCheckedOnAnotherVaultsRow()

	/**
	 * A row without a stored version can never match the lock, so its write
	 * is refused rather than made unlocked.
	 *
	 * @return void
	 */
	public function testARowWithoutAVersionIsNeverWrittenUnlocked(): void {
		$read = $this->row(key: 'OLD', version: '2026-01-01T00:00:00+00:00');
		$read->setUpdatedAt(null);
		$again = clone $read;
		$this->reads($read, $again, clone $read);
		$this->locksAnswer(0, 0);

		$this->expectException(StaleWriteException::class);
		try {
			$this->service->updateByApplication(id: 's1', data: ['key' => 'NEW'], applicationId: 'app-1');
		} finally {
			$this->assertSame([], $this->written);
		}
	}//end testARowWithoutAVersionIsNeverWrittenUnlocked()
}//end class
