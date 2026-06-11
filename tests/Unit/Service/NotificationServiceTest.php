<?php

/**
 * Unit tests for NotificationService.
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Service
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

namespace OCA\Doriath\Tests\Unit\Service;

use OCA\Doriath\Service\NotificationService;
use OCP\IConfig;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for NotificationService.
 */
class NotificationServiceTest extends TestCase
{
    /**
     * Mock notification manager.
     *
     * @var IManager
     */
    private IManager $manager;

    /**
     * Mock config.
     *
     * @var IConfig
     */
    private IConfig $config;

    /**
     * Service under test.
     *
     * @var NotificationService
     */
    private NotificationService $service;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->manager = $this->createMock(originalClassName: IManager::class);
        $this->config  = $this->createMock(originalClassName: IConfig::class);
        $logger        = $this->createMock(originalClassName: LoggerInterface::class);
        $this->service = new NotificationService(
            notificationManager: $this->manager,
            config: $this->config,
            logger: $logger,
        );
    }

    /**
     * notify(): unknown subject returns false without dispatching.
     *
     * @return void
     */
    public function testNotifyUnknownSubjectReturnsFalse(): void
    {
        $this->manager->expects($this->never())->method('createNotification');

        $result = $this->service->notify(subject: 'mystery_subject', recipientId: 'alice');

        $this->assertFalse(condition: $result);
    }

    /**
     * notify(): empty recipient short-circuits.
     *
     * @return void
     */
    public function testNotifyEmptyRecipientReturnsFalse(): void
    {
        $this->manager->expects($this->never())->method('createNotification');

        $this->assertFalse(condition: $this->service->notify(subject: 'secret_shared', recipientId: ''));
    }

    /**
     * notify(): user opt-out suppresses dispatch.
     *
     * @return void
     */
    public function testNotifyOptedOutReturnsFalse(): void
    {
        $this->config
            ->method('getUserValue')
            ->with('alice', 'doriath', 'notify_shares', '1')
            ->willReturn('0');

        $this->manager->expects($this->never())->method('createNotification');

        $this->assertFalse(condition: $this->service->notify(subject: 'secret_shared', recipientId: 'alice'));
    }

    /**
     * notify(): subjects with a null setting key bypass opt-out.
     *
     * @return void
     */
    public function testNotifyAppPendingBypassesOptOut(): void
    {
        $this->config->expects($this->never())->method('getUserValue');

        $notification = $this->createMock(originalClassName: INotification::class);
        $notification->method('setApp')->willReturnSelf();
        $notification->method('setUser')->willReturnSelf();
        $notification->method('setDateTime')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();
        $notification->method('setSubject')->willReturnSelf();

        $this->manager->method('createNotification')->willReturn($notification);
        $this->manager->expects($this->once())->method('notify')->with($notification);

        $this->assertTrue(
            condition: $this->service->notify(
                subject: 'app_pending',
                recipientId: 'admin',
                params: ['app_name' => 'Foo', 'registered_by' => 'bar'],
            )
        );
    }

    /**
     * notify(): default opt-in (no stored value) delivers.
     *
     * @return void
     */
    public function testNotifyDefaultOptInDelivers(): void
    {
        $this->config
            ->method('getUserValue')
            ->with('alice', 'doriath', 'notify_shares', '1')
            ->willReturn('1');

        $notification = $this->createMock(originalClassName: INotification::class);
        $notification->method('setApp')->willReturnSelf();
        $notification->method('setUser')->willReturnSelf();
        $notification->method('setDateTime')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();
        $notification->method('setSubject')->willReturnSelf();

        $this->manager->method('createNotification')->willReturn($notification);
        $this->manager->expects($this->once())->method('notify')->with($notification);

        $this->assertTrue(
            condition: $this->service->notify(
                subject: 'secret_shared',
                recipientId: 'alice',
                params: ['secret_name' => 'GitHub', 'shared_by' => 'bob', 'secret_id' => 's-1'],
                objectType: 'secret',
                objectId: 's-1',
            )
        );
    }

    /**
     * SUBJECT_SETTING_MAP covers every spec'd subject.
     *
     * @return void
     */
    public function testSubjectSettingMapCoverage(): void
    {
        $expected = [
            'secret_shared',
            'share_request',
            'share_request_result',
            'group_member_added',
            'secret_compromised',
            'request_fulfilled',
            'app_pending',
        ];

        foreach ($expected as $subject) {
            $this->assertArrayHasKey(key: $subject, array: NotificationService::SUBJECT_SETTING_MAP);
        }
    }
}
