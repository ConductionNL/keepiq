<?php

/**
 * FederatedShareService::resumeSuspended() makes a suspended share live with
 * a value encrypted now, inside the caller's transaction
 * (sharing-atomic-share-save task 3.4).
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
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-the-owner-resumes-a-suspended-partner-share
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use OCA\Keepiq\Db\FederatedShare;
use OCA\Keepiq\Db\FederatedShareMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\FederatedNotificationDelivery;
use OCA\Keepiq\Service\FederatedShareAuditTrail;
use OCA\Keepiq\Service\FederatedShareMessenger;
use OCA\Keepiq\Service\FederatedShareService;
use OCA\Keepiq\Service\FederationPartnerService;
use OCA\Keepiq\Service\FederationRootService;
use OCP\Federation\ICloudIdManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FederatedShareServiceResumeTest extends TestCase {
	private const FINGERPRINT = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

	/**
	 * The share rows.
	 *
	 * @var FederatedShareMapper&MockObject
	 */
	private FederatedShareMapper&MockObject $mapper;

	/**
	 * The audit event types recorded.
	 *
	 * @var list<string>
	 */
	private array $recorded = [];

	/**
	 * The writes, in order: `status <read status>` or `active`.
	 *
	 * @var list<string>
	 */
	private array $writes = [];

	/**
	 * The service under test.
	 *
	 * @return FederatedShareService
	 */
	private function service(): FederatedShareService {
		$this->mapper = $this->createMock(originalClassName: FederatedShareMapper::class);
		$this->mapper->method('updateIfActive')->willReturnCallback(callback: function (FederatedShare $share): int {
			$this->writes[] = 'active';
			return 1;
		});
		$audit = $this->createMock(originalClassName: FederatedShareAuditTrail::class);
		$audit->method('recordOutbound')->willReturnCallback(callback: function (string $eventType, FederatedShare $row, ?string $actorId): void {
			$this->recorded[] = $eventType . ' by ' . $actorId;
		});
		$delivery = $this->createMock(originalClassName: FederatedNotificationDelivery::class);
		$delivery->method('leaseFrom')->willReturnCallback(
			callback: static fn (DateTime $queuedAt): DateTime => (clone $queuedAt)->modify(modifier: '+60 seconds')
		);
		$delivery->expects($this->never())->method('deliver');

		return new FederatedShareService(
			shareMapper: $this->mapper,
			secretMapper: $this->createMock(originalClassName: SecretMapper::class),
			partners: $this->createMock(originalClassName: FederationPartnerService::class),
			root: $this->createMock(originalClassName: FederationRootService::class),
			cloudIdManager: $this->createMock(originalClassName: ICloudIdManager::class),
			messenger: $this->createMock(originalClassName: FederatedShareMessenger::class),
			random: $this->createMock(originalClassName: ISecureRandom::class),
			delivery: $delivery,
			audit: $audit,
		);
	}//end service()

	/**
	 * Alice's suspended share with Bob, holding the value from before.
	 *
	 * @return FederatedShare
	 */
	private function suspended(): FederatedShare {
		$row = new FederatedShare();
		$row->setId('fs-1');
		$row->setOwnerId('alice');
		$row->setStatus(FederatedShare::STATUS_SUSPENDED);
		$row->setKey('OLD');

		return $row;
	}//end suspended()

	/**
	 * The share is made live only while it is still suspended, then gets the
	 * new value with `SHARE_UPDATED` queued for after the commit.
	 *
	 * @return void
	 */
	public function testASuspendedShareGoesLiveWithTheNewValue(): void {
		$service = $this->service();
		$this->mapper->expects($this->once())->method('updateIfStatus')->willReturnCallback(
			callback: function (FederatedShare $share, string $status, string $ending): int {
				$this->writes[] = 'status ' . $status;
				$this->assertSame(
					expected: [FederatedShare::STATUS_ACTIVE, FederatedNotificationDelivery::SHARE_UNSHARED],
					actual: [$share->getStatus(), $ending]
				);
				return 1;
			}
		);
		$at = new DateTime('2026-11-25T10:00:00Z');

		$row = $service->resumeSuspended(
			row: $this->suspended(),
			certFingerprint: self::FINGERPRINT,
			ciphertext: ['key' => 'NEW', 'login' => null, 'additionalFields' => null, 'totp' => null, 'passkeys' => null],
			savedAt: $at
		);

		$this->assertSame(expected: ['status suspended', 'active'], actual: $this->writes);
		$this->assertSame(
			expected: [FederatedShare::STATUS_ACTIVE, 'NEW', self::FINGERPRINT, FederatedNotificationDelivery::SHARE_UPDATED],
			actual: [$row->getStatus(), $row->getKey(), $row->getRecipientCertFingerprint(), $row->getPendingNotification()]
		);
		$this->assertSame(expected: $at->getTimestamp() + 60, actual: $row->getNextNotifyAt()?->getTimestamp());
		$this->assertSame(
			expected: [AuditEventTypes::FEDERATED_SHARE_UPDATED . ' by alice', AuditEventTypes::FEDERATED_SHARE_RESUMED . ' by alice'],
			actual: $this->recorded
		);
	}//end testASuspendedShareGoesLiveWithTheNewValue()

	/**
	 * A revoke or a decline that landed meanwhile wins: no value is written
	 * and nothing is recorded.
	 *
	 * @return void
	 */
	public function testAShareThatStoppedBeingSuspendedIsNotFound(): void {
		$service = $this->service();
		$this->mapper->method('updateIfStatus')->willReturn(value: 0);

		try {
			$service->resumeSuspended(
				row: $this->suspended(),
				certFingerprint: self::FINGERPRINT,
				ciphertext: ['key' => 'NEW', 'login' => null, 'additionalFields' => null],
				savedAt: new DateTime()
			);
			$this->fail(message: 'A share that changed meanwhile resumed');
		} catch (NotFoundException) {
			$this->assertSame(expected: [[], []], actual: [$this->writes, $this->recorded]);
		}
	}//end testAShareThatStoppedBeingSuspendedIsNotFound()
}//end class
