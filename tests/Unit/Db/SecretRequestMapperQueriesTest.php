<?php

/**
 * SecretRequestMapper against a recording connection.
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
use OCA\Keepiq\Db\SecretRequest;
use OCA\Keepiq\Db\SecretRequestMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The SQL each SecretRequestMapper query builds: lookups by id, token, secret
 * and creator (an application's requests under its `application:` actor id);
 * the lapsed-pending sweep that never takes a request without an expiry; the
 * status transitions that only ever move a row from the status they guard on;
 * and the cascade deletes.
 *
 * The real-database guarantee of transitionIfPending() lives in
 * SecretRequestMapperTest; this asserts the guard is part of the statement.
 *
 * @covers \OCA\Keepiq\Db\SecretRequestMapper
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @uses   \OCA\Keepiq\Db\SecretRequest
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class SecretRequestMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over the recording connection.
	 *
	 * @return SecretRequestMapper
	 */
	private function mapper(): SecretRequestMapper {
		return new SecretRequestMapper(db: $this->recordingConnection());
	}//end mapper()

	/**
	 * Lookup by id hydrates the single row it finds.
	 *
	 * @return void
	 */
	public function testFindByIdHydratesTheRow(): void {
		$this->queueRows(rows: [['id' => 'r1', 'token' => 'tok', 'status' => 'pending', 'secret_id' => 's1']]);

		$request = $this->mapper()->findById(id: 'r1');

		$this->assertInstanceOf(expected: SecretRequest::class, actual: $request);
		$this->assertSame(expected: 'r1', actual: $request->getId());
		$this->assertSame(expected: 'tok', actual: $request->getToken());
		$this->assertSame(expected: 's1', actual: $request->getSecretId());
		$this->assertSame(expected: ['select(*)', 'from(keepiq_secret_requests)', 'where(eq(id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'r1'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'query', actual: $this->ranOf(index: 0));
	}//end testFindByIdHydratesTheRow()

	/**
	 * No row is a DoesNotExistException.
	 *
	 * @return void
	 */
	public function testFindByIdWithoutARowThrows(): void {
		$this->expectException(exception: DoesNotExistException::class);

		$this->mapper()->findById(id: 'missing');
	}//end testFindByIdWithoutARowThrows()

	/**
	 * Lookup by token filters on the token column.
	 *
	 * @return void
	 */
	public function testFindByToken(): void {
		$this->queueRows(rows: [['id' => 'r2', 'token' => 'abc']]);

		$this->assertSame(expected: 'r2', actual: $this->mapper()->findByToken(token: 'abc')->getId());

		$this->assertSame(expected: ['select(*)', 'from(keepiq_secret_requests)', 'where(eq(token, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'abc'], actual: $this->paramsOf(index: 0));
	}//end testFindByToken()

	/**
	 * Every request of a secret, hydrated in row order.
	 *
	 * @return void
	 */
	public function testFindBySecretIdReturnsEveryRow(): void {
		$this->queueRows(rows: [['id' => 'r1', 'secret_id' => 's1'], ['id' => 'r2', 'secret_id' => 's1']]);

		$requests = $this->mapper()->findBySecretId(secretId: 's1');

		$this->assertSame(expected: ['r1', 'r2'], actual: array_map(static fn (SecretRequest $r): string => $r->getId(), $requests));
		$this->assertSame(expected: ['select(*)', 'from(keepiq_secret_requests)', 'where(eq(secret_id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 's1'], actual: $this->paramsOf(index: 0));
	}//end testFindBySecretIdReturnsEveryRow()

	/**
	 * A creator's requests, and an application's under its prefixed actor id.
	 *
	 * @return void
	 */
	public function testFindByCreatorAndByApplication(): void {
		$this->queueRows(rows: [['id' => 'r1', 'created_by' => 'alice']]);
		$this->queueRows(rows: []);
		$mapper = $this->mapper();

		$mine = $mapper->findByCreatedBy(userId: 'alice');
		$apps = $mapper->findByApplication(applicationId: 'app-7');

		$this->assertCount(expectedCount: 1, haystack: $mine);
		$this->assertSame(expected: 'alice', actual: $mine[0]->getCreatedBy());
		$this->assertSame(expected: [], actual: $apps);
		$this->assertSame(expected: ['select(*)', 'from(keepiq_secret_requests)', 'where(eq(created_by, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: ['select(*)', 'from(keepiq_secret_requests)', 'where(eq(created_by, :p1))'], actual: $this->opsOf(index: 1));
		$this->assertSame(expected: [':p0' => 'alice'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: [':p1' => 'application:app-7'], actual: $this->paramsOf(index: 1));
	}//end testFindByCreatorAndByApplication()

	/**
	 * The sweep takes pending rows with an expiry before now, bounded, default 500.
	 *
	 * @return void
	 */
	public function testFindLapsedPendingIsNarrowAndBounded(): void {
		$now = new DateTime('2026-03-01T00:00:00+00:00');
		$this->queueRows(rows: [['id' => 'r9', 'status' => 'pending']]);
		$mapper = $this->mapper();

		$lapsed = $mapper->findLapsedPending(now: $now);
		$mapper->findLapsedPending(now: $now, limit: 7);

		$this->assertSame(expected: 'r9', actual: $lapsed[0]->getId());
		$this->assertSame(
			expected: [
				'select(*)', 'from(keepiq_secret_requests)', 'where(eq(status, :p0))',
				'andWhere(isNotNull(expires_at))', 'andWhere(lt(expires_at, :p1))', 'setMaxResults(500)',
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => SecretRequest::STATUS_PENDING, ':p1' => $now], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: IQueryBuilder::PARAM_DATETIME_MUTABLE, actual: $this->queries[0]['types'][':p1']);
		$this->assertSame(expected: 'setMaxResults(7)', actual: $this->opsOf(index: 1)[5]);
	}//end testFindLapsedPendingIsNarrowAndBounded()

	/**
	 * The pending request of a secret; two of them is an error.
	 *
	 * @return void
	 */
	public function testFindPendingBySecretIdRefusesTwoRows(): void {
		$this->queueRows(rows: [['id' => 'r1'], ['id' => 'r2']]);

		try {
			$this->mapper()->findPendingBySecretId(secretId: 's1');
			$this->fail(message: 'two pending rows must not be returned as one');
		} catch (MultipleObjectsReturnedException $e) {
			$this->assertSame(
				expected: [
					'select(*)', 'from(keepiq_secret_requests)', 'where(eq(secret_id, :p0))',
					'andWhere(eq(status, :p1))',
				],
				actual: $this->opsOf(index: 0)
			);
			$this->assertSame(expected: [':p0' => 's1', ':p1' => 'pending'], actual: $this->paramsOf(index: 0));
		}
	}//end testFindPendingBySecretIdRefusesTwoRows()

	/**
	 * The single pending request of a secret is returned.
	 *
	 * @return void
	 */
	public function testFindPendingBySecretIdReturnsTheOne(): void {
		$this->queueRows(rows: [['id' => 'r3', 'status' => 'pending']]);

		$this->assertSame(expected: 'pending', actual: $this->mapper()->findPendingBySecretId(secretId: 's1')->getStatus());
	}//end testFindPendingBySecretIdReturnsTheOne()

	/**
	 * The transition is one UPDATE guarded on the row still being pending.
	 *
	 * @return void
	 */
	public function testTransitionIfPendingIsGuardedOnPending(): void {
		$this->affected = [1, 0];
		$mapper = $this->mapper();

		$this->assertTrue(condition: $mapper->transitionIfPending(requestId: 'r1', toStatus: 'expired'));
		$this->assertFalse(condition: $mapper->transitionIfPending(requestId: 'r1', toStatus: 'declined'));

		$this->assertSame(
			expected: ['update(keepiq_secret_requests)', 'set(status, :p0)', 'where(eq(id, :p1))', 'andWhere(eq(status, :p2))'],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'expired', ':p1' => 'r1', ':p2' => 'pending'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'statement', actual: $this->ranOf(index: 0));
		$this->assertSame(expected: [':p3' => 'declined', ':p4' => 'r1', ':p5' => 'pending'], actual: $this->paramsOf(index: 1));
	}//end testTransitionIfPendingIsGuardedOnPending()

	/**
	 * Locking freezes only pending requests on the suite and reports the count.
	 *
	 * @return void
	 */
	public function testLockByEncryptionSuiteId(): void {
		$this->affected = [3];

		$this->assertSame(expected: 3, actual: $this->mapper()->lockByEncryptionSuiteId(encryptionSuiteId: 'suite-1'));

		$this->assertSame(
			expected: [
				'update(keepiq_secret_requests)', 'set(status, :p0)', 'where(eq(encryption_suite_id, :p1))',
				'andWhere(eq(status, :p2))',
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'locked', ':p1' => 'suite-1', ':p2' => 'pending'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'statement', actual: $this->ranOf(index: 0));
	}//end testLockByEncryptionSuiteId()

	/**
	 * Unlocking returns only locked requests on the suite to pending.
	 *
	 * @return void
	 */
	public function testUnlockByEncryptionSuiteId(): void {
		$this->affected = [2];

		$this->assertSame(expected: 2, actual: $this->mapper()->unlockByEncryptionSuiteId(encryptionSuiteId: 'suite-1'));

		$this->assertSame(
			expected: [
				'update(keepiq_secret_requests)', 'set(status, :p0)', 'where(eq(encryption_suite_id, :p1))',
				'andWhere(eq(status, :p2))',
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'pending', ':p1' => 'suite-1', ':p2' => 'locked'], actual: $this->paramsOf(index: 0));
	}//end testUnlockByEncryptionSuiteId()

	/**
	 * Re-pointing moves locked requests to the new suite and back to pending.
	 *
	 * @return void
	 */
	public function testUnlockAndUpdateSuite(): void {
		$this->affected = [4];

		$this->assertSame(expected: 4, actual: $this->mapper()->unlockAndUpdateSuite(oldEncryptionSuiteId: 'old', newEncryptionSuiteId: 'new'));

		$this->assertSame(
			expected: [
				'update(keepiq_secret_requests)', 'set(encryption_suite_id, :p0)', 'set(status, :p1)',
				'where(eq(encryption_suite_id, :p2))', 'andWhere(eq(status, :p3))',
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'new', ':p1' => 'pending', ':p2' => 'old', ':p3' => 'locked'], actual: $this->paramsOf(index: 0));
	}//end testUnlockAndUpdateSuite()

	/**
	 * The cascades delete by secret and by creator.
	 *
	 * @return void
	 */
	public function testCascadeDeletes(): void {
		$this->affected = [1, 5];
		$mapper = $this->mapper();

		$mapper->deleteBySecretId(secretId: 's1');
		$this->assertSame(expected: 5, actual: $mapper->deleteByCreatedBy(userId: 'bob'));

		$this->assertSame(expected: ['delete(keepiq_secret_requests)', 'where(eq(secret_id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 's1'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'statement', actual: $this->ranOf(index: 0));
		$this->assertSame(expected: ['delete(keepiq_secret_requests)', 'where(eq(created_by, :p1))'], actual: $this->opsOf(index: 1));
		$this->assertSame(expected: [':p1' => 'bob'], actual: $this->paramsOf(index: 1));
		$this->assertSame(expected: 'statement', actual: $this->ranOf(index: 1));
	}//end testCascadeDeletes()
}//end class
