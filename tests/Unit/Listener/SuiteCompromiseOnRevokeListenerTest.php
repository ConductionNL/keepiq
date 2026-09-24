<?php

/**
 * Unit tests for SuiteCompromiseOnRevokeListener.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Listener
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

namespace OCA\Keepiq\Tests\Unit\Listener;

use DateTime;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Event\EncryptionSuiteRevokedEvent;
use OCA\Keepiq\Listener\SuiteCompromiseOnRevokeListener;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\RotationPolicyService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SuiteCompromiseOnRevokeListener.
 */
class SuiteCompromiseOnRevokeListenerTest extends TestCase {
	/**
	 * Build a revoke event with the given compromise flag.
	 *
	 * @param bool $compromised The compromise flag
	 *
	 * @return EncryptionSuiteRevokedEvent
	 */
	private function event(bool $compromised): EncryptionSuiteRevokedEvent {
		return new EncryptionSuiteRevokedEvent(
			suiteId: 'suite-1',
			ownerType: 'user',
			ownerId: 'alice',
			revokedBy: 'admin',
			compromised: $compromised
		);
	}//end event()

	/**
	 * A compromise revocation stamps possibly_compromised_at on every un-stamped
	 * secret in the blast radius, raises a suite_compromise flag per secret, and
	 * notifies the affected owner.
	 *
	 * @return void
	 */
	public function testCompromiseStampsFlagsAndNotifies(): void {
		$secretMapper = $this->createMock(SecretMapper::class);
		$shareTargetMapper = $this->createMock(ShareTargetMapper::class);
		$notificationService = $this->createMock(NotificationService::class);
		$rotationService = $this->createMock(RotationPolicyService::class);
		$listener = new SuiteCompromiseOnRevokeListener(
			secretMapper: $secretMapper,
			shareTargetMapper: $shareTargetMapper,
			notificationService: $notificationService,
			logger: $this->createMock(LoggerInterface::class),
			rotationService: $rotationService
		);

		$secret = new Secret();
		$secret->setId('secret-1');
		$secret->setOwnerType('user');
		$secret->setOwnerId('alice');
		$secret->setName('prod-db');

		$secretMapper->method('findByEncryptionSuiteId')->with('suite-1')->willReturn([$secret]);
		$shareTargetMapper->method('findByRecipientSecret')
			->willThrowException(new DoesNotExistException('not shared'));

		// Nothing stamped it before the revoke — the listener must stamp + persist.
		$secretMapper->expects($this->once())
			->method('update')
			->willReturnCallback(
				function (Secret $updated): Secret {
					$this->assertNotNull($updated->getPossiblyCompromisedAt());
					return $updated;
				}
			);
		$rotationService->expects($this->once())
			->method('flag')
			->with('secret-1', 'suite_compromise');
		$notificationService->expects($this->once())
			->method('notify')
			->with('secret_compromised', 'alice');

		$listener->handle($this->event(compromised: true));

		$this->assertNotNull($secret->getPossiblyCompromisedAt());
	}//end testCompromiseStampsFlagsAndNotifies()

	/**
	 * A non-compromise revocation (the owner path, or an administrator who left
	 * markCompromised off) is a total no-op: no lookup, no stamp, no flag, no
	 * notification.
	 *
	 * @return void
	 */
	public function testNonCompromiseRevocationIsANoOp(): void {
		$secretMapper = $this->createMock(SecretMapper::class);
		$shareTargetMapper = $this->createMock(ShareTargetMapper::class);
		$notificationService = $this->createMock(NotificationService::class);
		$rotationService = $this->createMock(RotationPolicyService::class);
		$listener = new SuiteCompromiseOnRevokeListener(
			secretMapper: $secretMapper,
			shareTargetMapper: $shareTargetMapper,
			notificationService: $notificationService,
			logger: $this->createMock(LoggerInterface::class),
			rotationService: $rotationService
		);

		$secretMapper->expects($this->never())->method('findByEncryptionSuiteId');
		$secretMapper->expects($this->never())->method('update');
		$rotationService->expects($this->never())->method('flag');
		$notificationService->expects($this->never())->method('notify');

		$listener->handle($this->event(compromised: false));
	}//end testNonCompromiseRevocationIsANoOp()

	/**
	 * An already-stamped secret is not re-stamped (the listener persists only the
	 * secrets it newly flags) but is still flagged and its owner notified.
	 *
	 * @return void
	 */
	public function testAlreadyStampedSecretIsNotRePersisted(): void {
		$secretMapper = $this->createMock(SecretMapper::class);
		$shareTargetMapper = $this->createMock(ShareTargetMapper::class);
		$notificationService = $this->createMock(NotificationService::class);
		$rotationService = $this->createMock(RotationPolicyService::class);
		$listener = new SuiteCompromiseOnRevokeListener(
			secretMapper: $secretMapper,
			shareTargetMapper: $shareTargetMapper,
			notificationService: $notificationService,
			logger: $this->createMock(LoggerInterface::class),
			rotationService: $rotationService
		);

		$secret = new Secret();
		$secret->setId('secret-1');
		$secret->setOwnerType('user');
		$secret->setOwnerId('alice');
		$secret->setName('prod-db');
		$secret->setPossiblyCompromisedAt(new DateTime());

		$secretMapper->method('findByEncryptionSuiteId')->willReturn([$secret]);
		$shareTargetMapper->method('findByRecipientSecret')
			->willThrowException(new DoesNotExistException('not shared'));

		$secretMapper->expects($this->never())->method('update');
		$rotationService->expects($this->once())->method('flag')->with('secret-1', 'suite_compromise');
		$notificationService->expects($this->once())->method('notify')->with('secret_compromised', 'alice');

		$listener->handle($this->event(compromised: true));
	}//end testAlreadyStampedSecretIsNotRePersisted()

