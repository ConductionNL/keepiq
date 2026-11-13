<?php

/**
 * PasskeyMapper against a recording connection.
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

use OCA\Keepiq\Db\PasskeyMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The SQL each PasskeyMapper query builds: every lookup is scoped to the
 * owner, the unlock set is the owner's active passkeys only, a credential
 * lookup answers null rather than throwing, revoking an app token deletes its
 * passkeys one by one and counts them per owner, and marking stale touches
 * only the owner's active rows.
 *
 * @covers \OCA\Keepiq\Db\PasskeyMapper
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @uses   \OCA\Keepiq\Db\PasskeyCredential
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class PasskeyMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over the recording connection.
	 *
	 * @return PasskeyMapper
	 */
	private function mapper(): PasskeyMapper {
		return new PasskeyMapper($this->recordingConnection());
	}//end mapper()

	/**
	 * By id is the string-id query on the passkey table.
	 *
	 * @return void
	 */
	public function testFindByIdUsesTheStringIdQuery(): void {
		$this->queueRows([['id' => 'p1', 'owner_id' => 'alice', 'label' => 'Laptop']]);

		$credential = $this->mapper()->findById(id: 'p1');

		$this->assertSame('Laptop', $credential->getLabel());
		$this->assertSame(['select(*)', 'from(keepiq_passkey_credentials)', 'where(eq(id, :p0))'], $this->opsOf(0));
	}//end testFindByIdUsesTheStringIdQuery()

	/**
	 * The owner's list is newest first; the active list filters on status.
	 *
	 * @return void
	 */
	public function testOwnerListsAndTheActiveSet(): void {
		$mapper = $this->mapper();
		$mapper->findByOwner(ownerId: 'alice');
		$mapper->findActiveByOwner(ownerId: 'alice');

		$this->assertSame(
			['select(*)', 'from(keepiq_passkey_credentials)', 'where(eq(owner_id, :p0))', 'orderBy(created_at, DESC)'],
			$this->opsOf(0)
		);
		$this->assertSame(
			['select(*)', 'from(keepiq_passkey_credentials)', 'where(eq(owner_id, :p1))', 'andWhere(eq(status, :p2))'],
			$this->opsOf(1)
		);
		$this->assertSame([':p1' => 'alice', ':p2' => 'active'], $this->paramsOf(1));
	}//end testOwnerListsAndTheActiveSet()

	/**
	 * A credential lookup is scoped to the owner and answers null when absent.
	 *
	 * @return void
	 */
	public function testCredentialLookupIsOwnerScopedAndNullable(): void {
		$this->queueRows([['id' => 'p1', 'owner_id' => 'alice', 'credential_id' => 'cred-1']]);
		$mapper = $this->mapper();

		$this->assertSame('p1', $mapper->findByCredentialId(ownerId: 'alice', credentialId: 'cred-1')?->getId());
		$this->assertNull($mapper->findByCredentialId(ownerId: 'bob', credentialId: 'cred-1'));

		$this->assertSame(
			['select(*)', 'from(keepiq_passkey_credentials)', 'where(eq(owner_id, :p0))', 'andWhere(eq(credential_id, :p1))'],
			$this->opsOf(0)
		);
		$this->assertSame([':p2' => 'bob', ':p3' => 'cred-1'], $this->paramsOf(1));
	}//end testCredentialLookupIsOwnerScopedAndNullable()

	/**
	 * Revoking a token deletes each of its passkeys and counts them per owner.
	 *
	 * @return void
	 */
	public function testDeleteByTokenCountsPerOwner(): void {
		$this->queueRows([
			['id' => 'p1', 'owner_id' => 'alice'],
			['id' => 'p2', 'owner_id' => 'bob'],
			['id' => 'p3', 'owner_id' => 'alice'],
		]);

		$owners = $this->mapper()->deleteByToken(tokenId: 7);

		$this->assertSame(['alice' => 2, 'bob' => 1], $owners);
		$this->assertSame(['select(*)', 'from(keepiq_passkey_credentials)', 'where(eq(token_id, :p0))'], $this->opsOf(0));
		$this->assertSame(IQueryBuilder::PARAM_INT, $this->queries[0]['types'][':p0']);
		$this->assertCount(4, $this->queries, 'One select, then one delete per passkey.');
		$this->assertSame('delete(keepiq_passkey_credentials)', $this->opsOf(1)[0]);
		$this->assertSame('statement', $this->ranOf(3));
	}//end testDeleteByTokenCountsPerOwner()

	/**
	 * The owner delete and the stale mark are owner-scoped statements.
	 *
	 * @return void
	 */
	public function testOwnerDeleteAndStaleMark(): void {
		$mapper = $this->mapper();
		$mapper->deleteByOwner(ownerId: 'alice');
		$mapper->markOwnerStale(ownerId: 'alice');

		$this->assertSame(['delete(keepiq_passkey_credentials)', 'where(eq(owner_id, :p0))'], $this->opsOf(0));
		$this->assertSame(
			['update(keepiq_passkey_credentials)', 'set(status, :p1)', 'where(eq(owner_id, :p2))', 'andWhere(eq(status, :p3))'],
			$this->opsOf(1)
		);
		$this->assertSame([':p1' => 'stale', ':p2' => 'alice', ':p3' => 'active'], $this->paramsOf(1));
		$this->assertSame('statement', $this->ranOf(1));
	}//end testOwnerDeleteAndStaleMark()
}//end class
