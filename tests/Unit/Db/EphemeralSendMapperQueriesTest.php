<?php

/**
 * EphemeralSendMapper against a recording connection.
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
use OCA\Keepiq\Db\EphemeralSendMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The SQL each EphemeralSendMapper query builds. The serve counter is the
 * brute-force brake: one conditional UPDATE that counts a view only while
 * views remain and failed attempts are under the cap, and that also counts
 * the attempt when asked. Purging takes expired or used-up sends, bounded.
 *
 * @covers \OCA\Keepiq\Db\EphemeralSendMapper
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @uses   \OCA\Keepiq\Db\EphemeralSend
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class EphemeralSendMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over the recording connection.
	 *
	 * @return EphemeralSendMapper
	 */
	private function mapper(): EphemeralSendMapper {
		return new EphemeralSendMapper($this->recordingConnection());
	}//end mapper()

	/**
	 * Lookups by id, token and owner.
	 *
	 * @return void
	 */
	public function testLookups(): void {
		$this->queueRows([['id' => 'e1', 'token' => 'tok']]);
		$this->queueRows([['id' => 'e1', 'token' => 'tok']]);
		$mapper = $this->mapper();

		$this->assertSame('tok', $mapper->findById(id: 'e1')->getToken());
		$this->assertSame('e1', $mapper->findByToken(token: 'tok')->getId());
		$mapper->findByOwner(ownerId: 'alice');

		$this->assertSame(['select(*)', 'from(keepiq_ephemeral_sends)', 'where(eq(id, :p0))'], $this->opsOf(0));
		$this->assertSame(['select(*)', 'from(keepiq_ephemeral_sends)', 'where(eq(token, :p1))'], $this->opsOf(1));
		$this->assertSame(
			['select(*)', 'from(keepiq_ephemeral_sends)', 'where(eq(owner_id, :p2))', 'orderBy(created_at, DESC)'],
			$this->opsOf(2)
		);
	}//end testLookups()

	/**
	 * Purgeable means expired or used up, bounded.
	 *
	 * @return void
	 */
	public function testPurgeableIsExpiredOrUsedUp(): void {
		$now = new DateTime('2026-03-01T00:00:00+00:00');

		$this->mapper()->findPurgeable(now: $now, limit: 50);

		$this->assertSame(
			[
				'select(*)', 'from(keepiq_ephemeral_sends)',
				'where(orX(andX(isNotNull(expires_at), lte(expires_at, :p0)), gte(view_count, max_views)))',
				'setMaxResults(50)',
			],
			$this->opsOf(0)
		);
		$this->assertSame($now, $this->paramsOf(0)[':p0']);
		$this->assertSame('datetime', $this->queries[0]['types'][':p0']);
	}//end testPurgeableIsExpiredOrUsedUp()

	/**
	 * A serve counts a view only within the view and attempt caps.
	 *
	 * @return void
	 */
	public function testAServeCountsAViewWithinTheCaps(): void {
		$this->affected = [1];

		$this->assertSame(1, $this->mapper()->countServeIfAllowed(token: 'tok', attemptsCap: 5, countAttempt: false));

		$this->assertSame(
			[
				'update(keepiq_ephemeral_sends)', 'set(view_count, view_count + 1)', 'where(eq(token, :p0))',
				'andWhere(lt(view_count, max_views))', 'andWhere(lt(failed_attempts, :p1))',
			],
			$this->opsOf(0)
		);
		$this->assertSame([':p0' => 'tok', ':p1' => 5], $this->paramsOf(0));
		$this->assertSame(IQueryBuilder::PARAM_INT, $this->queries[0]['types'][':p1']);
	}//end testAServeCountsAViewWithinTheCaps()

	/**
	 * A failed attempt also bumps the attempt counter; a refused serve answers 0.
	 *
	 * @return void
	 */
	public function testAFailedAttemptAlsoCountsTheAttempt(): void {
		$this->affected = [0];

		$this->assertSame(0, $this->mapper()->countServeIfAllowed(token: 'tok', attemptsCap: 5, countAttempt: true));

		$this->assertSame('set(failed_attempts, failed_attempts + 1)', $this->opsOf(0)[2]);
	}//end testAFailedAttemptAlsoCountsTheAttempt()

	/**
	 * A successful unlock resets the attempts to zero.
	 *
	 * @return void
	 */
	public function testResetFailedAttempts(): void {
		$this->mapper()->resetFailedAttempts(token: 'tok');

		$this->assertSame(['update(keepiq_ephemeral_sends)', 'set(failed_attempts, :p0)', 'where(eq(token, :p1))'], $this->opsOf(0));
		$this->assertSame([':p0' => 0, ':p1' => 'tok'], $this->paramsOf(0));
	}//end testResetFailedAttempts()
}//end class
