<?php

/**
 * DeviceApprovalRequest and RotationRaise: a new device's approval request,
 * checked and written as a pending row, and who raised a rotation flag.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\DeviceApproval;
use OCA\Keepiq\Service\DeviceApprovalRequest;
use OCA\Keepiq\Service\RotationRaise;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The device approval request.
 */
class DeviceApprovalRequestTest extends TestCase {
	/**
	 * A request with the given key and client kind.
	 *
	 * @param string $publicKey The base64 public key.
	 * @param string $clientKind The client kind.
	 * @param string $label The device label.
	 * @param string $agent The user agent.
	 *
	 * @return DeviceApprovalRequest
	 */
	private function request(string $publicKey, string $clientKind='web', string $label='Laptop', string $agent='Firefox'): DeviceApprovalRequest {
		return new DeviceApprovalRequest(
			userId: 'anne',
			publicKey: $publicKey,
			clientKind: $clientKind,
			label: $label,
			address: '192.0.2.10',
			agent: $agent
		);
	}//end request()

	/**
	 * Keys and client kinds that are refused, with the message.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function refusals(): array {
		$key = base64_encode(str_repeat("\x01", 32));
		return [
			'not base64'        => ['***', 'web', 'publicKey must be a raw X25519 public key'],
			'31 bytes'          => [base64_encode(str_repeat("\x01", 31)), 'web', 'publicKey must be a raw X25519 public key'],
			'33 bytes'          => [base64_encode(str_repeat("\x01", 33)), 'web', 'publicKey must be a raw X25519 public key'],
			'empty'             => ['', 'web', 'publicKey must be a raw X25519 public key'],
			'an unknown client' => [$key, 'mobile', 'clientKind must be web or extension'],
			'client case'       => [$key, 'Web', 'clientKind must be web or extension'],
		];
	}//end refusals()

	/**
	 * The key must be 32 raw bytes and the client web or extension.
	 *
	 * @param string $publicKey The base64 public key.
	 * @param string $clientKind The client kind.
	 * @param string $message The refusal message.
	 *
	 * @return void
	 */
	#[DataProvider('refusals')]
	public function testAMalformedRequestIsRefused(string $publicKey, string $clientKind, string $message): void {
		$this->expectException(exception: InvalidArgumentException::class);
		$this->expectExceptionMessage(message: $message);
		$this->request(publicKey: $publicKey, clientKind: $clientKind)->assertValid();
	}//end testAMalformedRequestIsRefused()

	/**
	 * A valid request from either client passes.
	 *
	 * @return void
	 */
	public function testAValidRequestPasses(): void {
		$key = base64_encode(random_bytes(32));
		$this->request(publicKey: $key)->assertValid();
		$this->request(publicKey: $key, clientKind: 'extension')->assertValid();
		$this->addToAssertionCount(count: 2);
	}//end testAValidRequestPasses()

	/**
	 * The row is pending, expires after the approval window, and holds the key and secret hash.
	 *
	 * @return void
	 */
	public function testTheRowIsPendingForTheApprovalWindow(): void {
		$key = base64_encode(random_bytes(32));
		$now = new DateTime('2026-11-14T12:00:00+00:00');
		$row = $this->request(publicKey: $key, clientKind: 'extension')->toRow(secretHash: 'hash-1', now: $now);
		$this->assertMatchesRegularExpression(pattern: '/^[0-9a-f-]{36}$/', string: (string)$row->getId());
		$this->assertSame(
			expected: ['anne', 'extension', 'Laptop', '192.0.2.10', 'Firefox', $key, 'hash-1', DeviceApproval::STATUS_PENDING],
			actual: [
				$row->getUserId(),
				$row->getClientKind(),
				$row->getDeviceLabel(),
				$row->getRequesterIp(),
				$row->getRequesterAgent(),
				$row->getRequestPublicKey(),
				$row->getRequestSecretHash(),
				$row->getStatus(),
			]
		);
		$this->assertSame(expected: '2026-11-14T12:00:00+00:00', actual: $row->getCreatedAt()->format('c'));
		$this->assertSame(expected: '2026-11-14T12:15:00+00:00', actual: $row->getExpiresAt()->format('c'));
		$this->assertSame(expected: '2026-11-14T12:00:00+00:00', actual: $now->format('c'));
	}//end testTheRowIsPendingForTheApprovalWindow()

	/**
	 * The label is trimmed and the label, address and agent are cut to their columns.
	 *
	 * @return void
	 */
	public function testLongTextsAreCutToTheirColumns(): void {
		$row = $this->request(publicKey: base64_encode(random_bytes(32)), label: '  '.str_repeat('l', 300).' ', agent: str_repeat('a', 600))
			->toRow(secretHash: 'h', now: new DateTime());
		$this->assertSame(expected: 255, actual: mb_strlen($row->getDeviceLabel()));
		$this->assertSame(expected: 'l', actual: $row->getDeviceLabel()[0]);
		$this->assertSame(expected: 512, actual: mb_strlen($row->getRequesterAgent()));
	}//end testLongTextsAreCutToTheirColumns()

	/**
	 * A rotation flag names who raised it, or the system when no one did.
	 *
	 * @return void
	 */
	public function testARotationRaiseNamesItsActor(): void {
		$this->assertSame(expected: 'anne', actual: (new RotationRaise(reason: 'leaked', flaggedBy: 'anne'))->actorId());
		$this->assertSame(expected: 'system', actual: (new RotationRaise(reason: 'policy', flaggedBy: null))->actorId());
	}//end testARotationRaiseNamesItsActor()
}//end class
