<?php

/**
 * Unit tests for MigrationFailureMapper's per-record failure log.
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

use DateTime;
use OCA\Keepiq\Db\MigrationFailure;
use OCA\Keepiq\Db\MigrationFailureMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use PHPUnit\Framework\TestCase;

/**
 * MigrationFailureMapper against a recording connection.
 *
 * @covers \OCA\Keepiq\Db\MigrationFailureMapper
 * @uses   \OCA\Keepiq\Db\MigrationFailure
 */
class MigrationFailureMapperTest extends TestCase {
	use RecordingDatabase;

	/**
	 * A first failure for a record is inserted with all its fields.
	 *
	 * @return void
	 */
	public function testRecordInsertsANewFailure(): void {
		$this->queueRows([]);

		$failure = (new MigrationFailureMapper($this->recordingConnection()))
			->record(migrationId: 'm1', store: 'vault', recordId: 'r1', secretId: 's1', message: 'bad padding');

		$this->assertSame(42, $failure->getId());
		$this->assertSame('bad padding', $failure->getMessage());
		$this->assertInstanceOf(DateTime::class, $failure->getCreatedAt());
		$this->assertSame(
			['select(*)', 'from(keepiq_migration_failures)', 'where(eq(migration_id, :p0))', 'andWhere(eq(store, :p1))', 'andWhere(eq(record_id, :p2))', 'setMaxResults(1)'],
			$this->opsOf(0)
		);
		$this->assertSame('insert(keepiq_migration_failures)', $this->opsOf(1)[0]);
		$this->assertSame(['m1', 'vault', 'r1', 's1', 'bad padding'], array_slice(array_values($this->paramsOf(1)), 0, 5));
	}//end testRecordInsertsANewFailure()

	/**
	 * A repeat failure updates the existing row's message and secret, not a new row.
	 *
	 * @return void
	 */
	public function testRecordUpdatesAnExistingFailure(): void {
		$this->queueRows([['id' => 7, 'migration_id' => 'm1', 'store' => 'vault', 'record_id' => 'r1', 'secret_id' => 'old', 'message' => 'first']]);

		$failure = (new MigrationFailureMapper($this->recordingConnection()))
			->record(migrationId: 'm1', store: 'vault', recordId: 'r1', secretId: 's2', message: null);

		$this->assertSame(7, $failure->getId());
		$this->assertSame('s2', $failure->getSecretId());
		$this->assertNull($failure->getMessage());
		$this->assertCount(2, $this->queries);
		$this->assertSame('update(keepiq_migration_failures)', $this->opsOf(1)[0]);
		$this->assertContains('where(eq(id, :p5))', $this->opsOf(1));
		$this->assertSame(7, $this->paramsOf(1)[':p5']);
	}//end testRecordUpdatesAnExistingFailure()

	/**
	 * Counts read the total alias and default to zero without a row.
	 *
	 * @return void
	 */
	public function testCountsReadTotalOrZero(): void {
		$this->queueRows([['total' => '6']]);
		$this->queueRows([]);
		$mapper = new MigrationFailureMapper($this->recordingConnection());

		$this->assertSame(6, $mapper->countByMigration(migrationId: 'm1'));
		$this->assertSame(0, $mapper->countByMigrationAndStore(migrationId: 'm1', store: 'vault'));
		$this->assertSame(['select(count(*, total))', 'from(keepiq_migration_failures)', 'where(eq(migration_id, :p0))'], $this->opsOf(0));
		$this->assertSame([':p1' => 'm1', ':p2' => 'vault'], $this->paramsOf(1));
	}//end testCountsReadTotalOrZero()

	/**
	 * Listing is newest first with a limit of at least one.
	 *
	 * @return void
	 */
	public function testFindByMigrationOrdersNewestFirstAndClampsLimit(): void {
		$this->queueRows([['id' => 2, 'record_id' => 'r2'], ['id' => 1, 'record_id' => 'r1']]);

		$rows = (new MigrationFailureMapper($this->recordingConnection()))->findByMigration(migrationId: 'm1', limit: 0);

		$this->assertSame(['r2', 'r1'], array_map(static fn (MigrationFailure $f) => $f->getRecordId(), $rows));
		$this->assertSame(['orderBy(created_at, DESC)', 'addOrderBy(id, DESC)', 'setMaxResults(1)'], array_slice($this->opsOf(0), -3));
	}//end testFindByMigrationOrdersNewestFirstAndClampsLimit()

	/**
	 * Clearing one record and deleting a migration are statements on the right keys.
	 *
	 * @return void
	 */
	public function testClearAndDeleteAreScopedStatements(): void {
		$mapper = new MigrationFailureMapper($this->recordingConnection());
		$mapper->clearRecord(migrationId: 'm1', store: 'vault', recordId: 'r1');
		$mapper->deleteByMigration(migrationId: 'm2');

		$this->assertSame(
			['delete(keepiq_migration_failures)', 'where(eq(migration_id, :p0))', 'andWhere(eq(store, :p1))', 'andWhere(eq(record_id, :p2))'],
			$this->opsOf(0)
		);
		$this->assertSame(['delete(keepiq_migration_failures)', 'where(eq(migration_id, :p3))'], $this->opsOf(1));
		$this->assertSame([':p3' => 'm2'], $this->paramsOf(1));
		$this->assertSame(['statement', 'statement'], [$this->ranOf(0), $this->ranOf(1)]);
	}//end testClearAndDeleteAreScopedStatements()
}//end class
