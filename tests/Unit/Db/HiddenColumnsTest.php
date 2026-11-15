<?php

/**
 * The columns an entity keeps out of its API form: wrapped keys, sealed
 * results, shared secrets, queued payloads and blob references never reach
 * a client, whatever they hold.
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
use OCA\Keepiq\Db\Attachment;
use OCA\Keepiq\Db\DeviceApproval;
use OCA\Keepiq\Db\FederatedInbound;
use OCA\Keepiq\Db\FederationPartner;
use OCA\Keepiq\Db\RecoveryEnrolment;
use OCA\Keepiq\Db\RecoveryOfficer;
use OCA\Keepiq\Db\RecoveryRequest;
use OCA\Keepiq\Db\RotationFlag;
use OCA\Keepiq\Db\SiemQueueItem;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The hidden columns.
 */
class HiddenColumnsTest extends TestCase {
	/**
	 * Entities, a column that must stay hidden, and its setter.
	 *
	 * @return array<string, array{0: class-string<Entity>, 1: string}>
	 */
	public static function hiddenColumns(): array {
		return [
			'the inbound shared secret'      => [FederatedInbound::class, 'sharedSecretEnc'],
			'the inbound remote share'       => [FederatedInbound::class, 'remoteShareId'],
			'the sealed recovery result'     => [RecoveryRequest::class, 'sealedResult'],
			'the sealed device unlock key'   => [DeviceApproval::class, 'sealedUnlockKey'],
			'the device request secret hash' => [DeviceApproval::class, 'requestSecretHash'],
			'the device user agent'          => [DeviceApproval::class, 'requesterAgent'],
			'the queued SIEM payload'        => [SiemQueueItem::class, 'payload'],
			'the attachment blob reference'  => [Attachment::class, 'blobRef'],
			'the recovery envelope'          => [RecoveryEnrolment::class, 'envelope'],
			'the officer wrapped private key' => [RecoveryOfficer::class, 'wrappedPrivateKey'],
		];
	}//end hiddenColumns()

	/**
	 * A hidden column is not in the API form, under any key or as any value.
	 *
	 * @param class-string<Entity> $class The entity.
	 * @param string $column The hidden column.
	 *
	 * @return void
	 */
	#[DataProvider('hiddenColumns')]
	public function testAHiddenColumnStaysHidden(string $class, string $column): void {
		$entity = new $class();
		$entity->setId('e1');
		$entity->{'set'.ucfirst($column)}('hidden-value-42');
		$json = $entity->jsonSerialize();
		$this->assertArrayNotHasKey(key: $column, array: $json);
		$this->assertStringNotContainsString(needle: 'hidden-value-42', haystack: (string)json_encode($json));
	}//end testAHiddenColumnStaysHidden()

	/**
	 * The columns each API form shows, in order.
	 *
	 * @return array<string, array{0: class-string<Entity>, 1: array<int, string>}>
	 */
	public static function shownColumns(): array {
		return [
			'federated inbound' => [FederatedInbound::class, ['id', 'senderCloudId', 'name', 'secretId', 'status', 'receivedAt', 'updatedAt']],
			'recovery request'  => [
				RecoveryRequest::class,
				['id', 'userId', 'suiteId', 'enrolmentId', 'requestPublicKey', 'status', 'createdAt', 'expiresAt', 'handledBy', 'fulfilledAt', 'purpose'],
			],
			'device approval'   => [
				DeviceApproval::class,
				['id', 'clientKind', 'deviceLabel', 'requesterIp', 'requestPublicKey', 'status', 'createdAt', 'expiresAt'],
			],
			'siem queue item'   => [SiemQueueItem::class, ['id', 'sinkId', 'status', 'attempts', 'enqueuedAt', 'lastError']],
			'attachment'        => [Attachment::class, ['id', 'sourceSecretId', 'encryptedMetadata', 'sizeBytes', 'createdAt']],
			'rotation flag'     => [RotationFlag::class, ['id', 'secretId', 'reason', 'status', 'flaggedAt', 'flaggedBy', 'resolvedAt']],
			'recovery enrolment' => [RecoveryEnrolment::class, ['id', 'userId', 'suiteId', 'recoveryKeyId', 'enrolledAt']],
			'recovery officer'  => [RecoveryOfficer::class, ['id', 'recoveryKeyId', 'officerUid', 'officerSuiteId', 'addedBy', 'addedAt']],
		];
	}//end shownColumns()

	/**
	 * Each API form shows exactly its listed columns, so a new column stays hidden until listed.
	 *
	 * @param class-string<Entity> $class The entity.
	 * @param array<int, string> $columns The shown columns.
	 *
	 * @return void
	 */
	#[DataProvider('shownColumns')]
	public function testEachApiFormShowsItsColumns(string $class, array $columns): void {
		$this->assertSame(expected: $columns, actual: array_keys((new $class())->jsonSerialize()));
	}//end testEachApiFormShowsItsColumns()

	/**
	 * A federation partner's directions are strict booleans and its value fields decode.
	 *
	 * @return void
	 */
	public function testAFederationPartnersForm(): void {
		$partner = new FederationPartner();
		$partner->setAllowOutbound(true);
		$partner->setValueFields('["totp"]');
		$partner->setAddedAt(new DateTime('2026-11-14T12:00:00+00:00'));
		$json = $partner->jsonSerialize();
		$this->assertSame(expected: [true, false], actual: [$json['allowOutbound'], $json['allowInbound']]);
		$this->assertSame(expected: ['totp'], actual: $json['valueFields']);
		$this->assertSame(expected: '2026-11-14T12:00:00+00:00', actual: $json['addedAt']);
		$this->assertNull(actual: (new FederationPartner())->jsonSerialize()['valueFields']);
	}//end testAFederationPartnersForm()
}//end class
