<?php

/**
 * SecretMapper against a recording connection.
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
use DateTimeZone;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The SQL the SecretMapper queries not pinned by the golden traces build:
 * id lookups (single and chunked), the optimistic version advance in UTC
 * seconds, the access-expiry window, the folder, type and owner cascades,
 * tombstoning, the per-holder favourite and last-used writes, and the
 * grouped per-user row count.
 *
 * @covers \OCA\Keepiq\Db\SecretMapper
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @uses   \OCA\Keepiq\Db\QueryCount
 * @uses   \OCA\Keepiq\Db\Secret
 * @uses   \OCA\Keepiq\Db\SecretListFilter
 * @uses   \OCA\Keepiq\Db\SecretListOrganisation
 * @uses   \OCA\Keepiq\Db\SecretStateFilter
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 * @uses   \OCA\Keepiq\Db\SuiteOwnerRef
 */
class SecretMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over the recording connection.
	 *
	 * @return SecretMapper
	 */
	private function mapper(): SecretMapper {
		return new SecretMapper(db: $this->recordingConnection());
	}//end mapper()

	/**
	 * A secret row as the table holds it.
	 *
	 * @param string $id The row id
	 *
	 * @return array<string,mixed>
	 */
	private function row(string $id): array {
		return ['id' => $id, 'name' => 'Bank ' . $id, 'owner_type' => 'user', 'owner_id' => 'alice'];
	}//end row()

	/**
	 * Ids of the hydrated secrets.
	 *
	 * @param Secret[] $secrets The secrets
	 *
	 * @return string[]
	 */
	private function ids(array $secrets): array {
		return array_values(array_map(static fn (Secret $secret) => $secret->getId(), $secrets));
	}//end ids()

	/**
	 * The lookup by id hydrates the single row.
	 *
	 * @return void
	 */
	public function testFindByIdHydratesTheRow(): void {
		$this->queueRows(rows: [$this->row(id: 's1')]);

		$found = $this->mapper()->findById(id: 's1');

		$this->assertSame(expected: 's1', actual: $found->getId());
		$this->assertSame(expected: 'Bank s1', actual: $found->getName());
		$this->assertSame(expected: 'alice', actual: $found->getOwnerId());
		$this->assertSame(expected: ['select(*)', 'from(keepiq_secrets)', 'where(eq(id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 's1'], actual: $this->paramsOf(index: 0));
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
		$this->queueRows(rows: [$this->row(id: 's1'), $this->row(id: 's1')]);

		$this->expectException(exception: MultipleObjectsReturnedException::class);

		$this->mapper()->findById(id: 's1');
	}//end testFindByIdThrowsOnDuplicates()

	/**
	 * The batch lookup deduplicates ids, keys the result by id and leaves
	 * unknown ids out.
	 *
	 * @return void
	 */
	public function testFindByIdsKeysByIdAndDeduplicates(): void {
		$this->queueRows(rows: [$this->row(id: 's2'), $this->row(id: 's1')]);

		$found = $this->mapper()->findByIds(ids: ['s1', 's2', 's1', 'gone']);

		$this->assertSame(expected: ['s2', 's1'], actual: array_keys($found));
		$this->assertSame(expected: 'Bank s1', actual: $found['s1']->getName());
		$this->assertCount(expectedCount: 1, haystack: $this->queries);
		$this->assertSame(expected: ['select(*)', 'from(keepiq_secrets)', 'where(in(id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => ['s1', 's2', 'gone']], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: IQueryBuilder::PARAM_STR_ARRAY, actual: $this->queries[0]['types'][':p0']);
	}//end testFindByIdsKeysByIdAndDeduplicates()

	/**
	 * More than 1000 ids are read in chunks of 1000; no ids runs no query.
	 *
	 * @return void
	 */
	public function testFindByIdsChunksAtAThousand(): void {
		$ids = array_map(static fn (int $n) => 'id-' . $n, range(1, 1001));
		$this->queueRows(rows: [$this->row(id: 'id-1')]);
		$this->queueRows(rows: [$this->row(id: 'id-1001')]);
		$mapper = $this->mapper();

		$found = $mapper->findByIds(ids: $ids);

		$this->assertSame(expected: ['id-1', 'id-1001'], actual: array_keys($found));
		$this->assertCount(expectedCount: 2, haystack: $this->queries);
		$this->assertCount(expectedCount: 1000, haystack: $this->paramsOf(index: 0)[':p0']);
		$this->assertSame(expected: [':p1' => ['id-1001']], actual: $this->paramsOf(index: 1));

		$this->assertSame(expected: [], actual: $mapper->findByIds(ids: []));
		$this->assertCount(expectedCount: 2, haystack: $this->queries);
	}//end testFindByIdsChunksAtAThousand()

	/**
	 * The version advance compares and sets updated_at in UTC whole seconds
	 * and answers the affected-row count.
	 *
	 * @return void
	 */
	public function testAdvanceVersionIsAConditionalUtcUpdate(): void {
		$this->affected = [1];
		$base = new DateTime('2026-03-01 12:00:00.750', new DateTimeZone('Europe/Amsterdam'));
		$savedAt = new DateTime('2026-03-01 14:30:05', new DateTimeZone('+02:00'));

		$this->assertSame(expected: 1, actual: $this->mapper()->advanceVersion(id: 's1', base: $base, savedAt: $savedAt));

		$this->assertSame(
			expected: ['update(keepiq_secrets)', 'set(updated_at, :p0)', 'where(eq(id, :p1))', 'andWhere(eq(updated_at, :p2))'],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(
			expected: [':p0' => '2026-03-01 12:30:05', ':p1' => 's1', ':p2' => '2026-03-01 11:00:00'],
			actual: $this->paramsOf(index: 0)
		);
		$this->assertSame(expected: 'statement', actual: $this->ranOf(index: 0));
		$this->assertSame(expected: 'Europe/Amsterdam', actual: $base->getTimezone()->getName());
	}//end testAdvanceVersionIsAConditionalUtcUpdate()

	/**
	 * A row that moved on answers zero.
	 *
	 * @return void
	 */
	public function testAdvanceVersionAnswersZeroWhenTheRowMoved(): void {
		$this->affected = [0];
		$moment = new DateTime('2026-03-01T00:00:00+00:00');

		$savedAt = new DateTime('2026-03-02T00:00:00+00:00');

		$this->assertSame(expected: 0, actual: $this->mapper()->advanceVersion(id: 's1', base: $moment, savedAt: $savedAt));
	}//end testAdvanceVersionAnswersZeroWhenTheRowMoved()

	/**
	 * Without a lower bound the window is every copy ended at or before `to`.
	 *
	 * @return void
	 */
	public function testAccessEndingUpToTo(): void {
		$to = new DateTime('2026-03-01T00:00:00+00:00');
		$this->queueRows(rows: [$this->row(id: 's1')]);

		$this->assertSame(expected: ['s1'], actual: $this->ids(secrets: $this->mapper()->findAccessEndingBetween(from: null, to: $to)));

		$this->assertSame(
			expected: [
				'select(*)', 'from(keepiq_secrets)', 'where(isNotNull(access_expires_at))',
				'andWhere(lte(access_expires_at, :p0))',
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => $to], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: IQueryBuilder::PARAM_DATETIME_MUTABLE, actual: $this->queries[0]['types'][':p0']);
	}//end testAccessEndingUpToTo()

	/**
	 * With a lower bound the window is (from, to].
	 *
	 * @return void
	 */
	public function testAccessEndingBetweenBounds(): void {
		$from = new DateTime('2026-03-01T00:00:00+00:00');
		$to = new DateTime('2026-03-02T00:00:00+00:00');

		$this->mapper()->findAccessEndingBetween(from: $from, to: $to);

		$this->assertSame(expected: 'andWhere(gt(access_expires_at, :p1))', actual: $this->opsOf(index: 0)[4]);
		$this->assertSame(expected: [':p0' => $to, ':p1' => $from], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: IQueryBuilder::PARAM_DATETIME_MUTABLE, actual: $this->queries[0]['types'][':p1']);
	}//end testAccessEndingBetweenBounds()

	/**
	 * An unknown sort column falls back to name and the expired copies are left out.
	 *
	 * @return void
	 */
	public function testFindByOwnerRefusesAnUnknownSortColumn(): void {
		$this->queueRows(rows: [$this->row(id: 's1')]);

		$found = $this->mapper()->findByOwner(
			ownerType: 'user',
			ownerId: 'alice',
			sort: 'key; DROP TABLE',
			direction: 'desc',
			limit: 10,
			offset: 20
		);

		$this->assertSame(expected: ['s1'], actual: $this->ids(secrets: $found));
		$ops = $this->opsOf(index: 0);
		$this->assertSame(expected: ['orderBy(name, DESC)', 'setMaxResults(10)', 'setFirstResult(20)'], actual: array_slice($ops, -3));
		$this->assertContains(needle: 'andWhere(orX(isNull(access_expires_at), gt(access_expires_at, :p2)))', haystack: $ops);
		$this->assertInstanceOf(expected: DateTime::class, actual: $this->paramsOf(index: 0)[':p2']);
		$this->assertSame(expected: IQueryBuilder::PARAM_DATETIME_MUTABLE, actual: $this->queries[0]['types'][':p2']);
	}//end testFindByOwnerRefusesAnUnknownSortColumn()

	/**
	 * An allow-listed sort column is used as given, with name as tie-break.
	 *
	 * @return void
	 */
	public function testFindByOwnerKeepsAnAllowedSortColumn(): void {
		$this->mapper()->findByOwner(ownerType: 'user', ownerId: 'alice', sort: 'created_at', direction: 'asc');

		$this->assertSame(
			expected: ['orderBy(created_at, ASC)', 'addOrderBy(name, ASC)', 'setMaxResults(1000)', 'setFirstResult(0)'],
			actual: array_slice($this->opsOf(index: 0), -4)
		);
	}//end testFindByOwnerKeepsAnAllowedSortColumn()

	/**
	 * The rotation scan pages user-owned rows by id.
	 *
	 * @return void
	 */
	public function testFindAllUserOwnedPaged(): void {
		$this->queueRows(rows: [$this->row(id: 's1'), $this->row(id: 's2')]);

		$this->assertSame(expected: ['s1', 's2'], actual: $this->ids(secrets: $this->mapper()->findAllUserOwnedPaged(limit: 50, offset: 100)));

		$this->assertSame(
			expected: [
				'select(*)', 'from(keepiq_secrets)', 'where(eq(owner_type, :p0))', 'orderBy(id, ASC)',
				'setMaxResults(50)', 'setFirstResult(100)',
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'user'], actual: $this->paramsOf(index: 0));
	}//end testFindAllUserOwnedPaged()

	/**
	 * Folder and suite listings key on their column.
	 *
	 * @return void
	 */
	public function testFindByFolderAndBySuite(): void {
		$this->queueRows(rows: [$this->row(id: 's1')]);
		$this->queueRows(rows: [$this->row(id: 's2'), $this->row(id: 's3')]);
		$mapper = $this->mapper();

		$this->assertSame(expected: ['s1'], actual: $this->ids(secrets: $mapper->findByFolderId(folderId: 'f1')));
		$this->assertSame(expected: ['s2', 's3'], actual: $this->ids(secrets: $mapper->findByEncryptionSuiteId(encryptionSuiteId: 'suite-1')));

		$this->assertSame(expected: ['select(*)', 'from(keepiq_secrets)', 'where(eq(folder_id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'f1'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: ['select(*)', 'from(keepiq_secrets)', 'where(eq(encryption_suite_id, :p1))'], actual: $this->opsOf(index: 1));
		$this->assertSame(expected: [':p1' => 'suite-1'], actual: $this->paramsOf(index: 1));
	}//end testFindByFolderAndBySuite()

	/**
	 * Re-parenting moves a folder's secrets to another folder or to the root.
	 *
	 * @return void
	 */
	public function testUpdateFolderForSecrets(): void {
		$mapper = $this->mapper();

		$mapper->updateFolderForSecrets(oldFolderId: 'f1', newFolderId: 'f2');
		$mapper->updateFolderForSecrets(oldFolderId: 'f1', newFolderId: null);

		$this->assertSame(expected: ['update(keepiq_secrets)', 'set(folder_id, :p0)', 'where(eq(folder_id, :p1))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'f2', ':p1' => 'f1'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'statement', actual: $this->ranOf(index: 0));
		$this->assertSame(expected: [':p2' => null, ':p3' => 'f1'], actual: $this->paramsOf(index: 1));
		$this->assertSame(expected: 'statement', actual: $this->ranOf(index: 1));
	}//end testUpdateFolderForSecrets()

	/**
	 * The folder cascade and the type fallback each run one statement.
	 *
	 * @return void
	 */
	public function testDeleteByFolderAndReassignType(): void {
		$mapper = $this->mapper();

		$mapper->deleteByFolderId(folderId: 'f1');
		$mapper->reassignType(oldTypeId: 'custom', newTypeId: 'login');

		$this->assertSame(expected: ['delete(keepiq_secrets)', 'where(eq(folder_id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'f1'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'statement', actual: $this->ranOf(index: 0));
		$this->assertSame(expected: ['update(keepiq_secrets)', 'set(type_id, :p1)', 'where(eq(type_id, :p2))'], actual: $this->opsOf(index: 1));
		$this->assertSame(expected: [':p1' => 'login', ':p2' => 'custom'], actual: $this->paramsOf(index: 1));
		$this->assertSame(expected: 'statement', actual: $this->ranOf(index: 1));
	}//end testDeleteByFolderAndReassignType()

	/**
	 * Tombstoning stamps the moment and the reason on the one row.
	 *
	 * @return void
	 */
	public function testTombstoneStampsMomentAndReason(): void {
		$this->mapper()->tombstone(secretId: 's1', reason: 'sharer_deleted');

		$this->assertSame(
			expected: ['update(keepiq_secrets)', 'set(tombstoned_at, :p0)', 'set(tombstone_reason, :p1)', 'where(eq(id, :p2))'],
			actual: $this->opsOf(index: 0)
		);
		$params = $this->paramsOf(index: 0);
		$this->assertMatchesRegularExpression(pattern: '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', string: $params[':p0']);
		$this->assertSame(expected: 'sharer_deleted', actual: $params[':p1']);
		$this->assertSame(expected: 's1', actual: $params[':p2']);
		$this->assertSame(expected: 'statement', actual: $this->ranOf(index: 0));
	}//end testTombstoneStampsMomentAndReason()

	/**
	 * Ownership transfer rewrites only the one row's owner.
	 *
	 * @return void
	 */
	public function testReassignOwner(): void {
		$this->mapper()->reassignOwner(secretId: 's1', newOwnerId: 'bob');

		$this->assertSame(expected: ['update(keepiq_secrets)', 'set(owner_id, :p0)', 'where(eq(id, :p1))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'bob', ':p1' => 's1'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'statement', actual: $this->ranOf(index: 0));
	}//end testReassignOwner()

	/**
	 * Starring is keyed by id and holder and answers the affected rows.
	 *
	 * @return void
	 */
	public function testSetFavouriteIsKeyedByHolder(): void {
		$this->affected = [1, 0];
		$mapper = $this->mapper();

		$this->assertSame(expected: 1, actual: $mapper->setFavourite(id: 's1', ownerId: 'alice', favourite: true));
		$this->assertSame(expected: 0, actual: $mapper->setFavourite(id: 's1', ownerId: 'mallory', favourite: false));

		$this->assertSame(
			expected: [
				'update(keepiq_secrets)', 'set(is_favourite, :p0)', 'where(eq(id, :p1))',
				'andWhere(eq(owner_type, :p2))', 'andWhere(eq(owner_id, :p3))',
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => true, ':p1' => 's1', ':p2' => 'user', ':p3' => 'alice'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: IQueryBuilder::PARAM_BOOL, actual: $this->queries[0]['types'][':p0']);
		$this->assertSame(expected: [':p4' => false, ':p5' => 's1', ':p6' => 'user', ':p7' => 'mallory'], actual: $this->paramsOf(index: 1));
	}//end testSetFavouriteIsKeyedByHolder()

	/**
	 * Marking used writes only last_used_at, keyed by holder.
	 *
	 * @return void
	 */
	public function testMarkUsedWritesOnlyLastUsed(): void {
		$this->affected = [1];
		$usedAt = new DateTime('2026-03-01 08:09:10');

		$this->assertSame(expected: 1, actual: $this->mapper()->markUsed(id: 's1', ownerId: 'alice', usedAt: $usedAt));

		$this->assertSame(
			expected: [
				'update(keepiq_secrets)', 'set(last_used_at, :p0)', 'where(eq(id, :p1))',
				'andWhere(eq(owner_type, :p2))', 'andWhere(eq(owner_id, :p3))',
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => '2026-03-01 08:09:10', ':p1' => 's1', ':p2' => 'user', ':p3' => 'alice'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: IQueryBuilder::PARAM_STR, actual: $this->queries[0]['types'][':p0']);
	}//end testMarkUsedWritesOnlyLastUsed()

	/**
	 * The per-user count is one grouped query over live user rows.
	 *
	 * @return void
	 */
	public function testCountByUserOwnersGroupsLiveRows(): void {
		$this->queueRows(rows: [['owner_id' => 'alice', 'row_count' => '3'], ['owner_id' => 'bob', 'row_count' => '1']]);

		$this->assertSame(expected: ['alice' => 3, 'bob' => 1], actual: $this->mapper()->countByUserOwners(ownerIds: ['alice', 'bob', 'carol']));

		$this->assertSame(
			expected: [
				'select(owner_id)', 'selectAlias(count(id), row_count)', 'from(keepiq_secrets)',
				'where(eq(owner_type, :p0))', 'andWhere(in(owner_id, :p1))', 'andWhere(isNull(tombstoned_at))',
				'groupBy(owner_id)',
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'user', ':p1' => ['alice', 'bob', 'carol']], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: IQueryBuilder::PARAM_STR_ARRAY, actual: $this->queries[0]['types'][':p1']);
	}//end testCountByUserOwnersGroupsLiveRows()

	/**
	 * No users runs no query; users without rows are absent.
	 *
	 * @return void
	 */
	public function testCountByUserOwnersEdges(): void {
		$mapper = $this->mapper();

		$this->assertSame(expected: [], actual: $mapper->countByUserOwners(ownerIds: []));
		$this->assertSame(expected: [], actual: $this->queries);

		$this->assertSame(expected: [], actual: $mapper->countByUserOwners(ownerIds: ['carol']));
		$this->assertSame(expected: 'query', actual: $this->ranOf(index: 0));
	}//end testCountByUserOwnersEdges()
	/**
	 * By-name lookups stay inside the owner's vault and optionally one folder.
	 *
	 * @return void
	 */
	public function testFindByNameIsOwnerScoped(): void {
		$this->queueRows(rows: [$this->row(id: 's1'), $this->row(id: 's2')]);
		$mapper = $this->mapper();

		$this->assertSame(expected: ['s1', 's2'], actual: $this->ids(secrets: $mapper->findByName(ownerType: 'application', ownerId: 'app-1', name: 'db')));
		$mapper->findByName(ownerType: 'application', ownerId: 'app-1', name: 'db', folderId: 'f1');

		$owned = ['select(*)', 'from(keepiq_secrets)', 'where(eq(owner_type, :p0))', 'andWhere(eq(owner_id, :p1))'];
		$this->assertSame(expected: array_merge($owned, ['andWhere(eq(name, :p2))', 'orderBy(id, ASC)']), actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'application', ':p1' => 'app-1', ':p2' => 'db'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: ['andWhere(eq(folder_id, :p6))', 'orderBy(id, ASC)'], actual: array_slice($this->opsOf(index: 1), -2));
		$this->assertSame(expected: 'f1', actual: $this->paramsOf(index: 1)[':p6']);
	}//end testFindByNameIsOwnerScoped()

	/**
	 * Rotation polling takes rows updated strictly after the instant, oldest first.
	 *
	 * @return void
	 */
	public function testFindByOwnerUpdatedSince(): void {
		$this->mapper()->findByOwnerUpdatedSince(ownerType: 'application', ownerId: 'app-1', since: new DateTime('2026-01-02 03:04:05'), limit: 10);

		$this->assertSame(
			expected: ['andWhere(gt(updated_at, :p2))', 'orderBy(updated_at, ASC)', 'setMaxResults(10)'],
			actual: array_slice($this->opsOf(index: 0), -3)
		);
		$this->assertSame(expected: '2026-01-02 03:04:05', actual: $this->paramsOf(index: 0)[':p2']);
		$this->assertSame(expected: IQueryBuilder::PARAM_STR, actual: $this->queries[0]['types'][':p2']);
	}//end testFindByOwnerUpdatedSince()

	/**
	 * Counts read the cnt column; no row counts as zero.
	 *
	 * @return void
	 */
	public function testCounts(): void {
		$this->queueRows(rows: [['cnt' => '5']]);
		$this->queueRows(rows: [['cnt' => '2']]);
		$this->queueRows(rows: []);
		$this->queueRows(rows: [['cnt' => '7']]);
		$mapper = $this->mapper();
		$before = new DateTime('2026-06-01T00:00:00+00:00');

		$this->assertSame(expected: 5, actual: $mapper->countByOwner(ownerType: 'user', ownerId: 'alice'));
		$this->assertSame(expected: 2, actual: $mapper->countByTypeId(typeId: 'cert', expiresBefore: $before));
		$this->assertSame(expected: 0, actual: $mapper->countByFolder(folderId: 'f1'));
		$this->assertSame(expected: 7, actual: $mapper->countByFolderIds(folderIds: ['f1', 'f2']));

		$this->assertSame(expected: ['select(count(*, cnt))', 'from(keepiq_secrets)'], actual: array_slice($this->opsOf(index: 0), 0, 2));
		$this->assertSame(
			expected: [
				'select(count(*, cnt))', 'from(keepiq_secrets)', 'where(eq(type_id, :p3))',
				'andWhere(isNotNull(expires_at))', 'andWhere(lt(expires_at, :p4))',
			],
			actual: $this->opsOf(index: 1)
		);
		$this->assertSame(expected: [':p3' => 'cert', ':p4' => $before], actual: $this->paramsOf(index: 1));
		$this->assertSame(expected: ['select(count(*, cnt))', 'from(keepiq_secrets)', 'where(eq(folder_id, :p5))'], actual: $this->opsOf(index: 2));
		$this->assertSame(expected: ['select(count(*, cnt))', 'from(keepiq_secrets)', 'where(in(folder_id, :p6))'], actual: $this->opsOf(index: 3));
		$this->assertSame(expected: IQueryBuilder::PARAM_STR_ARRAY, actual: $this->queries[3]['types'][':p6']);
	}//end testCounts()

	/**
	 * A type count without cutoff has no expiry clause; no folders counts zero without a query.
	 *
	 * @return void
	 */
	public function testCountEdges(): void {
		$mapper = $this->mapper();

		$this->assertSame(expected: 0, actual: $mapper->countByFolderIds(folderIds: []));
		$this->assertSame(expected: [], actual: $this->queries);

		$mapper->countByTypeId(typeId: 'cert');
		$this->assertSame(expected: ['select(count(*, cnt))', 'from(keepiq_secrets)', 'where(eq(type_id, :p0))'], actual: $this->opsOf(index: 0));
	}//end testCountEdges()

	/**
	 * Both searches match name or url on live rows, escaped and bounded.
	 *
	 * @return void
	 */
	public function testSearchesMatchLiveRows(): void {
		$this->queueRows(rows: [$this->row(id: 's1')]);
		$mapper = $this->mapper();

		$found = $mapper->searchByNameOrUrl(ownerType: 'user', ownerId: 'alice', term: 'bank', limit: 0);
		$this->assertSame(expected: ['s1'], actual: $this->ids(secrets: $found));
		$mapper->findForUnifiedSearch(userId: 'alice', term: 'bank', limit: 5);

		$live = [
			'andWhere(orX(iLike(name, :p2), iLike(url, :p3), in(id, SQL)))', 'andWhere(isNull(trashed_at))', 'andWhere(isNull(archived_at))',
		];
		$this->assertSame(expected: $live, actual: array_slice($this->opsOf(index: 0), 4, 3));
		$this->assertSame(expected: 'setMaxResults(1)', actual: $this->opsOf(index: 0)[7]);
		$this->assertSame(expected: '%bank%', actual: $this->paramsOf(index: 0)[':p2']);
		$this->assertSame(expected: '%bank%', actual: $this->paramsOf(index: 0)[':p3']);
		// An extra address of the login matches too (vault-website-addresses).
		$this->assertSame(expected: '%bank%', actual: $this->paramsOf(index: 0)[':p4']);
		// Each search builds its extra-address subquery on a builder of its own (queries 1 and 3).
		$this->assertSame(expected: ['select(su.secret_id)', 'from(keepiq_secret_urls, su)', 'where(iLike(su.url, :p4))'], actual: $this->opsOf(index: 1));
		$this->assertSame(expected: ['orderBy(name, ASC)', 'setMaxResults(5)'], actual: array_slice($this->opsOf(index: 2), 7, 2));
		$this->assertSame(expected: 'user', actual: $this->paramsOf(index: 2)[':p6']);
		$this->assertSame(expected: '%bank%', actual: $this->paramsOf(index: 2)[':p10']);
	}//end testSearchesMatchLiveRows()

	/**
	 * The account and application cascades delete by owner and answer the rows deleted.
	 *
	 * @return void
	 */
	public function testOwnerCascades(): void {
		$this->affected = [4, 0];
		$mapper = $this->mapper();

		$this->assertSame(expected: 4, actual: $mapper->deleteByOwnerUser(ownerId: 'alice'));
		$this->assertSame(expected: 0, actual: $mapper->deleteByOwnerApplication(applicationId: 'app-1'));

		$this->assertSame(
			expected: ['delete(keepiq_secrets)', 'where(eq(owner_type, :p0))', 'andWhere(eq(owner_id, :p1))'],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'user', ':p1' => 'alice'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: [':p2' => 'application', ':p3' => 'app-1'], actual: $this->paramsOf(index: 1));
		$this->assertSame(expected: 'statement', actual: $this->ranOf(index: 1));
	}//end testOwnerCascades()

	/**
	 * The migration queries key on suite and owner.
	 *
	 * @return void
	 */
	public function testSuiteMigrationQueries(): void {
		$this->queueRows(rows: [['cnt' => '9']]);
		$this->queueRows(rows: [['cnt' => '1']]);
		$this->queueRows(rows: [$this->row(id: 's1')]);
		$this->queueRows(rows: [$this->row(id: 's2')]);
		$mapper = $this->mapper();

		$this->assertSame(expected: 9, actual: $mapper->countBySuiteForOwner(encryptionSuiteId: 'suite-1', ownerType: 'user', ownerId: 'alice'));
		$this->assertSame(expected: 1, actual: $mapper->countUnaccountedBySuiteForOwner(encryptionSuiteId: 'suite-1', ownerType: 'user', ownerId: 'alice'));
		$failed = $mapper->findFailedBySuiteForOwner(encryptionSuiteId: 'suite-1', ownerType: 'user', ownerId: 'alice', limit: 0);
		$paged = $mapper->findBySuiteForOwner(encryptionSuiteId: 'suite-1', ownerType: 'user', ownerId: 'alice', limit: 10, offset: -5);

		$this->assertSame(expected: ['s1'], actual: $this->ids(secrets: $failed));
		$this->assertSame(expected: ['s2'], actual: $this->ids(secrets: $paged));
		$narrow = ['where(eq(encryption_suite_id, :p0))', 'andWhere(eq(owner_type, :p1))', 'andWhere(eq(owner_id, :p2))'];
		$this->assertSame(expected: array_merge(['select(count(*, cnt))', 'from(keepiq_secrets)'], $narrow), actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'suite-1', ':p1' => 'user', ':p2' => 'alice'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'andWhere(isNull(migration_error))', actual: $this->opsOf(index: 1)[5]);
		$this->assertSame(
			expected: ['andWhere(isNotNull(migration_error))', 'orderBy(name, ASC)', 'setMaxResults(1)'],
			actual: array_slice($this->opsOf(index: 2), 5)
		);
		$this->assertSame(expected: ['orderBy(id, ASC)', 'setMaxResults(10)', 'setFirstResult(0)'], actual: array_slice($this->opsOf(index: 3), 5));
	}//end testSuiteMigrationQueries()
}//end class
