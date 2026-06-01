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

/**
 * Tests for NotificationService.
 */
class NotificationServiceTest extends TestCase
{
    private NotificationService $service;

    private IManager $manager;

    private IConfig $config;

    /**
     * Set up the service under test with mocked collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->manager = $this->createMock(IManager::class);
        $this->config  = $this->createMock(IConfig::class);
        $this->service = new NotificationService($this->manager, $this->config);
    }//end setUp()

    /**
     * The subject-setting map covers the documented sharing subjects.
     *
     * @return void
     */
    public function testSubjectSettingMap(): void
    {
        $map = NotificationService::SUBJECT_SETTING_MAP;
        $this->assertSame('notify_shares', $map['secret_shared']);
        $this->assertSame('notify_group_shares', $map['group_member_added']);
        $this->assertSame('notify_security', $map['secret_compromised']);
    }//end testSubjectSettingMap()

    /**
     * isEnabled defaults to true when the user has no explicit preference.
     *
     * @return void
     */
    public function testIsEnabledDefaultsTrue(): void
    {
        $this->config->method('getUserValue')->willReturn('true');
        $this->assertTrue($this->service->isEnabled('secret_shared', 'bob'));
    }//end testIsEnabledDefaultsTrue()

    /**
     * isEnabled returns false when the user disabled the relevant setting.
     *
     * @return void
     */
    public function testIsEnabledRespectsDisabled(): void
    {
        $this->config->method('getUserValue')->willReturn('false');
        $this->assertFalse($this->service->isEnabled('secret_shared', 'bob'));
    }//end testIsEnabledRespectsDisabled()

    /**
     * isEnabled returns true for a subject not present in the map.
     *
     * @return void
     */
    public function testIsEnabledUnmappedSubjectAlwaysTrue(): void
    {
        $this->assertTrue($this->service->isEnabled('unknown_subject', 'bob'));
    }//end testIsEnabledUnmappedSubjectAlwaysTrue()

    /**
     * notify dispatches when the preference is enabled.
     *
     * @return void
     */
    public function testNotifyDispatchesWhenEnabled(): void
    {
        $this->config->method('getUserValue')->willReturn('true');

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturnSelf();
        $notification->method('setUser')->willReturnSelf();
        $notification->method('setDateTime')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();
        $notification->method('setSubject')->willReturnSelf();
        $this->manager->method('createNotification')->willReturn($notification);

        $this->manager->expects($this->once())->method('notify')->with($notification);

        $this->service->notify('secret_shared', 'bob', ['actorId' => 'alice'], 'secret-1');
    }//end testNotifyDispatchesWhenEnabled()

    /**
     * notify is suppressed when the preference is disabled.
     *
     * @return void
     */
    public function testNotifySuppressedWhenDisabled(): void
    {
        $this->config->method('getUserValue')->willReturn('false');
        $this->manager->expects($this->never())->method('notify');

        $this->service->notify('secret_shared', 'bob', ['actorId' => 'alice'], 'secret-1');
    }//end testNotifySuppressedWhenDisabled()
}//end class
