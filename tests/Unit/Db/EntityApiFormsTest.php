<?php

/**
 * The API forms of the passkey, ephemeral send, team folder member, secret
 * type and expiry policy entities: what each shows, and the key material it
 * keeps to itself.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Db;

use DateTime;
use OCA\Keepiq\Db\EphemeralSend;
use OCA\Keepiq\Db\ExpiryPolicy;
use OCA\Keepiq\Db\PasskeyCredential;
use OCA\Keepiq\Db\SecretType;
use OCA\Keepiq\Db\TeamFolderMember;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The entity API forms.
 */
class EntityApiFormsTest extends TestCase {
	/**
	 * A passkey's API form shows how to recognise it, never its key material.
	 *
	 * @return void
	 */
	public function testAPasskeyKeepsItsKeyMaterial(): void {
		$passkey = new PasskeyCredential();
		$passkey->setId('pk-1');
		$passkey->setLabel('YubiKey');
		$passkey->setPrfSalt('salt-secret');
		$passkey->setWrappedUnlockKey('wrapped-secret');
		$passkey->setPublicKey('public-secret');
		$passkey->setUserHandle('handle-secret');
		$passkey->setCreatedAt(new DateTime('2026-11-14T12:00:00+00:00'));
		$json = $passkey->jsonSerialize();
		$this->assertSame(expected: ['id', 'label', 'transports', 'aaguid', 'clientKind', 'status', 'lastUsedAt', 'createdAt'], actual: array_keys($json));
		$this->assertSame(
			expected: ['pk-1', 'YubiKey', 'web', 'active', null],
			actual: [$json['id'], $json['label'], $json['clientKind'], $json['status'], $json['lastUsedAt']]
		);
		$this->assertStringNotContainsString(needle: 'secret', haystack: (string)json_encode($json));
	}//end testAPasskeyKeepsItsKeyMaterial()

	/**
	 * Views used and the views left.
	 *
	 * @return array<string, array{0: int, 1: int, 2: int}>
	 */
	public static function views(): array {
		return [
			'unopened'   => [3, 0, 3],
			'opened'     => [3, 2, 1],
			'used up'    => [1, 1, 0],
			'over count' => [1, 4, 0],
		];
	}//end views()

	/**
	 * An ephemeral send counts down its views, never below zero, and keeps its payload and key.
	 *
	 * @param int $max The views allowed.
	 * @param int $used The views used.
	 * @param int $left The views left.
	 *
	 * @return void
	 */
	#[DataProvider('views')]
	public function testAnEphemeralSendCountsDownItsViews(int $max, int $used, int $left): void {
		$send = new EphemeralSend();
		$send->setMaxViews($max);
		$send->setViewCount($used);
		$send->setEncryptedPayload('payload-secret');
		$send->setWrappedKey('key-secret');
		$send->setArgon2idSalt('salt-secret');
		$json = $send->jsonSerialize();
		$this->assertSame(expected: $left, actual: $json['remainingViews']);
		$this->assertStringNotContainsString(needle: 'secret', haystack: (string)json_encode($json));
	}//end testAnEphemeralSendCountsDownItsViews()

	/**
	 * Stored grades and the grade a member effectively has.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function grades(): array {
		return [
			'read'       => ['read', 'read'],
			'write'      => ['write', 'write'],
			'manage'     => ['manage', 'manage'],
			'empty'      => ['', 'read'],
			'unknown'    => ['owner', 'read'],
			'case'       => ['Manage', 'read'],
		];
	}//end grades()

	/**
	 * A grade that is not one of the three reads as the least: read.
	 *
	 * @param string $stored The stored grade.
	 * @param string $effective The effective grade.
	 *
	 * @return void
	 */
	#[DataProvider('grades')]
	public function testAnUnknownGradeReadsAsRead(string $stored, string $effective): void {
		$member = new TeamFolderMember();
		$member->setGrade($stored);
		$this->assertSame(expected: $effective, actual: $member->effectiveGrade());
		$this->assertSame(expected: $effective, actual: $member->jsonSerialize()['grade']);
	}//end testAnUnknownGradeReadsAsRead()

	/**
	 * A member is use-only only when that is stored as true.
	 *
	 * @return void
	 */
	public function testUseOnlyIsStrict(): void {
		$member = new TeamFolderMember();
		$this->assertFalse(condition: $member->jsonSerialize()['useOnly']);
		$member->setUseOnly(null);
		$this->assertFalse(condition: $member->jsonSerialize()['useOnly']);
		$member->setUseOnly(true);
		$this->assertTrue(condition: $member->jsonSerialize()['useOnly']);
	}//end testUseOnlyIsStrict()

	/**
	 * A secret type's fields round-trip as a list; no fields store as null.
	 *
	 * @return void
	 */
	public function testASecretTypesFieldsRoundTrip(): void {
		$type = new SecretType();
		$this->assertSame(expected: [], actual: $type->getFieldList());
		$type->setFieldList(['a' => ['name' => 'pin'], 'b' => ['name' => 'puk']]);
		$this->assertSame(expected: '[{"name":"pin"},{"name":"puk"}]', actual: $type->getFields());
		$this->assertSame(expected: [['name' => 'pin'], ['name' => 'puk']], actual: $type->jsonSerialize()['fields']);
		$type->setFieldList([]);
		$this->assertNull(actual: $type->getFields());
		$type->setFields('not json');
		$this->assertSame(expected: [], actual: $type->getFieldList());
	}//end testASecretTypesFieldsRoundTrip()

	/**
	 * An expiry policy's reminder days decode from JSON, or are null when none are set.
	 *
	 * @return void
	 */
	public function testAnExpiryPolicysReminderDays(): void {
		$policy = new ExpiryPolicy();
		$this->assertNull(actual: $policy->decodedReminderDays());
		$policy->setReminderDays('[30,7,1]');
		$policy->setMaxAgeDays(90);
		$json = $policy->jsonSerialize();
		$this->assertSame(expected: [30, 7, 1], actual: $json['reminderDays']);
		$this->assertSame(expected: 90, actual: $json['maxAgeDays']);
		$this->assertArrayNotHasKey(key: 'updatedAt', array: $json);
	}//end testAnExpiryPolicysReminderDays()
}//end class
