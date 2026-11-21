<?php

/**
 * SecretVersionMapper against a recording connection.
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
use OCA\Keepiq\Db\SecretVersion;
use OCA\Keepiq\Db\SecretVersionMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The SQL each SecretVersionMapper query builds: newest-first listing with a
 * floor of one row, the 1-based monotonic next number, count and age
 * pruning through per-row deletes, the delete cascade and the prune sweep.
 *
 * @covers \OCA\Keepiq\Db\SecretVersionMapper
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @uses   \OCA\Keepiq\Db\QueryCount
 * @uses   \OCA\Keepiq\Db\SecretVersion
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 * @uses   \OCA\Keepiq\Db\SuiteOwnerRef
 */
class SecretVersionMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over the recording connection.
	 *
	 * @return SecretVersionMapper
	 */
	private function mapper(): SecretVersionMapper {
		return new SecretVersionMapper(db: $this->recordingConnection());
	}//end mapper()

	/**
	 * A version row as the table holds it.
	 *
	 * @param string $id     The row id
	 * @param int    $number The version number
	 *
	 * @return array<string,mixed>
	 */
	private function row(string $id, int $number): array {
		return ['id' => $id, 'secret_id' => 's1', 'version_number' => $number, 'encryption_suite_id' => 'suite-1'];
	}//end row()

	/**
	 * Ids of the hydrated versions.
	 *
	 * @param SecretVersion[] $versions The versions
	 *
	 * @return string[]
	 */
	private function ids(array $versions): array {
		return array_map(static fn (SecretVersion $version) => $version->getId(), $versions);
	}//end ids()

	/**
	 * The lookup by id hydrates the single row.
	 *
	 * @return void
	 */
	public function testFindByIdHydratesTheRow(): void {
		$this->queueRows(rows: [$this->row(id: 'v1', number: 4)]);

		$found = $this->mapper()->findById(id: 'v1');

		$this->assertSame(expected: 'v1', actual: $found->getId());
		$this->assertSame(expected: 4, actual: $found->getVersionNumber());
		$this->assertSame(expected: 's1', actual: $found->getSecretId());
		$this->assertSame(expected: ['select(*)', 'from(keepiq_secret_versions)', 'where(eq(id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'v1'], actual: $this->paramsOf(index: 0));
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
	 * The listing is newest first and bounded by the limit.
	 *
	 * @return void
	 */
	public function testFindBySecretIsNewestFirstAndBounded(): void {
		$this->queueRows(rows: [$this->row(id: 'v2', number: 2), $this->row(id: 'v1', number: 1)]);

		$found = $this->mapper()->findBySecret(secretId: 's1', limit: 25);

		$this->assertSame(expected: ['v2', 'v1'], actual: $this->ids(versions: $found));
		$this->assertSame(
			expected: [
				'select(*)', 'from(keepiq_secret_versions)', 'where(eq(secret_id, :p0))',
				'orderBy(version_number, DESC)', 'setMaxResults(25)',
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 's1'], actual: $this->paramsOf(index: 0));
	}//end testFindBySecretIsNewestFirstAndBounded()

	/**
	 * A non-positive limit is raised to one row; the default is 200.
	 *
	 * @return void
	 */
	public function testFindBySecretLimitFloorAndDefault(): void {
		$mapper = $this->mapper();

		$mapper->findBySecret(secretId: 's1', limit: 0);
		$mapper->findBySecret(secretId: 's1');

		$this->assertSame(expected: 'setMaxResults(1)', actual: $this->opsOf(index: 0)[4]);
		$this->assertSame(expected: 'setMaxResults(200)', actual: $this->opsOf(index: 1)[4]);
	}//end testFindBySecretLimitFloorAndDefault()

	/**
	 * The next number is the max plus one.
	 *
	 * @return void
	 */
	public function testNextVersionNumberIsMaxPlusOne(): void {
		$this->queueRows(rows: [['max' => '7']]);

		$this->assertSame(expected: 8, actual: $this->mapper()->nextVersionNumber(secretId: 's1'));

		$this->assertSame(
			expected: ['select(max(version_number))', 'from(keepiq_secret_versions)', 'where(eq(secret_id, :p0))'],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 's1'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'query', actual: $this->ranOf(index: 0));
	}//end testNextVersionNumberIsMaxPlusOne()

	/**
	 * A secret without versions starts at one, whether no row or a NULL max comes back.
	 *
	 * @return void
	 */
	public function testNextVersionNumberStartsAtOne(): void {
		$this->queueRows(rows: []);
		$this->queueRows(rows: [['max' => null]]);
		$mapper = $this->mapper();

		$this->assertSame(expected: 1, actual: $mapper->nextVersionNumber(secretId: 's1'));
		$this->assertSame(expected: 1, actual: $mapper->nextVersionNumber(secretId: 's2'));
	}//end testNextVersionNumberStartsAtOne()

	/**
	 * Count pruning keeps the newest N and deletes each older row by id.
	 *
	 * @return void
	 */
	public function testPruneByCountDeletesBeyondTheKept(): void {
		$this->queueRows(
			rows: [$this->row(id: 'v3', number: 3), $this->row(id: 'v2', number: 2), $this->row(id: 'v1', number: 1)]
		);

		$this->assertSame(expected: 2, actual: $this->mapper()->pruneByCount(secretId: 's1', keep: 1));

		$this->assertCount(expectedCount: 3, haystack: $this->queries);
		$this->assertSame(expected: 'setMaxResults(100000)', actual: $this->opsOf(index: 0)[4]);
		$this->assertSame(expected: ['delete(keepiq_secret_versions)', 'where(eq(id, :p1))'], actual: $this->opsOf(index: 1));
		$this->assertSame(expected: [':p1' => 'v2'], actual: $this->paramsOf(index: 1));
		$this->assertSame(expected: IQueryBuilder::PARAM_STR, actual: $this->queries[1]['types'][':p1']);
		$this->assertSame(expected: 'statement', actual: $this->ranOf(index: 1));
		$this->assertSame(expected: ['delete(keepiq_secret_versions)', 'where(eq(id, :p2))'], actual: $this->opsOf(index: 2));
		$this->assertSame(expected: [':p2' => 'v1'], actual: $this->paramsOf(index: 2));
	}//end testPruneByCountDeletesBeyondTheKept()

	/**
	 * Within the retention count nothing is deleted.
	 *
	 * @return void
	 */
	public function testPruneByCountWithinRetentionDeletesNothing(): void {
		$this->queueRows(rows: [$this->row(id: 'v2', number: 2), $this->row(id: 'v1', number: 1)]);

		$this->assertSame(expected: 0, actual: $this->mapper()->pruneByCount(secretId: 's1', keep: 2));

		$this->assertCount(expectedCount: 1, haystack: $this->queries);
	}//end testPruneByCountWithinRetentionDeletesNothing()

	/**
	 * Age pruning reads a bounded batch older than the cutoff and deletes each.
	 *
	 * @return void
	 */
	public function testPruneOlderThanDeletesTheBatch(): void {
		$cutoff = new DateTime('2026-01-01T00:00:00+00:00');
		$this->queueRows(rows: [$this->row(id: 'v1', number: 1), $this->row(id: 'v9', number: 1)]);

		$this->assertSame(expected: 2, actual: $this->mapper()->pruneOlderThan(cutoff: $cutoff, limit: 50));

		$this->assertSame(
			expected: ['select(*)', 'from(keepiq_secret_versions)', 'where(lt(created_at, :p0))', 'setMaxResults(50)'],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: $cutoff, actual: $this->paramsOf(index: 0)[':p0']);
		$this->assertSame(expected: 'datetime', actual: $this->queries[0]['types'][':p0']);
		$this->assertSame(expected: [':p1' => 'v1'], actual: $this->paramsOf(index: 1));
		$this->assertSame(expected: [':p2' => 'v9'], actual: $this->paramsOf(index: 2));
		$this->assertSame(expected: 'statement', actual: $this->ranOf(index: 2));
	}//end testPruneOlderThanDeletesTheBatch()

	/**
	 * Nothing old enough prunes nothing; the limit has a floor of one and defaults to 500.
	 *
	 * @return void
	 */
	public function testPruneOlderThanWithNothingOld(): void {
		$mapper = $this->mapper();
		$cutoff = new DateTime('2026-01-01T00:00:00+00:00');

		$this->assertSame(expected: 0, actual: $mapper->pruneOlderThan(cutoff: $cutoff, limit: -3));
		$this->assertSame(expected: 0, actual: $mapper->pruneOlderThan(cutoff: $cutoff));

		$this->assertCount(expectedCount: 2, haystack: $this->queries);
		$this->assertSame(expected: 'setMaxResults(1)', actual: $this->opsOf(index: 0)[3]);
		$this->assertSame(expected: 'setMaxResults(500)', actual: $this->opsOf(index: 1)[3]);
	}//end testPruneOlderThanWithNothingOld()

	/**
	 * The delete cascade runs one DELETE keyed by secret.
	 *
	 * @return void
	 */
	public function testDeleteBySecretRunsOneDelete(): void {
		$this->mapper()->deleteBySecret(secretId: 's1');

		$this->assertCount(expectedCount: 1, haystack: $this->queries);
		$this->assertSame(expected: ['delete(keepiq_secret_versions)', 'where(eq(secret_id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 's1'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'statement', actual: $this->ranOf(index: 0));
	}//end testDeleteBySecretRunsOneDelete()

	/**
	 * The prune sweep reads distinct secret ids, bounded.
	 *
	 * @return void
	 */
	public function testFindSecretIdsWithVersions(): void {
		$this->queueRows(rows: [['secret_id' => 's1'], ['secret_id' => 's2']]);
		$mapper = $this->mapper();

		$this->assertSame(expected: ['s1', 's2'], actual: $mapper->findSecretIdsWithVersions(limit: 10));
		$this->assertSame(expected: [], actual: $mapper->findSecretIdsWithVersions(limit: 0));
		$mapper->findSecretIdsWithVersions();

		$this->assertSame(expected: ['selectDistinct(secret_id)', 'from(keepiq_secret_versions)', 'setMaxResults(10)'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: 'setMaxResults(1)', actual: $this->opsOf(index: 1)[2]);
		$this->assertSame(expected: 'setMaxResults(1000)', actual: $this->opsOf(index: 2)[2]);
		$this->assertSame(expected: 'query', actual: $this->ranOf(index: 0));
	}//end testFindSecretIdsWithVersions()

	/**
	 * The suite-bound snapshots of one secret, newest first.
	 *
	 * @return void
	 */
	public function testFindBySecretAndSuite(): void {
		$this->queueRows(rows: [$this->row(id: 'v2', number: 2)]);

		$found = $this->mapper()->findBySecretAndSuite(secretId: 's1', encryptionSuiteId: 'suite-1');

		$this->assertSame(expected: ['v2'], actual: $this->ids(versions: $found));
		$this->assertSame(
			expected: [
				'select(*)', 'from(keepiq_secret_versions)', 'where(eq(secret_id, :p0))',
				'andWhere(eq(encryption_suite_id, :p1))', 'orderBy(version_number, DESC)',
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 's1', ':p1' => 'suite-1'], actual: $this->paramsOf(index: 0));
	}//end testFindBySecretAndSuite()
	/**
	 * The join every suite-for-owner query shares, from parameter $first on.
	 *
	 * @param int $first The first placeholder number
	 *
	 * @return list<string>
	 */
	private function joinedToOwner(int $first): array {
		return [
			'from(keepiq_secret_versions, v)',
			'innerJoin(v, keepiq_secrets, s, eq(v.secret_id, s.id))',
			'where(eq(v.encryption_suite_id, :p' . $first . '))',
			'andWhere(eq(s.owner_type, :p' . ($first + 1) . '))',
			'andWhere(eq(s.owner_id, :p' . ($first + 2) . '))',
		];
	}//end joinedToOwner()

	/**
	 * An owner's suite-bound versions are counted through the owning secret.
	 *
	 * @return void
	 */
	public function testCountBySuiteForOwnerJoinsTheSecret(): void {
		$this->queueRows(rows: [['cnt' => '4']]);

		$this->assertSame(expected: 4, actual: $this->mapper()->countBySuiteForOwner(encryptionSuiteId: 'suite-1', ownerType: 'user', ownerId: 'alice'));

		$this->assertSame(expected: array_merge(['select(count(*, cnt))'], $this->joinedToOwner(first: 0)), actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'suite-1', ':p1' => 'user', ':p2' => 'alice'], actual: $this->paramsOf(index: 0));
	}//end testCountBySuiteForOwnerJoinsTheSecret()

	/**
	 * Unaccounted versions are those whose owning secret has no migration error;
	 * no row counts as zero.
	 *
	 * @return void
	 */
	public function testCountUnaccountedSkipsSecretsWithAnError(): void {
		$this->queueRows(rows: []);

		$this->assertSame(
			expected: 0,
			actual: $this->mapper()->countUnaccountedBySuiteForOwner(encryptionSuiteId: 'suite-1', ownerType: 'user', ownerId: 'alice')
		);

		$this->assertSame(
			expected: array_merge(['select(count(*, cnt))'], $this->joinedToOwner(first: 0), ['andWhere(isNull(s.migration_error))']),
			actual: $this->opsOf(index: 0)
		);
	}//end testCountUnaccountedSkipsSecretsWithAnError()

	/**
	 * Version work is paged by distinct secret id, with floors on limit and offset.
	 *
	 * @return void
	 */
	public function testSecretIdsWithSuiteVersionsArePagedBySecret(): void {
		$this->queueRows(rows: [['secret_id' => 's1'], ['secret_id' => 's2']]);
		$mapper = $this->mapper();

		$this->assertSame(
			expected: ['s1', 's2'],
			actual: $mapper->findSecretIdsWithSuiteVersionsForOwner(
				encryptionSuiteId: 'suite-1',
				ownerType: 'user',
				ownerId: 'alice',
				limit: 0,
				offset: -4
			)
		);
		$second = $mapper->findSecretIdsWithSuiteVersionsForOwner(encryptionSuiteId: 'suite-1', ownerType: 'user', ownerId: 'alice');
		$this->assertSame(expected: [], actual: $second);

		$this->assertSame(
			expected: array_merge(
				['selectDistinct(v.secret_id)'],
				$this->joinedToOwner(first: 0),
				['orderBy(v.secret_id, ASC)', 'setMaxResults(1)', 'setFirstResult(0)']
			),
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: ['setMaxResults(100)', 'setFirstResult(0)'], actual: array_slice($this->opsOf(index: 1), -2));
	}//end testSecretIdsWithSuiteVersionsArePagedBySecret()
}//end class
