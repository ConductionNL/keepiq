<?php

/**
 * GroupShareMapper against a recording connection.
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
use OCA\Keepiq\Db\GroupShare;
use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The SQL each GroupShareMapper query builds: shares by id, secret, group and
 * the one share of a secret with a group; the delete of a secret's shares;
 * and the expiring-grant window, open at the start when no lower bound is
 * given.
 *
 * @covers \OCA\Keepiq\Db\GroupShareMapper
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @covers \OCA\Keepiq\Db\ExpiringGrantQueries
 * @uses   \OCA\Keepiq\Db\GroupShare
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class GroupShareMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over the recording connection.
	 *
	 * @return GroupShareMapper
	 */
	private function mapper(): GroupShareMapper {
		return new GroupShareMapper(db: $this->recordingConnection());
	}//end mapper()

	/**
	 * By id hydrates the row; no row throws.
	 *
	 * @return void
	 */
	public function testFindById(): void {
		$this->queueRows(rows: [['id' => 'g1', 'secret_id' => 's1', 'group_id' => 'admins']]);
		$mapper = $this->mapper();

		$share = $mapper->findById(id: 'g1');

		$this->assertSame(expected: 'g1', actual: $share->getId());
		$this->assertSame(expected: 'admins', actual: $share->getGroupId());
		$this->assertSame(expected: ['select(*)', 'from(keepiq_group_shares)', 'where(eq(id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'g1'], actual: $this->paramsOf(index: 0));

		$this->expectException(exception: DoesNotExistException::class);
		$mapper->findById(id: 'gone');
	}//end testFindById()

	/**
	 * A secret's and a group's shares each filter on their column.
	 *
	 * @return void
	 */
	public function testListsBySecretAndByGroup(): void {
		$this->queueRows(rows: [['id' => 'g1'], ['id' => 'g2']]);
		$this->queueRows(rows: [['id' => 'g3', 'secret_id' => 's9']]);
		$mapper = $this->mapper();

		$bySecret = $mapper->findBySecret(secretId: 's1');
		$byGroup = $mapper->findByGroup(groupId: 'admins');

		$this->assertSame(expected: ['g1', 'g2'], actual: array_map(static fn (GroupShare $g): string => $g->getId(), $bySecret));
		$this->assertSame(expected: 's9', actual: $byGroup[0]->getSecretId());
		$this->assertSame(expected: ['select(*)', 'from(keepiq_group_shares)', 'where(eq(secret_id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: ['select(*)', 'from(keepiq_group_shares)', 'where(eq(group_id, :p1))'], actual: $this->opsOf(index: 1));
		$this->assertSame(expected: [':p0' => 's1'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: [':p1' => 'admins'], actual: $this->paramsOf(index: 1));
	}//end testListsBySecretAndByGroup()

	/**
	 * The share of a secret with a group filters on both; none throws.
	 *
	 * @return void
	 */
	public function testFindBySecretAndGroup(): void {
		$this->queueRows(rows: [['id' => 'g4', 'secret_id' => 's1', 'group_id' => 'admins']]);
		$mapper = $this->mapper();

		$this->assertSame(expected: 'g4', actual: $mapper->findBySecretAndGroup(secretId: 's1', groupId: 'admins')->getId());
		$this->assertSame(
			expected: ['select(*)', 'from(keepiq_group_shares)', 'where(eq(secret_id, :p0))', 'andWhere(eq(group_id, :p1))'],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 's1', ':p1' => 'admins'], actual: $this->paramsOf(index: 0));

		$this->expectException(exception: DoesNotExistException::class);
		$mapper->findBySecretAndGroup(secretId: 's1', groupId: 'staff');
	}//end testFindBySecretAndGroup()

	/**
	 * Two shares of one secret with one group is ambiguous.
	 *
	 * @return void
	 */
	public function testFindBySecretAndGroupRefusesTwoRows(): void {
		$this->queueRows(rows: [['id' => 'g4'], ['id' => 'g5']]);

		$this->expectException(exception: MultipleObjectsReturnedException::class);
		$this->mapper()->findBySecretAndGroup(secretId: 's1', groupId: 'admins');
	}//end testFindBySecretAndGroupRefusesTwoRows()

	/**
	 * Deleting a secret's shares runs one scoped statement.
	 *
	 * @return void
	 */
	public function testDeleteBySecretRunsAScopedStatement(): void {
		$this->mapper()->deleteBySecret(secretId: 's1');

		$this->assertSame(expected: ['delete(keepiq_group_shares)', 'where(eq(secret_id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 's1'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'statement', actual: $this->ranOf(index: 0));
	}//end testDeleteBySecretRunsAScopedStatement()

	/**
	 * The ending window is bounded above, and below only when a start is given.
	 *
	 * @return void
	 */
	public function testEndingBetweenBoundsTheWindow(): void {
		$this->queueRows(rows: [['id' => 'g6']]);
		$from = new DateTime();
		$to = new DateTime();
		$mapper = $this->mapper();

		$ending = $mapper->findEndingBetween(from: null, to: $to);
		$mapper->findEndingBetween(from: $from, to: $to);

		$this->assertSame(expected: 'g6', actual: $ending[0]->getId());
		$this->assertSame(
			expected: ['select(*)', 'from(keepiq_group_shares)', 'where(isNotNull(expires_at))', 'andWhere(lte(expires_at, :p0))'],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => $to], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'andWhere(gt(expires_at, :p2))', actual: $this->opsOf(index: 1)[4]);
		$this->assertSame(expected: [':p1' => $to, ':p2' => $from], actual: $this->paramsOf(index: 1));
		$this->assertSame(expected: IQueryBuilder::PARAM_DATETIME_MUTABLE, actual: $this->queries[1]['types'][':p2']);
	}//end testEndingBetweenBoundsTheWindow()
}//end class
