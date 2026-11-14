<?php

/**
 * SecretTrashMapper against a recording connection.
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
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretTrashMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The listing of user secrets trashed before a cutoff, oldest first, with a limit of at least one.
 *
 * @covers \OCA\Keepiq\Db\SecretTrashMapper
 * @uses   \OCA\Keepiq\Db\Secret
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class SecretTrashMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over a recording connection.
	 *
	 * @return SecretTrashMapper
	 */
	private function mapper(): SecretTrashMapper {
		return new SecretTrashMapper($this->recordingConnection());
	}//end mapper()

	/**
	 * User secrets trashed before the cutoff, oldest first, limited.
	 *
	 * @return void
	 */
	public function testFindTrashedBefore(): void {
		$cutoff = new DateTime('2026-10-01T00:00:00+00:00');
		$this->queueRows([['id' => 's1'], ['id' => 's2']]);

		$rows = $this->mapper()->findTrashedBefore(cutoff: $cutoff, limit: 50);

		$this->assertSame(['s1', 's2'], array_map(static fn (Secret $s) => $s->getId(), $rows));
		$this->assertSame(
			[
				'select(*)', 'from(keepiq_secrets)', 'where(eq(owner_type, :p0))', 'andWhere(isNotNull(trashed_at))',
				'andWhere(lt(trashed_at, :p1))', 'orderBy(trashed_at, ASC)', 'setMaxResults(50)',
			],
			$this->opsOf(0)
		);
		$this->assertSame([':p0' => 'user', ':p1' => $cutoff], $this->paramsOf(0));
	}//end testFindTrashedBefore()

	/**
	 * The default limit is 500 and a non-positive limit still asks for one row.
	 *
	 * @return void
	 */
	public function testLimitDefaultsAndFloorsAtOne(): void {
		$cutoff = new DateTime('2026-10-01T00:00:00+00:00');
		$mapper = $this->mapper();

		$mapper->findTrashedBefore(cutoff: $cutoff);
		$mapper->findTrashedBefore(cutoff: $cutoff, limit: 0);

		$this->assertSame('setMaxResults(500)', $this->opsOf(0)[6]);
		$this->assertSame('setMaxResults(1)', $this->opsOf(1)[6]);
	}//end testLimitDefaultsAndFloorsAtOne()
}//end class
