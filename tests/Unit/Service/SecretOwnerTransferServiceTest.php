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
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Service\SecretOwnerTransferService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A secret and its partner shares change owner together.
 */
class SecretOwnerTransferServiceTest extends TestCase {
	/**
	 * Both writes run inside one transaction.
	 *
	 * @return void
	 */
	public function testTheSecretAndItsPartnerSharesChangeOwnerInOneTransaction(): void {
		$calls = [];
		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction')->willReturnCallback(function () use (&$calls): void {
			$calls[] = 'begin';
		});
		$db->method('commit')->willReturnCallback(function () use (&$calls): void {
			$calls[] = 'commit';
		});
		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('reassignOwner')->willReturnCallback(function (string $secretId, string $newOwnerId) use (&$calls): void {
			$calls[] = 'secret '.$secretId.' '.$newOwnerId;
		});
		$shares = $this->createMock(FederatedShareMapper::class);
		$shares->method('reassignOwner')->willReturnCallback(function (string $sourceSecretId, string $ownerId) use (&$calls): int {
			$calls[] = 'partner shares '.$sourceSecretId.' '.$ownerId;
			return 1;
		});

		(new SecretOwnerTransferService(db: $db, secretMapper: $secrets, federatedShares: $shares))->transfer(secretId: 'sec-1', newOwnerId: 'dave');

		$this->assertSame(['begin', 'secret sec-1 dave', 'partner shares sec-1 dave', 'commit'], $calls);
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
		(new SecretOwnerTransferService(db: $db, secretMapper: $this->createMock(SecretMapper::class), federatedShares: $shares))->transfer(secretId: 'sec-1', newOwnerId: 'dave');
	}
}
