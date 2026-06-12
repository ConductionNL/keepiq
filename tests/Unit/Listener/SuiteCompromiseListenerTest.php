<?php

/**
 * Unit tests for SuiteCompromiseListener.
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Listener
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

namespace OCA\Doriath\Tests\Unit\Listener;

use DateTime;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\ShareTarget;
use OCA\Doriath\Db\ShareTargetMapper;
use OCA\Doriath\Event\SuiteMigrationCompletedEvent;
use OCA\Doriath\Listener\SuiteCompromiseListener;
use OCA\Doriath\Service\NotificationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SuiteCompromiseListener.
 */
class SuiteCompromiseListenerTest extends TestCase
{
    /**
     * Test the listener notifies the source owner for a flagged recipient
     * copy.
     *
     * @return void
     */
    public function testHandleNotifiesSourceOwnerForSharedCopy(): void
    {
        $secretMapper      = $this->createMock(SecretMapper::class);
        $shareTargetMapper = $this->createMock(ShareTargetMapper::class);
        $notificationService = $this->createMock(NotificationService::class);
        $logger            = $this->createMock(LoggerInterface::class);
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
        $secretMapper->method('findById')->willReturn($source);

        $notificationService->expects($this->once())
            ->method('notify')
            ->with('secret_compromised', 'alice');

        $listener->handle($event);
    }

    /**
     * Test the listener falls back to the secret's own owner when the
     * copy is not a shared one.
     *
     * @return void
     */
    public function testHandleFallsBackToOwnOwnerWhenNotShared(): void
    {
        $secretMapper      = $this->createMock(SecretMapper::class);
        $shareTargetMapper = $this->createMock(ShareTargetMapper::class);
        $notificationService = $this->createMock(NotificationService::class);
        $logger            = $this->createMock(LoggerInterface::class);
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
            ->with('secret_compromised', 'alice');

        $listener->handle($event);
    }

    /**
     * Test the listener no-ops on unrelated events.
     *
     * @return void
     */
    public function testHandleIgnoresUnrelatedEvents(): void
    {
        $secretMapper      = $this->createMock(SecretMapper::class);
        $shareTargetMapper = $this->createMock(ShareTargetMapper::class);
        $notificationService = $this->createMock(NotificationService::class);
        $logger            = $this->createMock(LoggerInterface::class);
        $listener = new SuiteCompromiseListener(
            secretMapper: $secretMapper,
            shareTargetMapper: $shareTargetMapper,
            notificationService: $notificationService,
            logger: $logger
        );

        $secretMapper->expects($this->never())->method('findByEncryptionSuiteId');

        $listener->handle($this->createMock(Event::class));
    }
}
