<?php

/**
 * DeviceApprovalMapper against a recording connection.
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

use DateTime;
use OCA\Keepiq\Db\DeviceApproval;
use OCA\Keepiq\Db\DeviceApprovalMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The SQL each DeviceApprovalMapper query builds: a user's pending requests
 * are only those still pending and not yet expired, newest first; lapsed
 * requests are the pending or approved ones whose window has closed.
 *
 * @covers \OCA\Keepiq\Db\DeviceApprovalMapper
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @uses   \OCA\Keepiq\Db\DeviceApproval
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class DeviceApprovalMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over the recording connection.
	 *
	 * @return DeviceApprovalMapper
	 */
	private function mapper(): DeviceApprovalMapper {
		return new DeviceApprovalMapper(db: $this->recordingConnection());
	}//end mapper()

	/**
	 * By id hydrates the row; no row throws.
	 *
	 * @return void
	 */
	public function testFindById(): void {
		$this->queueRows(rows: [['id' => 'd1', 'user_id' => 'alice', 'client_kind' => 'cli']]);
		$mapper = $this->mapper();

		$approval = $mapper->findById(id: 'd1');

		$this->assertSame(expected: 'd1', actual: $approval->getId());
		$this->assertSame(expected: 'alice', actual: $approval->getUserId());
		$this->assertSame(expected: 'cli', actual: $approval->getClientKind());
		$this->assertSame(expected: ['select(*)', 'from(keepiq_device_approvals)', 'where(eq(id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'd1'], actual: $this->paramsOf(index: 0));

		$this->expectException(exception: DoesNotExistException::class);
		$mapper->findById(id: 'gone');
	}//end testFindById()

	/**
	 * Two rows under one id is ambiguous.
	 *
	 * @return void
	 */
	public function testFindByIdRefusesTwoRows(): void {
		$this->queueRows(rows: [['id' => 'd1'], ['id' => 'd1']]);

		$this->expectException(exception: MultipleObjectsReturnedException::class);
		$this->mapper()->findById(id: 'd1');
	}//end testFindByIdRefusesTwoRows()

	/**
	 * Pending for a user means pending status and not yet expired, newest first.
	 *
	 * @return void
	 */
	public function testPendingForUserIsUnexpiredAndNewestFirst(): void {
		$this->queueRows(rows: [['id' => 'd2', 'status' => DeviceApproval::STATUS_PENDING], ['id' => 'd3']]);
		$now = new DateTime();

		$pending = $this->mapper()->findPendingForUser(userId: 'alice', now: $now);

		$this->assertSame(expected: ['d2', 'd3'], actual: array_map(static fn (DeviceApproval $d): string => $d->getId(), $pending));
		$this->assertSame(expected: DeviceApproval::STATUS_PENDING, actual: $pending[0]->getStatus());
		$this->assertSame(
			expected: [
				'select(*)', 'from(keepiq_device_approvals)', 'where(eq(user_id, :p0))', 'andWhere(eq(status, :p1))',
				'andWhere(gt(expires_at, :p2))', 'orderBy(created_at, DESC)',
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'alice', ':p1' => DeviceApproval::STATUS_PENDING, ':p2' => $now], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: IQueryBuilder::PARAM_DATETIME_MUTABLE, actual: $this->queries[0]['types'][':p2']);
	}//end testPendingForUserIsUnexpiredAndNewestFirst()

	/**
	 * Lapsed means pending or approved with the window closed.
	 *
	 * @return void
	 */
	public function testLapsedIsPendingOrApprovedPastExpiry(): void {
		$this->queueRows(rows: [['id' => 'd4', 'status' => DeviceApproval::STATUS_APPROVED]]);
		$now = new DateTime();

		$lapsed = $this->mapper()->findLapsed(now: $now);

		$this->assertCount(expectedCount: 1, haystack: $lapsed);
		$this->assertSame(expected: DeviceApproval::STATUS_APPROVED, actual: $lapsed[0]->getStatus());
		$this->assertSame(
			expected: ['select(*)', 'from(keepiq_device_approvals)', 'where(in(status, :p0))', 'andWhere(lte(expires_at, :p1))'],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(
			expected: [':p0' => [DeviceApproval::STATUS_PENDING, DeviceApproval::STATUS_APPROVED], ':p1' => $now],
			actual: $this->paramsOf(index: 0)
		);
		$this->assertSame(expected: IQueryBuilder::PARAM_STR_ARRAY, actual: $this->queries[0]['types'][':p0']);
		$this->assertSame(expected: IQueryBuilder::PARAM_DATETIME_MUTABLE, actual: $this->queries[0]['types'][':p1']);
	}//end testLapsedIsPendingOrApprovedPastExpiry()
}//end class
