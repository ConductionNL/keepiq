<?php

/**
 * Unit tests for SecretTagMapper's queries and the tag replace transaction.
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

use OCA\Keepiq\Db\SecretTagMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * SecretTagMapper against a recording connection.
 *
 * @covers \OCA\Keepiq\Db\SecretTagMapper
 * @uses   \OCA\Keepiq\Db\SecretTag
 */
class SecretTagMapperTest extends TestCase {
	use RecordingDatabase;

	/**
	 * Tags are grouped per secret, one query per 500 unique ids, scoped to the owner.
	 *
	 * @return void
	 */
	public function testFindTagsBySecretIdsGroupsPerSecretAndChunks(): void {
		$ids = [];
		for ($i = 0; $i < 501; $i++) {
			$ids[] = 's' . $i;
		}

		$ids[] = 's0';
		$this->queueRows([['secret_id' => 's0', 'tag' => 'a'], ['secret_id' => 's0', 'tag' => 'b'], ['secret_id' => 's7', 'tag' => 'a']]);
		$this->queueRows([['secret_id' => 's500', 'tag' => 'z']]);

		$tags = (new SecretTagMapper($this->recordingConnection()))->findTagsBySecretIds(ownerId: 'alice', secretIds: $ids);

		$this->assertSame(['s0' => ['a', 'b'], 's7' => ['a'], 's500' => ['z']], $tags);
		$this->assertCount(2, $this->queries);
		$this->assertSame(
			['select(secret_id, tag)', 'from(keepiq_secret_tags)', 'where(eq(owner_id, :p0))', 'andWhere(in(secret_id, :p1))', 'orderBy(tag, ASC)'],
			$this->opsOf(0)
		);
		$this->assertSame('alice', $this->paramsOf(0)[':p0']);
		$this->assertCount(500, $this->paramsOf(0)[':p1']);
		$this->assertSame(IQueryBuilder::PARAM_STR_ARRAY, $this->queries[0]['types'][':p1']);
		$this->assertSame(['s500'], $this->paramsOf(1)[':p3']);
	}//end testFindTagsBySecretIdsGroupsPerSecretAndChunks()

	/**
	 * No ids, no query.
	 *
	 * @return void
	 */
	public function testNoIdsRunsNoQuery(): void {
		$this->assertSame([], (new SecretTagMapper($this->recordingConnection()))->findTagsBySecretIds(ownerId: 'alice', secretIds: []));
		$this->assertSame([], $this->queries);
	}//end testNoIdsRunsNoQuery()

	/**
	 * Replacing deletes the secret's tags, inserts each new one, and commits.
	 *
	 * @return void
	 */
	public function testReplaceForSecretDeletesInsertsAndCommits(): void {
		(new SecretTagMapper($this->recordingConnection()))->replaceForSecret(secretId: 's1', ownerId: 'alice', tags: ['work', 'home']);

		$this->assertSame(['beginTransaction', 'commit'], $this->connectionCalls);
		$this->assertSame(['delete(keepiq_secret_tags)', 'where(eq(secret_id, :p0))'], $this->opsOf(0));
		$this->assertSame([':p0' => 's1'], $this->paramsOf(0));
		$this->assertCount(3, $this->queries);
		$this->assertSame('insert(keepiq_secret_tags)', $this->opsOf(1)[0]);
		$this->assertSame(['s1', 'alice', 'work'], array_values($this->paramsOf(1)));
		$this->assertSame(['s1', 'alice', 'home'], array_values($this->paramsOf(2)));
	}//end testReplaceForSecretDeletesInsertsAndCommits()

	/**
	 * A failing write rolls back and rethrows the same exception.
	 *
	 * @return void
	 */
	public function testReplaceForSecretRollsBackOnFailure(): void {
		$db = $this->recordingConnection();
		$db->method('getQueryBuilder')->willThrowException(new RuntimeException('db down'));
		try {
			(new SecretTagMapper($db))->replaceForSecret(secretId: 's1', ownerId: 'alice', tags: ['x']);
			$this->fail('no exception');
		} catch (RuntimeException $e) {
			$this->assertSame('db down', $e->getMessage());
		}

		$this->assertSame(['beginTransaction', 'rollBack'], $this->connectionCalls);
	}//end testReplaceForSecretRollsBackOnFailure()

	/**
	 * Tag counts count only the owner's live user secrets, grouped by tag.
	 *
	 * @return void
	 */
	public function testCountByOwnerCountsLiveUserSecrets(): void {
		$this->queueRows([['tag' => 'home', 'cnt' => '2'], ['tag' => 'work', 'cnt' => 5]]);

		$counts = (new SecretTagMapper($this->recordingConnection()))->countByOwner(ownerId: 'alice');

		$this->assertSame([['tag' => 'home', 'count' => 2], ['tag' => 'work', 'count' => 5]], $counts);
		$this->assertSame(
			[
				'select(t.tag)',
				'selectAlias(count(t.id), cnt)',
				'from(keepiq_secret_tags, t)',
				'innerJoin(t, keepiq_secrets, s, eq(s.id, t.secret_id))',
				'where(eq(t.owner_id, :p0))',
				'andWhere(eq(s.owner_type, :p1))',
				'andWhere(eq(s.owner_id, :p2))',
				'andWhere(isNull(s.trashed_at))',
				'andWhere(isNull(s.archived_at))',
				'groupBy(t.tag)',
				'orderBy(t.tag, ASC)',
			],
			$this->opsOf(0)
		);
		$this->assertSame([':p0' => 'alice', ':p1' => 'user', ':p2' => 'alice'], $this->paramsOf(0));
	}//end testCountByOwnerCountsLiveUserSecrets()

	/**
	 * The two deletes target the right column and return the affected count.
	 *
	 * @return void
	 */
	public function testDeletesReturnAffectedRows(): void {
		$this->affected = [3, 4];
		$mapper = new SecretTagMapper($this->recordingConnection());

		$this->assertSame(3, $mapper->deleteBySecret(secretId: 's9'));
		$this->assertSame(4, $mapper->deleteByOwner(ownerId: 'bob'));
		$this->assertSame(['delete(keepiq_secret_tags)', 'where(eq(secret_id, :p0))'], $this->opsOf(0));
		$this->assertSame(['delete(keepiq_secret_tags)', 'where(eq(owner_id, :p1))'], $this->opsOf(1));
		$this->assertSame('statement', $this->ranOf(1));
		$this->assertSame([':p1' => 'bob'], $this->paramsOf(1));
	}//end testDeletesReturnAffectedRows()
}//end class
