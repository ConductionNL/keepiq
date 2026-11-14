<?php

/**
 * FederatedInboundMapper against a recording connection.
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

use OCA\Keepiq\Db\FederatedInbound;
use OCA\Keepiq\Db\FederatedInboundMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use PHPUnit\Framework\TestCase;

/**
 * The SQL each FederatedInboundMapper query builds: a share received from a
 * partner is found by id, by the partner's own share id (scoped to that
 * partner, since remote ids are only unique per partner), by secret, and a
 * recipient's inbox newest first.
 *
 * @covers \OCA\Keepiq\Db\FederatedInboundMapper
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @uses   \OCA\Keepiq\Db\FederatedInbound
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class FederatedInboundMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over the recording connection.
	 *
	 * @return FederatedInboundMapper
	 */
	private function mapper(): FederatedInboundMapper {
		return new FederatedInboundMapper(db: $this->recordingConnection());
	}//end mapper()

	/**
	 * By id hydrates the row; no row throws.
	 *
	 * @return void
	 */
	public function testFindById(): void {
		$this->queueRows(rows: [['id' => 'f1', 'partner_id' => 'p1', 'status' => FederatedInbound::STATUS_PENDING]]);
		$mapper = $this->mapper();

		$inbound = $mapper->findById(id: 'f1');

		$this->assertSame(expected: 'f1', actual: $inbound->getId());
		$this->assertSame(expected: 'p1', actual: $inbound->getPartnerId());
		$this->assertSame(expected: FederatedInbound::STATUS_PENDING, actual: $inbound->getStatus());
		$this->assertSame(expected: ['select(*)', 'from(keepiq_federated_inbound)', 'where(eq(id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'f1'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'query', actual: $this->ranOf(index: 0));

		$this->expectException(exception: DoesNotExistException::class);
		$mapper->findById(id: 'gone');
	}//end testFindById()

	/**
	 * By remote id is scoped to the partner as well.
	 *
	 * @return void
	 */
	public function testFindByRemoteIsScopedToThePartner(): void {
		$this->queueRows(rows: [['id' => 'f2', 'partner_id' => 'p1', 'remote_share_id' => 'r9']]);

		$inbound = $this->mapper()->findByRemote(partnerId: 'p1', remoteShareId: 'r9');

		$this->assertSame(expected: 'f2', actual: $inbound->getId());
		$this->assertSame(expected: 'r9', actual: $inbound->getRemoteShareId());
		$this->assertSame(
			expected: [
				'select(*)', 'from(keepiq_federated_inbound)', 'where(eq(partner_id, :p0))',
				'andWhere(eq(remote_share_id, :p1))',
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'p1', ':p1' => 'r9'], actual: $this->paramsOf(index: 0));
	}//end testFindByRemoteIsScopedToThePartner()

	/**
	 * Two rows for one partner's remote id is ambiguous.
	 *
	 * @return void
	 */
	public function testFindByRemoteRefusesTwoRows(): void {
		$this->queueRows(rows: [['id' => 'f2'], ['id' => 'f3']]);

		$this->expectException(exception: MultipleObjectsReturnedException::class);
		$this->mapper()->findByRemote(partnerId: 'p1', remoteShareId: 'r9');
	}//end testFindByRemoteRefusesTwoRows()

	/**
	 * By remote share id alone and by secret return every row.
	 *
	 * @return void
	 */
	public function testListsByRemoteShareIdAndBySecret(): void {
		$this->queueRows(rows: [['id' => 'f1'], ['id' => 'f2']]);
		$this->queueRows(rows: [['id' => 'f3', 'secret_id' => 's1']]);
		$mapper = $this->mapper();

		$byRemote = $mapper->findByRemoteShareId(remoteShareId: 'r9');
		$bySecret = $mapper->findBySecretId(secretId: 's1');

		$this->assertSame(expected: ['f1', 'f2'], actual: array_map(static fn (FederatedInbound $f): string => $f->getId(), $byRemote));
		$this->assertCount(expectedCount: 1, haystack: $bySecret);
		$this->assertSame(expected: 's1', actual: $bySecret[0]->getSecretId());
		$this->assertSame(expected: ['select(*)', 'from(keepiq_federated_inbound)', 'where(eq(remote_share_id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: ['select(*)', 'from(keepiq_federated_inbound)', 'where(eq(secret_id, :p1))'], actual: $this->opsOf(index: 1));
		$this->assertSame(expected: [':p0' => 'r9'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: [':p1' => 's1'], actual: $this->paramsOf(index: 1));
	}//end testListsByRemoteShareIdAndBySecret()

	/**
	 * A recipient's inbox is newest first; an empty inbox is an empty list.
	 *
	 * @return void
	 */
	public function testRecipientInboxIsNewestFirst(): void {
		$this->queueRows(rows: [['id' => 'f5', 'recipient_uid' => 'alice']]);
		$mapper = $this->mapper();

		$inbox = $mapper->findByRecipient(recipientUid: 'alice');
		$empty = $mapper->findByRecipient(recipientUid: 'bob');

		$this->assertSame(expected: 'alice', actual: $inbox[0]->getRecipientUid());
		$this->assertSame(expected: [], actual: $empty);
		$this->assertSame(
			expected: ['select(*)', 'from(keepiq_federated_inbound)', 'where(eq(recipient_uid, :p0))', 'orderBy(received_at, DESC)'],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p1' => 'bob'], actual: $this->paramsOf(index: 1));
	}//end testRecipientInboxIsNewestFirst()
}//end class
