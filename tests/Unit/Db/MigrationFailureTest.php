<?php

/**
 * MigrationFailure entity: hydration and the API shape.
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
use OCA\Keepiq\Db\MigrationFailure;
use PHPUnit\Framework\TestCase;

/**
 * A migration failure hydrates its columns with their declared types and
 * serializes every field for the API.
 *
 * @covers \OCA\Keepiq\Db\MigrationFailure
 */
class MigrationFailureTest extends TestCase {

	/**
	 * A database row hydrates every column, the id as an integer and the timestamp as a DateTime.
	 *
	 * @return void
	 */
	public function testFromRowHydratesTheColumnsWithTheirTypes(): void {
		$failure = MigrationFailure::fromRow(
			[
				'id' => '7',
				'migration_id' => 'mig-1',
				'store' => 'openregister',
				'record_id' => 'rec-1',
				'secret_id' => 'sec-1',
				'message' => 'decrypt failed',
				'created_at' => '2026-01-02 03:04:05',
			]
		);

		$this->assertSame(expected: 7, actual: $failure->getId());
		$this->assertSame(expected: 'mig-1', actual: $failure->getMigrationId());
		$this->assertSame(expected: 'openregister', actual: $failure->getStore());
		$this->assertSame(expected: 'rec-1', actual: $failure->getRecordId());
		$this->assertSame(expected: 'sec-1', actual: $failure->getSecretId());
		$this->assertSame(expected: 'decrypt failed', actual: $failure->getMessage());
		$this->assertSame(expected: '2026-01-02 03:04:05', actual: $failure->getCreatedAt()->format('Y-m-d H:i:s'));
	}//end testFromRowHydratesTheColumnsWithTheirTypes()

	/**
	 * The API shape carries every field, the timestamp in ISO 8601.
	 *
	 * @return void
	 */
	public function testJsonSerializeCarriesEveryField(): void {
		$failure = new MigrationFailure();
		$failure->setId(9);
		$failure->setMigrationId('mig-2');
		$failure->setStore('local');
		$failure->setRecordId('rec-2');
		$failure->setSecretId('sec-2');
		$failure->setMessage('missing key');
		$failure->setCreatedAt(new DateTime('2026-01-02T03:04:05+00:00'));

		$this->assertSame(
			expected: [
				'id' => 9,
				'migrationId' => 'mig-2',
				'store' => 'local',
				'recordId' => 'rec-2',
				'secretId' => 'sec-2',
				'message' => 'missing key',
				'createdAt' => '2026-01-02T03:04:05+00:00',
			],
			actual: $failure->jsonSerialize()
		);
	}//end testJsonSerializeCarriesEveryField()

	/**
	 * A fresh failure serializes its unset message and timestamp as null.
	 *
	 * @return void
	 */
	public function testJsonSerializeOfAFreshFailure(): void {
		$json = (new MigrationFailure())->jsonSerialize();

		$this->assertNull(actual: $json['message']);
		$this->assertNull(actual: $json['createdAt']);
		$this->assertSame(expected: '', actual: $json['migrationId']);
	}//end testJsonSerializeOfAFreshFailure()
}//end class
