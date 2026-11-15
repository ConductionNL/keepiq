<?php

/**
 * ComplianceReportMapper against a recording connection.
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

use OCA\Keepiq\Db\ComplianceReport;
use OCA\Keepiq\Db\ComplianceReportMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * The SQL each ComplianceReportMapper query builds: lookup by id, and the newest reports
 * capped at 100 by default or at the limit given.
 *
 * @covers \OCA\Keepiq\Db\ComplianceReportMapper
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @uses   \OCA\Keepiq\Db\ComplianceReport
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class ComplianceReportMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over the recording connection.
	 *
	 * @return ComplianceReportMapper
	 */
	private function mapper(): ComplianceReportMapper {
		return new ComplianceReportMapper(db: $this->recordingConnection());
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
		$this->assertSame(expected: ['select(*)', 'from(keepiq_compliance_reports)', 'where(eq(id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'r1'], actual: $this->paramsOf(index: 0));

		$this->expectException(exception: DoesNotExistException::class);
		$mapper->findById(id: 'gone');
	}//end testFindById()

	/**
	 * The newest reports, at most 100 by default.
	 *
	 * @return void
	 */
	public function testFindAllDefaultsToAHundred(): void {
		$this->queueRows(rows: [['id' => 'r1'], ['id' => 'r2']]);

		$rows = $this->mapper()->findAll();

		$this->assertSame(expected: ['r1', 'r2'], actual: array_map(static fn (ComplianceReport $e): string => $e->getId(), $rows));
		$this->assertSame(
			expected: [
				'select(*)', 'from(keepiq_compliance_reports)', 'orderBy(generated_at, DESC)',
				'setMaxResults(100)'
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'query', actual: $this->ranOf(index: 0));
	}//end testFindAllDefaultsToAHundred()

	/**
	 * The limit given caps the result.
	 *
	 * @return void
	 */
	public function testFindAllHonoursTheLimit(): void {
		$this->queueRows(rows: [['id' => 'r1'], ['id' => 'r2']]);

		$rows = $this->mapper()->findAll(limit: 5);

		$this->assertSame(expected: ['r1', 'r2'], actual: array_map(static fn (ComplianceReport $e): string => $e->getId(), $rows));
		$this->assertSame(
			expected: [
				'select(*)', 'from(keepiq_compliance_reports)', 'orderBy(generated_at, DESC)', 'setMaxResults(5)'
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'query', actual: $this->ranOf(index: 0));
	}//end testFindAllHonoursTheLimit()
}//end class
