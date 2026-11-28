<?php

/**
 * CACertificateMapper against a recording connection.
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
use OCA\Keepiq\Db\CACertificate;
use OCA\Keepiq\Db\CACertificateMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The active-intermediate and newest-root lookups and the expiring-soon listing.
 *
 * @covers \OCA\Keepiq\Db\CACertificateMapper
 * @uses   \OCA\Keepiq\Db\CACertificate
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class CACertificateMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over a recording connection.
	 *
	 * @return CACertificateMapper
	 */
	private function mapper(): CACertificateMapper {
		return new CACertificateMapper($this->recordingConnection());
	}//end mapper()

	/**
	 * The active intermediate is the intermediate flagged active.
	 *
	 * @return void
	 */
	public function testFindActiveIntermediate(): void {
		$this->queueRows([['id' => 'c1', 'type' => 'intermediate']]);

		$this->assertSame('c1', $this->mapper()->findActiveIntermediate()->getId());
		$this->assertSame(
			['select(*)', 'from(keepiq_ca_certs)', 'where(eq(type, :p0))', 'andWhere(eq(is_active, :p1))'],
			$this->opsOf(0)
		);
		$this->assertSame([':p0' => 'intermediate', ':p1' => true], $this->paramsOf(0));
		$this->assertSame(IQueryBuilder::PARAM_BOOL, $this->queries[0]['types'][':p1']);
	}//end testFindActiveIntermediate()

	/**
	 * The root is the newest root certificate.
	 *
	 * @return void
	 */
	public function testFindRootIsTheNewestRoot(): void {
		$this->queueRows([['id' => 'r2', 'type' => 'root']]);

		$this->assertSame('r2', $this->mapper()->findRoot()->getId());
		$this->assertSame(
			['select(*)', 'from(keepiq_ca_certs)', 'where(eq(type, :p0))', 'orderBy(created_at, DESC)', 'setMaxResults(1)'],
			$this->opsOf(0)
		);
		$this->assertSame([':p0' => 'root'], $this->paramsOf(0));
	}//end testFindRootIsTheNewestRoot()

	/**
	 * Expiring-soon is unrevoked certificates expiring within the given days.
	 *
	 * @return void
	 */
	public function testFindExpiringSoon(): void {
		$this->queueRows([['id' => 'c3']]);
		$before = new DateTime('+30 days');

		$rows = $this->mapper()->findExpiringSoon(days: 30);

		$after = new DateTime('+30 days');
		$this->assertCount(1, $rows);
		$this->assertSame(
			['select(*)', 'from(keepiq_ca_certs)', 'where(lte(expires_at, :p0))', 'andWhere(isNull(revoked_at))'],
			$this->opsOf(0)
		);
		$threshold = $this->paramsOf(0)[':p0'];
		$this->assertInstanceOf(DateTime::class, $threshold);
		$this->assertGreaterThanOrEqual($before->getTimestamp(), $threshold->getTimestamp());
		$this->assertLessThanOrEqual($after->getTimestamp(), $threshold->getTimestamp());
	}//end testFindExpiringSoon()
}//end class
