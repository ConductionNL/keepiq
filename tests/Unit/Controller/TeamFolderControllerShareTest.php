<?php

/**
 * Contract tests for sharing, unsharing and offboarding team folders.
 *
 * The team-folder service owns the owner and admin checks; the controller's
 * contract is that anonymous callers are refused, the SESSION user reaches
 * the service as owner or acting admin, an unshare blocked by a pending share
 * scan reads as a 409 the client can recognise, and other refusals map to
 * 400, or 403 for offboarding.
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
use OCA\Keepiq\Controller\TeamFolderController;
use OCA\Keepiq\Db\TeamFolder;
use OCA\Keepiq\Exception\ShareScanPendingException;
use OCA\Keepiq\Service\TeamFolderConfirmationService;
use OCA\Keepiq\Service\TeamFolderService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TeamFolderControllerShareTest extends TestCase {

	/**
	 * The mocked team-folder service.
	 *
	 * @var TeamFolderService&MockObject
	 */
	private TeamFolderService&MockObject $service;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * The controller under test.
	 *
	 * @var TeamFolderController
	 */
	private TeamFolderController $controller;

	/**
	 * Build the controller over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(originalClassName: TeamFolderService::class);
		$this->session = $this->createMock(originalClassName: IUserSession::class);
		$this->controller = new TeamFolderController(
			request: $this->createMock(originalClassName: IRequest::class),
			teamFolderService: $this->service,
			userSession: $this->session,
			confirmations: $this->createMock(originalClassName: TeamFolderConfirmationService::class),
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
	 * The share, unshare, list and offboard endpoints.
	 *
	 * @return array<string, array{0: callable(TeamFolderController): mixed}>
	 */
	public static function endpoints(): array {
		return [
			'index'    => [static fn (TeamFolderController $c) => $c->index()],
			'create'   => [static fn (TeamFolderController $c) => $c->create(folderId: 'f-1')],
			'destroy'  => [static fn (TeamFolderController $c) => $c->destroy(id: 'tf-1')],
			'offboard' => [static fn (TeamFolderController $c) => $c->offboard(leavingUserId: 'bob', successorUserId: 'carol')],
		];
	}//end endpoints()

	/**
	 * An anonymous caller is refused before the service runs.
	 *
	 * @param callable $call The endpoint call.
	 *
	 * @dataProvider endpoints
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(callable $call): void {
		$this->session->method('getUser')->willReturn(null);
		foreach (['listForUser', 'shareFolder', 'unshareFolder', 'offboard'] as $method) {
			$this->service->expects($this->never())->method($method);
		}

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $call($this->controller)->getStatus());
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * Listing and sharing run as the session user.
	 *
	 * @return void
	 */
	public function testListAndShareAsTheSessionUser(): void {
		$this->signIn();
		$teamFolder = new TeamFolder();
		$teamFolder->setId('tf-1');
		$this->service->expects($this->once())->method('listForUser')->with('alice')->willReturn([['id' => 'tf-1']]);
		$this->service->expects($this->once())->method('shareFolder')->with('f-1', 'alice')->willReturn($teamFolder);

		$this->assertSame(expected: [['id' => 'tf-1']], actual: $this->controller->index()->getData());
		$shared = $this->controller->create(folderId: 'f-1');
		$this->assertSame(expected: Http::STATUS_CREATED, actual: $shared->getStatus());
		$this->assertSame(expected: 'tf-1', actual: $shared->getData()['id']);
	}//end testListAndShareAsTheSessionUser()

	/**
	 * A refused share is a 400.
	 *
	 * @return void
	 */
	public function testARefusedShareIs400(): void {
		$this->signIn();
		$this->service->method('shareFolder')->willThrowException(new InvalidArgumentException(message: 'Not your folder'));

		$response = $this->controller->create(folderId: 'f-1');

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Not your folder'], actual: $response->getData());
	}//end testARefusedShareIs400()

	/**
	 * Unsharing reports the revoked count; a pending scan is a recognisable 409.
	 *
	 * @return void
	 */
	public function testUnshare(): void {
		$this->signIn();
		$this->service->method('unshareFolder')->with('tf-1', 'alice')->willReturnOnConsecutiveCalls(
			3,
			$this->throwException(exception: new ShareScanPendingException(message: 'A share scan is still running')),
			$this->throwException(exception: new InvalidArgumentException(message: 'Not your team folder'))
		);

		$this->assertSame(expected: ['revoked' => 3], actual: $this->controller->destroy(id: 'tf-1')->getData());
		$pending = $this->controller->destroy(id: 'tf-1');
		$this->assertSame(expected: Http::STATUS_CONFLICT, actual: $pending->getStatus());
		$this->assertSame(
			expected: ['error' => 'share_scan_pending', 'message' => 'A share scan is still running'],
			actual: $pending->getData()
		);
		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $this->controller->destroy(id: 'tf-1')->getStatus());
	}//end testUnshare()

	/**
	 * Offboarding names the session user as acting admin; a refusal is a 403.
	 *
	 * @return void
	 */
	public function testOffboard(): void {
		$this->signIn();
		$summary = ['revoked' => 2, 'transferred' => 1, 'skipped' => []];
		$this->service->method('offboard')->with('bob', 'carol', 'alice')->willReturnOnConsecutiveCalls(
			$summary,
			$this->throwException(exception: new InvalidArgumentException(message: 'Admin only'))
		);

		$this->assertSame(expected: $summary, actual: $this->controller->offboard(leavingUserId: 'bob', successorUserId: 'carol')->getData());
		$refused = $this->controller->offboard(leavingUserId: 'bob', successorUserId: 'carol');
		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $refused->getStatus());
		$this->assertSame(expected: ['message' => 'Admin only'], actual: $refused->getData());
	}//end testOffboard()
}//end class
