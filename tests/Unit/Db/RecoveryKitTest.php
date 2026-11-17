<?php

/**
 * RecoveryKit entity: hydration and what settings show.
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
use OCA\Keepiq\Db\RecoveryKit;
use PHPUnit\Framework\TestCase;

/**
 * A recovery kit hydrates its columns with their declared types and never
 * serializes its wrapped key.
 *
 * @covers \OCA\Keepiq\Db\RecoveryKit
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class RecoveryKitTest extends TestCase {

	/**
	 * A database row hydrates every column, the timestamp as a DateTime.
	 *
	 * @return void
	 */
	public function testFromRowHydratesTheColumnsWithTheirTypes(): void {
		$kit = RecoveryKit::fromRow(
			[
				'id' => 'kit-1',
				'user_id' => 'alice',
				'suite_id' => 'suite-a',
				'wrapped_key' => 'wrapped',
				'kdf' => 'argon2id',
				'created_at' => '2026-03-04 05:06:07',
			]
		);

		$this->assertSame(expected: 'kit-1', actual: $kit->getId());
		$this->assertSame(expected: 'alice', actual: $kit->getUserId());
		$this->assertSame(expected: 'suite-a', actual: $kit->getSuiteId());
		$this->assertSame(expected: 'wrapped', actual: $kit->getWrappedKey());
		$this->assertSame(expected: 'argon2id', actual: $kit->getKdf());
		$this->assertInstanceOf(expected: DateTime::class, actual: $kit->getCreatedAt());
		$this->assertSame(expected: '2026-03-04 05:06:07', actual: $kit->getCreatedAt()->format('Y-m-d H:i:s'));
		$this->assertSame(expected: [], actual: $kit->getUpdatedFields());
	}//end testFromRowHydratesTheColumnsWithTheirTypes()

	/**
	 * The serialized kit carries its suite and creation time, never the wrapped key or KDF.
	 *
	 * @return void
	 */
	public function testJsonSerializeLeavesOutTheWrappedKey(): void {
		$kit = new RecoveryKit();
		$kit->setUserId('alice');
		$kit->setSuiteId('suite-a');
		$kit->setWrappedKey('wrapped');
		$kit->setKdf('argon2id');
		$kit->setCreatedAt(new DateTime('2026-03-04T05:06:07+00:00'));

		$this->assertSame(
			expected: ['suiteId' => 'suite-a', 'createdAt' => '2026-03-04T05:06:07+00:00'],
			actual: $kit->jsonSerialize()
		);
	}//end testJsonSerializeLeavesOutTheWrappedKey()

	/**
	 * A kit without a creation time serializes it as null.
	 *
	 * @return void
	 */
	public function testJsonSerializeWithoutCreationTime(): void {
		$kit = new RecoveryKit();
		$kit->setSuiteId('suite-b');

		$this->assertSame(expected: ['suiteId' => 'suite-b', 'createdAt' => null], actual: $kit->jsonSerialize());
	}//end testJsonSerializeWithoutCreationTime()
}//end class
