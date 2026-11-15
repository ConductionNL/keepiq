<?php

/**
 * Unit tests for SiemQueueItemMapper's queue queries.
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
use OCA\Keepiq\Db\SiemQueueItem;
use OCA\Keepiq\Db\SiemQueueItemMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use PHPUnit\Framework\TestCase;

/**
 * SiemQueueItemMapper against a recording connection.
 *
 * @covers \OCA\Keepiq\Db\SiemQueueItemMapper
 * @uses   \OCA\Keepiq\Db\SiemQueueItem
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class SiemQueueItemMapperTest extends TestCase {
	use RecordingDatabase;

	/**
	 * Pending and dead counts filter on sink and status and cast the count.
	 *
	 * @return void
	 */
	public function testCountsFilterOnSinkAndStatus(): void {
		$this->queueRows([['c' => '4']]);
		$this->queueRows([]);
		$mapper = new SiemQueueItemMapper($this->recordingConnection());

		$this->assertSame(4, $mapper->countPending(sinkId: 'sink-1'));
		$this->assertSame(0, $mapper->countDead(sinkId: 'sink-2'));
		$this->assertSame(
			['select(count(*))', 'from(keepiq_siem_queue)', 'where(eq(sink_id, :p0))', 'andWhere(eq(status, :p1))'],
			$this->opsOf(0)
		);
		$this->assertSame([':p0' => 'sink-1', ':p1' => 'pending'], $this->paramsOf(0));
		$this->assertSame([':p2' => 'sink-2', ':p3' => 'dead'], $this->paramsOf(1));
	}//end testCountsFilterOnSinkAndStatus()

	/**
	 * The oldest pending item is the first by enqueue time, or null on an empty queue.
	 *
	 * @return void
	 */
	public function testOldestPendingIsFirstByEnqueueTimeOrNull(): void {
		$this->queueRows([['id' => 'q1', 'sink_id' => 'sink-1', 'status' => 'pending', 'attempts' => '2']]);
		$this->queueRows([]);
		$mapper = new SiemQueueItemMapper($this->recordingConnection());

		$item = $mapper->oldestPending(sinkId: 'sink-1');
		$this->assertInstanceOf(SiemQueueItem::class, $item);
		$this->assertSame('q1', $item->getId());
		$this->assertSame(2, $item->getAttempts());
		$this->assertSame(['orderBy(enqueued_at, ASC)', 'setMaxResults(1)'], array_slice($this->opsOf(0), -2));
		$this->assertNull($mapper->oldestPending(sinkId: 'sink-1'));
	}//end testOldestPendingIsFirstByEnqueueTimeOrNull()

	/**
	 * Due items are pending with no retry time or one at or before now, oldest first, limited.
	 *
	 * @return void
	 */
	public function testFindDueSelectsUnscheduledOrDueRetries(): void {
		$now = new DateTime('2026-11-01T10:00:00+00:00');
		$this->queueRows([['id' => 'a'], ['id' => 'b']]);

		$due = (new SiemQueueItemMapper($this->recordingConnection()))->findDue(sinkId: 'sink-1', now: $now, limit: 7);

		$this->assertSame(['a', 'b'], array_map(static fn (SiemQueueItem $i) => $i->getId(), $due));
		$this->assertSame(
			[
				'select(*)',
				'from(keepiq_siem_queue)',
				'where(eq(sink_id, :p0))',
				'andWhere(eq(status, :p1))',
				'andWhere(orX(isNull(next_attempt_at), lte(next_attempt_at, :p2)))',
				'orderBy(enqueued_at, ASC)',
				'setMaxResults(7)',
			],
			$this->opsOf(0)
		);
		$this->assertSame($now, $this->paramsOf(0)[':p2']);
		$this->assertSame('datetime', $this->queries[0]['types'][':p2']);
	}//end testFindDueSelectsUnscheduledOrDueRetries()

	/**
	 * Deleting a sink's queue is one statement on sink_id.
	 *
	 * @return void
	 */
	public function testDeleteBySinkIsOneStatement(): void {
		(new SiemQueueItemMapper($this->recordingConnection()))->deleteBySink(sinkId: 'sink-9');

		$this->assertSame(['delete(keepiq_siem_queue)', 'where(eq(sink_id, :p0))'], $this->opsOf(0));
		$this->assertSame([':p0' => 'sink-9'], $this->paramsOf(0));
		$this->assertSame('statement', $this->ranOf(0));
	}//end testDeleteBySinkIsOneStatement()
}//end class
