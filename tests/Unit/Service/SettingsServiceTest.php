<?php

/**
 * Unit tests for SettingsService admin + user preference methods.
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
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the admin-settings and user-preference surface of SettingsService.
 */
class SettingsServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var SettingsService
     */
    private SettingsService $service;

    /**
     * Mock app config.
     *
     * @var IAppConfig
     */
    private IAppConfig $appConfig;

    /**
     * Mock per-user config.
     *
     * @var IConfig
     */
    private IConfig $config;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->config    = $this->createMock(IConfig::class);

        $this->service = new SettingsService(
            $this->appConfig,
            $this->config,
            $this->createMock(IAppManager::class),
            $this->createMock(ContainerInterface::class),
            $this->createMock(IGroupManager::class),
            $this->createMock(IUserSession::class),
            $this->createMock(LoggerInterface::class),
        );

    }//end setUp()

    /**
     * A valid admin update is persisted with typed setters.
     *
     * @return void
     */
    public function testUpdateAdminSettingsPersistsValidValues(): void
    {
        $this->appConfig->expects($this->once())
            ->method('setValueInt')
            ->with('doriath', 'min_password_length', 16);

        $this->appConfig->method('getValueInt')->willReturnOnConsecutiveCalls(16, 3);
        $this->appConfig->method('getValueString')->willReturn('session');
        $this->appConfig->method('getValueBool')->willReturn(true);

        $result = $this->service->updateAdminSettings(['min_password_length' => 16]);

        self::assertSame(16, $result['min_password_length']);

    }//end testUpdateAdminSettingsPersistsValidValues()

    /**
     * A below-floor length is rejected and nothing is persisted.
     *
     * @return void
     */
    public function testUpdateAdminSettingsRejectsBelowFloor(): void
    {
        $this->appConfig->expects($this->never())->method('setValueInt');

        $this->expectException(InvalidArgumentException::class);

        $this->service->updateAdminSettings(['min_password_length' => 8]);

    }//end testUpdateAdminSettingsRejectsBelowFloor()

    /**
     * An out-of-range score is rejected.
     *
     * @return void
     */
    public function testUpdateAdminSettingsRejectsBadScore(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->updateAdminSettings(['min_password_score' => 5]);

    }//end testUpdateAdminSettingsRejectsBadScore()

    /**
     * Only whitelisted keys are written; arbitrary keys are ignored.
     *
     * @return void
     */
    public function testUpdateUserPreferencesWhitelistsKeys(): void
    {
        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with('alice', 'doriath', 'session_timeout', '30min');

        $this->config->method('getUserValue')->willReturnCallback(
            static function ($uid, $app, $key, $default) {
                return $default;
            }
        );
        $this->appConfig->method('getValueString')->willReturn('session');

        $result = $this->service->updateUserPreferences(
            'alice',
            [
                'session_timeout' => '30min',
                'evil_key'        => 'ignored',
            ]
        );

        self::assertArrayHasKey('session_timeout', $result);
        self::assertArrayNotHasKey('evil_key', $result);

    }//end testUpdateUserPreferencesWhitelistsKeys()

    /**
     * Boolean toggles are returned as native booleans.
     *
     * @return void
     */
    public function testGetUserPreferencesReturnsBooleans(): void
    {
        $this->appConfig->method('getValueString')->willReturn('session');
        $this->config->method('getUserValue')->willReturnCallback(
            static function ($uid, $app, $key, $default) {
                if ($key === 'notify_shares') {
                    return '0';
                }
                return $default;
            }
        );

        $prefs = $this->service->getUserPreferences('alice');

        self::assertFalse($prefs['notify_shares']);
        self::assertTrue($prefs['notify_requests']);

    }//end testGetUserPreferencesReturnsBooleans()
}//end class
