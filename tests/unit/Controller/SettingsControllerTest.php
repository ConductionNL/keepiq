<?php

/**
 * Unit tests for SettingsController.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Controller
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

namespace OCA\Keepiq\Tests\Unit\Controller;

use OCA\Keepiq\Controller\SettingsController;
use OCA\Keepiq\Service\SettingsService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SettingsController.
 */
class SettingsControllerTest extends TestCase {

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
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->userSession = $this->createMock(IUserSession::class);

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
	public function testIndexReturnsJsonResponseWithSettings(): void {
		$settings = [
			'register' => 'some-uuid',
			'openregisters' => true,
			'isAdmin' => false,
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
	public function testCreateCallsUpdateSettingsAndReturnsSuccess(): void {
		$params = ['register' => 'new-uuid'];
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
	public function testLoadReturnsConfigurationResult(): void {
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
	 * Test that getGeneralSettings() returns the General area settings from the service.
	 *
	 * @return void
	 */
	public function testGetGeneralSettingsReturnsServiceResponse(): void {
		$expected = [
			'min_password_length' => 12,
			'min_password_score' => 3,
			'default_session_timeout' => 'session',
			'ca_auto_renew_enabled' => true,
		];

		$this->settingsService->expects($this->once())
			->method('getAreaSettings')
			->with('general')
			->willReturn($expected);

		$result = $this->controller->getGeneralSettings();

		self::assertInstanceOf(JSONResponse::class, $result);
		self::assertSame($expected, $result->getData());

	}//end testGetGeneralSettingsReturnsServiceResponse()

	/**
	 * Test that updatePolicySettings() returns 400 on InvalidArgumentException.
	 *
	 * @return void
	 */
	public function testUpdatePolicySettingsReturns400OnInvalidInput(): void {
		$this->request->expects($this->once())
			->method('getParams')
			->willReturn(['min_password_length' => 5]);

		$this->settingsService->expects($this->once())
			->method('updateAreaSettings')
			->with('policies', ['min_password_length' => 5])
			->willThrowException(new \InvalidArgumentException('min_password_length must be between 12 and 20'));

		$result = $this->controller->updatePolicySettings();

		self::assertInstanceOf(JSONResponse::class, $result);
		self::assertSame(400, $result->getStatus());

	}//end testUpdatePolicySettingsReturns400OnInvalidInput()

	/**
	 * Test that getUserSettings() returns prefs for the authenticated user.
	 *
	 * @return void
	 */
	public function testGetUserSettingsReturnsPrefs(): void {
		$expected = ['notify_shares' => '1', 'default_view' => 'list'];
		$this->settingsService->expects($this->once())
			->method('getUserPreferences')
			->with('testuser')
			->willReturn($expected);

		$result = $this->controller->getUserSettings();

		self::assertInstanceOf(JSONResponse::class, $result);
		self::assertSame($expected, $result->getData());

	}//end testGetUserSettingsReturnsPrefs()

	/**
	 * Test that updateUserSettings() forwards to the service for the user.
	 *
	 * @return void
	 */
	public function testUpdateUserSettingsForwardsToService(): void {
		$params = ['notify_shares' => '0'];
		$updated = ['notify_shares' => '0', 'default_view' => 'list'];

		$this->request->expects($this->once())
			->method('getParams')
			->willReturn($params);

		$this->settingsService->expects($this->once())
			->method('updateUserPreferences')
			->with('testuser', $params)
			->willReturn($updated);

		$result = $this->controller->updateUserSettings();

		self::assertInstanceOf(JSONResponse::class, $result);
		self::assertSame($updated, $result->getData());

	}//end testUpdateUserSettingsForwardsToService()
}//end class
