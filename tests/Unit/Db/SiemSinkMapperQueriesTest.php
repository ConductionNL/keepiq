<?php

/**
 * SiemSinkMapper against a recording connection.
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

use OCA\Keepiq\Db\SiemSink;
use OCA\Keepiq\Db\SiemSinkMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The SQL each SiemSinkMapper query builds: lookup by id, every sink newest first, and only
 * the enabled sinks, filtered on a boolean parameter.
 *
 * @covers \OCA\Keepiq\Db\SiemSinkMapper
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @uses   \OCA\Keepiq\Db\SiemSink
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class SiemSinkMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over the recording connection.
	 *
	 * @return SiemSinkMapper
	 */
	private function mapper(): SiemSinkMapper {
		return new SiemSinkMapper(db: $this->recordingConnection());
	}//end mapper()

	/**
	 * Lookup by id hydrates the row; no row throws.
	 *
	 * @return void
	 */
	public function testFindById(): void {
		$this->queueRows(rows: [['id' => 'r1']]);
		$mapper = $this->mapper();

		$this->assertSame(expected: 'r1', actual: $mapper->findById(id: 'r1')->getId());
		$this->assertSame(expected: ['select(*)', 'from(keepiq_siem_sinks)', 'where(eq(id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'r1'], actual: $this->paramsOf(index: 0));

		$this->expectException(exception: DoesNotExistException::class);
		$mapper->findById(id: 'gone');
	}//end testFindById()

	/**
	 * Every sink, newest first.
	 *
	 * @return void
	 */
	public function testFindAll(): void {
		$this->queueRows(rows: [['id' => 'r1'], ['id' => 'r2']]);

		$rows = $this->mapper()->findAll();

		$this->assertSame(expected: ['r1', 'r2'], actual: array_map(static fn (SiemSink $e): string => $e->getId(), $rows));
		$this->assertSame(
			expected: [
				'select(*)', 'from(keepiq_siem_sinks)', 'orderBy(created_at, DESC)'
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'query', actual: $this->ranOf(index: 0));
	}//end testFindAll()

	/**
	 * Only enabled sinks, compared as a boolean.
	 *
	 * @return void
	 */
	public function testFindEnabled(): void {
		$this->queueRows(rows: [['id' => 'r1'], ['id' => 'r2']]);

		$rows = $this->mapper()->findEnabled();

		$this->assertSame(expected: ['r1', 'r2'], actual: array_map(static fn (SiemSink $e): string => $e->getId(), $rows));
		$this->assertSame(
			expected: [
				'select(*)', 'from(keepiq_siem_sinks)', 'where(eq(enabled, :p0))'
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => true], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'query', actual: $this->ranOf(index: 0));
		$this->assertSame(expected: IQueryBuilder::PARAM_BOOL, actual: $this->queries[0]['types'][':p0']);
	}//end testFindEnabled()
}//end class
