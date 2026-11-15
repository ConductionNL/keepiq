<?php

/**
 * HoneyAlertMapper against a recording connection.
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

use OCA\Keepiq\Db\HoneyAlert;
use OCA\Keepiq\Db\HoneyAlertMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The SQL each HoneyAlertMapper query builds: the by-id lookup, the owner
 * listing over a flag set (empty set short-circuits without a query), the
 * instance-wide admin listing, the accessor dedup lookup (anonymous accessors
 * match on IS NULL) and the unacknowledged count.
 *
 * @covers \OCA\Keepiq\Db\HoneyAlertMapper
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @uses   \OCA\Keepiq\Db\HoneyAlert
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class HoneyAlertMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over the recording connection.
	 *
	 * @return HoneyAlertMapper
	 */
	private function mapper(): HoneyAlertMapper {
		return new HoneyAlertMapper($this->recordingConnection());
	}//end mapper()

	/**
	 * findById selects the row by id and hydrates it.
	 *
	 * @return void
	 */
	public function testFindByIdHydratesTheRow(): void {
		$this->queueRows([['id' => 'a1', 'honey_flag_id' => 'f1', 'channel' => 'api', 'access_count' => '3']]);

		$alert = $this->mapper()->findById(id: 'a1');

		$this->assertInstanceOf(HoneyAlert::class, $alert);
		$this->assertSame('a1', $alert->getId());
		$this->assertSame('f1', $alert->getHoneyFlagId());
		$this->assertSame('api', $alert->getChannel());
		$this->assertSame(3, $alert->getAccessCount());
		$this->assertSame(['select(*)', 'from(keepiq_honey_alerts)', 'where(eq(id, :p0))'], $this->opsOf(0));
		$this->assertSame([':p0' => 'a1'], $this->paramsOf(0));
		$this->assertSame('query', $this->ranOf(0));
	}//end testFindByIdHydratesTheRow()

	/**
	 * A missing id throws DoesNotExistException.
	 *
	 * @return void
	 */
	public function testFindByIdThrowsWhenMissing(): void {
		$this->expectException(DoesNotExistException::class);

		$this->mapper()->findById(id: 'nope');
	}//end testFindByIdThrowsWhenMissing()

	/**
	 * An empty flag set answers [] without building a query.
	 *
	 * @return void
	 */
	public function testFindByFlagIdsWithNoFlagsRunsNoQuery(): void {
		$this->assertSame([], $this->mapper()->findByFlagIds(flagIds: []));
		$this->assertSame([], $this->queries);
	}//end testFindByFlagIdsWithNoFlagsRunsNoQuery()

	/**
	 * The owner listing is an IN over the flags, newest first, bounded.
	 *
	 * @return void
	 */
	public function testFindByFlagIdsIsAnInListNewestFirst(): void {
		$this->queueRows([['id' => 'a2'], ['id' => 'a1']]);

		$rows = $this->mapper()->findByFlagIds(flagIds: ['f1', 'f2'], limit: 25);

		$this->assertSame(['a2', 'a1'], array_map(static fn (HoneyAlert $a) => $a->getId(), $rows));
		$this->assertSame(
			[
				'select(*)', 'from(keepiq_honey_alerts)', 'where(in(honey_flag_id, :p0))',
				'orderBy(accessed_at, DESC)', 'setMaxResults(25)',
			],
			$this->opsOf(0)
		);
		$this->assertSame([':p0' => ['f1', 'f2']], $this->paramsOf(0));
		$this->assertSame(IQueryBuilder::PARAM_STR_ARRAY, $this->queries[0]['types'][':p0']);
	}//end testFindByFlagIdsIsAnInListNewestFirst()

	/**
	 * The owner listing defaults to 200 rows.
	 *
	 * @return void
	 */
	public function testFindByFlagIdsDefaultLimit(): void {
		$this->mapper()->findByFlagIds(flagIds: ['f1']);

		$this->assertSame('setMaxResults(200)', $this->opsOf(0)[4]);
	}//end testFindByFlagIdsDefaultLimit()

	/**
	 * The admin listing is unfiltered, newest first, bounded.
	 *
	 * @return void
	 */
	public function testFindAllIsUnfilteredNewestFirst(): void {
		$this->queueRows([['id' => 'a9']]);
		$mapper = $this->mapper();

		$rows = $mapper->findAll(limit: 10);
		$mapper->findAll();

		$this->assertCount(1, $rows);
		$this->assertSame('a9', $rows[0]->getId());
		$this->assertSame(
			['select(*)', 'from(keepiq_honey_alerts)', 'orderBy(accessed_at, DESC)', 'setMaxResults(10)'],
			$this->opsOf(0)
		);
		$this->assertSame([], $this->paramsOf(0));
		$this->assertSame('setMaxResults(200)', $this->opsOf(1)[3]);
	}//end testFindAllIsUnfilteredNewestFirst()

	/**
	 * A named accessor matches on accessor_id equality; the newest row wins.
	 *
	 * @return void
	 */
	public function testFindLatestForNamedAccessor(): void {
		$this->queueRows([['id' => 'a5', 'accessor_type' => 'user', 'accessor_id' => 'bob']]);

		$alert = $this->mapper()->findLatestForAccessor(
			honeyFlagId: 'f1',
			accessorType: 'user',
			accessorId: 'bob',
			channel: 'web'
		);

		$this->assertNotNull($alert);
		$this->assertSame('a5', $alert->getId());
		$this->assertSame('bob', $alert->getAccessorId());
		$this->assertSame(
			[
				'select(*)', 'from(keepiq_honey_alerts)', 'where(eq(honey_flag_id, :p0))',
				'andWhere(eq(accessor_type, :p1))', 'andWhere(eq(channel, :p2))', 'andWhere(eq(accessor_id, :p3))',
				'orderBy(accessed_at, DESC)', 'setMaxResults(1)',
			],
			$this->opsOf(0)
		);
		$this->assertSame([':p0' => 'f1', ':p1' => 'user', ':p2' => 'web', ':p3' => 'bob'], $this->paramsOf(0));
	}//end testFindLatestForNamedAccessor()

	/**
	 * An anonymous accessor matches on IS NULL; no rows answers null.
	 *
	 * @return void
	 */
	public function testFindLatestForAnonymousAccessorAnswersNullWhenNone(): void {
		$alert = $this->mapper()->findLatestForAccessor(
			honeyFlagId: 'f1',
			accessorType: 'anonymous',
			accessorId: null,
			channel: 'link'
		);

		$this->assertNull($alert);
		$this->assertSame('andWhere(isNull(accessor_id))', $this->opsOf(0)[5]);
		$this->assertSame([':p0' => 'f1', ':p1' => 'anonymous', ':p2' => 'link'], $this->paramsOf(0));
	}//end testFindLatestForAnonymousAccessorAnswersNullWhenNone()

	/**
	 * The unacknowledged count reads the cnt column.
	 *
	 * @return void
	 */
	public function testCountUnacknowledged(): void {
		$this->queueRows([['cnt' => '7']]);

		$this->assertSame(7, $this->mapper()->countUnacknowledged());
		$this->assertSame(
			['select(count(*, cnt))', 'from(keepiq_honey_alerts)', 'where(isNull(acknowledged_at))'],
			$this->opsOf(0)
		);
		$this->assertSame('query', $this->ranOf(0));
	}//end testCountUnacknowledged()

	/**
	 * An empty result counts as zero.
	 *
	 * @return void
	 */
	public function testCountUnacknowledgedWithNoRowIsZero(): void {
		$this->assertSame(0, $this->mapper()->countUnacknowledged());
	}//end testCountUnacknowledgedWithNoRowIsZero()
}//end class
