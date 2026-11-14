<?php

/**
 * UsedProofNonceMapper against a recording connection.
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

use OCA\Keepiq\Db\UsedProofNonceMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * A claim inserts the nonce once; a constraint violation means it was claimed before.
 *
 * @covers \OCA\Keepiq\Db\UsedProofNonceMapper
 */
class UsedProofNonceMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * A first claim inserts the hash and its expiry as an int.
	 *
	 * @return void
	 */
	public function testAFirstClaimInsertsTheNonce(): void {
		$mapper = new UsedProofNonceMapper(db: $this->recordingConnection());

		$this->assertTrue($mapper->claim(nonceHash: 'abc', expiresAt: 1700000000));
		$this->assertSame(['insert(keepiq_used_proofs)', 'values([nonce_hash=:p0, expires_at=:p1])'], $this->opsOf(0));
		$this->assertSame([':p0' => 'abc', ':p1' => 1700000000], $this->paramsOf(0));
		$this->assertSame('statement', $this->ranOf(0));
	}//end testAFirstClaimInsertsTheNonce()

	/**
	 * The sweep deletes the rows expired before now and returns how many went.
	 *
	 * @return void
	 */
	public function testDeleteExpiredReturnsTheAffectedRows(): void {
		$this->affected[] = 3;
		$mapper           = new UsedProofNonceMapper(db: $this->recordingConnection());

		$this->assertSame(3, $mapper->deleteExpired(now: 1700000100));
		$this->assertSame(['delete(keepiq_used_proofs)', 'where(lt(expires_at, :p0))'], $this->opsOf(0));
		$this->assertSame([':p0' => 1700000100], $this->paramsOf(0));
	}//end testDeleteExpiredReturnsTheAffectedRows()

	/**
	 * Both constraint reasons mean the nonce was already used.
	 *
	 * @return void
	 */
	public function testAConstraintViolationLosesTheClaim(): void {
		foreach ([DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION, DbException::REASON_CONSTRAINT_VIOLATION] as $reason) {
			$mapper = new UsedProofNonceMapper(db: $this->throwingConnection(reason: $reason));

			$this->assertFalse($mapper->claim(nonceHash: 'abc', expiresAt: 1), 'reason ' . $reason);
		}
	}//end testAConstraintViolationLosesTheClaim()

	/**
	 * Any other database failure is not swallowed.
	 *
	 * @return void
	 */
	public function testAnotherDatabaseFailureIsRethrown(): void {
		$mapper = new UsedProofNonceMapper(db: $this->throwingConnection(reason: DbException::REASON_CONNECTION_LOST));

		$this->expectException(DbException::class);
		$mapper->claim(nonceHash: 'abc', expiresAt: 1);
	}//end testAnotherDatabaseFailureIsRethrown()

	/**
	 * A connection whose insert fails with the given reason.
	 *
	 * @param int $reason The DbException reason.
	 *
	 * @return IDBConnection
	 */
	private function throwingConnection(int $reason): IDBConnection {
		$failure = $this->createMock(DbException::class);
		$failure->method('getReason')->willReturn($reason);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('insert')->willReturnSelf();
		$qb->method('values')->willReturnSelf();
		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$qb->method('executeStatement')->willThrowException($failure);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return $db;
	}//end throwingConnection()
}//end class
