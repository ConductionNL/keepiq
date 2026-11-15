<?php

/**
 * FederatedShareMapper against a recording connection.
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
use OCA\Keepiq\Db\FederatedShare;
use OCA\Keepiq\Db\FederatedShareMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The SQL each FederatedShareMapper query builds: lookups by id, source secret
 * and partner; the owner reassignment that leaves rows already owned alone; the
 * notification outbox (due, and either still active or carrying the ending
 * notification); and the conditional writes, which write only the changed
 * columns and only while the row still has the state the caller read.
 *
 * @covers \OCA\Keepiq\Db\FederatedShareMapper
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @uses   \OCA\Keepiq\Db\FederatedShare
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class FederatedShareMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over the recording connection.
	 *
	 * @return FederatedShareMapper
	 */
	private function mapper(): FederatedShareMapper {
		return new FederatedShareMapper(db: $this->recordingConnection());
	}//end mapper()

	/**
	 * A share whose id, status and attempt counter are the changed fields.
	 *
	 * @return FederatedShare
	 */
	private function changedShare(): FederatedShare {
		$share = new FederatedShare();
		$share->setId('f1');
		$share->setStatus('suspended');
		$share->setNotifyAttempts(2);
		return $share;
	}//end changedShare()

	/**
	 * Lookup by id hydrates the row; no row throws.
	 *
	 * @return void
	 */
	public function testFindById(): void {
		$this->queueRows(rows: [['id' => 'f1', 'status' => 'active', 'notify_attempts' => '3']]);
		$mapper = $this->mapper();

		$share = $mapper->findById(id: 'f1');

		$this->assertSame(expected: 'f1', actual: $share->getId());
		$this->assertSame(expected: 'active', actual: $share->getStatus());
		$this->assertSame(expected: 3, actual: $share->getNotifyAttempts());
		$this->assertSame(expected: ['select(*)', 'from(keepiq_federated_shares)', 'where(eq(id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'f1'], actual: $this->paramsOf(index: 0));

		$this->expectException(exception: DoesNotExistException::class);
		$mapper->findById(id: 'gone');
	}//end testFindById()

	/**
	 * A secret's federated shares, oldest first; a partner's shares.
	 *
	 * @return void
	 */
	public function testFindBySourceSecretAndPartner(): void {
		$this->queueRows(rows: [['id' => 'f1'], ['id' => 'f2']]);
		$this->queueRows(rows: [['id' => 'f3', 'partner_id' => 'p1']]);
		$mapper = $this->mapper();

		$bySecret = $mapper->findBySourceSecret(sourceSecretId: 's1');
		$byPartner = $mapper->findByPartner(partnerId: 'p1');

		$this->assertSame(expected: ['f1', 'f2'], actual: array_map(static fn (FederatedShare $s): string => $s->getId(), $bySecret));
		$this->assertSame(expected: 'p1', actual: $byPartner[0]->getPartnerId());
		$this->assertSame(
			expected: ['select(*)', 'from(keepiq_federated_shares)', 'where(eq(source_secret_id, :p0))', 'orderBy(created_at, ASC)'],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 's1'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: ['select(*)', 'from(keepiq_federated_shares)', 'where(eq(partner_id, :p1))'], actual: $this->opsOf(index: 1));
		$this->assertSame(expected: [':p1' => 'p1'], actual: $this->paramsOf(index: 1));
	}//end testFindBySourceSecretAndPartner()

	/**
	 * Reassignment touches only rows not already owned by the new owner.
	 *
	 * @return void
	 */
	public function testReassignOwner(): void {
		$this->affected = [2];

		$this->assertSame(expected: 2, actual: $this->mapper()->reassignOwner(sourceSecretId: 's1', ownerId: 'bob'));

		$this->assertSame(
			expected: [
				'update(keepiq_federated_shares)', 'set(owner_id, :p0)', 'where(eq(source_secret_id, :p1))',
				'andWhere(neq(owner_id, :p2))',
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'bob', ':p1' => 's1', ':p2' => 'bob'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'statement', actual: $this->ranOf(index: 0));
	}//end testReassignOwner()

	/**
	 * Due notifications: pending, due, active or ending, earliest first, bounded.
	 *
	 * @return void
	 */
	public function testFindDueNotifications(): void {
		$now = new DateTime('2026-03-01T00:00:00+00:00');
		$this->queueRows(rows: [['id' => 'f1', 'pending_notification' => 'revoke']]);

		$due = $this->mapper()->findDueNotifications(now: $now, limit: 25, ending: 'revoke');

		$this->assertSame(expected: 'revoke', actual: $due[0]->getPendingNotification());
		$this->assertSame(
			expected: [
				'select(*)', 'from(keepiq_federated_shares)', 'where(isNotNull(pending_notification))',
				'andWhere(lte(next_notify_at, :p0))',
				'andWhere(orX(eq(status, :p1), eq(pending_notification, :p2)))',
				'orderBy(next_notify_at, ASC)', 'setMaxResults(25)',
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => $now, ':p1' => FederatedShare::STATUS_ACTIVE, ':p2' => 'revoke'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: IQueryBuilder::PARAM_DATETIME_MUTABLE, actual: $this->queries[0]['types'][':p0']);
	}//end testFindDueNotifications()

	/**
	 * The active-only write sets only changed columns, typed, and resets them.
	 *
	 * @return void
	 */
	public function testUpdateIfActiveWritesChangedFieldsOnlyWhileActive(): void {
		$share = $this->changedShare();
		$this->affected = [1];

		$this->assertSame(expected: 1, actual: $this->mapper()->updateIfActive(share: $share));

		$this->assertSame(
			expected: [
				'update(keepiq_federated_shares)', 'set(status, :p0)', 'set(notify_attempts, :p1)',
				'where(eq(id, :p2))', 'andWhere(eq(status, :p3))',
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'suspended', ':p1' => 2, ':p2' => 'f1', ':p3' => 'active'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: IQueryBuilder::PARAM_STR, actual: $this->queries[0]['types'][':p0']);
		$this->assertSame(expected: IQueryBuilder::PARAM_INT, actual: $this->queries[0]['types'][':p1']);
		$this->assertSame(expected: 'statement', actual: $this->ranOf(index: 0));
		$this->assertSame(expected: [], actual: $share->getUpdatedFields());
	}//end testUpdateIfActiveWritesChangedFieldsOnlyWhileActive()

	/**
	 * The status-guarded write also refuses a row carrying the ending notification.
	 *
	 * @return void
	 */
	public function testUpdateIfStatusSkipsARowAlreadyEnding(): void {
		$share = $this->changedShare();
		$this->affected = [0];

		$this->assertSame(expected: 0, actual: $this->mapper()->updateIfStatus(share: $share, status: 'active', ending: 'revoke'));

		$this->assertSame(
			expected: [
				'update(keepiq_federated_shares)', 'set(status, :p0)', 'set(notify_attempts, :p1)',
				'where(eq(id, :p2))', 'andWhere(eq(status, :p3))',
				'andWhere(orX(isNull(pending_notification), neq(pending_notification, :p4)))',
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: ['active', 'revoke'], actual: [$this->paramsOf(index: 0)[':p3'], $this->paramsOf(index: 0)[':p4']]);
		$this->assertSame(expected: [], actual: $share->getUpdatedFields());
	}//end testUpdateIfStatusSkipsARowAlreadyEnding()

	/**
	 * The unchanged-guarded write compares the version stamp, or its absence.
	 *
	 * @return void
	 */
	public function testUpdateIfUnchangedGuardsOnTheVersion(): void {
		$stamp = new DateTime('2026-02-01T10:00:00+00:00');
		$this->affected = [1, 1];
		$mapper = $this->mapper();

		$this->assertSame(
			expected: 1,
			actual: $mapper->updateIfUnchanged(share: $this->changedShare(), pending: 'grant', status: 'active', updatedAt: $stamp)
		);
		$this->assertSame(
			expected: 1,
			actual: $mapper->updateIfUnchanged(share: $this->changedShare(), pending: 'grant', status: 'active', updatedAt: null)
		);

		$this->assertSame(
			expected: [
				'update(keepiq_federated_shares)', 'set(status, :p0)', 'set(notify_attempts, :p1)',
				'where(eq(id, :p2))', 'andWhere(eq(pending_notification, :p3))', 'andWhere(eq(status, :p4))',
				'andWhere(eq(updated_at, :p5))',
			],
			actual: $this->opsOf(index: 0)
		);
		$params = $this->paramsOf(index: 0);
		$this->assertSame(expected: ['grant', 'active', $stamp], actual: [$params[':p3'], $params[':p4'], $params[':p5']]);
		$this->assertSame(expected: IQueryBuilder::PARAM_DATETIME_MUTABLE, actual: $this->queries[0]['types'][':p5']);
		$this->assertSame(expected: 'andWhere(isNull(updated_at))', actual: $this->opsOf(index: 1)[6]);
		$this->assertCount(expectedCount: 7, haystack: $this->opsOf(index: 1));
	}//end testUpdateIfUnchangedGuardsOnTheVersion()

	/**
	 * The delete happens only while the row still carries the pending notification.
	 *
	 * @return void
	 */
	public function testDeleteIfPending(): void {
		$share = new FederatedShare();
		$share->setId('f9');
		$this->affected = [1];

		$this->assertSame(expected: 1, actual: $this->mapper()->deleteIfPending(share: $share, pending: 'revoke'));

		$this->assertSame(
			expected: ['delete(keepiq_federated_shares)', 'where(eq(id, :p0))', 'andWhere(eq(pending_notification, :p1))'],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'f9', ':p1' => 'revoke'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'statement', actual: $this->ranOf(index: 0));
	}//end testDeleteIfPending()
}//end class
