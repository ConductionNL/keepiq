<?php

/**
 * RecoveryApprovalMapper against a recording connection.
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

use OCA\Keepiq\Db\RecoveryApproval;
use OCA\Keepiq\Db\RecoveryApprovalMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * The SQL each RecoveryApprovalMapper query builds: lookup by id and every approval of a
 * recovery request.
 *
 * @covers \OCA\Keepiq\Db\RecoveryApprovalMapper
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @uses   \OCA\Keepiq\Db\RecoveryApproval
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class RecoveryApprovalMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over the recording connection.
	 *
	 * @return RecoveryApprovalMapper
	 */
	private function mapper(): RecoveryApprovalMapper {
		return new RecoveryApprovalMapper(db: $this->recordingConnection());
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
		$this->assertSame(expected: ['select(*)', 'from(keepiq_recovery_approvals)', 'where(eq(id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'r1'], actual: $this->paramsOf(index: 0));

		$this->expectException(exception: DoesNotExistException::class);
		$mapper->findById(id: 'gone');
	}//end testFindById()

	/**
	 * Every approval of one request.
	 *
	 * @return void
	 */
	public function testFindByRequest(): void {
		$this->queueRows(rows: [['id' => 'r1'], ['id' => 'r2']]);

		$rows = $this->mapper()->findByRequest(requestId: 'rq1');

		$this->assertSame(expected: ['r1', 'r2'], actual: array_map(static fn (RecoveryApproval $e): string => $e->getId(), $rows));
		$this->assertSame(
			expected: [
				'select(*)', 'from(keepiq_recovery_approvals)', 'where(eq(request_id, :p0))'
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'rq1'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'query', actual: $this->ranOf(index: 0));
	}//end testFindByRequest()
}//end class
