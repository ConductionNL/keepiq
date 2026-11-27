<?php

/**
 * Unit tests for SecretVersionLock.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Db
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

namespace OCA\Keepiq\Tests\Unit\Db;

use OCA\Keepiq\Db\SecretVersionLock;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The lock is a no-op UPDATE of the row, which takes its lock without moving the version, then a read of the
 * version it holds.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-a-share-is-made-from-the-current-version
 */
#[CoversClass(SecretVersionLock::class)]
class SecretVersionLockTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The lock updates updated_at to itself, then reads it back in UTC.
	 *
	 * @return void
	 */
	public function testTheLockLeavesTheVersionAndReadsIt(): void {
		$this->affected = [0];
		$this->queueRows(rows: [['updated_at' => '2026-03-01 11:00:00']]);

		$version = (new SecretVersionLock(db: $this->recordingConnection()))->lock(id: 's1');

		$this->assertSame(
			expected: ['update(keepiq_secrets)', 'set(updated_at, updated_at)', 'where(eq(id, :p0))'],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: 'statement', actual: $this->ranOf(index: 0));
		$this->assertSame(expected: ['select(updated_at)', 'from(keepiq_secrets)', 'where(eq(id, :p1))'], actual: $this->opsOf(index: 1));
		$this->assertSame(expected: '2026-03-01T11:00:00+00:00', actual: $version?->format(DATE_ATOM));
	}//end testTheLockLeavesTheVersionAndReadsIt()

	/**
	 * A row that is gone has no version.
	 *
	 * @return void
	 */
	public function testAGoneRowHasNoVersion(): void {
		$this->affected = [0];
		$this->queueRows(rows: []);

		$this->assertNull(actual: (new SecretVersionLock(db: $this->recordingConnection()))->lock(id: 'gone'));
	}//end testAGoneRowHasNoVersion()

	/**
	 * The work runs inside the transaction with the version it locked, and
	 * its result comes back after the commit.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-the-owner-resumes-a-suspended-partner-share
	 */
	public function testWorkRunsWhileTheRowIsLocked(): void {
		$this->affected = [0];
		$this->queueRows(rows: [['updated_at' => '2026-03-01 11:00:00']]);
		$seen = [];

		$result = (new SecretVersionLock(db: $this->recordingConnection()))->whileLocked(
			id: 's1',
			work: function (?\DateTime $version) use (&$seen): string {
				$seen = [$this->connectionCalls, $version?->format(DATE_ATOM)];
				return 'done';
			}
		);

		$this->assertSame(expected: 'done', actual: $result);
		$this->assertSame(expected: [['beginTransaction'], '2026-03-01T11:00:00+00:00'], actual: $seen);
		$this->assertSame(expected: ['beginTransaction', 'commit'], actual: $this->connectionCalls);
	}//end testWorkRunsWhileTheRowIsLocked()

	/**
	 * Work that throws rolls the transaction back and the error goes on.
	 *
	 * @return void
	 */
	public function testWorkThatThrowsRollsBack(): void {
		$this->affected = [0];
		$this->queueRows(rows: []);

		try {
			(new SecretVersionLock(db: $this->recordingConnection()))->whileLocked(
				id: 'gone',
				work: function (?\DateTime $version): never {
					$this->assertNull(actual: $version);
					throw new \RuntimeException(message: 'refused');
				}
			);
			$this->fail(message: 'The error was swallowed');
		} catch (\RuntimeException $exception) {
			$this->assertSame(expected: 'refused', actual: $exception->getMessage());
		}

		$this->assertSame(expected: ['beginTransaction', 'rollBack'], actual: $this->connectionCalls);
	}//end testWorkThatThrowsRollsBack()
}//end class
