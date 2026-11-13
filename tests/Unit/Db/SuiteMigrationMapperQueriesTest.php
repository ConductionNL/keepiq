<?php

/**
 * SuiteMigrationMapper against a recording connection.
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

use OCA\Keepiq\Db\SuiteMigration;
use OCA\Keepiq\Db\SuiteMigrationMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The SQL each SuiteMigrationMapper query builds: the in-progress lookup by
 * old suite (and the boolean built on it), migrations touching a suite on
 * either side, and the GDPR cascade delete over a suite set, which is a no-op
 * for an empty set.
 *
 * @covers \OCA\Keepiq\Db\SuiteMigrationMapper
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @uses   \OCA\Keepiq\Db\SuiteMigration
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class SuiteMigrationMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over the recording connection.
	 *
	 * @return SuiteMigrationMapper
	 */
	private function mapper(): SuiteMigrationMapper {
		return new SuiteMigrationMapper($this->recordingConnection());
	}//end mapper()

	/**
	 * findById selects by id and hydrates.
	 *
	 * @return void
	 */
	public function testFindByIdHydratesTheRow(): void {
		$this->queueRows([['id' => 'm1', 'old_suite_id' => 's-old', 'new_suite_id' => 's-new', 'status' => 'completed']]);

		$migration = $this->mapper()->findById(id: 'm1');

		$this->assertInstanceOf(SuiteMigration::class, $migration);
		$this->assertSame('s-old', $migration->getOldSuiteId());
		$this->assertSame('s-new', $migration->getNewSuiteId());
		$this->assertSame('completed', $migration->getStatus());
		$this->assertSame(['select(*)', 'from(keepiq_suite_migr)', 'where(eq(id, :p0))'], $this->opsOf(0));
		$this->assertSame([':p0' => 'm1'], $this->paramsOf(0));
	}//end testFindByIdHydratesTheRow()

	/**
	 * The in-progress lookup filters on old suite and status in_progress.
	 *
	 * @return void
	 */
	public function testFindInProgressByOldSuiteId(): void {
		$this->queueRows([['id' => 'm2', 'old_suite_id' => 's1', 'status' => 'in_progress']]);

		$migration = $this->mapper()->findInProgressByOldSuiteId(oldSuiteId: 's1');

		$this->assertSame('m2', $migration->getId());
		$this->assertSame(
			['select(*)', 'from(keepiq_suite_migr)', 'where(eq(old_suite_id, :p0))', 'andWhere(eq(status, :p1))'],
			$this->opsOf(0)
		);
		$this->assertSame([':p0' => 's1', ':p1' => 'in_progress'], $this->paramsOf(0));
	}//end testFindInProgressByOldSuiteId()

	/**
	 * No in-progress migration throws DoesNotExistException.
	 *
	 * @return void
	 */
	public function testFindInProgressThrowsWhenNone(): void {
		$this->expectException(DoesNotExistException::class);

		$this->mapper()->findInProgressByOldSuiteId(oldSuiteId: 's1');
	}//end testFindInProgressThrowsWhenNone()

	/**
	 * hasInProgress answers true when the lookup finds a row.
	 *
	 * @return void
	 */
	public function testHasInProgressTrueWhenFound(): void {
		$this->queueRows([['id' => 'm2']]);

		$this->assertTrue($this->mapper()->hasInProgress(oldSuiteId: 's1'));
		$this->assertSame([':p0' => 's1', ':p1' => 'in_progress'], $this->paramsOf(0));
	}//end testHasInProgressTrueWhenFound()

	/**
	 * hasInProgress answers false when nothing exists.
	 *
	 * @return void
	 */
	public function testHasInProgressFalseWhenMissing(): void {
		$this->assertFalse($this->mapper()->hasInProgress(oldSuiteId: 's1'));
		$this->assertSame('query', $this->ranOf(0));
	}//end testHasInProgressFalseWhenMissing()

	/**
	 * hasInProgress does not swallow a duplicate-row error.
	 *
	 * @return void
	 */
	public function testHasInProgressPropagatesMultipleRows(): void {
		$this->queueRows([['id' => 'm2'], ['id' => 'm3']]);
		$this->expectException(MultipleObjectsReturnedException::class);

		$this->mapper()->hasInProgress(oldSuiteId: 's1');
	}//end testHasInProgressPropagatesMultipleRows()

	/**
	 * findBySuiteId matches either side of the migration.
	 *
	 * @return void
	 */
	public function testFindBySuiteIdMatchesOldOrNew(): void {
		$this->queueRows([['id' => 'm1'], ['id' => 'm4']]);

		$rows = $this->mapper()->findBySuiteId(suiteId: 's9');

		$this->assertSame(['m1', 'm4'], array_map(static fn (SuiteMigration $m) => $m->getId(), $rows));
		$this->assertSame(
			['select(*)', 'from(keepiq_suite_migr)', 'where(orX(eq(old_suite_id, :p0), eq(new_suite_id, :p1)))'],
			$this->opsOf(0)
		);
		$this->assertSame([':p0' => 's9', ':p1' => 's9'], $this->paramsOf(0));
	}//end testFindBySuiteIdMatchesOldOrNew()

	/**
	 * An empty suite set deletes nothing and builds no query.
	 *
	 * @return void
	 */
	public function testDeleteBySuiteIdsWithEmptySetIsNoOp(): void {
		$this->assertSame(0, $this->mapper()->deleteBySuiteIds(suiteIds: []));
		$this->assertSame([], $this->queries);
	}//end testDeleteBySuiteIdsWithEmptySetIsNoOp()

	/**
	 * The cascade delete removes rows referencing the suites on either side.
	 *
	 * @return void
	 */
	public function testDeleteBySuiteIdsDeletesEitherSide(): void {
		$this->affected = [3];

		$this->assertSame(3, $this->mapper()->deleteBySuiteIds(suiteIds: ['s1', 's2']));

		$this->assertSame(
			['delete(keepiq_suite_migr)', 'where(orX(in(old_suite_id, :p0), in(new_suite_id, :p1)))'],
			$this->opsOf(0)
		);
		$this->assertSame([':p0' => ['s1', 's2'], ':p1' => ['s1', 's2']], $this->paramsOf(0));
		$this->assertSame(IQueryBuilder::PARAM_STR_ARRAY, $this->queries[0]['types'][':p0']);
		$this->assertSame(IQueryBuilder::PARAM_STR_ARRAY, $this->queries[0]['types'][':p1']);
		$this->assertSame('statement', $this->ranOf(0));
	}//end testDeleteBySuiteIdsDeletesEitherSide()
}//end class
