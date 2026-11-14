<?php

/**
 * QueryCount reads the cnt column of a count query.
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

use OCA\Keepiq\Db\QueryCount;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use PHPUnit\Framework\TestCase;

/**
 * A count query's cnt column as an int, zero when the query returns no row.
 *
 * @covers \OCA\Keepiq\Db\QueryCount
 */
class QueryCountTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The cnt column is read and cast to an int.
	 *
	 * @return void
	 */
	public function testReadCastsTheCountToAnInt(): void {
		$this->queueRows([['cnt' => '7']]);
		$qb = $this->recordingQueryBuilder();

		$this->assertSame(7, (new QueryCount())->read(qb: $qb));
		$this->assertSame('query', $this->ranOf(0));
	}//end testReadCastsTheCountToAnInt()

	/**
	 * No row, or a row without cnt, counts as zero.
	 *
	 * @return void
	 */
	public function testReadIsZeroWithoutACount(): void {
		$this->queueRows([]);
		$this->queueRows([['other' => 3]]);

		$this->assertSame(0, (new QueryCount())->read(qb: $this->recordingQueryBuilder()));
		$this->assertSame(0, (new QueryCount())->read(qb: $this->recordingQueryBuilder()));
	}//end testReadIsZeroWithoutACount()
}//end class
