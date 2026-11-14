<?php

/**
 * The columns the honey, certificate, lease, recovery and grant entities
 * show in their API form, and the moments written as ISO dates.
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
use OCA\Keepiq\Db\ApplicationLeasePolicy;
use OCA\Keepiq\Db\AttachmentGrant;
use OCA\Keepiq\Db\CertificateMetadata;
use OCA\Keepiq\Db\HoneyAlert;
use OCA\Keepiq\Db\HoneyFlag;
use OCA\Keepiq\Db\MachineLease;
use OCA\Keepiq\Db\RecoveryApproval;
use OCA\Keepiq\Db\RecoveryKey;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shown columns.
 */
class EntityShownColumnsTest extends TestCase {
	/**
	 * Each entity, the columns its API form shows in order, and one moment it writes.
	 *
	 * @return array<string, array{0: class-string<Entity>, 1: array<int, string>, 2: string}>
	 */
	public static function forms(): array {
		return [
			'honey alert'          => [
				HoneyAlert::class,
				['id', 'honeyFlagId', 'secretId', 'accessorType', 'accessorId', 'channel', 'ip', 'userAgent', 'accessCount', 'accessedAt', 'acknowledgedAt',
					'acknowledgedBy', 'snoozedUntil',
				],
				'accessedAt',
			],
			'honey flag'           => [HoneyFlag::class, ['id', 'secretId', 'ownerId', 'note', 'createdBy', 'createdAt'], 'createdAt'],
			'certificate metadata' => [
				CertificateMetadata::class,
				['id', 'secretId', 'subject', 'issuer', 'serial', 'fingerprintSha256', 'notBefore', 'notAfter', 'parsedAt'],
				'notAfter',
			],
			'machine lease'        => [
				MachineLease::class,
				['id', 'applicationId', 'secretId', 'scope', 'grantedAt', 'expiresAt', 'renewedCount', 'lastRenewedAt', 'status', 'revokedAt', 'revokedBy'],
				'expiresAt',
			],
			'recovery key'         => [
				RecoveryKey::class,
				['id', 'certificate', 'fingerprint', 'threshold', 'status', 'createdBy', 'createdAt', 'retiredAt'],
				'retiredAt',
			],
			'recovery approval'    => [RecoveryApproval::class, ['id', 'requestId', 'officerUid', 'decision', 'decidedAt'], 'decidedAt'],
			'attachment grant'     => [
				AttachmentGrant::class,
				['id', 'attachmentId', 'secretId', 'recipientType', 'recipientId', 'wrappedFileKey', 'createdAt'],
				'createdAt',
			],
		];
	}//end forms()

	/**
	 * The API form shows exactly its columns, and an unset moment is null.
	 *
	 * @param class-string<Entity> $class The entity.
	 * @param array<int, string> $columns The shown columns.
	 * @param string $moment A moment column.
	 *
	 * @return void
	 */
	#[DataProvider('forms')]
	public function testTheFormShowsItsColumns(string $class, array $columns, string $moment): void {
		$json = (new $class())->jsonSerialize();
		$this->assertSame(expected: $columns, actual: array_keys($json));
		$this->assertNull(actual: $json[$moment]);
	}//end testTheFormShowsItsColumns()

	/**
	 * A set moment is written as an ISO 8601 date with its offset.
	 *
	 * @param class-string<Entity> $class The entity.
	 * @param array<int, string> $columns The shown columns.
	 * @param string $moment A moment column.
	 *
	 * @return void
	 */
	#[DataProvider('forms')]
	public function testAMomentIsWrittenAsIso(string $class, array $columns, string $moment): void {
		$entity = new $class();
		$entity->{'set'.ucfirst($moment)}(new DateTime('2026-11-14T12:30:00+01:00'));
		$this->assertSame(expected: '2026-11-14T12:30:00+01:00', actual: $entity->jsonSerialize()[$moment]);
		$this->assertContains(needle: $moment, haystack: $columns);
	}//end testAMomentIsWrittenAsIso()

	/**
	 * A lease policy without an override shows nulls, so the instance setting applies.
	 *
	 * @return void
	 */
	public function testALeasePolicyShowsItsOverrides(): void {
		$policy = new ApplicationLeasePolicy();
		$policy->setApplicationId('app-1');
		$this->assertSame(
			expected: ['applicationId' => 'app-1', 'defaultTtlSeconds' => null, 'maxTtlSeconds' => null, 'renewable' => null],
			actual: $policy->jsonSerialize()
		);
		$policy->setMaxTtlSeconds(3600);
		$policy->setRenewable(false);
		$this->assertSame(expected: [3600, false], actual: [$policy->jsonSerialize()['maxTtlSeconds'], $policy->jsonSerialize()['renewable']]);
	}//end testALeasePolicyShowsItsOverrides()
}//end class
