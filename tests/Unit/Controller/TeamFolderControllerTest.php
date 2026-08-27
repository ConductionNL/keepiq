<?php

/**
 * Contract tests for the TeamFolderController endpoints that carry no wire
 * proof: `teamFolder#reconcile` and `teamFolder#registerShares`.
 *
 * The membership endpoints moved to TeamFolderMemberController; their tests
 * moved with them, unchanged, to TeamFolderMemberControllerTest.
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

use InvalidArgumentException;
use OCA\Keepiq\Controller\TeamFolderController;
use OCA\Keepiq\Service\TeamFolderService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Every method here is `#[NoAdminRequired]`; the per-object owner check lives
 * in TeamFolderService. The controller's own contract is therefore narrow but
 * load-bearing: the URL's team-folder id and the SESSION user must both reach
 * the service, and the service's answer — a refusal, a reconciliation delta,
 * a created-row count — must reach the caller intact.
 *
 */
class TeamFolderControllerTest extends TestCase {

	/**
	 * The mocked request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The mocked team-folder service.
	 *
	 * @var TeamFolderService&MockObject
	 */
	private TeamFolderService&MockObject $teamFolderService;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Set up the mocks shared by every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->teamFolderService = $this->createMock(TeamFolderService::class);
		$this->userSession = $this->createMock(IUserSession::class);
	}//end setUp()

	/**
	 * Build the controller with a signed-in or an anonymous session.
	 *
	 * @param string|null $userId The session UID, or null for an anonymous caller.
	 *
	 * @return TeamFolderController The controller under test.
	 */
	private function controller(?string $userId = 'owner'): TeamFolderController {
		if ($userId === null) {
			$this->userSession->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
			$this->userSession->method('getUser')->willReturn($user);
		}

		return new TeamFolderController(
			request: $this->request,
			teamFolderService: $this->teamFolderService,
			userSession: $this->userSession
		);
	}//end controller()

	/**
	 * GET /api/v1/team-folders/{id}/reconcile must return the full expected
	 * fan-out state — secrets, recipients AND the missing pairs the browser
	 * still has to encrypt. Dropping `missing` would make a partial fan-out
	 * look complete.
	 *
	 * @return void
	 */
	public function testReconcileReturnsTheMissingFanOutPairs(): void {
		$state = [
			'secrets' => [['id' => 'secret-1']],
			'recipients' => [
				[
					'userId' => 'bob',
					'certificate' => 'CERT_BOB',
				],
			],
			'missing' => [
				[
					'secretId' => 'secret-1',
					'userId' => 'bob',
				],
			],
		];

		$this->teamFolderService->expects($this->once())
			->method('reconcile')
			->with('tf-1', 'owner')
			->willReturn($state);

		$response = $this->controller('owner')->reconcile(id: 'tf-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			$state,
			$response->getData(),
			'reconcile() must hand back the exact delta the service computed'
		);
	}//end testReconcileReturnsTheMissingFanOutPairs()

	/**
	 * Reconciling a folder the caller does not own is refused with 400 and
	 * the service's reason reaches the caller.
	 *
	 * @return void
	 */
	public function testReconcileAnswers400WhenTheCallerDoesNotOwnTheFolder(): void {
		$this->teamFolderService->expects($this->once())
			->method('reconcile')
			->with('tf-1', 'mallory')
			->willThrowException(new InvalidArgumentException('Team folder not found'));

		$response = $this->controller('mallory')->reconcile(id: 'tf-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'Team folder not found'], $response->getData());
	}//end testReconcileAnswers400WhenTheCallerDoesNotOwnTheFolder()

	/**
	 * An anonymous caller cannot probe a folder's fan-out state.
	 *
	 * @return void
	 */
	public function testReconcileRejectsAnAnonymousCallerBeforeTheService(): void {
		$this->teamFolderService->expects($this->never())->method('reconcile');

		$response = $this->controller(null)->reconcile(id: 'tf-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Unauthorized'], $response->getData());
	}//end testReconcileRejectsAnAnonymousCallerBeforeTheService()

	/**
	 * POST /api/v1/team-folders/{id}/shares must forward the whole encrypted
	 * chunk together with the folder id and the session user, answer 201, and
	 * report how many rows were actually created — a fully-skipped (retried)
	 * chunk must report zero, not the chunk size.
	 *
	 * @return void
	 */
	public function testRegisterSharesForwardsTheChunkAndReportsTheCreatedCount(): void {
		$shares = [
			[
				'sourceSecretId' => 'secret-1',
				'targetUserId' => 'bob',
				'encryptedKey' => 'CIPHERTEXT_BOB',
			],
			[
				'sourceSecretId' => 'secret-2',
				'targetUserId' => 'bob',
				'encryptedKey' => 'CIPHERTEXT_BOB_2',
			],
		];

		$result = [
			'created' => 1,
			'rows' => [
				[
					'sourceSecretId' => 'secret-1',
					'targetUserId' => 'bob',
					'recipientSecretId' => 'copy-1',
				],
			],
		];

		// The ITEM: the ciphertext rows reach the service unchanged.
		$this->teamFolderService->expects($this->once())
			->method('registerFanOutShares')
			->with('tf-1', $shares, 'owner')
			->willReturn($result);

		$response = $this->controller('owner')->registerShares(id: 'tf-1', shares: $shares);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(
			$result,
			$response->getData(),
			'registerShares() must report the rows the service really created'
		);
	}//end testRegisterSharesForwardsTheChunkAndReportsTheCreatedCount()

	/**
	 * A chunk the service refuses answers 400 — never a 201 that would let
	 * the browser mark the fan-out chunk as done.
	 *
	 * @return void
	 */
	public function testRegisterSharesAnswers400WhenTheServiceRefusesTheChunk(): void {
		$this->teamFolderService->expects($this->once())
			->method('registerFanOutShares')
			->willThrowException(new InvalidArgumentException('Team folder not found'));

		$response = $this->controller('mallory')->registerShares(
			id: 'tf-1',
			shares: [['sourceSecretId' => 'secret-1']]
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'Team folder not found'], $response->getData());
	}//end testRegisterSharesAnswers400WhenTheServiceRefusesTheChunk()

	/**
	 * An anonymous caller may not register fan-out shares.
	 *
	 * @return void
	 */
	public function testRegisterSharesRejectsAnAnonymousCallerBeforeTheService(): void {
		$this->teamFolderService->expects($this->never())->method('registerFanOutShares');

		$response = $this->controller(null)->registerShares(id: 'tf-1', shares: []);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Unauthorized'], $response->getData());
	}//end testRegisterSharesRejectsAnAnonymousCallerBeforeTheService()

}//end class
