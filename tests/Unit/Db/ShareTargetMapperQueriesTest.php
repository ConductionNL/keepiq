<?php

/**
 * ShareTargetMapper against a recording connection.
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
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The SQL each ShareTargetMapper query builds. ShareTargetMapperQuarantineTest
 * proves the quarantine filter against a real database and is skipped without
 * one; this pins the same queries standalone: every read hides quarantined
 * rows, the deletes are unfiltered and scoped to the one column they name, the
 * direct-share probe excludes group and team-folder grants, and the suite
 * delete narrows to the suite's secrets through a subquery.
 *
 * @covers \OCA\Keepiq\Db\ShareTargetMapper
 * @covers \OCA\Keepiq\Db\ExpiringGrantQueries
 * @uses   \OCA\Keepiq\Db\ShareTarget
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class ShareTargetMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over the recording connection.
	 *
	 * @return ShareTargetMapper
	 */
	private function mapper(): ShareTargetMapper {
		return new ShareTargetMapper($this->recordingConnection());
	}//end mapper()

	/**
	 * A single-row lookup by id hides quarantined rows and hydrates the entity.
	 *
	 * @return void
	 */
	public function testFindByIdHidesQuarantinedRows(): void {
		$this->queueRows([['id' => 't1', 'source_secret_id' => 's1', 'target_user_id' => 'bob']]);

		$target = $this->mapper()->findById(id: 't1');

		$this->assertSame('bob', $target->getTargetUserId());
		$this->assertSame(
			['select(*)', 'from(keepiq_share_targets)', 'where(eq(id, :p0))', 'andWhere(isNull(quarantined_at))'],
			$this->opsOf(0)
		);
		$this->assertSame([':p0' => 't1'], $this->paramsOf(0));
	}//end testFindByIdHidesQuarantinedRows()

	/**
	 * No row is DoesNotExist.
	 *
	 * @return void
	 */
	public function testAMissingRowIsDoesNotExist(): void {
		$this->expectException(DoesNotExistException::class);

		$this->mapper()->findByRecipientSecret(recipientSecretId: 'copy-1');
	}//end testAMissingRowIsDoesNotExist()

	/**
	 * The list lookups filter on their own column and hide quarantined rows.
	 *
	 * @return void
	 */
	public function testListLookupsFilterOnTheirColumn(): void {
		$this->queueRows([['id' => 't1'], ['id' => 't2']]);
		$mapper = $this->mapper();

		$this->assertSame(['t1', 't2'], array_map(static fn ($t) => $t->getId(), $mapper->findBySourceSecret(sourceSecretId: 's1')));
		$mapper->findByTargetUser(targetUserId: 'bob');

		$this->assertSame(
			['select(*)', 'from(keepiq_share_targets)', 'where(eq(source_secret_id, :p0))', 'andWhere(isNull(quarantined_at))'],
			$this->opsOf(0)
		);
		$this->assertSame(
			['select(*)', 'from(keepiq_share_targets)', 'where(eq(target_user_id, :p1))', 'andWhere(isNull(quarantined_at))'],
			$this->opsOf(1)
		);
		$this->assertSame([':p1' => 'bob'], $this->paramsOf(1));
	}//end testListLookupsFilterOnTheirColumn()

	/**
	 * The pair lookup and the recipient-copy lookup both hide quarantined rows.
	 *
	 * @return void
	 */
	public function testPairAndRecipientCopyLookups(): void {
		$this->queueRows([['id' => 't1']]);
		$this->queueRows([['id' => 't2']]);
		$mapper = $this->mapper();

		$this->assertSame('t1', $mapper->findBySourceSecretAndTargetUser(sourceSecretId: 's1', targetUserId: 'bob')->getId());
		$this->assertSame('t2', $mapper->findByRecipientSecret(recipientSecretId: 'copy-1')->getId());

		$this->assertSame(
			[
				'select(*)', 'from(keepiq_share_targets)', 'where(eq(source_secret_id, :p0))',
				'andWhere(eq(target_user_id, :p1))', 'andWhere(isNull(quarantined_at))',
			],
			$this->opsOf(0)
		);
		$this->assertSame(
			['select(*)', 'from(keepiq_share_targets)', 'where(eq(secret_id, :p2))', 'andWhere(isNull(quarantined_at))'],
			$this->opsOf(1)
		);
	}//end testPairAndRecipientCopyLookups()

	/**
	 * A page is keyset-paginated after an id, ascending, bounded.
	 *
	 * @return void
	 */
	public function testFindPageIsKeysetPaginated(): void {
		$this->mapper()->findPage(after: 't5', limit: 25);

		$this->assertSame(
			[
				'select(*)', 'from(keepiq_share_targets)', 'where(gt(id, :p0))', 'andWhere(isNull(quarantined_at))',
				'orderBy(id, ASC)', 'setMaxResults(25)',
			],
			$this->opsOf(0)
		);
		$this->assertSame([':p0' => 't5'], $this->paramsOf(0));
	}//end testFindPageIsKeysetPaginated()

	/**
	 * A direct share exists only for a live row that is no group or team-folder grant.
	 *
	 * @return void
	 */
	public function testDirectShareProbeExcludesGroupAndFolderGrants(): void {
		$this->queueRows([['id' => 't1']]);
		$mapper = $this->mapper();

		$this->assertTrue($mapper->hasDirectShareBetween(createdBy: 'alice', targetUserId: 'bob'));
		$this->assertFalse($mapper->hasDirectShareBetween(createdBy: 'alice', targetUserId: 'carol'));

		$this->assertSame(
			[
				'select(id)', 'from(keepiq_share_targets)', 'where(eq(created_by, :p0))', 'andWhere(eq(target_user_id, :p1))',
				'andWhere(isNull(group_share_id))', 'andWhere(isNull(team_folder_id))', 'andWhere(isNull(quarantined_at))',
				'setMaxResults(1)',
			],
			$this->opsOf(0)
		);
	}//end testDirectShareProbeExcludesGroupAndFolderGrants()

	/**
	 * The deletes are scoped to one column and include quarantined rows.
	 *
	 * @return void
	 */
	public function testDeletesAreScopedAndUnfiltered(): void {
		$this->affected = [1, 3];
		$mapper = $this->mapper();

		$mapper->deleteBySourceSecret(sourceSecretId: 's1');
		$this->assertSame(3, $mapper->deleteByTargetUser(targetUserId: 'bob'));

		$this->assertSame(['delete(keepiq_share_targets)', 'where(eq(source_secret_id, :p0))'], $this->opsOf(0));
		$this->assertSame('statement', $this->ranOf(0));
		$this->assertSame(['delete(keepiq_share_targets)', 'where(eq(target_user_id, :p1))'], $this->opsOf(1));
	}//end testDeletesAreScopedAndUnfiltered()

	/**
	 * The suite delete narrows to the suite's secrets through a subquery.
	 *
	 * @return void
	 */
	public function testSuiteDeleteNarrowsThroughASubquery(): void {
		$this->mapper()->deleteByTargetUserAndSuite(targetUserId: 'bob', suiteId: 'suite-2');

		$this->assertSame(['select(id)', 'from(keepiq_secrets)', 'where(eq(encryption_suite_id, :p0))'], $this->opsOf(1));
		$this->assertSame(
			['delete(keepiq_share_targets)', 'where(eq(target_user_id, :p1))', 'andWhere(in(secret_id, SQL))'],
			$this->opsOf(0)
		);
		$this->assertSame([':p0' => 'suite-2', ':p1' => 'bob'], $this->paramsOf(0));
		$this->assertSame('statement', $this->ranOf(0));
	}//end testSuiteDeleteNarrowsThroughASubquery()

	/**
	 * Grants ending in a window are bounded on both sides and hide quarantined rows.
	 *
	 * @return void
	 */
	public function testEndingBetweenIsBoundedAndHidesQuarantinedRows(): void {
		$from = new DateTime('2026-01-01T00:00:00+00:00');
		$to = new DateTime('2026-01-08T00:00:00+00:00');
		$mapper = $this->mapper();

		$mapper->findEndingBetween(from: $from, to: $to);
		$mapper->findEndingBetween(from: null, to: $to);

		$this->assertSame(
			[
				'select(*)', 'from(keepiq_share_targets)', 'where(isNotNull(expires_at))', 'andWhere(lte(expires_at, :p0))',
				'andWhere(gt(expires_at, :p1))', 'andWhere(isNull(quarantined_at))',
			],
			$this->opsOf(0)
		);
		$this->assertSame(IQueryBuilder::PARAM_DATETIME_MUTABLE, $this->queries[0]['types'][':p1']);
		$this->assertSame(
			['select(*)', 'from(keepiq_share_targets)', 'where(isNotNull(expires_at))', 'andWhere(lte(expires_at, :p2))', 'andWhere(isNull(quarantined_at))'],
			$this->opsOf(1)
		);
	}//end testEndingBetweenIsBoundedAndHidesQuarantinedRows()
}//end class
