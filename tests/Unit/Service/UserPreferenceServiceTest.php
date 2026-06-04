<?php

/**
 * Unit tests for UserPreferenceService.
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

use OCA\Doriath\Service\UserPreferenceService;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the per-user preference service.
 */
class UserPreferenceServiceTest extends TestCase
{

    /**
     * The per-user config mock.
     *
     * @var IConfig
     */
    private IConfig $config;

    /**
     * The app config mock.
     *
     * @var IAppConfig
     */
    private IAppConfig $appConfig;

    /**
     * The service under test.
     *
     * @var UserPreferenceService
     */
    private UserPreferenceService $service;

    /**
     * Set up the service with mocked collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->config    = $this->createMock(IConfig::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->service   = new UserPreferenceService($this->config, $this->appConfig);
    }//end setUp()

    /**
     * getUserPreferences resolves booleans and the admin default timeout.
     *
     * @return void
     */
    public function testGetUserPreferencesDefaults(): void
    {
        $this->appConfig->method('getValueString')->willReturn('30min');
        $this->config->method('getUserValue')
            ->willReturnCallback(
                static function ($uid, $app, $key, $default) {
                    return $default;
                }
            );

        $prefs = $this->service->getUserPreferences('alice');

        self::assertSame('30min', $prefs['session_timeout']);
        self::assertTrue($prefs['notify_shares']);
        self::assertTrue($prefs['notify_requests']);
        self::assertSame('login', $prefs['default_secret_type']);
        self::assertSame('list', $prefs['default_view']);
    }//end testGetUserPreferencesDefaults()

    /**
     * getUserPreferences maps a stored '0' boolean to false.
     *
     * @return void
     */
    public function testGetUserPreferencesReadsStoredBoolean(): void
    {
        $this->appConfig->method('getValueString')->willReturn('session');
        $this->config->method('getUserValue')
            ->willReturnCallback(
                static function ($uid, $app, $key, $default) {
                    if ($key === 'notify_shares') {
                        return '0';
                    }

                    return $default;
                }
            );

        $prefs = $this->service->getUserPreferences('alice');

        self::assertFalse($prefs['notify_shares']);
    }//end testGetUserPreferencesReadsStoredBoolean()

    /**
     * updateUserPreferences whitelists keys and coerces booleans to '1'/'0'.
     *
     * @return void
     */
    public function testUpdateUserPreferencesWhitelistsAndCoerces(): void
    {
        $written = [];
        $this->config->method('setUserValue')
            ->willReturnCallback(
                static function ($uid, $app, $key, $value) use (&$written) {
                    $written[$key] = $value;
                }
            );
        $this->config->method('getUserValue')
            ->willReturnCallback(
                static function ($uid, $app, $key, $default) {
                    return $default;
                }
            );
        $this->appConfig->method('getValueString')->willReturn('session');

        $this->service->updateUserPreferences(
            'alice',
            [
                'notify_shares'  => false,
                'default_view'   => 'grid',
                'evil_injection' => 'x',
            ]
        );

        self::assertSame('0', $written['notify_shares']);
        self::assertSame('grid', $written['default_view']);
        self::assertArrayNotHasKey('evil_injection', $written);
    }//end testUpdateUserPreferencesWhitelistsAndCoerces()
}//end class
