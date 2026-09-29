<?php

/**
 * Unit tests for SuiteCompromiseListener.
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
use OCA\Keepiq\Db\RotationFlag;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Event\SuiteMigrationCompletedEvent;
use OCA\Keepiq\Listener\SuiteCompromiseListener;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\RotationPolicyService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for SuiteCompromiseListener.
 */
class SuiteCompromiseListenerTest extends TestCase {
	/**
	 * Test the listener notifies the source owner for a flagged recipient
	 * copy.
	 *
	 * @return void
	 */
	public function testHandleNotifiesSourceOwnerForSharedCopy(): void {
		$secretMapper = $this->createMock(SecretMapper::class);
		$shareTargetMapper = $this->createMock(ShareTargetMapper::class);
		$notificationService = $this->createMock(NotificationService::class);
		$rotationService = $this->createMock(RotationPolicyService::class);
		$listener = new SuiteCompromiseListener(
			secretMapper: $secretMapper,
			shareTargetMapper: $shareTargetMapper,
			notificationService: $notificationService,
			logger: $this->createMock(LoggerInterface::class),
			rotationService: $rotationService
		);

		$event = new SuiteMigrationCompletedEvent(
			oldSuiteId: 'old',
			newSuiteId: 'new',
			migrationId: 'mig-1'
		);

		// Recipient copy flagged compromised.
		$copy = new Secret();
		$copy->setId('copy-1');
		$copy->setOwnerType('user');
		$copy->setOwnerId('bob');
		$copy->setName('shared-thing');
		$copy->setPossiblyCompromisedAt(new DateTime());
		// Owner copy NOT compromised.
		$other = new Secret();
		$other->setId('copy-2');
		$other->setOwnerId('eve');

		$secretMapper->method('findByEncryptionSuiteId')->willReturn([$copy, $other]);

		$shareTarget = new ShareTarget();
		$shareTarget->setSourceSecretId('src-1');
		$shareTarget->setSecretId('copy-1');
		$shareTargetMapper->method('findByRecipientSecret')->willReturn($shareTarget);

		$source = new Secret();
		$source->setId('src-1');
		$source->setOwnerType('user');
		$source->setOwnerId('alice');
		$source->setName('shared-thing-source');
		$secretMapper->method('findById')->willReturn($source);

		// The source is not sealed under the new suite, so nothing else in the
		// migration path stamps or flags it: this listener has to (#805 review).
		$updated = [];
		$secretMapper->method('update')->willReturnCallback(
			static function (Secret $secret) use (&$updated): Secret {
				$updated[] = $secret->getId();
				return $secret;
			}
		);
		$flagged = [];
		// flag() returns a RotationFlag; a callback returning nothing would make
		// the mock throw, which the listener's catch-all swallows mid-loop.
		$rotationService->method('flag')->willReturnCallback(
			static function (string $secretId) use (&$flagged): RotationFlag {
				$flagged[] = $secretId;
				return new RotationFlag();
			}
		);

		// snake_case, as the notifier reads it, pointing at the source alice
		// can open (#805 review).
		$notificationService->expects($this->once())
			->method('notify')
			->with(
				'secret_compromised',
				'alice',
				$this->callback(static fn (array $params): bool => ($params['secret_id'] ?? null) === 'src-1'
					&& ($params['secret_name'] ?? null) === 'shared-thing-source')
			);

		$listener->handle($event);

		$this->assertSame(['src-1'], $updated, 'only the un-stamped source is persisted');
		$this->assertNotNull($source->getPossiblyCompromisedAt(), 'the source must be stamped');
		$this->assertSame(['copy-1', 'src-1'], $flagged, 'the copy and its source are both flagged');
	}//end testHandleNotifiesSourceOwnerForSharedCopy()

