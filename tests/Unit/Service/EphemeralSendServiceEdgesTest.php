<?php

/**
 * Unit tests for the validation, owner, metadata, failure and purge paths of
 * EphemeralSendService.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Edges of the ephemeral send lifecycle.
 */
class EphemeralSendServiceEdgesTest extends TestCase {

	/** @var EphemeralSendMapper&MockObject */
	private EphemeralSendMapper $mapper;

	private EphemeralSendService $service;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(EphemeralSendMapper::class);
		$this->service = new EphemeralSendService(mapper: $this->mapper);
	}//end setUp()

	/**
	 * A send that can still be viewed.
	 *
	 * @param bool $hasPassword Whether it is password protected
	 * @param int $failedAttempts Failed password attempts so far
	 *
	 * @return EphemeralSend
	 */
	private function liveSend(bool $hasPassword = false, int $failedAttempts = 0): EphemeralSend {
		$send = new EphemeralSend();
		$send->setId('send-1');
		$send->setOwnerId('alice');
		$send->setToken(str_repeat('cd', 32));
		$send->setEncryptedPayload('CIPHERTEXT');
		$send->setPayloadType('credential');
		$send->setHasPassword($hasPassword);
		$send->setArgon2idSalt($hasPassword === true ? 'SALT' : null);
		$send->setMaxViews(3);
		$send->setViewCount(1);
		$send->setFailedAttempts($failedAttempts);
		$send->setCreatedAt(new DateTime());

		return $send;
	}//end liveSend()

	/**
	 * Create refuses each invalid request with the message naming the problem,
	 * and stores nothing.
	 *
	 * @return void
	 */
	public function testCreateNamesEachInvalidField(): void {
		$this->mapper->expects($this->never())->method('insert');

		$cases = [
			'encryptedPayload is required' => ['payloadType' => 'text'],
			'payloadType must be text or credential' => ['encryptedPayload' => 'CT', 'payloadType' => 'file'],
			'wrappedKey/argon2idSalt are only valid with hasPassword' => ['encryptedPayload' => 'CT', 'wrappedKey' => 'WRAPPED'],
		];
		foreach ($cases as $message => $params) {
			try {
				$this->service->create(ownerId: 'alice', params: $params);
				$this->fail('Expected a refusal: ' . $message);
			} catch (InvalidArgumentException $exception) {
				$this->assertSame($message, $exception->getMessage());
			}
		}
	}//end testCreateNamesEachInvalidField()

	/**
	 * A TTL within the cap becomes an absolute expiry that many seconds ahead.
	 *
	 * @return void
	 */
	public function testATtlBecomesAnAbsoluteExpiry(): void {
		$this->mapper->method('insert')->willReturnArgument(0);

		$before = time();
		$send = $this->service->create(ownerId: 'alice', params: ['encryptedPayload' => 'CT', 'ttlSeconds' => 3600]);
		$after = time();

		$this->assertNotNull($send->getExpiresAt());
		$this->assertGreaterThanOrEqual($before + 3600, $send->getExpiresAt()->getTimestamp());
		$this->assertLessThanOrEqual($after + 3600, $send->getExpiresAt()->getTimestamp());
		$this->assertSame('text', $send->getPayloadType());
		$this->assertSame('alice', $send->getOwnerId());
	}//end testATtlBecomesAnAbsoluteExpiry()

	/**
	 * The owner's list comes from the mapper, and an owner revokes their own send.
	 *
	 * @return void
	 */
	public function testAnOwnerListsAndRevokesTheirOwnSend(): void {
		$send = $this->liveSend();
		$this->mapper->expects($this->once())->method('findByOwner')->with('alice')->willReturn([$send]);
		$this->mapper->method('findById')->with('send-1')->willReturn($send);
		$this->mapper->expects($this->once())->method('delete')->with($this->identicalTo($send));

		$this->assertSame([$send], $this->service->listForOwner(ownerId: 'alice'));
		$this->service->revoke(id: 'send-1', ownerId: 'alice');
	}//end testAnOwnerListsAndRevokesTheirOwnSend()

	/**
	 * Peek tells the access page what it needs and never the ciphertext.
	 *
	 * @return void
	 */
	public function testPeekCarriesMetadataOnly(): void {
		$this->mapper->method('findByToken')->willReturn($this->liveSend(hasPassword: true));
		$this->mapper->expects($this->never())->method('delete');

		$this->assertSame(
			['payloadType' => 'credential', 'hasPassword' => true, 'argon2idSalt' => 'SALT', 'remainingViews' => 2],
			$this->service->peek(token: str_repeat('cd', 32))
		);
	}//end testPeekCarriesMetadataOnly()

	/**
	 * A failure report on a send without a password counts nothing.
	 *
	 * @return void
	 */
	public function testAFailureWithoutAPasswordCountsNothing(): void {
		$this->mapper->method('findByToken')->willReturn($this->liveSend(hasPassword: false));
		$this->mapper->expects($this->never())->method('update');
		$this->mapper->expects($this->never())->method('delete');

		$this->assertSame(
			['burned' => false, 'attemptsLeft' => EphemeralSendService::FAILED_ATTEMPTS_CAP],
			$this->service->reportFailure(token: str_repeat('cd', 32))
		);
	}//end testAFailureWithoutAPasswordCountsNothing()

	/**
	 * A failure below the cap is stored and the attempts left are reported.
	 *
	 * @return void
	 */
	public function testAFailureBelowTheCapIsStored(): void {
		$send = $this->liveSend(hasPassword: true, failedAttempts: 1);
		$this->mapper->method('findByToken')->willReturn($send);
		$this->mapper->expects($this->once())->method('update')->with($this->identicalTo($send));
		$this->mapper->expects($this->never())->method('delete');

		$this->assertSame(
			['burned' => false, 'attemptsLeft' => 3],
			$this->service->reportFailure(token: str_repeat('cd', 32))
		);
		$this->assertSame(2, $send->getFailedAttempts());
	}//end testAFailureBelowTheCapIsStored()

	/**
	 * Purge deletes every purgeable send and returns how many.
	 *
	 * @return void
	 */
	public function testPurgeDeletesEveryPurgeableSend(): void {
		$first = $this->liveSend();
		$second = $this->liveSend();
		$this->mapper->expects($this->once())->method('findPurgeable')
			->with($this->isInstanceOf(DateTime::class))
			->willReturn([$first, $second]);
		$deleted = [];
		$this->mapper->method('delete')->willReturnCallback(
			static function (EphemeralSend $send) use (&$deleted): EphemeralSend {
				$deleted[] = $send;
				return $send;
			}
		);

		$this->assertSame(2, $this->service->purge());
		$this->assertSame([$first, $second], $deleted);
	}//end testPurgeDeletesEveryPurgeableSend()
}//end class
