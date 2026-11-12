<?php

/**
 * Contract tests for DashboardSettingsController.
 *
 * The dashboard settings belong to the signed-in user: every endpoint refuses
 * an anonymous caller and reads or writes only the session user's settings.
 * An update takes either a `settings` map or one `key` and `value`, and a
 * missing key or a refused value answers 400.
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Keepiq\Controller\DashboardSettingsController;
use OCA\Keepiq\Service\DashboardService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DashboardSettingsControllerTest extends TestCase {

	/**
	 * The mocked request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The mocked dashboard service.
	 *
	 * @var DashboardService&MockObject
	 */
	private DashboardService&MockObject $service;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * The controller under test.
	 *
	 * @var DashboardSettingsController
	 */
	private DashboardSettingsController $controller;

	/**
	 * Build the controller over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(originalClassName: IRequest::class);
		$this->service = $this->createMock(originalClassName: DashboardService::class);
		$this->session = $this->createMock(originalClassName: IUserSession::class);
		$this->controller = new DashboardSettingsController(
			request: $this->request,
			service: $this->service,
			session: $this->session,
		);
	}//end setUp()

	/**
	 * Sign alice in on the mocked session.
	 *
	 * @return void
	 */
	private function signIn(): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->session->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * Every endpoint refuses an anonymous caller and leaves the settings alone.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefusedEverywhere(): void {
		$this->session->method('getUser')->willReturn(null);
		foreach (['listForUser', 'get', 'set', 'setMany', 'deleteAllForUser'] as $method) {
			$this->service->expects($this->never())->method($method);
		}

		$responses = [
			$this->controller->index(),
			$this->controller->show(key: 'layout'),
			$this->controller->update(),
			$this->controller->destroy(),
		];

		foreach ($responses as $response) {
			$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
		}
	}//end testAnAnonymousCallerIsRefusedEverywhere()

	/**
	 * Index and show read the session user's settings.
	 *
	 * @return void
	 */
	public function testIndexAndShowReadTheSessionUsersSettings(): void {
		$this->signIn();
		$this->service->method('listForUser')->with('alice')->willReturn(['layout' => 'grid']);
		$this->service->method('get')->with('alice', 'layout')->willReturn('grid');

		$this->assertSame(expected: ['layout' => 'grid'], actual: $this->controller->index()->getData());
		$this->assertSame(expected: ['key' => 'layout', 'value' => 'grid'], actual: $this->controller->show(key: 'layout')->getData());
	}//end testIndexAndShowReadTheSessionUsersSettings()

	/**
	 * An unknown key on show answers 400 with the service's message.
	 *
	 * @return void
	 */
	public function testShowAnUnknownKeyAnswers400(): void {
		$this->signIn();
		$this->service->method('get')->willThrowException(new InvalidArgumentException(message: 'Unknown setting'));

		$response = $this->controller->show(key: 'nope');

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Unknown setting'], actual: $response->getData());
	}//end testShowAnUnknownKeyAnswers400()

	/**
	 * A settings map is written in one go and its result returned.
	 *
	 * @return void
	 */
	public function testUpdateWithASettingsMapWritesThemAll(): void {
		$this->signIn();
		$this->request->method('getParams')->willReturn(['settings' => ['layout' => 'list', 'density' => 'compact']]);
		$this->service->expects($this->once())->method('setMany')
			->with('alice', ['layout' => 'list', 'density' => 'compact'])
			->willReturn(['layout' => 'list', 'density' => 'compact']);
		$this->service->expects($this->never())->method('set');

		$this->assertSame(
			expected: ['layout' => 'list', 'density' => 'compact'],
			actual: $this->controller->update()->getData()
		);
	}//end testUpdateWithASettingsMapWritesThemAll()

	/**
	 * A single key is written and the full list returned.
	 *
	 * @return void
	 */
	public function testUpdateWithOneKeyWritesItAndReturnsTheList(): void {
		$this->signIn();
		$this->request->method('getParams')->willReturn(['key' => 'layout', 'value' => 'list']);
		$this->service->expects($this->once())->method('set')->with('alice', 'layout', 'list');
		$this->service->method('listForUser')->with('alice')->willReturn(['layout' => 'list']);

		$this->assertSame(expected: ['layout' => 'list'], actual: $this->controller->update()->getData());
	}//end testUpdateWithOneKeyWritesItAndReturnsTheList()

	/**
	 * Payloads without a usable key.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function keylessPayloads(): array {
		return [
			'nothing'      => [[]],
			'empty key'    => [['key' => '', 'value' => 'x']],
			'non-string'   => [['key' => 42, 'value' => 'x']],
			'settings str' => [['settings' => 'layout=list']],
		];
	}//end keylessPayloads()

	/**
	 * An update without a usable key answers 400 and writes nothing.
	 *
	 * @param array<string, mixed> $params The request payload.
	 *
	 * @dataProvider keylessPayloads
	 *
	 * @return void
	 */
	public function testUpdateWithoutAKeyAnswers400(array $params): void {
		$this->signIn();
		$this->request->method('getParams')->willReturn($params);
		$this->service->expects($this->never())->method('set');
		$this->service->expects($this->never())->method('setMany');

		$response = $this->controller->update();

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'key is required'], actual: $response->getData());
	}//end testUpdateWithoutAKeyAnswers400()

	/**
	 * A refused value answers 400.
	 *
	 * @return void
	 */
	public function testUpdateWithARefusedValueAnswers400(): void {
		$this->signIn();
		$this->request->method('getParams')->willReturn(['key' => 'layout', 'value' => 'spiral']);
		$this->service->method('set')->willThrowException(new InvalidArgumentException(message: 'Invalid layout'));

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $this->controller->update()->getStatus());
	}//end testUpdateWithARefusedValueAnswers400()

	/**
	 * Destroy removes only the session user's settings.
	 *
	 * @return void
	 */
	public function testDestroyRemovesTheSessionUsersSettings(): void {
		$this->signIn();
		$this->service->expects($this->once())->method('deleteAllForUser')->with('alice');

		$this->assertSame(expected: ['status' => 'deleted'], actual: $this->controller->destroy()->getData());
	}//end testDestroyRemovesTheSessionUsersSettings()
}//end class