	/**
	 * A shared copy whose source is gone warns about the copy itself, quietly:
	 * that is an expected state, not an error.
	 *
	 * @return void
	 */
	public function testHandleFallsBackToTheCopyWhenTheSourceIsGone(): void {
		$secretMapper = $this->createMock(SecretMapper::class);
		$shareTargetMapper = $this->createMock(ShareTargetMapper::class);
		$notificationService = $this->createMock(NotificationService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$listener = new SuiteCompromiseListener(
			secretMapper: $secretMapper,
			shareTargetMapper: $shareTargetMapper,
			notificationService: $notificationService,
			logger: $logger
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
		$secretMapper->method('findById')->willThrowException(new DoesNotExistException('source gone'));

		$logger->expects($this->never())->method('warning');
		$notificationService->expects($this->once())
			->method('notify')
			->with(
				'secret_compromised',
				'bob',
				$this->callback(static fn (array $params): bool => ($params['secret_id'] ?? null) === 'copy-1')
			);

		$listener->handle(new SuiteMigrationCompletedEvent(oldSuiteId: 'old', newSuiteId: 'new', migrationId: 'mig-1'));
	}//end testHandleFallsBackToTheCopyWhenTheSourceIsGone()

	/**
	 * A lookup that fails for any other reason still falls back to the copy,
	 * but is logged: silently warning the wrong owner is the #802 bug.
	 *
	 * @return void
	 */
	public function testHandleLogsALookupErrorAndFallsBackToTheCopy(): void {
		$secretMapper = $this->createMock(SecretMapper::class);
		$shareTargetMapper = $this->createMock(ShareTargetMapper::class);
		$notificationService = $this->createMock(NotificationService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$listener = new SuiteCompromiseListener(
			secretMapper: $secretMapper,
			shareTargetMapper: $shareTargetMapper,
			notificationService: $notificationService,
			logger: $logger
		);

		$copy = new Secret();
		$copy->setId('copy-1');
		$copy->setOwnerType('user');
		$copy->setOwnerId('bob');
		$copy->setName('shared-thing');
		$copy->setPossiblyCompromisedAt(new DateTime());
		$secretMapper->method('findByEncryptionSuiteId')->willReturn([$copy]);
		$shareTargetMapper->method('findByRecipientSecret')
			->willThrowException(new RuntimeException('database unavailable'));

		$logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('database unavailable'));
		$notificationService->expects($this->once())
			->method('notify')
			->with('secret_compromised', 'bob', $this->anything());

		$listener->handle(new SuiteMigrationCompletedEvent(oldSuiteId: 'old', newSuiteId: 'new', migrationId: 'mig-1'));
	}//end testHandleLogsALookupErrorAndFallsBackToTheCopy()

	/**
	 * Test the listener falls back to the secret's own owner when the
	 * copy is not a shared one.
	 *
	 * @return void
	 */
	public function testHandleFallsBackToOwnOwnerWhenNotShared(): void {
		$secretMapper = $this->createMock(SecretMapper::class);
		$shareTargetMapper = $this->createMock(ShareTargetMapper::class);
		$notificationService = $this->createMock(NotificationService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$listener = new SuiteCompromiseListener(
			secretMapper: $secretMapper,
			shareTargetMapper: $shareTargetMapper,
			notificationService: $notificationService,
			logger: $logger
		);

		$event = new SuiteMigrationCompletedEvent(
			oldSuiteId: 'old',
			newSuiteId: 'new',
			migrationId: 'mig-1'
		);

		$copy = new Secret();
		$copy->setId('copy-1');
		$copy->setOwnerType('user');
		$copy->setOwnerId('alice');
		$copy->setName('demo');
		$copy->setPossiblyCompromisedAt(new DateTime());

		$secretMapper->method('findByEncryptionSuiteId')->willReturn([$copy]);
		$shareTargetMapper->method('findByRecipientSecret')
			->willThrowException(new DoesNotExistException('no'));

		$notificationService->expects($this->once())
			->method('notify')
			->with(
				'secret_compromised',
				'alice',
				$this->callback(static fn (array $params): bool => ($params['secret_id'] ?? null) === 'copy-1'
					&& ($params['secret_name'] ?? null) === 'demo')
			);

		$listener->handle($event);
	}//end testHandleFallsBackToOwnOwnerWhenNotShared()

	/**
	 * Test the listener no-ops on unrelated events.
	 *
	 * @return void
	 */
	public function testHandleIgnoresUnrelatedEvents(): void {
		$secretMapper = $this->createMock(SecretMapper::class);
		$shareTargetMapper = $this->createMock(ShareTargetMapper::class);
		$notificationService = $this->createMock(NotificationService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$listener = new SuiteCompromiseListener(
			secretMapper: $secretMapper,
			shareTargetMapper: $shareTargetMapper,
			notificationService: $notificationService,
			logger: $logger
		);

		$secretMapper->expects($this->never())->method('findByEncryptionSuiteId');

		$listener->handle($this->createMock(Event::class));
	}//end testHandleIgnoresUnrelatedEvents()

	/**
	 * Test one owner gets one notification no matter how many of their secrets
	 * the migration flagged.
	 *
	 * This listener has never fired in production: nothing set
	 * `possibly_compromised_at`, so it was inert. Compromise-recovery migration
	 * now sets it on every migrated secret, which switches this code on for the
	 * first time — and a 500-secret vault must not produce 500 notifications.
	 *
	 * @return void
	 */
	public function testHandleDeduplicatesNotificationsAcrossALargeMigration(): void {
		$secretMapper = $this->createMock(SecretMapper::class);
		$shareTargetMapper = $this->createMock(ShareTargetMapper::class);
		$notificationService = $this->createMock(NotificationService::class);
		$listener = new SuiteCompromiseListener(
			secretMapper: $secretMapper,
			shareTargetMapper: $shareTargetMapper,
			notificationService: $notificationService,
			logger: $this->createMock(LoggerInterface::class)
		);

		// A large migration: 250 of alice's secrets and 250 of bob's, every one
		// of them flagged.
		$flagged = [];
		foreach (['alice', 'bob'] as $ownerId) {
			for ($index = 0; $index < 250; $index++) {
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
		// Not shared copies, so the owner resolves to the secret's own owner.
		$shareTargetMapper->method('findByRecipientSecret')
			->willThrowException(new DoesNotExistException('not shared'));

		$notified = [];
		$notificationService->expects($this->exactly(2))
			->method('notify')
			->willReturnCallback(
				// Must return bool: the listener swallows Throwable, so a
				// callback returning null would abort the walk after the first
				// owner and quietly look like de-duplication working.
				function (string $subject, string $recipientId) use (&$notified): bool {
					$notified[] = $recipientId;
					return true;
				}
			);

		$listener->handle(
			new SuiteMigrationCompletedEvent(
				oldSuiteId: 'old',
				newSuiteId: 'new',
				migrationId: 'mig-1'
			)
		);

		// One per owner, not one per secret.
		$this->assertSame(['alice', 'bob'], $notified);
	}//end testHandleDeduplicatesNotificationsAcrossALargeMigration()

	/**
	 * Test unflagged secrets never produce a notification.
	 *
	 * A record the migration did not touch, or one whose re-encryption failed,
	 * carries no flag and must stay silent.
	 *
	 * @return void
	 */
	public function testHandleIgnoresUnflaggedSecrets(): void {
		$secretMapper = $this->createMock(SecretMapper::class);
		$shareTargetMapper = $this->createMock(ShareTargetMapper::class);
		$notificationService = $this->createMock(NotificationService::class);
		$listener = new SuiteCompromiseListener(
			secretMapper: $secretMapper,
			shareTargetMapper: $shareTargetMapper,
			notificationService: $notificationService,
			logger: $this->createMock(LoggerInterface::class)
		);

		$unflagged = new Secret();
		$unflagged->setId('secret-1');
		$unflagged->setOwnerType('user');
		$unflagged->setOwnerId('alice');

		$secretMapper->method('findByEncryptionSuiteId')->willReturn([$unflagged]);

		$notificationService->expects($this->never())->method('notify');

		$listener->handle(
			new SuiteMigrationCompletedEvent(
				oldSuiteId: 'old',
				newSuiteId: 'new',
				migrationId: 'mig-1'
			)
		);
	}//end testHandleIgnoresUnflaggedSecrets()
}//end class
