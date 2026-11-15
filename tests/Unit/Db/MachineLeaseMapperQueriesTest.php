<?php

/**
 * MachineLeaseMapper against a recording connection.
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
use OCA\Keepiq\Db\MachineLeaseMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The SQL each MachineLeaseMapper query builds: the live lease is the newest
 * active, unexpired one for an application and secret (null when none), the
 * latest is the newest regardless of state, listings are newest first and
 * bounded, and the expiry sweep takes active leases past their end.
 *
 * @covers \OCA\Keepiq\Db\MachineLeaseMapper
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @uses   \OCA\Keepiq\Db\MachineLease
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class MachineLeaseMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over the recording connection.
	 *
	 * @return MachineLeaseMapper
	 */
	private function mapper(): MachineLeaseMapper {
		return new MachineLeaseMapper($this->recordingConnection());
	}//end mapper()

	/**
	 * The live lease is the newest active unexpired one, or null.
	 *
	 * @return void
	 */
	public function testFindLiveIsTheNewestActiveUnexpiredLease(): void {
		$now = new DateTime('2026-04-01T12:00:00+00:00');
		$this->queueRows([['id' => 'm1', 'application_id' => 'a1', 'secret_id' => 's1']]);
		$mapper = $this->mapper();

		$this->assertSame('m1', $mapper->findLive(applicationId: 'a1', secretId: 's1', now: $now)?->getId());
		$this->assertNull($mapper->findLive(applicationId: 'a1', secretId: 's2', now: $now));

		$this->assertSame(
			[
				'select(*)', 'from(keepiq_machine_leases)', 'where(eq(application_id, :p0))', 'andWhere(eq(secret_id, :p1))',
				'andWhere(eq(status, :p2))', 'andWhere(gt(expires_at, :p3))', 'orderBy(granted_at, DESC)', 'setMaxResults(1)',
			],
			$this->opsOf(0)
		);
		$this->assertSame('active', $this->paramsOf(0)[':p2']);
		$this->assertSame($now, $this->paramsOf(0)[':p3']);
		$this->assertSame('datetime', $this->queries[0]['types'][':p3']);
	}//end testFindLiveIsTheNewestActiveUnexpiredLease()

	/**
	 * The latest lease ignores status and expiry.
	 *
	 * @return void
	 */
	public function testFindLatestIgnoresStatusAndExpiry(): void {
		$this->queueRows([['id' => 'm2']]);
		$mapper = $this->mapper();

		$this->assertSame('m2', $mapper->findLatest(applicationId: 'a1', secretId: 's1')?->getId());
		$this->assertNull($mapper->findLatest(applicationId: 'a1', secretId: 's1'));

		$this->assertSame(
			[
				'select(*)', 'from(keepiq_machine_leases)', 'where(eq(application_id, :p0))', 'andWhere(eq(secret_id, :p1))',
				'orderBy(granted_at, DESC)', 'setMaxResults(1)',
			],
			$this->opsOf(0)
		);
	}//end testFindLatestIgnoresStatusAndExpiry()

	/**
	 * By id, by application (bounded, newest first) and the application delete.
	 *
	 * @return void
	 */
	public function testApplicationListingAndDelete(): void {
		$this->queueRows([['id' => 'm1']]);
		$mapper = $this->mapper();

		$this->assertSame('m1', $mapper->findById(id: 'm1')->getId());
		$mapper->findByApplication(applicationId: 'a1', limit: 20);
		$mapper->deleteByApplication(applicationId: 'a1');

		$this->assertSame(['select(*)', 'from(keepiq_machine_leases)', 'where(eq(id, :p0))'], $this->opsOf(0));
		$this->assertSame(
			['select(*)', 'from(keepiq_machine_leases)', 'where(eq(application_id, :p1))', 'orderBy(granted_at, DESC)', 'setMaxResults(20)'],
			$this->opsOf(1)
		);
		$this->assertSame(['delete(keepiq_machine_leases)', 'where(eq(application_id, :p2))'], $this->opsOf(2));
		$this->assertSame('statement', $this->ranOf(2));
	}//end testApplicationListingAndDelete()

	/**
	 * The expiry sweep takes active leases at or past their end, bounded.
	 *
	 * @return void
	 */
	public function testExpiredActiveSweep(): void {
		$now = new DateTime('2026-04-01T12:00:00+00:00');

		$this->mapper()->findExpiredActive(now: $now);

		$this->assertSame(
			['select(*)', 'from(keepiq_machine_leases)', 'where(eq(status, :p0))', 'andWhere(lte(expires_at, :p1))', 'setMaxResults(500)'],
			$this->opsOf(0)
		);
		$this->assertSame([':p0' => 'active', ':p1' => $now], $this->paramsOf(0));
	}//end testExpiredActiveSweep()
}//end class
