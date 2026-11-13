<?php

/**
 * Further unit tests for SettingsController: anonymous callers on the user
 * reads and writes, the policy floor read and the two-factor gap report.
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

use OCA\Keepiq\Controller\SettingsController;
use OCA\Keepiq\Service\SettingsService;
use OCA\Keepiq\Service\TwoFactorGate;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the remaining branches of SettingsController.
 *
 * @covers \OCA\Keepiq\Controller\SettingsController
 */
class SettingsControllerMoreTest extends TestCase {

	/**
	 * The mocked request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The mocked settings service.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settingsService;

	/**
	 * Set up the mocks shared by every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(originalClassName: IRequest::class);
		$this->settingsService = $this->createMock(originalClassName: SettingsService::class);
	}//end setUp()

	/**
	 * Build the controller with the session resolving to the given user.
	 *
	 * @param string|null $userId The session user, or null for an anonymous caller
	 * @param TwoFactorGate|null $twoFactor The two-factor gate, or null when unavailable
	 *
	 * @return SettingsController
	 */
	private function controller(?string $userId = 'alice', ?TwoFactorGate $twoFactor = null): SettingsController {
		$user = null;
		if ($userId !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($userId);
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new SettingsController(
			request: $this->request,
			settingsService: $this->settingsService,
			userSession: $session,
			areas: null,
			twoFactor: $twoFactor,
		);
	}//end controller()

	/**
	 * The session-scoped endpoints, as calls on the controller.
	 *
	 * @return array<string,array{0:callable}>
	 */
	public static function endpointProvider(): array {
		return [
			'index'              => [fn (SettingsController $c) => $c->index()],
			'getUserSettings'    => [fn (SettingsController $c) => $c->getUserSettings()],
			'updateUserSettings' => [fn (SettingsController $c) => $c->updateUserSettings()],
			'getPolicy'          => [fn (SettingsController $c) => $c->getPolicy()],
		];
	}//end endpointProvider()

	/**
	 * Without a session user every session-scoped endpoint answers 401 and
	 * neither reads nor writes settings.
	 *
	 * @param callable $call The endpoint invocation
	 *
	 * @dataProvider endpointProvider
	 *
	 * @return void
	 */
	public function testEverySessionEndpointRefusesAnAnonymousCaller(callable $call): void {
		$this->request->method('getParams')->willReturn(['theme' => 'dark']);
		$this->settingsService->expects($this->never())->method($this->anything());

		$response = $call($this->controller(userId: null));

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Unauthorized'], actual: $response->getData());
	}//end testEverySessionEndpointRefusesAnAnonymousCaller()

	/**
	 * An authenticated user reads the policy floor exactly as the service
	 * reports it.
	 *
	 * @return void
	 */
	public function testGetPolicyReturnsTheServicePolicy(): void {
		$policy = ['master_password_min_length' => 16, 'master_password_min_score' => 3];

		$this->settingsService->expects($this->once())->method('getPolicy')->willReturn($policy);

		$response = $this->controller()->getPolicy();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: $policy, actual: $response->getData());
	}//end testGetPolicyReturnsTheServicePolicy()

	/**
	 * Without a two-factor gate wired in, the gap report is 503, not an
	 * empty report that would read as "nobody is missing two-factor".
	 *
	 * @return void
	 */
	public function testTwoFactorGapsWithoutAGateIsUnavailable(): void {
		$response = $this->controller(twoFactor: null)->twoFactorGaps(groups: ['admins']);

		$this->assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Unavailable'], actual: $response->getData());
	}//end testTwoFactorGapsWithoutAGateIsUnavailable()

	/**
	 * The gap report receives the groups as a list of strings, whatever keys
	 * and scalar types the request carried.
	 *
	 * @return void
	 */
	public function testTwoFactorGapsForwardsTheGroupsAsAStringList(): void {
		$report = ['inScope' => 12, 'withoutTwoFactor' => 3];

		$gate = $this->createMock(originalClassName: TwoFactorGate::class);
		$gate->expects($this->once())
			->method('gapReport')
			->with(['admins', '42'])
			->willReturn($report);

		$response = $this->controller(twoFactor: $gate)->twoFactorGaps(groups: ['a' => 'admins', 'b' => 42]);

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: $report, actual: $response->getData());
	}//end testTwoFactorGapsForwardsTheGroupsAsAStringList()
}//end class
