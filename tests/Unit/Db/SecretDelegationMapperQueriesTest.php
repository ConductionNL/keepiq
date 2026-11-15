<?php

/**
 * SecretDelegationMapper against a recording connection.
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

use OCA\Keepiq\Db\SecretDelegation;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use PHPUnit\Framework\TestCase;

/**
 * The SQL each SecretDelegationMapper query builds. Delegations are keyed by
 * secret, by original owner and by the (secret, delegate) pair the share and
 * revoke checks ask about; temporary ones are cemented in one conditional
 * UPDATE when the original owner loses access for good.
 *
 * @covers \OCA\Keepiq\Db\SecretDelegationMapper
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @uses   \OCA\Keepiq\Db\SecretDelegation
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class SecretDelegationMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over the recording connection.
	 *
	 * @return SecretDelegationMapper
	 */
	private function mapper(): SecretDelegationMapper {
		return new SecretDelegationMapper(db: $this->recordingConnection());
	}//end mapper()

	/**
	 * A delegation row as the table holds it.
	 *
	 * @param string $id The row id
	 *
	 * @return array<string,mixed>
	 */
	private function row(string $id): array {
		return [
			'id' => $id,
			'secret_id' => 's1',
			'original_owner_id' => 'alice',
			'delegated_to' => 'bob',
			'initiated_by' => 'admin',
			'is_permanent' => false,
		];
	}//end row()

	/**
	 * The lookup by id hydrates the single row.
	 *
	 * @return void
	 */
	public function testFindByIdHydratesTheRow(): void {
		$this->queueRows(rows: [$this->row(id: 'd1')]);

		$found = $this->mapper()->findById(id: 'd1');

		$this->assertInstanceOf(expected: SecretDelegation::class, actual: $found);
		$this->assertSame(expected: 'd1', actual: $found->getId());
		$this->assertSame(expected: 'bob', actual: $found->getDelegatedTo());
		$this->assertSame(expected: 'alice', actual: $found->getOriginalOwnerId());
		$this->assertSame(expected: ['select(*)', 'from(keepiq_secret_delegations)', 'where(eq(id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'd1'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'query', actual: $this->ranOf(index: 0));
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
	 * By-secret and by-owner listings hydrate every row.
	 *
	 * @return void
	 */
	public function testListingsBySecretAndByOriginalOwner(): void {
		$this->queueRows(rows: [$this->row(id: 'd1'), $this->row(id: 'd2')]);
		$this->queueRows(rows: [$this->row(id: 'd3')]);
		$mapper = $this->mapper();

		$bySecret = $mapper->findBySecret(secretId: 's1');
		$byOwner = $mapper->findByOriginalOwner(originalOwnerId: 'alice');

		$this->assertSame(expected: ['d1', 'd2'], actual: array_map(static fn (SecretDelegation $d) => $d->getId(), $bySecret));
		$this->assertSame(expected: ['d3'], actual: array_map(static fn (SecretDelegation $d) => $d->getId(), $byOwner));
		$this->assertSame(expected: ['select(*)', 'from(keepiq_secret_delegations)', 'where(eq(secret_id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 's1'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: ['select(*)', 'from(keepiq_secret_delegations)', 'where(eq(original_owner_id, :p1))'], actual: $this->opsOf(index: 1));
		$this->assertSame(expected: [':p1' => 'alice'], actual: $this->paramsOf(index: 1));
	}//end testListingsBySecretAndByOriginalOwner()

	/**
	 * An empty listing answers an empty array.
	 *
	 * @return void
	 */
	public function testAnEmptyListingAnswersNothing(): void {
		$this->assertSame(expected: [], actual: $this->mapper()->findBySecret(secretId: 'none'));
	}//end testAnEmptyListingAnswersNothing()

	/**
	 * The active-delegate check keys on both secret and delegate.
	 *
	 * @return void
	 */
	public function testActiveDelegateIsKeyedBySecretAndUser(): void {
		$this->queueRows(rows: [$this->row(id: 'd1')]);

		$found = $this->mapper()->findActiveBySecretAndUser(secretId: 's1', userId: 'bob');

		$this->assertSame(expected: 'd1', actual: $found->getId());
		$this->assertSame(
			expected: [
				'select(*)', 'from(keepiq_secret_delegations)', 'where(eq(secret_id, :p0))',
				'andWhere(eq(delegated_to, :p1))',
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 's1', ':p1' => 'bob'], actual: $this->paramsOf(index: 0));
	}//end testActiveDelegateIsKeyedBySecretAndUser()

	/**
	 * No active delegate throws DoesNotExistException.
	 *
	 * @return void
	 */
	public function testActiveDelegateThrowsWhenNone(): void {
		$this->expectException(exception: DoesNotExistException::class);

		$this->mapper()->findActiveBySecretAndUser(secretId: 's1', userId: 'carol');
	}//end testActiveDelegateThrowsWhenNone()

	/**
	 * Two delegations for one pair throws MultipleObjectsReturnedException.
	 *
	 * @return void
	 */
	public function testActiveDelegateThrowsOnDuplicates(): void {
		$this->queueRows(rows: [$this->row(id: 'd1'), $this->row(id: 'd2')]);

		$this->expectException(exception: MultipleObjectsReturnedException::class);

		$this->mapper()->findActiveBySecretAndUser(secretId: 's1', userId: 'bob');
	}//end testActiveDelegateThrowsOnDuplicates()

	/**
	 * Temporary delegations are the original owner's non-permanent rows.
	 *
	 * @return void
	 */
	public function testTemporaryDelegationsAreTheNonPermanentOnes(): void {
		$this->queueRows(rows: [$this->row(id: 'd1')]);

		$found = $this->mapper()->findTemporaryByOriginalOwner(originalOwnerId: 'alice');

		$this->assertCount(expectedCount: 1, haystack: $found);
		$this->assertSame(
			expected: [
				'select(*)', 'from(keepiq_secret_delegations)', 'where(eq(original_owner_id, :p0))',
				'andWhere(eq(is_permanent, :p1))',
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'alice', ':p1' => false], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: \PDO::PARAM_BOOL, actual: $this->queries[0]['types'][':p1']);
	}//end testTemporaryDelegationsAreTheNonPermanentOnes()

	/**
	 * The secret-delete cascade runs one DELETE keyed by secret.
	 *
	 * @return void
	 */
	public function testDeleteBySecretRunsOneDelete(): void {
		$this->mapper()->deleteBySecret(secretId: 's1');

		$this->assertCount(expectedCount: 1, haystack: $this->queries);
		$this->assertSame(expected: ['delete(keepiq_secret_delegations)', 'where(eq(secret_id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 's1'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'statement', actual: $this->ranOf(index: 0));
	}//end testDeleteBySecretRunsOneDelete()

	/**
	 * Cementing promotes only the temporary rows, stamps the moment and
	 * answers the affected-row count.
	 *
	 * @return void
	 */
	public function testMakePermanentPromotesOnlyTemporaryRows(): void {
		$this->affected = [3];

		$this->assertSame(expected: 3, actual: $this->mapper()->makePermanentByOriginalOwner(originalOwnerId: 'alice'));

		$this->assertSame(
			expected: [
				'update(keepiq_secret_delegations)', 'set(is_permanent, :p0)', 'set(made_permanent_at, :p1)',
				'where(eq(original_owner_id, :p2))', 'andWhere(eq(is_permanent, :p3))',
			],
			actual: $this->opsOf(index: 0)
		);
		$params = $this->paramsOf(index: 0);
		$this->assertTrue(condition: $params[':p0']);
		$this->assertMatchesRegularExpression(pattern: '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', string: $params[':p1']);
		$this->assertSame(expected: 'alice', actual: $params[':p2']);
		$this->assertFalse(condition: $params[':p3']);
		$this->assertSame(expected: \PDO::PARAM_BOOL, actual: $this->queries[0]['types'][':p0']);
		$this->assertSame(expected: \PDO::PARAM_BOOL, actual: $this->queries[0]['types'][':p3']);
		$this->assertSame(expected: 'statement', actual: $this->ranOf(index: 0));
	}//end testMakePermanentPromotesOnlyTemporaryRows()

	/**
	 * Nothing to cement answers zero.
	 *
	 * @return void
	 */
	public function testMakePermanentAnswersZeroWhenNothingMatches(): void {
		$this->affected = [0];

		$this->assertSame(expected: 0, actual: $this->mapper()->makePermanentByOriginalOwner(originalOwnerId: 'nobody'));
	}//end testMakePermanentAnswersZeroWhenNothingMatches()
}//end class
