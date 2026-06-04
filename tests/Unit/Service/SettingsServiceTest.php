<?php

/**
 * Unit tests for the admin-settings surface of SettingsService.
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

use InvalidArgumentException;
use OCA\Doriath\Service\SettingsService;
use OCA\Doriath\Service\UserPreferenceService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for SettingsService admin-config methods.
 */
class SettingsServiceTest extends TestCase
{

    /**
     * The app config mock.
     *
     * @var IAppConfig
     */
    private IAppConfig $appConfig;

    /**
     * The delegated user-preference service mock.
     *
     * @var UserPreferenceService
     */
    private UserPreferenceService $userPreferenceService;

    /**
     * The service under test.
     *
     * @var SettingsService
     */
    private SettingsService $service;

    /**
     * Set up the service with mocked collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->appConfig             = $this->createMock(IAppConfig::class);
        $this->userPreferenceService = $this->createMock(UserPreferenceService::class);

        $this->service = new SettingsService(
            $this->appConfig,
            $this->createMock(IAppManager::class),
            $this->createMock(ContainerInterface::class),
            $this->createMock(IGroupManager::class),
            $this->createMock(IUserSession::class),
            $this->createMock(LoggerInterface::class),
            $this->userPreferenceService,
        );
    }//end setUp()

    /**
     * getAdminSettings reads typed values from IAppConfig.
     *
     * @return void
     */
    public function testGetAdminSettings(): void
    {
        $this->appConfig->method('getValueInt')->willReturnMap([
            ['doriath', 'master_password_min_length', 12, false, 16],
            ['doriath', 'master_password_min_score', 3, false, 4],
        ]);
        $this->appConfig->method('getValueString')->willReturn('10min');
        $this->appConfig->method('getValueBool')->willReturn(false);

        $result = $this->service->getAdminSettings();

        self::assertSame(16, $result['master_password_min_length']);
        self::assertSame(4, $result['master_password_min_score']);
        self::assertSame('10min', $result['default_session_timeout']);
        self::assertFalse($result['ca_auto_renew_enabled']);
    }//end testGetAdminSettings()

    /**
     * updateAdminSettings persists valid values via typed setters.
     *
     * @return void
     */
    public function testUpdateAdminSettingsPersistsValidValues(): void
    {
        $this->appConfig->expects($this->once())
            ->method('setValueInt')
            ->with('doriath', 'master_password_min_length', 14);

        $this->appConfig->method('getValueInt')->willReturn(14);
        $this->appConfig->method('getValueString')->willReturn('session');
        $this->appConfig->method('getValueBool')->willReturn(true);

        $result = $this->service->updateAdminSettings(['master_password_min_length' => 14]);

        self::assertArrayHasKey('master_password_min_length', $result);
    }//end testUpdateAdminSettingsPersistsValidValues()

    /**
     * updateAdminSettings rejects an out-of-bounds minimum length.
     *
     * @return void
     */
    public function testUpdateAdminSettingsRejectsLowLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->updateAdminSettings(['master_password_min_length' => 8]);
    }//end testUpdateAdminSettingsRejectsLowLength()

    /**
     * updateAdminSettings rejects an out-of-bounds score.
     *
     * @return void
     */
    public function testUpdateAdminSettingsRejectsHighScore(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->updateAdminSettings(['master_password_min_score' => 5]);
    }//end testUpdateAdminSettingsRejectsHighScore()

    /**
     * updateAdminSettings rejects an unknown session-timeout enum.
     *
     * @return void
     */
    public function testUpdateAdminSettingsRejectsBadTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->updateAdminSettings(['default_session_timeout' => 'forever']);
    }//end testUpdateAdminSettingsRejectsBadTimeout()

    /**
     * getUserPreferences delegates to the UserPreferenceService.
     *
     * @return void
     */
    public function testGetUserPreferencesDelegates(): void
    {
        $expected = ['session_timeout' => '10min'];
        $this->userPreferenceService->expects($this->once())
            ->method('getUserPreferences')
            ->with('alice')
            ->willReturn($expected);

        self::assertSame($expected, $this->service->getUserPreferences('alice'));
    }//end testGetUserPreferencesDelegates()

    /**
     * updateUserPreferences delegates to the UserPreferenceService.
     *
     * @return void
     */
    public function testUpdateUserPreferencesDelegates(): void
    {
        $data     = ['notify_shares' => false];
        $expected = ['notify_shares' => false];
        $this->userPreferenceService->expects($this->once())
            ->method('updateUserPreferences')
            ->with('alice', $data)
            ->willReturn($expected);

        self::assertSame($expected, $this->service->updateUserPreferences('alice', $data));
    }//end testUpdateUserPreferencesDelegates()
}//end class
