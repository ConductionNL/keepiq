<?php

/**
 * LinkShareMapper against a recording connection.
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

use OCA\Keepiq\Db\LinkShareMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The SQL each LinkShareMapper query builds: lookups by id, token, secret and
 * creator; "held or made by" spanning the current and the original creator
 * (except for the deleted-account marker, which is only ever a holder);
 * deletes that never reach a link someone else now holds; the creator
 * reassignment that remembers the original creator once; and the two
 * conditional usage counters that brake link guessing.
 *
 * @covers \OCA\Keepiq\Db\LinkShareMapper
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @uses   \OCA\Keepiq\Db\LinkShare
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class LinkShareMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over the recording connection.
	 *
	 * @return LinkShareMapper
	 */
	private function mapper(): LinkShareMapper {
		return new LinkShareMapper($this->recordingConnection());
	}//end mapper()

	/**
	 * Lookups by id, token, secret and creator each filter on their column.
	 *
	 * @return void
	 */
	public function testLookupsFilterOnTheirColumn(): void {
		$this->queueRows([['id' => 'l1', 'token' => 'tok']]);
		$this->queueRows([['id' => 'l1', 'token' => 'tok']]);
		$mapper = $this->mapper();

		$this->assertSame('tok', $mapper->findById(id: 'l1')->getToken());
		$this->assertSame('l1', $mapper->findByToken(token: 'tok')->getId());
		$mapper->findBySecretId(secretId: 's1');
		$mapper->findByCreatedBy(userId: 'alice');

		$this->assertSame('where(eq(id, :p0))', $this->opsOf(0)[2]);
		$this->assertSame('where(eq(token, :p1))', $this->opsOf(1)[2]);
		$this->assertSame(['select(*)', 'from(keepiq_link_shares)', 'where(eq(secret_id, :p2))'], $this->opsOf(2));
		$this->assertSame(['select(*)', 'from(keepiq_link_shares)', 'where(eq(created_by, :p3))'], $this->opsOf(3));
	}//end testLookupsFilterOnTheirColumn()

	/**
	 * Held-or-made spans both creator columns, except for the deleted-account marker.
	 *
	 * @return void
	 */
	public function testHeldOrMadeSpansBothCreatorColumns(): void {
		$mapper = $this->mapper();

		$mapper->findHeldOrMadeBy(userId: 'alice');
		$mapper->findHeldOrMadeBy(userId: LinkShareMapper::DELETED_ACCOUNT_MARKER);

		$this->assertSame(
			['select(*)', 'from(keepiq_link_shares)', 'where(orX(eq(created_by, :p0), eq(original_created_by, :p1)))'],
			$this->opsOf(0)
		);
		$this->assertSame([':p0' => 'alice', ':p1' => 'alice'], $this->paramsOf(0));
		$this->assertSame(['select(*)', 'from(keepiq_link_shares)', 'where(eq(created_by, :p2))'], $this->opsOf(1));
	}//end testHeldOrMadeSpansBothCreatorColumns()

	/**
	 * Deleting what a user made also takes links they made that someone else now holds, but not for the marker.
	 *
	 * @return void
	 */
	public function testDeleteMadeByCoversOriginalCreatorExceptForTheMarker(): void {
		$mapper = $this->mapper();

		$mapper->deleteMadeBy(userId: 'alice');
		$mapper->deleteMadeBy(userId: LinkShareMapper::DELETED_ACCOUNT_MARKER);

		$this->assertSame(
			['delete(keepiq_link_shares)', 'where(orX(andX(eq(created_by, :p0), isNull(original_created_by)), eq(original_created_by, :p1)))'],
			$this->opsOf(0)
		);
		$this->assertSame(
			['delete(keepiq_link_shares)', 'where(andX(eq(created_by, :p2), isNull(original_created_by)))'],
			$this->opsOf(1)
		);
		$this->assertSame('statement', $this->ranOf(1));
	}//end testDeleteMadeByCoversOriginalCreatorExceptForTheMarker()

	/**
	 * The plain deletes are scoped to the secret or the current creator.
	 *
	 * @return void
	 */
	public function testPlainDeletes(): void {
		$mapper = $this->mapper();
		$mapper->deleteBySecretId(secretId: 's1');
		$mapper->deleteByUserId(userId: 'alice');

		$this->assertSame(['delete(keepiq_link_shares)', 'where(eq(secret_id, :p0))'], $this->opsOf(0));
		$this->assertSame(['delete(keepiq_link_shares)', 'where(eq(created_by, :p1))'], $this->opsOf(1));
	}//end testPlainDeletes()

	/**
	 * Forgetting the original creator writes the marker over it.
	 *
	 * @return void
	 */
	public function testForgetOriginalCreatorWritesTheMarker(): void {
		$this->affected = [2];

		$this->assertSame(2, $this->mapper()->forgetOriginalCreator(userId: 'alice'));
		$this->assertSame(['update(keepiq_link_shares)', 'set(original_created_by, :p0)', 'where(eq(original_created_by, :p1))'], $this->opsOf(0));
		$this->assertSame([':p0' => LinkShareMapper::DELETED_ACCOUNT_MARKER, ':p1' => 'alice'], $this->paramsOf(0));
	}//end testForgetOriginalCreatorWritesTheMarker()

	/**
	 * Reassigning keeps the first original creator and moves the current one.
	 *
	 * @return void
	 */
	public function testReassignKeepsTheOriginalCreator(): void {
		$mapper = $this->mapper();
		$mapper->reassignCreator(secretId: 's1', fromUserId: 'alice', toUserId: 'bob');
		$mapper->reassignCreatorById(id: 'l1', toUserId: 'bob');

		$this->assertStringStartsWith('set(original_created_by, COALESCE(', $this->opsOf(0)[1]);
		$this->assertSame(
			['set(created_by, :p0)', 'where(eq(secret_id, :p1))', 'andWhere(eq(created_by, :p2))'],
			array_slice($this->opsOf(0), 2)
		);
		$this->assertSame([':p0' => 'bob', ':p1' => 's1', ':p2' => 'alice'], $this->paramsOf(0));
		$this->assertSame(
			['set(created_by, :p3)', 'where(eq(id, :p4))', 'andWhere(neq(created_by, :p5))'],
			array_slice($this->opsOf(1), 2)
		);
	}//end testReassignKeepsTheOriginalCreator()

	/**
	 * A successful use counts within the limit and clears the failed attempts.
	 *
	 * @return void
	 */
	public function testUsageIncrementsWithinTheLimitAndClearsAttempts(): void {
		$this->mapper()->incrementUsageIfBelowLimit(token: 'tok');

		$this->assertSame(
			[
				'update(keepiq_link_shares)', 'set(usage_count, usage_count + 1)', 'set(failed_attempts, :p0)',
				'where(eq(token, :p1))', 'andWhere(lt(usage_count, usage_limit))',
			],
			$this->opsOf(0)
		);
		$this->assertSame(0, $this->paramsOf(0)[':p0']);
	}//end testUsageIncrementsWithinTheLimitAndClearsAttempts()

	/**
	 * A guarded serve counts a use and an attempt within both caps; reset clears attempts.
	 *
	 * @return void
	 */
	public function testGuardedServeAndReset(): void {
		$this->affected = [0];
		$mapper = $this->mapper();

		$this->assertSame(0, $mapper->countServeIfAllowed(token: 'tok', attemptsCap: 10));
		$mapper->resetFailedAttempts(token: 'tok');

		$this->assertSame(
			[
				'update(keepiq_link_shares)', 'set(usage_count, usage_count + 1)', 'set(failed_attempts, failed_attempts + 1)',
				'where(eq(token, :p0))', 'andWhere(lt(usage_count, usage_limit))', 'andWhere(lt(failed_attempts, :p1))',
			],
			$this->opsOf(0)
		);
		$this->assertSame(IQueryBuilder::PARAM_INT, $this->queries[0]['types'][':p1']);
		$this->assertSame(['update(keepiq_link_shares)', 'set(failed_attempts, :p2)', 'where(eq(token, :p3))'], $this->opsOf(1));
	}//end testGuardedServeAndReset()
}//end class
