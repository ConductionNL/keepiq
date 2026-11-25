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
}//end class
