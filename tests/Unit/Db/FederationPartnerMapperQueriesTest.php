<?php

/**
 * FederationPartnerMapper against a recording connection.
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

use OCA\Keepiq\Db\FederationPartner;
use OCA\Keepiq\Db\FederationPartnerMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * The SQL each FederationPartnerMapper query builds: lookup by id, by host, and every
 * partner in the order it was added.
 *
 * @covers \OCA\Keepiq\Db\FederationPartnerMapper
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @uses   \OCA\Keepiq\Db\FederationPartner
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class FederationPartnerMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over the recording connection.
	 *
	 * @return FederationPartnerMapper
	 */
	private function mapper(): FederationPartnerMapper {
		return new FederationPartnerMapper(db: $this->recordingConnection());
	}//end mapper()

	/**
	 * Lookup by id hydrates the row; no row throws.
	 *
	 * @return void
	 */
	public function testFindById(): void {
		$this->queueRows(rows: [['id' => 'r1']]);
		$mapper = $this->mapper();

		$this->assertSame(expected: 'r1', actual: $mapper->findById(id: 'r1')->getId());
		$this->assertSame(expected: ['select(*)', 'from(keepiq_federation_partners)', 'where(eq(id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'r1'], actual: $this->paramsOf(index: 0));

		$this->expectException(exception: DoesNotExistException::class);
		$mapper->findById(id: 'gone');
	}//end testFindById()

	/**
	 * The partner of a host; an unknown host throws.
	 *
	 * @return void
	 */
	public function testFindByHost(): void {
		$this->queueRows(rows: [['id' => 'r1']]);
		$mapper = $this->mapper();

		$this->assertSame(expected: 'r1', actual: $mapper->findByHost(host: 'peer.example')->getId());
		$this->assertSame(
			expected: [
				'select(*)', 'from(keepiq_federation_partners)', 'where(eq(host, :p0))'
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'peer.example'], actual: $this->paramsOf(index: 0));

		$this->expectException(exception: DoesNotExistException::class);
		$mapper->findByHost(host: 'unknown.example');
	}//end testFindByHost()

	/**
	 * Every partner, oldest first.
	 *
	 * @return void
	 */
	public function testFindAllPartners(): void {
		$this->queueRows(rows: [['id' => 'r1'], ['id' => 'r2']]);

		$rows = $this->mapper()->findAllPartners();

		$this->assertSame(expected: ['r1', 'r2'], actual: array_map(static fn (FederationPartner $e): string => $e->getId(), $rows));
		$this->assertSame(
			expected: [
				'select(*)', 'from(keepiq_federation_partners)', 'orderBy(added_at, ASC)'
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'query', actual: $this->ranOf(index: 0));
	}//end testFindAllPartners()

	/**
	 * The partners with an offered root renewal, for the daily reminders.
	 *
	 * @return void
	 */
	public function testFindWithOfferedRenewal(): void {
		$this->queueRows(rows: [['id' => 'r1', 'pending_root_fingerprint' => str_repeat('cd', 32)]]);

		$rows = $this->mapper()->findWithOfferedRenewal();

		$this->assertSame(expected: str_repeat('cd', 32), actual: $rows[0]->getPendingRootFingerprint());
		$this->assertSame(
			expected: ['select(*)', 'from(keepiq_federation_partners)', 'where(isNotNull(pending_root_fingerprint))'],
			actual: $this->opsOf(index: 0)
		);
	}//end testFindWithOfferedRenewal()

	/**
	 * The partners whose renewal notice is waiting and due.
	 *
	 * @return void
	 */
	public function testFindDueRenewalNotices(): void {
		$now = new \DateTime('2026-11-25 12:00:00');
		$this->queueRows(rows: [['id' => 'r1']]);

		$this->assertCount(expectedCount: 1, haystack: $this->mapper()->findDueRenewalNotices(now: $now));
		$this->assertSame(
			expected: [
				'select(*)', 'from(keepiq_federation_partners)', 'where(isNotNull(renewal_notice_next_at))',
				'andWhere(lte(renewal_notice_next_at, :p0))',
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => $now], actual: $this->paramsOf(index: 0));
	}//end testFindDueRenewalNotices()
}//end class
