<?php

/**
 * RecoveryOfficerMapper against a recording connection.
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

use OCA\Keepiq\Db\RecoveryOfficer;
use OCA\Keepiq\Db\RecoveryOfficerMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use PHPUnit\Framework\TestCase;

/**
 * The SQL each RecoveryOfficerMapper query builds: the officers of a recovery
 * key, one officer's seat on one key, every seat an officer holds, and the
 * delete that removes all of an officer's seats.
 *
 * @covers \OCA\Keepiq\Db\RecoveryOfficerMapper
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @uses   \OCA\Keepiq\Db\RecoveryOfficer
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class RecoveryOfficerMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over the recording connection.
	 *
	 * @return RecoveryOfficerMapper
	 */
	private function mapper(): RecoveryOfficerMapper {
		return new RecoveryOfficerMapper(db: $this->recordingConnection());
	}//end mapper()

	/**
	 * By id hydrates the row; no row throws.
	 *
	 * @return void
	 */
	public function testFindById(): void {
		$this->queueRows(rows: [['id' => 'o1', 'officer_uid' => 'carol', 'wrapped_private_key' => 'wpk']]);
		$mapper = $this->mapper();

		$officer = $mapper->findById(id: 'o1');

		$this->assertSame(expected: 'o1', actual: $officer->getId());
		$this->assertSame(expected: 'carol', actual: $officer->getOfficerUid());
		$this->assertSame(expected: 'wpk', actual: $officer->getWrappedPrivateKey());
		$this->assertSame(expected: ['select(*)', 'from(keepiq_recovery_officers)', 'where(eq(id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'o1'], actual: $this->paramsOf(index: 0));

		$this->expectException(exception: DoesNotExistException::class);
		$mapper->findById(id: 'gone');
	}//end testFindById()

	/**
	 * A key's officers and an officer's seats each filter on their column.
	 *
	 * @return void
	 */
	public function testListsByKeyAndByOfficer(): void {
		$this->queueRows(rows: [['id' => 'o1'], ['id' => 'o2']]);
		$this->queueRows(rows: [['id' => 'o3', 'recovery_key_id' => 'k2']]);
		$mapper = $this->mapper();

		$byKey = $mapper->findByKey(recoveryKeyId: 'k1');
		$byOfficer = $mapper->findByOfficer(officerUid: 'carol');

		$this->assertSame(expected: ['o1', 'o2'], actual: array_map(static fn (RecoveryOfficer $o): string => $o->getId(), $byKey));
		$this->assertSame(expected: 'k2', actual: $byOfficer[0]->getRecoveryKeyId());
		$this->assertSame(expected: ['select(*)', 'from(keepiq_recovery_officers)', 'where(eq(recovery_key_id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: ['select(*)', 'from(keepiq_recovery_officers)', 'where(eq(officer_uid, :p1))'], actual: $this->opsOf(index: 1));
		$this->assertSame(expected: [':p0' => 'k1'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: [':p1' => 'carol'], actual: $this->paramsOf(index: 1));
	}//end testListsByKeyAndByOfficer()

	/**
	 * One officer's seat on one key filters on both; none throws.
	 *
	 * @return void
	 */
	public function testFindForKeyAndOfficer(): void {
		$this->queueRows(rows: [['id' => 'o4', 'recovery_key_id' => 'k1', 'officer_uid' => 'carol']]);
		$mapper = $this->mapper();

		$this->assertSame(expected: 'o4', actual: $mapper->findForKeyAndOfficer(recoveryKeyId: 'k1', officerUid: 'carol')->getId());
		$this->assertSame(
			expected: ['select(*)', 'from(keepiq_recovery_officers)', 'where(eq(recovery_key_id, :p0))', 'andWhere(eq(officer_uid, :p1))'],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'k1', ':p1' => 'carol'], actual: $this->paramsOf(index: 0));

		$this->expectException(exception: DoesNotExistException::class);
		$mapper->findForKeyAndOfficer(recoveryKeyId: 'k1', officerUid: 'dave');
	}//end testFindForKeyAndOfficer()

	/**
	 * Two seats for one officer on one key is ambiguous.
	 *
	 * @return void
	 */
	public function testFindForKeyAndOfficerRefusesTwoRows(): void {
		$this->queueRows(rows: [['id' => 'o4'], ['id' => 'o5']]);

		$this->expectException(exception: MultipleObjectsReturnedException::class);
		$this->mapper()->findForKeyAndOfficer(recoveryKeyId: 'k1', officerUid: 'carol');
	}//end testFindForKeyAndOfficerRefusesTwoRows()

	/**
	 * Deleting an officer runs one delete scoped to that officer.
	 *
	 * @return void
	 */
	public function testDeleteByOfficerRunsAScopedStatement(): void {
		$this->mapper()->deleteByOfficer(officerUid: 'carol');

		$this->assertSame(expected: ['delete(keepiq_recovery_officers)', 'where(eq(officer_uid, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'carol'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'statement', actual: $this->ranOf(index: 0));
	}//end testDeleteByOfficerRunsAScopedStatement()
}//end class
