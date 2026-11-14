<?php

/**
 * RecoveryEnrolmentMapper against a recording connection.
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

use OCA\Keepiq\Db\RecoveryEnrolment;
use OCA\Keepiq\Db\RecoveryEnrolmentMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use PHPUnit\Framework\TestCase;

/**
 * The SQL each RecoveryEnrolmentMapper query builds: a user's enrolments
 * newest first, and the enrolments sealed under a suite or a recovery key.
 *
 * @covers \OCA\Keepiq\Db\RecoveryEnrolmentMapper
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @uses   \OCA\Keepiq\Db\RecoveryEnrolment
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class RecoveryEnrolmentMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over the recording connection.
	 *
	 * @return RecoveryEnrolmentMapper
	 */
	private function mapper(): RecoveryEnrolmentMapper {
		return new RecoveryEnrolmentMapper(db: $this->recordingConnection());
	}//end mapper()

	/**
	 * By id hydrates the row; no row throws.
	 *
	 * @return void
	 */
	public function testFindById(): void {
		$this->queueRows(rows: [['id' => 'e1', 'user_id' => 'alice', 'envelope' => 'env']]);
		$mapper = $this->mapper();

		$enrolment = $mapper->findById(id: 'e1');

		$this->assertSame(expected: 'e1', actual: $enrolment->getId());
		$this->assertSame(expected: 'alice', actual: $enrolment->getUserId());
		$this->assertSame(expected: 'env', actual: $enrolment->getEnvelope());
		$this->assertSame(expected: ['select(*)', 'from(keepiq_recovery_enrolments)', 'where(eq(id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'e1'], actual: $this->paramsOf(index: 0));

		$this->expectException(exception: DoesNotExistException::class);
		$mapper->findById(id: 'gone');
	}//end testFindById()

	/**
	 * Two rows under one id is ambiguous.
	 *
	 * @return void
	 */
	public function testFindByIdRefusesTwoRows(): void {
		$this->queueRows(rows: [['id' => 'e1'], ['id' => 'e1']]);

		$this->expectException(exception: MultipleObjectsReturnedException::class);
		$this->mapper()->findById(id: 'e1');
	}//end testFindByIdRefusesTwoRows()

	/**
	 * A user's enrolments are newest first.
	 *
	 * @return void
	 */
	public function testByUserIsNewestFirst(): void {
		$this->queueRows(rows: [['id' => 'e2'], ['id' => 'e1']]);

		$enrolments = $this->mapper()->findByUser(userId: 'alice');

		$this->assertSame(expected: ['e2', 'e1'], actual: array_map(static fn (RecoveryEnrolment $e): string => $e->getId(), $enrolments));
		$this->assertSame(
			expected: ['select(*)', 'from(keepiq_recovery_enrolments)', 'where(eq(user_id, :p0))', 'orderBy(enrolled_at, DESC)'],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'alice'], actual: $this->paramsOf(index: 0));
	}//end testByUserIsNewestFirst()

	/**
	 * By suite and by key each filter on their column; nothing found is an empty list.
	 *
	 * @return void
	 */
	public function testListsBySuiteAndByKey(): void {
		$this->queueRows(rows: [['id' => 'e3', 'suite_id' => 'suite-a']]);
		$mapper = $this->mapper();

		$bySuite = $mapper->findBySuite(suiteId: 'suite-a');
		$byKey = $mapper->findByKey(recoveryKeyId: 'k1');

		$this->assertSame(expected: 'suite-a', actual: $bySuite[0]->getSuiteId());
		$this->assertSame(expected: [], actual: $byKey);
		$this->assertSame(expected: ['select(*)', 'from(keepiq_recovery_enrolments)', 'where(eq(suite_id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: ['select(*)', 'from(keepiq_recovery_enrolments)', 'where(eq(recovery_key_id, :p1))'], actual: $this->opsOf(index: 1));
		$this->assertSame(expected: [':p0' => 'suite-a'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: [':p1' => 'k1'], actual: $this->paramsOf(index: 1));
	}//end testListsBySuiteAndByKey()
}//end class
