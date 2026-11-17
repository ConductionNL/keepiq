<?php

/**
 * RecoveryKitMapper against a recording connection.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Db;

use OCA\Keepiq\Db\RecoveryKit;
use OCA\Keepiq\Db\RecoveryKitMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The SQL each RecoveryKitMapper query builds: a user's kits newest first, and the
 * delete that is always scoped to the owner and optionally to one suite.
 *
 * @covers \OCA\Keepiq\Db\RecoveryKitMapper
 * @uses   \OCA\Keepiq\Db\RecoveryKit
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class RecoveryKitMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over the recording connection.
	 *
	 * @return RecoveryKitMapper
	 */
	private function mapper(): RecoveryKitMapper {
		return new RecoveryKitMapper(db: $this->recordingConnection());
	}//end mapper()

	/**
	 * A user's kits are read by owner, newest first, and hydrated.
	 *
	 * @return void
	 */
	public function testFindByUserReadsTheOwnersKitsNewestFirst(): void {
		$this->queueRows(rows: [['id' => 'k2', 'suite_id' => 's2'], ['id' => 'k1', 'suite_id' => 's1']]);

		$kits = $this->mapper()->findByUser(userId: 'alice');

		$this->assertSame(
			expected: [['k2', 's2'], ['k1', 's1']],
			actual: array_map(static fn (RecoveryKit $kit): array => [$kit->getId(), $kit->getSuiteId()], $kits)
		);
		$this->assertSame(
			expected: ['select(*)', 'from(keepiq_recovery_kits)', 'where(eq(user_id, :p0))', 'orderBy(created_at, DESC)'],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'alice'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'query', actual: $this->ranOf(index: 0));
	}//end testFindByUserReadsTheOwnersKitsNewestFirst()

	/**
	 * Without a suite every kit of the owner is deleted, and the count is returned.
	 *
	 * @return void
	 */
	public function testDeleteForUserWithoutSuiteDeletesEveryKitOfTheOwner(): void {
		$this->affected = [3];

		$deleted = $this->mapper()->deleteForUser(userId: 'alice');

		$this->assertSame(expected: 3, actual: $deleted);
		$this->assertSame(expected: ['delete(keepiq_recovery_kits)', 'where(eq(user_id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'alice'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'statement', actual: $this->ranOf(index: 0));
	}//end testDeleteForUserWithoutSuiteDeletesEveryKitOfTheOwner()

	/**
	 * With a suite the delete is narrowed to that suite and still scoped to the owner.
	 *
	 * @return void
	 */
	public function testDeleteForUserWithSuiteNarrowsToThatSuite(): void {
		$this->affected = [1];

		$deleted = $this->mapper()->deleteForUser(userId: 'alice', suiteId: 'suite-a');

		$this->assertSame(expected: 1, actual: $deleted);
		$this->assertSame(
			expected: ['delete(keepiq_recovery_kits)', 'where(eq(user_id, :p0))', 'andWhere(eq(suite_id, :p1))'],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'alice', ':p1' => 'suite-a'], actual: $this->paramsOf(index: 0));
	}//end testDeleteForUserWithSuiteNarrowsToThatSuite()
}//end class
