<?php

/**
 * Unit tests for the dashboard summary endpoint (GET /api/dashboard/summary).
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Controller;

use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Controller\DashboardController;
use OCA\Keepiq\Service\DashboardSummaryService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for `dashboard#summary`.
 *
 * The aggregate is scoped by the SESSION's uid and by whether that uid is an
 * admin — the two arguments the endpoint's whole tenancy story rests on. A
 * summary computed for the wrong uid, or with isAdmin hard-coded either way,
 * would still return a perfectly well-formed 200, so both arguments are
 * asserted explicitly and the anonymous branch is proven to compute nothing.
 *
 */
class DashboardControllerTest extends TestCase {

	/**
	 * The mocked summary aggregator.
	 *
	 * @var DashboardSummaryService&MockObject
	 */
	private DashboardSummaryService&MockObject $summaryService;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * The mocked group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * The mocked initial-state service.
	 *
	 * @var IInitialState&MockObject
	 */
	private IInitialState&MockObject $initialState;

	/**
	 * The mocked app manager (the version source for page()).
	 *
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * Set up the mocks shared by every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->summaryService = $this->createMock(DashboardSummaryService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->initialState = $this->createMock(IInitialState::class);
		$this->appManager = $this->createMock(IAppManager::class);
	}//end setUp()

	/**
	 * Build the controller under test with its collaborators mocked.
	 *
	 * @param string|null $userId The session uid, or null for an anonymous caller.
	 *
	 * @return DashboardController The controller under test.
	 */
	private function controller(?string $userId = 'alice'): DashboardController {
		if ($userId === null) {
			$this->userSession->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
			$this->userSession->method('getUser')->willReturn($user);
		}

		return new DashboardController(
			request: $this->createMock(IRequest::class),
			initialState: $this->initialState,
			appConfig: $this->createMock(IAppConfig::class),
			appManager: $this->appManager,
			summaryService: $this->summaryService,
			userSession: $this->userSession,
			groupManager: $this->groupManager
		);
	}//end controller()

	/**
	 * The happy path for an ordinary user: the aggregate is scoped to that
	 * user's own uid with isAdmin false, and the service payload is returned.
	 *
	 * @return void
	 */
	public function testSummaryScopesTheAggregateToTheSessionUserAndReturnsThePayload(): void {
		$summary = [
			'secrets' => 12,
			'sharedWithMe' => 3,
			'applications' => 1,
			'expiringSoon' => 0,
		];

		$this->groupManager->expects($this->once())
			->method('isAdmin')
			->with('alice')
			->willReturn(false);

		// The ITEM: the aggregate is computed for the SESSION uid, unprivileged.
		$this->summaryService->expects($this->once())
			->method('fetchSummary')
			->with('alice', false)
			->willReturn($summary);

		$response = $this->controller(userId: 'alice')->summary();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($summary, $response->getData());
	}//end testSummaryScopesTheAggregateToTheSessionUserAndReturnsThePayload()

	/**
	 * An admin session forwards isAdmin true, so the service can widen the
	 * aggregate — proving the flag is read from the group manager and not
	 * hard-coded.
	 *
	 * @return void
	 */
	public function testSummaryForwardsTheAdminFlagFromTheGroupManager(): void {
		$summary = [
			'secrets' => 480,
			'sharedWithMe' => 0,
			'applications' => 9,
			'instanceWide' => true,
		];

		$this->groupManager->expects($this->once())
			->method('isAdmin')
			->with('root')
			->willReturn(true);

		$this->summaryService->expects($this->once())
			->method('fetchSummary')
			->with('root', true)
			->willReturn($summary);

		$response = $this->controller(userId: 'root')->summary();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($summary, $response->getData());
	}//end testSummaryForwardsTheAdminFlagFromTheGroupManager()

	/**
	 * An anonymous caller gets 401 and no aggregate is computed for anybody.
	 *
	 * @return void
	 */
	public function testSummaryRejectsAnAnonymousCallerWithoutAggregatingAnything(): void {
		$this->summaryService->expects($this->never())->method('fetchSummary');
		$this->groupManager->expects($this->never())->method('isAdmin');

		$response = $this->controller(userId: null)->summary();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'unauthenticated'], $response->getData());
	}//end testSummaryRejectsAnAnonymousCallerWithoutAggregatingAnything()

	/**
	 * page() feeds the frontend its initial state, including the app version
	 * for the user-settings dialog's footer. The version must come from
	 * IAppManager (appinfo/info.xml — the version truth) scoped to THIS app
	 * id, and it must ride the same initial-state channel as the two
	 * pre-existing keys, which are asserted too so a regression that drops
	 * one of them cannot hide behind a green version assertion.
	 *
	 * @return void
	 */
	public function testPageProvidesTheAppVersionAsInitialState(): void {
		$this->appManager->expects($this->once())
			->method('getAppVersion')
			->with(Application::APP_ID)
			->willReturn('0.1.6');

		$provided = [];
		$this->initialState->method('provideInitialState')
			->willReturnCallback(
				function (string $key, mixed $data) use (&$provided): void {
					$provided[$key] = $data;
				}
			);

		$response = $this->controller()->page();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('0.1.6', $provided['appVersion'] ?? null);
		$this->assertArrayHasKey('faviconServiceUrl', $provided);
		$this->assertArrayHasKey('breachCheckEnabled', $provided);
	}//end testPageProvidesTheAppVersionAsInitialState()

}//end class
