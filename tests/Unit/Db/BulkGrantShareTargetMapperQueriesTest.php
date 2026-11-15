<?php

/**
 * BulkGrantShareTargetMapper against a recording connection.
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

use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\BulkGrantShareTargetMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The SQL each BulkGrantShareTargetMapper query builds: the live (unquarantined) targets of
 * a group share, of a team folder and of one user in a team folder, and the group-share
 * cascade, which removes quarantined targets too.
 *
 * @covers \OCA\Keepiq\Db\BulkGrantShareTargetMapper
 * @uses   \OCA\Keepiq\Db\ShareTarget
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class BulkGrantShareTargetMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over the recording connection.
	 *
	 * @return BulkGrantShareTargetMapper
	 */
	private function mapper(): BulkGrantShareTargetMapper {
		return new BulkGrantShareTargetMapper(db: $this->recordingConnection());
	}//end mapper()

	/**
	 * A group share's live targets; quarantined targets are excluded.
	 *
	 * @return void
	 */
	public function testFindByGroupShare(): void {
		$this->queueRows(rows: [['id' => 'r1'], ['id' => 'r2']]);

		$rows = $this->mapper()->findByGroupShare(groupShareId: 'gs1');

		$this->assertSame(expected: ['r1', 'r2'], actual: array_map(static fn (ShareTarget $e): string => $e->getId(), $rows));
		$this->assertSame(
			expected: [
				'select(*)', 'from(keepiq_share_targets)', 'where(eq(group_share_id, :p0))',
				'andWhere(isNull(quarantined_at))'
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'gs1'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'query', actual: $this->ranOf(index: 0));
	}//end testFindByGroupShare()

	/**
	 * The cascade deletes every target of the group share, quarantined or not.
	 *
	 * @return void
	 */
	public function testDeleteByGroupShare(): void {
		$this->mapper()->deleteByGroupShare(groupShareId: 'gs1');

		$this->assertSame(
			expected: [
				'delete(keepiq_share_targets)', 'where(eq(group_share_id, :p0))'
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'gs1'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'statement', actual: $this->ranOf(index: 0));
	}//end testDeleteByGroupShare()

	/**
	 * A team folder's live targets.
	 *
	 * @return void
	 */
	public function testFindByTeamFolder(): void {
		$this->queueRows(rows: [['id' => 'r1'], ['id' => 'r2']]);

		$rows = $this->mapper()->findByTeamFolder(teamFolderId: 'tf1');

		$this->assertSame(expected: ['r1', 'r2'], actual: array_map(static fn (ShareTarget $e): string => $e->getId(), $rows));
		$this->assertSame(
			expected: [
				'select(*)', 'from(keepiq_share_targets)', 'where(eq(team_folder_id, :p0))',
				'andWhere(isNull(quarantined_at))'
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'tf1'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'query', actual: $this->ranOf(index: 0));
	}//end testFindByTeamFolder()

	/**
	 * One user's live targets in a team folder.
	 *
	 * @return void
	 */
	public function testFindByTeamFolderAndTargetUser(): void {
		$this->queueRows(rows: [['id' => 'r1'], ['id' => 'r2']]);

		$rows = $this->mapper()->findByTeamFolderAndTargetUser(teamFolderId: 'tf1', targetUserId: 'alice');

		$this->assertSame(expected: ['r1', 'r2'], actual: array_map(static fn (ShareTarget $e): string => $e->getId(), $rows));
		$this->assertSame(
			expected: [
				'select(*)', 'from(keepiq_share_targets)', 'where(eq(team_folder_id, :p0))',
				'andWhere(eq(target_user_id, :p1))', 'andWhere(isNull(quarantined_at))'
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'tf1', ':p1' => 'alice'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'query', actual: $this->ranOf(index: 0));
	}//end testFindByTeamFolderAndTargetUser()
}//end class
