<?php

/**
 * Query-shape unit tests for AuditEntryMapper, without a database.
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
use OCA\Keepiq\Db\AuditEntry;
use OCA\Keepiq\Db\AuditEntryMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * AuditEntryMapper against a recording connection.
 *
 * @covers \OCA\Keepiq\Db\AuditEntryMapper
 * @uses   \OCA\Keepiq\Db\AuditEntry
 */
class AuditEntryMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * Object and actor listings are newest first, paged, and hydrate entries.
	 *
	 * @return void
	 */
	public function testObjectAndActorListingsArePagedNewestFirst(): void {
		$this->queueRows([['id' => 5, 'event_type' => 'secret.read', 'object_id' => 'o1']]);
		$this->queueRows([]);
		$mapper = new AuditEntryMapper($this->recordingConnection());

		$entries = $mapper->findByObject(objectType: 'secret', objectId: 'o1', limit: 10, offset: 20);
		$this->assertSame('secret.read', $entries[0]->getEventType());
		$this->assertSame(
			[
				'select(*)', 'from(keepiq_audit_log)', 'where(eq(object_type, :p0))', 'andWhere(eq(object_id, :p1))',
				'orderBy(occurred_at, DESC)', 'addOrderBy(id, DESC)', 'setMaxResults(10)', 'setFirstResult(20)',
			],
			$this->opsOf(0)
		);

		$this->assertSame([], $mapper->findByActor(actorId: 'alice'));
		$this->assertSame(
			['select(*)', 'from(keepiq_audit_log)', 'where(eq(actor_id, :p2))', 'orderBy(occurred_at, DESC)', 'addOrderBy(id, DESC)', 'setMaxResults(50)', 'setFirstResult(0)'],
			$this->opsOf(1)
		);
	}//end testObjectAndActorListingsArePagedNewestFirst()

	/**
	 * Recent reads are the actor's secret.read events only.
	 *
	 * @return void
	 */
	public function testRecentReadsFilterOnSecretRead(): void {
		(new AuditEntryMapper($this->recordingConnection()))->findRecentReadsByActor(actorId: 'alice', limit: 3);

		$this->assertSame([':p0' => 'alice', ':p1' => 'secret.read'], $this->paramsOf(0));
		$this->assertSame('setMaxResults(3)', $this->opsOf(0)[6]);
	}//end testRecentReadsFilterOnSecretRead()

	/**
	 * Every filter adds its own condition; empty filters add none; dates are parsed.
	 *
	 * @return void
	 */
	public function testFiltersAddOneConditionEach(): void {
		$from = new DateTime('2026-01-01T00:00:00+00:00');
		$mapper = new AuditEntryMapper($this->recordingConnection());
		$mapper->findFiltered(
			filters: ['eventType' => 'secret.read', 'actor' => 'alice', 'objectType' => 'secret', 'objectId' => 'o1', 'from' => $from, 'to' => '2026-02-01T00:00:00+00:00'],
			limit: 5,
			offset: 10
		);

		$this->assertSame(
			[
				'select(*)', 'from(keepiq_audit_log)',
				'andWhere(eq(event_type, :p0))', 'andWhere(eq(actor_id, :p1))', 'andWhere(eq(object_type, :p2))', 'andWhere(eq(object_id, :p3))',
				'andWhere(gte(occurred_at, :p4))', 'andWhere(lte(occurred_at, :p5))',
				'orderBy(occurred_at, DESC)', 'addOrderBy(id, DESC)', 'setMaxResults(5)', 'setFirstResult(10)',
			],
			$this->opsOf(0)
		);
		$params = $this->paramsOf(0);
		$this->assertSame($from, $params[':p4']);
		$this->assertEquals(new DateTime('2026-02-01T00:00:00+00:00'), $params[':p5']);
		$this->assertSame(IQueryBuilder::PARAM_DATETIME_MUTABLE, $this->queries[0]['types'][':p5']);

		$mapper->findFiltered(filters: ['eventType' => '', 'actor' => null, 'from' => '']);
		$this->assertSame([], $this->paramsOf(1));
	}//end testFiltersAddOneConditionEach()

	/**
	 * The filtered count uses the same filters and casts the count.
	 *
	 * @return void
	 */
	public function testCountFilteredCastsTheCount(): void {
		$this->queueRows([['cnt' => '12']]);

		$this->assertSame(12, (new AuditEntryMapper($this->recordingConnection()))->countFiltered(filters: ['actor' => 'bob']));
		$this->assertSame(['select(count(*, cnt))', 'from(keepiq_audit_log)', 'andWhere(eq(actor_id, :p0))'], $this->opsOf(0));
	}//end testCountFilteredCastsTheCount()

	/**
	 * Purging collects a bounded batch of ids, then deletes exactly those.
	 *
	 * @return void
	 */
	public function testPurgeDeletesTheCollectedIds(): void {
		$cutoff = new DateTime('2025-01-01T00:00:00+00:00');
		$this->queueRows([['id' => '3'], ['id' => '8']]);
		$this->affected = [2];

		$this->assertSame(2, (new AuditEntryMapper($this->recordingConnection()))->purgeOlderThan(cutoff: $cutoff, batchSize: 100));
		$this->assertSame(['select(id)', 'from(keepiq_audit_log)', 'where(lt(occurred_at, :p0))', 'setMaxResults(100)'], $this->opsOf(0));
		$this->assertSame(['delete(keepiq_audit_log)', 'where(in(id, :p1))'], $this->opsOf(1));
		$this->assertSame([3, 8], $this->paramsOf(1)[':p1']);
		$this->assertSame(IQueryBuilder::PARAM_INT_ARRAY, $this->queries[1]['types'][':p1']);
	}//end testPurgeDeletesTheCollectedIds()

	/**
	 * Nothing old enough: no delete statement.
	 *
	 * @return void
	 */
	public function testPurgeWithNothingToDeleteRunsNoDelete(): void {
		$this->assertSame(0, (new AuditEntryMapper($this->recordingConnection()))->purgeOlderThan(cutoff: new DateTime('2025-01-01')));
		$this->assertCount(1, $this->queries);
	}//end testPurgeWithNothingToDeleteRunsNoDelete()

	/**
	 * Anonymising rewrites the actor to the deleted-account marker.
	 *
	 * @return void
	 */
	public function testAnonymizeActorWritesTheMarker(): void {
		$this->affected = [4];

		$this->assertSame(4, (new AuditEntryMapper($this->recordingConnection()))->anonymizeActor(userId: 'carol'));
		$this->assertSame(['update(keepiq_audit_log)', 'set(actor_id, :p0)', 'where(eq(actor_id, :p1))'], $this->opsOf(0));
		$this->assertSame([':p0' => AuditEntryMapper::DELETED_ACCOUNT_MARKER, ':p1' => 'carol'], $this->paramsOf(0));
	}//end testAnonymizeActorWritesTheMarker()

	/**
	 * Metadata is rewritten by integer id, and referencing rows are found by an escaped like.
	 *
	 * @return void
	 */
	public function testMetadataRewriteAndLookup(): void {
		$this->queueRows([['id' => 1, 'metadata' => '{"user":"dave"}']]);
		$mapper = new AuditEntryMapper($this->recordingConnection());

		$mapper->rewriteMetadata(id: 9, metadata: null);
		$found = $mapper->findMetadataReferencing(userId: 'dave');

		$this->assertSame(['update(keepiq_audit_log)', 'set(metadata, :p0)', 'where(eq(id, :p1))'], $this->opsOf(0));
		$this->assertSame([':p0' => null, ':p1' => 9], $this->paramsOf(0));
		$this->assertSame('where(iLike(metadata, :p2))', $this->opsOf(1)[2]);
		$this->assertSame('%dave%', $this->paramsOf(1)[':p2']);
		$this->assertInstanceOf(AuditEntry::class, $found[0]);
	}//end testMetadataRewriteAndLookup()
}//end class
