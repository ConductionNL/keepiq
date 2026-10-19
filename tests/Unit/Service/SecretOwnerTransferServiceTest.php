<?php

/**
 * Unit tests for SecretOwnerTransferService.
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

use OCA\Keepiq\Db\FederatedShareMapper;
use OCA\Keepiq\Db\LinkShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Service\SecretOwnerTransferService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A secret, its partner shares and its previous owner's link shares change
 * owner together.
 */
class SecretOwnerTransferServiceTest extends TestCase {
	/**
	 * A secret owned by Alice.
	 *
	 * @return SecretMapper
	 */
	private function alicesSecret(): SecretMapper {
		$secret = new Secret();
		$secret->setId('sec-1');
		$secret->setOwnerId('alice');

		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('findById')->with('sec-1')->willReturn($secret);

		return $secrets;
	}

	/**
	 * All three writes run inside one transaction, and only the links Alice
	 * created are handed over: the mapper is asked for hers, not a
	 * delegate's.
	 *
	 * @return void
	 */
	public function testTheSecretAndItsSharesChangeOwnerInOneTransaction(): void {
		$calls = [];
		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction')->willReturnCallback(function () use (&$calls): void {
			$calls[] = 'begin';
		});
		$db->method('commit')->willReturnCallback(function () use (&$calls): void {
			$calls[] = 'commit';
		});
		$secrets = $this->alicesSecret();
		$secrets->method('reassignOwner')->willReturnCallback(function (string $secretId, string $newOwnerId) use (&$calls): void {
			$calls[] = 'secret '.$secretId.' '.$newOwnerId;
		});
		$shares = $this->createMock(FederatedShareMapper::class);
		$shares->method('reassignOwner')->willReturnCallback(function (string $sourceSecretId, string $ownerId) use (&$calls): int {
			$calls[] = 'partner shares '.$sourceSecretId.' '.$ownerId;
			return 1;
		});
		$links = $this->createMock(LinkShareMapper::class);
		$links->method('reassignCreator')->willReturnCallback(function (string $secretId, string $fromUserId, string $toUserId) use (&$calls): int {
			$calls[] = 'link shares '.$secretId.' '.$fromUserId.' '.$toUserId;
			return 1;
		});

		(new SecretOwnerTransferService(db: $db, secretMapper: $secrets, federatedShares: $shares, linkShares: $links))->transfer(secretId: 'sec-1', newOwnerId: 'dave');

		$this->assertSame(['begin', 'secret sec-1 dave', 'partner shares sec-1 dave', 'link shares sec-1 alice dave', 'commit'], $calls);
	}

	/**
	 * When the partner shares can't be handed over, the secret keeps its
	 * owner too.
	 *
	 * @return void
	 */
	public function testAFailedPartnerShareWriteRollsTheSecretBack(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('commit');
		$db->expects($this->once())->method('rollBack');
		$shares = $this->createMock(FederatedShareMapper::class);
		$shares->method('reassignOwner')->willThrowException(new RuntimeException('database gone'));

		$this->expectException(RuntimeException::class);
		(new SecretOwnerTransferService(db: $db, secretMapper: $this->alicesSecret(), federatedShares: $shares, linkShares: $this->createMock(LinkShareMapper::class)))->transfer(secretId: 'sec-1', newOwnerId: 'dave');
	}

	/**
	 * When the link shares can't be handed over, the secret and its partner
	 * shares keep their owner too.
	 *
	 * @return void
	 */
	public function testAFailedLinkShareWriteRollsTheTransferBack(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('commit');
		$db->expects($this->once())->method('rollBack');
		$links = $this->createMock(LinkShareMapper::class);
		$links->method('reassignCreator')->willThrowException(new RuntimeException('database gone'));

		$this->expectException(RuntimeException::class);
		(new SecretOwnerTransferService(db: $db, secretMapper: $this->alicesSecret(), federatedShares: $this->createMock(FederatedShareMapper::class), linkShares: $links))->transfer(secretId: 'sec-1', newOwnerId: 'dave');
	}

	/**
	 * A secret that is gone has no previous owner to take links from, but
	 * its partner shares are still handed over.
	 *
	 * @return void
	 */
	public function testAMissingSecretStillHandsOverItsPartnerShares(): void {
		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('findById')->willThrowException(new DoesNotExistException(''));
		$shares = $this->createMock(FederatedShareMapper::class);
		$shares->expects($this->once())->method('reassignOwner')->with('sec-1', 'dave')->willReturn(1);
		$links = $this->createMock(LinkShareMapper::class);
		$links->expects($this->never())->method('reassignCreator');

		(new SecretOwnerTransferService(db: $this->createMock(IDBConnection::class), secretMapper: $secrets, federatedShares: $shares, linkShares: $links))->transfer(secretId: 'sec-1', newOwnerId: 'dave');
	}
}
