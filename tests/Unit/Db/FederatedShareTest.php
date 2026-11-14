<?php

/**
 * FederatedShare and Application: the federated share's version stamp and
 * API form, and an application's status.
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
use OCA\Keepiq\Db\Application;
use OCA\Keepiq\Db\FederatedShare;
use PHPUnit\Framework\TestCase;

/**
 * The federated share and application entities.
 */
class FederatedShareTest extends TestCase {
	/**
	 * A first stamp takes the current moment.
	 *
	 * @return void
	 */
	public function testAFirstStampIsNow(): void {
		$share = new FederatedShare();
		$share->stamp(now: new DateTime('2026-11-14T12:00:00+00:00'));
		$this->assertSame(expected: '2026-11-14T12:00:00+00:00', actual: $share->getUpdatedAt()->format(DATE_ATOM));
	}//end testAFirstStampIsNow()

	/**
	 * Two stamps in the same second, or a clock that went back, still move the version forward.
	 *
	 * @return void
	 */
	public function testTheStampAlwaysMovesForward(): void {
		$share = new FederatedShare();
		$now   = new DateTime('2026-11-14T12:00:00+00:00');
		$share->stamp(now: $now);
		$share->stamp(now: $now);
		$this->assertSame(expected: $now->getTimestamp() + 1, actual: $share->getUpdatedAt()->getTimestamp());
		$share->stamp(now: new DateTime('2026-11-14T11:00:00+00:00'));
		$this->assertSame(expected: $now->getTimestamp() + 2, actual: $share->getUpdatedAt()->getTimestamp());
		$share->stamp(now: new DateTime('2026-11-14T13:00:00+00:00'));
		$this->assertSame(expected: '2026-11-14T13:00:00+00:00', actual: $share->getUpdatedAt()->format(DATE_ATOM));
	}//end testTheStampAlwaysMovesForward()

	/**
	 * The API form leaves out the encrypted values and the shared secret hash.
	 *
	 * @return void
	 */
	public function testTheApiFormLeavesOutTheCiphertext(): void {
		$share = new FederatedShare();
		$share->setId('fs-1');
		$share->setSourceSecretId('s1');
		$share->setRecipientCloudId('bob@cloud.example.org');
		$share->setKey('enc:key');
		$share->setSharedSecretHash('hash:secret');
		$json = $share->jsonSerialize();
		$this->assertSame(expected: 'active', actual: $json['status']);
		$this->assertSame(
			expected: ['fs-1', 's1', 'bob@cloud.example.org', 0],
			actual: [$json['id'], $json['sourceSecretId'], $json['recipientCloudId'], $json['notifyAttempts']]
		);
		$this->assertArrayNotHasKey(key: 'key', array: $json);
		$this->assertStringNotContainsString(needle: 'enc:', haystack: (string)json_encode($json));
		$this->assertStringNotContainsString(needle: 'hash:', haystack: (string)json_encode($json));
	}//end testTheApiFormLeavesOutTheCiphertext()

	/**
	 * An application is pending or active by its status alone.
	 *
	 * @return void
	 */
	public function testAnApplicationsStatus(): void {
		$application = new Application();
		$application->setStatus(Application::STATUS_PENDING);
		$this->assertSame(expected: [true, false], actual: [$application->isPending(), $application->isActive()]);
		$application->setStatus(Application::STATUS_ACTIVE);
		$this->assertSame(expected: [false, true], actual: [$application->isPending(), $application->isActive()]);
		$application->setStatus('revoked');
		$this->assertSame(expected: [false, false], actual: [$application->isPending(), $application->isActive()]);
	}//end testAnApplicationsStatus()

	/**
	 * An application's API form shows its dates as ISO moments, or null.
	 *
	 * @return void
	 */
	public function testAnApplicationsApiForm(): void {
		$application = new Application();
		$application->setId('app-1');
		$application->setName('Backup robot');
		$application->setCreatedAt(new DateTime('2026-11-14T12:00:00+00:00'));
		$json = $application->jsonSerialize();
		$this->assertSame(
			expected: ['app-1', 'Backup robot', '2026-11-14T12:00:00+00:00', null],
			actual: [$json['id'], $json['name'], $json['createdAt'], $json['approvedAt']]
		);
	}//end testAnApplicationsApiForm()
}//end class
