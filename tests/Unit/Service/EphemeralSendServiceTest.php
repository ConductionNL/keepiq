<?php

/**
 * Unit tests for EphemeralSendService (ephemeral-send §6.1/§6.2).
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\EphemeralSend;
use OCA\Keepiq\Db\EphemeralSendMapper;
use OCA\Keepiq\Service\EphemeralSendService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EphemeralSendService.
 */
class EphemeralSendServiceTest extends TestCase {
	private EphemeralSendService $service;

	private EphemeralSendMapper&MockObject $mapper;

	/**
	 * Build the service with a fresh mapper mock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(originalClassName: EphemeralSendMapper::class);
		$this->service = new EphemeralSendService(mapper: $this->mapper);
	}//end setUp()

	/**
	 * Insert-capturing stub returning the entity unchanged.
	 *
	 * @return void
	 */
	private function passThroughInsert(): void {
		$this->mapper->method('insert')->willReturnCallback(static fn (EphemeralSend $send) => $send);
	}//end passThroughInsert()

	/**
	 * 6.1: unlimited/invalid maxViews and an over-cap TTL are rejected.
	 *
	 * @return void
	 */
	public function testCreateRejectsInvalidLimits(): void {
		foreach ([0, -1, (EphemeralSendService::MAX_VIEWS_CAP + 1)] as $maxViews) {
			try {
				$this->service->create(ownerId: 'alice', params: ['encryptedPayload' => 'CT', 'maxViews' => $maxViews]);
				$this->fail('maxViews ' . $maxViews . ' must be rejected');
			} catch (InvalidArgumentException $exception) {
				$this->assertStringContainsString('maxViews', $exception->getMessage());
			}
		}

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('ttlSeconds exceeds the cap');
		$this->service->create(
			ownerId: 'alice',
			params: [
				'encryptedPayload' => 'CT',
				'ttlSeconds' => (EphemeralSendService::TTL_CAP_SECONDS + 1),
			]
		);
	}//end testCreateRejectsInvalidLimits()

	/**
	 * 6.1: no-password stores hold no key material; a password store
	 * requires (and holds only) the wrapped key + salt; the token has
	 * >=128 bits of entropy. No Secret row is created anywhere.
	 *
	 * @return void
	 */
	public function testCreateStoresCiphertextOnly(): void {
		$this->passThroughInsert();

		$plain = $this->service->create(
			ownerId: 'alice',
			params: [
				'encryptedPayload' => 'CIPHERTEXT',
				'payloadType' => 'credential',
			]
		);
		$this->assertNull($plain->getWrappedKey());
		$this->assertNull($plain->getArgon2idSalt());
		$this->assertFalse($plain->getHasPassword());
		// 32 bytes hex = 64 chars = 256 bits, above the 128-bit floor.
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $plain->getToken());

		$protected = $this->service->create(
			ownerId: 'alice',
			params: [
				'encryptedPayload' => 'CIPHERTEXT',
				'hasPassword' => true,
				'wrappedKey' => 'WRAPPED',
				'argon2idSalt' => 'SALT',
			]
		);
		$this->assertSame('WRAPPED', $protected->getWrappedKey());
		$this->assertSame('SALT', $protected->getArgon2idSalt());

		$this->expectException(InvalidArgumentException::class);
		$this->service->create(
			ownerId: 'alice',
			params: [
				'encryptedPayload' => 'CIPHERTEXT',
				'hasPassword' => true,
			]
		);
	}//end testCreateStoresCiphertextOnly()

	/**
	 * Build a live send row.
	 *
	 * @param int $maxViews Max views
	 * @param int $viewCount Current views
	 *
	 * @return EphemeralSend
	 */
	private function liveSend(int $maxViews = 1, int $viewCount = 0): EphemeralSend {
		$send = new EphemeralSend();
		$send->setId('send-1');
		$send->setOwnerId('alice');
		$send->setToken(str_repeat('ab', 32));
		$send->setEncryptedPayload('CIPHERTEXT');
		$send->setPayloadType('text');
		$send->setMaxViews($maxViews);
		$send->setViewCount($viewCount);
		$send->setCreatedAt(new DateTime());
		return $send;
	}//end liveSend()

	/**
	 * 6.2: confirmView increments and burns (deletes) at max_views;
	 * access itself never consumes a view.
	 *
	 * @return void
	 */
	public function testConfirmViewBurnsAtCap(): void {
		$send = $this->liveSend(maxViews: 1);
		$this->mapper->method('findByToken')->willReturn($send);

		$deleted = false;
		$this->mapper->method('delete')->willReturnCallback(
			static function (EphemeralSend $row) use (&$deleted) {
				$deleted = true;
				return $row;
			}
		);

		// Access returns ciphertext without consuming.
		$payload = $this->service->access(token: $send->getToken());
		$this->assertSame('CIPHERTEXT', $payload['encryptedPayload']);
		$this->assertFalse($deleted);

		$result = $this->service->confirmView(token: $send->getToken());
		$this->assertTrue($result['burned']);
		$this->assertTrue($deleted);
	}//end testConfirmViewBurnsAtCap()

	/**
	 * 6.2: an expired send is deleted and rejected as the SAME
	 * not-found as a missing one.
	 *
	 * @return void
	 */
	public function testExpiredSendRejects(): void {
		$send = $this->liveSend();
		$send->setExpiresAt(new DateTime('-1 minute'));
		$this->mapper->method('findByToken')->willReturn($send);
		$this->mapper->expects($this->once())->method('delete');

		$this->expectException(DoesNotExistException::class);
		$this->service->peek(token: $send->getToken());
	}//end testExpiredSendRejects()

	/**
	 * 6.2: five failed password attempts permanently burn the send.
	 *
	 * @return void
	 */
	public function testFiveFailedPasswordsBurn(): void {
		$send = $this->liveSend();
		$send->setHasPassword(true);
		$send->setWrappedKey('WRAPPED');
		$send->setArgon2idSalt('SALT');
		$this->mapper->method('findByToken')->willReturn($send);
		$this->mapper->method('update')->willReturnCallback(static fn (EphemeralSend $row) => $row);

		$deleted = false;
		$this->mapper->method('delete')->willReturnCallback(
			static function (EphemeralSend $row) use (&$deleted) {
				$deleted = true;
				return $row;
			}
		);

		for ($attempt = 1; $attempt <= 4; $attempt++) {
			$result = $this->service->reportFailure(token: $send->getToken());
			$this->assertFalse($result['burned']);
			$this->assertSame((5 - $attempt), $result['attemptsLeft']);
		}

		$result = $this->service->reportFailure(token: $send->getToken());
		$this->assertTrue($result['burned']);
		$this->assertTrue($deleted);
	}//end testFiveFailedPasswordsBurn()

	/**
	 * 6.2: listForOwner/revoke are owner-scoped — a foreign revoke is
	 * the SAME not-found as a missing send.
	 *
	 * @return void
	 */
	public function testRevokeIsOwnerScoped(): void {
		$send = $this->liveSend();
		$this->mapper->method('findById')->willReturn($send);
		$this->mapper->expects($this->never())->method('delete');

		$this->expectException(DoesNotExistException::class);
		$this->service->revoke(id: 'send-1', ownerId: 'mallory');
	}//end testRevokeIsOwnerScoped()
}//end class