	/**
	 * A shared recipient copy resolves back to the source secret's owner, so the
	 * ORIGINAL owner is notified, not the recipient.
	 *
	 * @return void
	 */
	public function testSharedCopyNotifiesTheSourceOwner(): void {
		$secretMapper = $this->createMock(SecretMapper::class);
		$shareTargetMapper = $this->createMock(ShareTargetMapper::class);
		$notificationService = $this->createMock(NotificationService::class);
		$listener = new SuiteCompromiseOnRevokeListener(
			secretMapper: $secretMapper,
			shareTargetMapper: $shareTargetMapper,
			notificationService: $notificationService,
			logger: $this->createMock(LoggerInterface::class),
			rotationService: $this->createMock(RotationPolicyService::class)
		);

		$copy = new Secret();
		$copy->setId('copy-1');
		$copy->setOwnerType('user');
		$copy->setOwnerId('bob');
		$copy->setName('shared-thing');
		$copy->setPossiblyCompromisedAt(new DateTime());

		$secretMapper->method('findByEncryptionSuiteId')->willReturn([$copy]);

		$shareTarget = new ShareTarget();
		$shareTarget->setSourceSecretId('src-1');
		$shareTarget->setSecretId('copy-1');
		$shareTargetMapper->method('findByRecipientSecret')->willReturn($shareTarget);

		$source = new Secret();
		$source->setId('src-1');
		$source->setOwnerType('user');
		$source->setOwnerId('carol');
		$secretMapper->method('findById')->willReturn($source);

		$notificationService->expects($this->once())
			->method('notify')
			->with('secret_compromised', 'carol');

		$listener->handle($this->event(compromised: true));
	}//end testSharedCopyNotifiesTheSourceOwner()

	/**
	 * One owner gets one notification no matter how many of their secrets the
	 * revoked suite carried.
	 *
	 * @return void
	 */
	public function testNotificationsAreDeduplicatedPerOwner(): void {
		$secretMapper = $this->createMock(SecretMapper::class);
		$shareTargetMapper = $this->createMock(ShareTargetMapper::class);
		$notificationService = $this->createMock(NotificationService::class);
		$listener = new SuiteCompromiseOnRevokeListener(
			secretMapper: $secretMapper,
			shareTargetMapper: $shareTargetMapper,
			notificationService: $notificationService,
			logger: $this->createMock(LoggerInterface::class),
			rotationService: $this->createMock(RotationPolicyService::class)
		);

		$flagged = [];
		foreach (['alice', 'bob'] as $ownerId) {
			for ($index = 0; $index < 50; $index++) {
				$secret = new Secret();
				$secret->setId($ownerId . '-secret-' . $index);
				$secret->setOwnerType('user');
				$secret->setOwnerId($ownerId);
				$secret->setName('thing-' . $index);
				$secret->setPossiblyCompromisedAt(new DateTime());
				$flagged[] = $secret;
			}
		}

		$secretMapper->method('findByEncryptionSuiteId')->willReturn($flagged);
		$shareTargetMapper->method('findByRecipientSecret')
			->willThrowException(new DoesNotExistException('not shared'));

		$notified = [];
		$notificationService->expects($this->exactly(2))
			->method('notify')
			->willReturnCallback(
				function (string $subject, string $recipientId) use (&$notified): bool {
					$notified[] = $recipientId;
					return true;
				}
			);

		$listener->handle($this->event(compromised: true));

		$this->assertSame(['alice', 'bob'], $notified);
	}//end testNotificationsAreDeduplicatedPerOwner()

	/**
	 * The listener ignores events other than EncryptionSuiteRevokedEvent.
	 *
	 * @return void
	 */
	public function testUnrelatedEventsAreIgnored(): void {
		$secretMapper = $this->createMock(SecretMapper::class);
		$listener = new SuiteCompromiseOnRevokeListener(
			secretMapper: $secretMapper,
			shareTargetMapper: $this->createMock(ShareTargetMapper::class),
			notificationService: $this->createMock(NotificationService::class),
			logger: $this->createMock(LoggerInterface::class),
			rotationService: $this->createMock(RotationPolicyService::class)
		);

		$secretMapper->expects($this->never())->method('findByEncryptionSuiteId');

		$listener->handle($this->createMock(Event::class));
	}//end testUnrelatedEventsAreIgnored()
}//end class
