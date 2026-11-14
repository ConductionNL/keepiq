<?php

/**
 * ShareSaveStageMapper against a recording connection.
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
use OCA\Keepiq\Db\ShareSaveStage;
use OCA\Keepiq\Db\ShareSaveStageMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The live-by-stage listing and the four deletes, each returning the affected row count.
 *
 * @covers \OCA\Keepiq\Db\ShareSaveStageMapper
 * @uses   \OCA\Keepiq\Db\ShareSaveStage
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class ShareSaveStageMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over a recording connection.
	 *
	 * @return ShareSaveStageMapper
	 */
	private function mapper(): ShareSaveStageMapper {
		return new ShareSaveStageMapper($this->recordingConnection());
	}//end mapper()

	/**
	 * Live rows of a stage: not yet expired, oldest first.
	 *
	 * @return void
	 */
	public function testFindLiveByStage(): void {
		$now = new DateTime('2026-11-13T10:00:00+00:00');
		$this->queueRows([['id' => 'r1', 'stage_id' => 'st1']]);

		$rows = $this->mapper()->findLiveByStage(stageId: 'st1', now: $now);

		$this->assertSame(['r1'], array_map(static fn (ShareSaveStage $r) => $r->getId(), $rows));
		$this->assertSame(
			['select(*)', 'from(keepiq_share_save_stages)', 'where(eq(stage_id, :p0))', 'andWhere(gt(expires_at, :p1))', 'orderBy(created_at, ASC)'],
			$this->opsOf(0)
		);
		$this->assertSame([':p0' => 'st1', ':p1' => $now], $this->paramsOf(0));
		$this->assertSame(IQueryBuilder::PARAM_DATETIME_MUTABLE, $this->queries[0]['types'][':p1']);
	}//end testFindLiveByStage()

	/**
	 * Each delete is scoped as named and returns the affected count.
	 *
	 * @return void
	 */
	public function testDeletesAreScopedAndReturnTheCount(): void {
		$now = new DateTime('2026-11-13T10:00:00+00:00');
		$this->affected = [2, 3, 4, 5];
		$mapper = $this->mapper();

		$this->assertSame(2, $mapper->deleteByStage(stageId: 'st1'));
		$this->assertSame(3, $mapper->deleteByUserAndSecret(userId: 'alice', secretId: 's1'));
		$this->assertSame(4, $mapper->deleteByUser(userId: 'alice'));
		$this->assertSame(5, $mapper->deleteExpired(now: $now));

		$this->assertSame(['delete(keepiq_share_save_stages)', 'where(eq(stage_id, :p0))'], $this->opsOf(0));
		$this->assertSame(['delete(keepiq_share_save_stages)', 'where(eq(user_id, :p1))', 'andWhere(eq(secret_id, :p2))'], $this->opsOf(1));
		$this->assertSame([':p1' => 'alice', ':p2' => 's1'], $this->paramsOf(1));
		$this->assertSame(['delete(keepiq_share_save_stages)', 'where(eq(user_id, :p3))'], $this->opsOf(2));
		$this->assertSame(['delete(keepiq_share_save_stages)', 'where(lte(expires_at, :p4))'], $this->opsOf(3));
		$this->assertSame([':p4' => $now], $this->paramsOf(3));
	}//end testDeletesAreScopedAndReturnTheCount()
}//end class
