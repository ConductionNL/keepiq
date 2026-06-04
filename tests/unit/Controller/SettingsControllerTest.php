<?php

/**
 * Unit tests for SettingsController.
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Controller
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

namespace OCA\Doriath\Tests\Unit\Controller;

use OCA\Doriath\Controller\SettingsController;
use OCA\Doriath\Service\SettingsService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SettingsController.
 */
class SettingsControllerTest extends TestCase
{

    /**
     * The controller under test.
     *
     * @var SettingsController
     */
    private SettingsController $controller;

    /**
     * Mock IRequest.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Mock SettingsService.
     *
     * @var SettingsService&MockObject
     */
    private SettingsService&MockObject $settingsService;

    /**
     * Mock IUserSession.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request         = $this->createMock(IRequest::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->userSession     = $this->createMock(IUserSession::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($user);

        $this->controller = new SettingsController(
            $this->request,
            $this->settingsService,
            $this->userSession,
        );

    }//end setUp()

    /**
     * Test that index() returns a JSONResponse containing the settings from the service.
     *
     * @return void
     */
    public function testIndexReturnsJsonResponseWithSettings(): void
    {
        $settings = [
            'register'      => 'some-uuid',
            'openregisters' => true,
            'isAdmin'       => false,
        ];

        $this->settingsService->expects($this->once())
            ->method('getSettings')
            ->willReturn($settings);

        $result = $this->controller->index();

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame($settings, $result->getData());

    }//end testIndexReturnsJsonResponseWithSettings()

    /**
     * Test that create() calls updateSettings with request params and returns success.
     *
     * @return void
     */
    public function testCreateCallsUpdateSettingsAndReturnsSuccess(): void
    {
        $params  = ['register' => 'new-uuid'];
        $updated = ['register' => 'new-uuid', 'openregisters' => true, 'isAdmin' => false];

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($params);

        $this->settingsService->expects($this->once())
            ->method('updateSettings')
            ->with($params)
            ->willReturn($updated);

        $result = $this->controller->create();

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertTrue($result->getData()['success']);
        self::assertArrayHasKey('config', $result->getData());

    }//end testCreateCallsUpdateSettingsAndReturnsSuccess()

    /**
     * Test that load() returns the result of loadConfiguration.
     *
     * @return void
     */
    public function testLoadReturnsConfigurationResult(): void
    {
        $loadResult = [
            'success' => true,
            'message' => 'Configuration imported successfully.',
            'version' => '0.1.0',
        ];

        $this->settingsService->expects($this->once())
            ->method('loadConfiguration')
            ->with(force: true)
            ->willReturn($loadResult);

        $result = $this->controller->load();

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertTrue($result->getData()['success']);

    }//end testLoadReturnsConfigurationResult()

    /**
     * Test that getAdminSettings() returns the service payload.
     *
     * @return void
     */
    public function testGetAdminSettingsReturnsServicePayload(): void
    {
        $admin = [
            'master_password_min_length' => 12,
            'master_password_min_score'  => 3,
            'default_session_timeout'    => 'session',
            'ca_auto_renew_enabled'      => true,
        ];

        $this->settingsService->expects($this->once())
            ->method('getAdminSettings')
            ->willReturn($admin);

        $result = $this->controller->getAdminSettings();

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame($admin, $result->getData());

    }//end testGetAdminSettingsReturnsServicePayload()

    /**
     * Test that updateAdminSettings() returns the updated settings on success.
     *
     * @return void
     */
    public function testUpdateAdminSettingsReturnsUpdated(): void
    {
        $params  = ['master_password_min_length' => 14];
        $updated = [
            'master_password_min_length' => 14,
            'master_password_min_score'  => 3,
            'default_session_timeout'    => 'session',
            'ca_auto_renew_enabled'      => true,
        ];

        $this->request->method('getParams')->willReturn($params);
        $this->settingsService->expects($this->once())
            ->method('updateAdminSettings')
            ->with($params)
            ->willReturn($updated);

        $result = $this->controller->updateAdminSettings();

        self::assertSame($updated, $result->getData());

    }//end testUpdateAdminSettingsReturnsUpdated()

    /**
     * Test that updateAdminSettings() maps a validation error to a 400.
     *
     * @return void
     */
    public function testUpdateAdminSettingsReturns400OnValidationError(): void
    {
        $this->request->method('getParams')->willReturn(['master_password_min_length' => 8]);
        $this->settingsService->method('updateAdminSettings')
            ->willThrowException(new \InvalidArgumentException('out of bounds'));

        $result = $this->controller->updateAdminSettings();

        self::assertSame(\OCP\AppFramework\Http::STATUS_BAD_REQUEST, $result->getStatus());
        self::assertSame('out of bounds', $result->getData()['message']);

    }//end testUpdateAdminSettingsReturns400OnValidationError()

    /**
     * Test that getUserSettings() returns the current user's preferences.
     *
     * @return void
     */
    public function testGetUserSettingsReturnsPreferences(): void
    {
        $prefs = ['session_timeout' => '10min', 'notify_shares' => true];

        $this->settingsService->expects($this->once())
            ->method('getUserPreferences')
            ->with('testuser')
            ->willReturn($prefs);

        $result = $this->controller->getUserSettings();

        self::assertSame($prefs, $result->getData());

    }//end testGetUserSettingsReturnsPreferences()

    /**
     * Test that updateUserSettings() persists for the current user.
     *
     * @return void
     */
    public function testUpdateUserSettingsPersistsForCurrentUser(): void
    {
        $params  = ['session_timeout' => '30min'];
        $updated = ['session_timeout' => '30min', 'notify_shares' => true];

        $this->request->method('getParams')->willReturn($params);
        $this->settingsService->expects($this->once())
            ->method('updateUserPreferences')
            ->with('testuser', $params)
            ->willReturn($updated);

        $result = $this->controller->updateUserSettings();

        self::assertSame($updated, $result->getData());

    }//end testUpdateUserSettingsPersistsForCurrentUser()
}//end class
