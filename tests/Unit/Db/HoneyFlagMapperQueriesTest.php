<?php

/**
 * HoneyFlagMapper against a recording connection.
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

use OCA\Keepiq\Db\HoneyFlag;
use OCA\Keepiq\Db\HoneyFlagMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The by-secret and by-owner lookups.
 *
 * @covers \OCA\Keepiq\Db\HoneyFlagMapper
 * @uses   \OCA\Keepiq\Db\HoneyFlag
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class HoneyFlagMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over a recording connection.
	 *
	 * @return HoneyFlagMapper
	 */
	private function mapper(): HoneyFlagMapper {
		return new HoneyFlagMapper($this->recordingConnection());
	}//end mapper()

	/**
	 * The flag of a secret is found by its secret id.
	 *
	 * @return void
	 */
	public function testFindBySecretId(): void {
		$this->queueRows([['id' => 'f1', 'secret_id' => 's1', 'owner_id' => 'alice']]);

		$flag = $this->mapper()->findBySecretId(secretId: 's1');

		$this->assertInstanceOf(HoneyFlag::class, $flag);
		$this->assertSame('s1', $flag->getSecretId());
		$this->assertSame(['select(*)', 'from(keepiq_honey_flags)', 'where(eq(secret_id, :p0))'], $this->opsOf(0));
		$this->assertSame([':p0' => 's1'], $this->paramsOf(0));
	}//end testFindBySecretId()

	/**
	 * A secret without a flag throws.
	 *
	 * @return void
	 */
	public function testFindBySecretIdThrowsWhenMissing(): void {
		$this->expectException(DoesNotExistException::class);

		$this->mapper()->findBySecretId(secretId: 'none');
	}//end testFindBySecretIdThrowsWhenMissing()

	/**
	 * An owner's flags are listed.
	 *
	 * @return void
	 */
	public function testFindByOwner(): void {
		$this->queueRows([['id' => 'f1', 'owner_id' => 'alice'], ['id' => 'f2', 'owner_id' => 'alice']]);

		$flags = $this->mapper()->findByOwner(ownerId: 'alice');

		$this->assertSame(['f1', 'f2'], array_map(static fn (HoneyFlag $f) => $f->getId(), $flags));
		$this->assertSame(['select(*)', 'from(keepiq_honey_flags)', 'where(eq(owner_id, :p0))'], $this->opsOf(0));
		$this->assertSame([':p0' => 'alice'], $this->paramsOf(0));
	}//end testFindByOwner()
}//end class
