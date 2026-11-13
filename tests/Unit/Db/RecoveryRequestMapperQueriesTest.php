<?php

/**
 * RecoveryRequestMapper against a recording connection.
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
use OCA\Keepiq\Db\RecoveryRequest;
use OCA\Keepiq\Db\RecoveryRequestMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The SQL each RecoveryRequestMapper query builds. The officer queue lists
 * open requests oldest first, a user sees their own newest first, and the
 * lapse sweep takes open requests whose expiry has passed.
 *
 * @covers \OCA\Keepiq\Db\RecoveryRequestMapper
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @uses   \OCA\Keepiq\Db\RecoveryRequest
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class RecoveryRequestMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over the recording connection.
	 *
	 * @return RecoveryRequestMapper
	 */
	private function mapper(): RecoveryRequestMapper {
		return new RecoveryRequestMapper(db: $this->recordingConnection());
	}//end mapper()

	/**
	 * A request row as the table holds it.
	 *
	 * @param string $id     The row id
	 * @param string $status The status
	 *
	 * @return array<string,mixed>
	 */
	private function row(string $id, string $status = 'pending'): array {
		return ['id' => $id, 'user_id' => 'alice', 'suite_id' => 'suite-1', 'status' => $status];
	}//end row()

	/**
	 * Ids of the hydrated requests.
	 *
	 * @param RecoveryRequest[] $requests The requests
	 *
	 * @return string[]
	 */
	private function ids(array $requests): array {
		return array_map(static fn (RecoveryRequest $request) => $request->getId(), $requests);
	}//end ids()

	/**
	 * The lookup by id hydrates the single row.
	 *
	 * @return void
	 */
	public function testFindByIdHydratesTheRow(): void {
		$this->queueRows(rows: [$this->row(id: 'r1', status: 'approved')]);

		$found = $this->mapper()->findById(id: 'r1');

		$this->assertInstanceOf(expected: RecoveryRequest::class, actual: $found);
		$this->assertSame(expected: 'r1', actual: $found->getId());
		$this->assertSame(expected: 'approved', actual: $found->getStatus());
		$this->assertSame(expected: 'alice', actual: $found->getUserId());
		$this->assertSame(expected: ['select(*)', 'from(keepiq_recovery_requests)', 'where(eq(id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'r1'], actual: $this->paramsOf(index: 0));
	}//end testFindByIdHydratesTheRow()

	/**
	 * An unknown id throws DoesNotExistException.
	 *
	 * @return void
	 */
	public function testFindByIdThrowsWhenMissing(): void {
		$this->expectException(exception: DoesNotExistException::class);

		$this->mapper()->findById(id: 'missing');
	}//end testFindByIdThrowsWhenMissing()

	/**
	 * Two rows for one id throws MultipleObjectsReturnedException.
	 *
	 * @return void
	 */
	public function testFindByIdThrowsOnDuplicates(): void {
		$this->queueRows(rows: [$this->row(id: 'r1'), $this->row(id: 'r1')]);

		$this->expectException(exception: MultipleObjectsReturnedException::class);

		$this->mapper()->findById(id: 'r1');
	}//end testFindByIdThrowsOnDuplicates()

	/**
	 * The status queue binds the statuses as a string array, oldest first.
	 *
	 * @return void
	 */
	public function testFindByStatusesIsOldestFirst(): void {
		$this->queueRows(rows: [$this->row(id: 'r1'), $this->row(id: 'r2', status: 'approved')]);

		$found = $this->mapper()->findByStatuses(statuses: ['pending', 'approved']);

		$this->assertSame(expected: ['r1', 'r2'], actual: $this->ids(requests: $found));
		$this->assertSame(
			expected: ['select(*)', 'from(keepiq_recovery_requests)', 'where(in(status, :p0))', 'orderBy(created_at, ASC)'],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => ['pending', 'approved']], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: IQueryBuilder::PARAM_STR_ARRAY, actual: $this->queries[0]['types'][':p0']);
	}//end testFindByStatusesIsOldestFirst()

	/**
	 * A user's requests are newest first; a suite's are unordered.
	 *
	 * @return void
	 */
	public function testFindByUserAndBySuite(): void {
		$this->queueRows(rows: [$this->row(id: 'r2'), $this->row(id: 'r1')]);
		$this->queueRows(rows: [$this->row(id: 'r3')]);
		$mapper = $this->mapper();

		$this->assertSame(expected: ['r2', 'r1'], actual: $this->ids(requests: $mapper->findByUser(userId: 'alice')));
		$this->assertSame(expected: ['r3'], actual: $this->ids(requests: $mapper->findBySuite(suiteId: 'suite-1')));

		$this->assertSame(
			expected: ['select(*)', 'from(keepiq_recovery_requests)', 'where(eq(user_id, :p0))', 'orderBy(created_at, DESC)'],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'alice'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: ['select(*)', 'from(keepiq_recovery_requests)', 'where(eq(suite_id, :p1))'], actual: $this->opsOf(index: 1));
		$this->assertSame(expected: [':p1' => 'suite-1'], actual: $this->paramsOf(index: 1));
	}//end testFindByUserAndBySuite()

	/**
	 * No matching rows answers an empty list.
	 *
	 * @return void
	 */
	public function testAnEmptyListingAnswersNothing(): void {
		$this->assertSame(expected: [], actual: $this->mapper()->findBySuite(suiteId: 'none'));
		$this->assertSame(expected: 'query', actual: $this->ranOf(index: 0));
	}//end testAnEmptyListingAnswersNothing()

	/**
	 * Lapsed means an open status and an expiry at or before now.
	 *
	 * @return void
	 */
	public function testFindLapsedTakesOpenRequestsPastExpiry(): void {
		$now = new DateTime('2026-03-01T00:00:00+00:00');
		$this->queueRows(rows: [$this->row(id: 'r1')]);

		$found = $this->mapper()->findLapsed(statuses: ['pending'], now: $now);

		$this->assertSame(expected: ['r1'], actual: $this->ids(requests: $found));
		$this->assertSame(
			expected: [
				'select(*)', 'from(keepiq_recovery_requests)', 'where(in(status, :p0))',
				'andWhere(lte(expires_at, :p1))',
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: ['pending'], actual: $this->paramsOf(index: 0)[':p0']);
		$this->assertSame(expected: $now, actual: $this->paramsOf(index: 0)[':p1']);
		$this->assertSame(expected: IQueryBuilder::PARAM_STR_ARRAY, actual: $this->queries[0]['types'][':p0']);
		$this->assertSame(expected: IQueryBuilder::PARAM_DATETIME_MUTABLE, actual: $this->queries[0]['types'][':p1']);
	}//end testFindLapsedTakesOpenRequestsPastExpiry()
}//end class
