<?php

/**
 * RotationFlagMapper against a recording connection.
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

use OCA\Keepiq\Db\RotationFlag;
use OCA\Keepiq\Db\RotationFlagMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use PHPUnit\Framework\TestCase;

/**
 * The SQL each RotationFlagMapper query builds: a secret's flag, an owner's
 * open flags joined to the secrets that user personally owns, and the delete
 * of a secret's flag.
 *
 * @covers \OCA\Keepiq\Db\RotationFlagMapper
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @uses   \OCA\Keepiq\Db\RotationFlag
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class RotationFlagMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over the recording connection.
	 *
	 * @return RotationFlagMapper
	 */
	private function mapper(): RotationFlagMapper {
		return new RotationFlagMapper(db: $this->recordingConnection());
	}//end mapper()

	/**
	 * By id hydrates the row; no row throws.
	 *
	 * @return void
	 */
	public function testFindById(): void {
		$this->queueRows(rows: [['id' => 'r1', 'reason' => 'leak', 'status' => 'open']]);
		$mapper = $this->mapper();

		$flag = $mapper->findById(id: 'r1');

		$this->assertSame(expected: 'r1', actual: $flag->getId());
		$this->assertSame(expected: 'leak', actual: $flag->getReason());
		$this->assertSame(expected: ['select(*)', 'from(keepiq_rotation_flags)', 'where(eq(id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'r1'], actual: $this->paramsOf(index: 0));

		$this->expectException(exception: DoesNotExistException::class);
		$mapper->findById(id: 'gone');
	}//end testFindById()

	/**
	 * A secret has at most one flag; none throws.
	 *
	 * @return void
	 */
	public function testFindBySecret(): void {
		$this->queueRows(rows: [['id' => 'r2', 'secret_id' => 's1']]);
		$mapper = $this->mapper();

		$this->assertSame(expected: 's1', actual: $mapper->findBySecret(secretId: 's1')->getSecretId());
		$this->assertSame(expected: ['select(*)', 'from(keepiq_rotation_flags)', 'where(eq(secret_id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 's1'], actual: $this->paramsOf(index: 0));

		$this->expectException(exception: DoesNotExistException::class);
		$mapper->findBySecret(secretId: 's2');
	}//end testFindBySecret()

	/**
	 * Two flags for one secret is ambiguous.
	 *
	 * @return void
	 */
	public function testFindBySecretRefusesTwoRows(): void {
		$this->queueRows(rows: [['id' => 'r2'], ['id' => 'r3']]);

		$this->expectException(exception: MultipleObjectsReturnedException::class);
		$this->mapper()->findBySecret(secretId: 's1');
	}//end testFindBySecretRefusesTwoRows()

	/**
	 * Open flags for an owner join to the secrets that user personally owns.
	 *
	 * @return void
	 */
	public function testOpenForOwnerJoinsPersonallyOwnedSecrets(): void {
		$this->queueRows(rows: [['id' => 'r4', 'status' => 'open'], ['id' => 'r5', 'status' => 'open']]);

		$open = $this->mapper()->findOpenForOwner(ownerId: 'alice');

		$this->assertSame(expected: ['r4', 'r5'], actual: array_map(static fn (RotationFlag $f): string => $f->getId(), $open));
		$this->assertSame(
			expected: [
				'select(f.*)', 'from(keepiq_rotation_flags, f)', 'innerJoin(f, keepiq_secrets, s, eq(f.secret_id, s.id))',
				'where(eq(f.status, :p0))', 'andWhere(eq(s.owner_type, :p1))', 'andWhere(eq(s.owner_id, :p2))',
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'open', ':p1' => 'user', ':p2' => 'alice'], actual: $this->paramsOf(index: 0));
	}//end testOpenForOwnerJoinsPersonallyOwnedSecrets()

	/**
	 * Deleting a secret's flag runs one scoped statement.
	 *
	 * @return void
	 */
	public function testDeleteBySecretRunsAScopedStatement(): void {
		$this->mapper()->deleteBySecret(secretId: 's1');

		$this->assertSame(expected: ['delete(keepiq_rotation_flags)', 'where(eq(secret_id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 's1'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'statement', actual: $this->ranOf(index: 0));
	}//end testDeleteBySecretRunsAScopedStatement()
}//end class
