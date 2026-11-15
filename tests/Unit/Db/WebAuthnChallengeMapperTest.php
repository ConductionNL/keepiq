<?php

/**
 * Unit tests for WebAuthnChallengeMapper's challenge rows.
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

use OCA\Keepiq\Db\WebAuthnChallengeMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * WebAuthnChallengeMapper against a recording connection.
 *
 * @covers \OCA\Keepiq\Db\WebAuthnChallengeMapper
 */
class WebAuthnChallengeMapperTest extends TestCase {
	use RecordingDatabase;

	/**
	 * Replace deletes the pending challenge for the same user, token and purpose, then inserts.
	 *
	 * @return void
	 */
	public function testReplaceDeletesPendingThenInserts(): void {
		$row = ['uid' => 'alice', 'token_id' => 12, 'purpose' => 'unlock', 'challenge' => 'c1', 'assertion_challenge' => 'a1', 'expires_at' => 1700];

		(new WebAuthnChallengeMapper($this->recordingConnection()))->replace(row: $row);

		$this->assertCount(2, $this->queries);
		$this->assertSame(
			['delete(keepiq_webauthn_challenges)', 'where(eq(uid, :p0))', 'andWhere(eq(token_id, :p1))', 'andWhere(eq(purpose, :p2))'],
			$this->opsOf(0)
		);
		$this->assertSame([':p0' => 'alice', ':p1' => 12, ':p2' => 'unlock'], $this->paramsOf(0));
		$this->assertSame(IQueryBuilder::PARAM_INT, $this->queries[0]['types'][':p1']);
		$this->assertSame(
			['insert(keepiq_webauthn_challenges)', 'values([uid=:p3, token_id=:p4, purpose=:p5, challenge=:p6, assertion_challenge=:p7, expires_at=:p8])'],
			$this->opsOf(1)
		);
		$this->assertSame(['alice', 12, 'unlock', 'c1', 'a1', 1700], array_values($this->paramsOf(1)));
		$this->assertSame(IQueryBuilder::PARAM_INT, $this->queries[1]['types'][':p8']);
		$this->assertSame('statement', $this->ranOf(0));
		$this->assertSame('statement', $this->ranOf(1));
	}//end testReplaceDeletesPendingThenInserts()

	/**
	 * A pending challenge is the first matching row, or null.
	 *
	 * @return void
	 */
	public function testFindPendingReturnsRowOrNull(): void {
		$this->queueRows([['id' => 3, 'challenge' => 'c1']]);
		$this->queueRows([]);
		$mapper = new WebAuthnChallengeMapper($this->recordingConnection());

		$this->assertSame(['id' => 3, 'challenge' => 'c1'], $mapper->findPending(uid: 'alice', tokenId: 12, purpose: 'unlock'));
		$this->assertNull($mapper->findPending(uid: 'alice', tokenId: 12, purpose: 'register'));
		$this->assertSame(
			['select(*)', 'from(keepiq_webauthn_challenges)', 'where(eq(uid, :p0))', 'andWhere(eq(token_id, :p1))', 'andWhere(eq(purpose, :p2))', 'setMaxResults(1)'],
			$this->opsOf(0)
		);
		$this->assertSame([':p3' => 'alice', ':p4' => 12, ':p5' => 'register'], $this->paramsOf(1));
	}//end testFindPendingReturnsRowOrNull()

	/**
	 * The three deletes use their own column and operator and return the affected count.
	 *
	 * @return void
	 */
	public function testDeletesUseTheirColumnAndReturnCount(): void {
		$this->affected = [1, 2, 5];
		$mapper = new WebAuthnChallengeMapper($this->recordingConnection());

		$this->assertSame(1, $mapper->deleteById(id: 9));
		$this->assertSame(2, $mapper->deleteByToken(tokenId: 12));
		$this->assertSame(5, $mapper->deleteExpired(now: 1800));
		$this->assertSame('where(eq(id, :p0))', $this->opsOf(0)[1]);
		$this->assertSame('where(eq(token_id, :p1))', $this->opsOf(1)[1]);
		$this->assertSame('where(lt(expires_at, :p2))', $this->opsOf(2)[1]);
		$this->assertSame([9, 12, 1800], [$this->paramsOf(0)[':p0'], $this->paramsOf(1)[':p1'], $this->paramsOf(2)[':p2']]);
	}//end testDeletesUseTheirColumnAndReturnCount()
}//end class
