<?php

/**
 * QuarantinedShareTargetMapper against a recording connection.
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

use OCA\Keepiq\Db\QuarantinedShareTargetMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCP\AppFramework\Db\DoesNotExistException;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The quarantined-only share-target listings: all, by id, by source secret and by target user.
 *
 * @covers \OCA\Keepiq\Db\QuarantinedShareTargetMapper
 * @uses   \OCA\Keepiq\Db\ShareTarget
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class QuarantinedShareTargetMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over a recording connection.
	 *
	 * @return QuarantinedShareTargetMapper
	 */
	private function mapper(): QuarantinedShareTargetMapper {
		return new QuarantinedShareTargetMapper($this->recordingConnection());
	}//end mapper()

	/**
	 * All quarantined targets, oldest quarantine first, then by id.
	 *
	 * @return void
	 */
	public function testFindQuarantinedOrdersOldestFirst(): void {
		$this->queueRows([['id' => 't1'], ['id' => 't2']]);

		$rows = $this->mapper()->findQuarantined();

		$this->assertSame(['t1', 't2'], array_map(static fn (ShareTarget $t) => $t->getId(), $rows));
		$this->assertSame(
			['select(*)', 'from(keepiq_share_targets)', 'where(isNotNull(quarantined_at))', 'orderBy(quarantined_at, ASC)', 'addOrderBy(id, ASC)'],
			$this->opsOf(0)
		);
	}//end testFindQuarantinedOrdersOldestFirst()

	/**
	 * A target is found by id only while quarantined.
	 *
	 * @return void
	 */
	public function testFindQuarantinedById(): void {
		$this->queueRows([['id' => 't1']]);

		$this->assertSame('t1', $this->mapper()->findQuarantinedById(id: 't1')->getId());
		$this->assertSame(
			['select(*)', 'from(keepiq_share_targets)', 'where(eq(id, :p0))', 'andWhere(isNotNull(quarantined_at))'],
			$this->opsOf(0)
		);
		$this->assertSame([':p0' => 't1'], $this->paramsOf(0));
	}//end testFindQuarantinedById()

	/**
	 * A target that is not quarantined is not found.
	 *
	 * @return void
	 */
	public function testFindQuarantinedByIdThrowsWhenNotQuarantined(): void {
		$this->expectException(DoesNotExistException::class);

		$this->mapper()->findQuarantinedById(id: 't9');
	}//end testFindQuarantinedByIdThrowsWhenNotQuarantined()

	/**
	 * Quarantined targets of one source secret, and of one target user.
	 *
	 * @return void
	 */
	public function testFindQuarantinedBySourceSecretAndByTargetUser(): void {
		$this->queueRows([['id' => 't1']]);
		$this->queueRows([['id' => 't2'], ['id' => 't3']]);
		$mapper = $this->mapper();

		$this->assertCount(1, $mapper->findQuarantinedBySourceSecret(sourceSecretId: 's1'));
		$this->assertCount(2, $mapper->findQuarantinedByTargetUser(targetUserId: 'bob'));

		$this->assertSame(
			['select(*)', 'from(keepiq_share_targets)', 'where(eq(source_secret_id, :p0))', 'andWhere(isNotNull(quarantined_at))'],
			$this->opsOf(0)
		);
		$this->assertSame([':p0' => 's1'], $this->paramsOf(0));
		$this->assertSame(
			['select(*)', 'from(keepiq_share_targets)', 'where(eq(target_user_id, :p1))', 'andWhere(isNotNull(quarantined_at))'],
			$this->opsOf(1)
		);
		$this->assertSame([':p1' => 'bob'], $this->paramsOf(1));
	}//end testFindQuarantinedBySourceSecretAndByTargetUser()
}//end class
