<?php

/**
 * Unit tests for the personal-preference endpoints of SettingsController.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
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
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * User preferences are read and written only for the signed-in user.
 */
#[CoversClass(SettingsController::class)]
class SettingsControllerUserPreferencesTest extends TestCase {
	/** @var SettingsService&MockObject */
	private SettingsService $settings;

	/** @var IRequest&MockObject */
	private IRequest $request;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settings = $this->createMock(SettingsService::class);
		$this->request = $this->createMock(IRequest::class);
	}//end setUp()

	/**
	 * The controller over a session holding the given user id, or none.
	 *
	 * @param string|null $uid The signed-in user id
	 *
	 * @return SettingsController
	 */
	private function controller(?string $uid): SettingsController {
		$session = $this->createStub(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createStub(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new SettingsController(
			request: $this->request,
			settingsService: $this->settings,
			userSession: $session,
		);
	}//end controller()

	/**
	 * Reading preferences without a session is a 401 that reads nothing.
	 *
	 * @return void
	 */
	public function testGetUserSettingsRefusesAnonymous(): void {
		$this->settings->expects($this->never())->method('getUserPreferences');

		$response = $this->controller(uid: null)->getUserSettings();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Unauthorized'], $response->getData());
	}//end testGetUserSettingsRefusesAnonymous()

	/**
	 * Writing preferences without a session is a 401 that writes nothing.
	 *
	 * @return void
	 */
	public function testUpdateUserSettingsRefusesAnonymous(): void {
		$this->settings->expects($this->never())->method('updateUserPreferences');

		$response = $this->controller(uid: null)->updateUserSettings();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Unauthorized'], $response->getData());
	}//end testUpdateUserSettingsRefusesAnonymous()

	/**
	 * A write passes the request params for the session user and returns the stored result.
	 *
	 * @return void
	 */
	public function testUpdateUserSettingsWritesForTheSessionUser(): void {
		$this->request->method('getParams')->willReturn(['defaultView' => 'grid']);
		$this->settings->expects($this->once())
			->method('updateUserPreferences')
			->with('alice', ['defaultView' => 'grid'])
			->willReturn(['defaultView' => 'grid', 'compact' => false]);

		$response = $this->controller(uid: 'alice')->updateUserSettings();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['defaultView' => 'grid', 'compact' => false], $response->getData());
	}//end testUpdateUserSettingsWritesForTheSessionUser()
}//end class
