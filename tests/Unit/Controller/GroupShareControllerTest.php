<?php

/**
 * Unit tests for the GroupShareController new-member resolution endpoints
 * (POST /api/v1/group-shares/{id}/approve-new-member and
 * POST /api/v1/group-shares/{id}/deny-new-member).
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
use OCA\Keepiq\Controller\GroupShareController;
use OCA\Keepiq\Db\GroupShare;
use OCA\Keepiq\Exception\ShareScanPendingException;
use OCA\Keepiq\Service\GroupShareService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * These two endpoints resolve the `group_member_added` notification: a new
 * member joined a group that already holds a shared secret, and the sharer
 * decides whether that member gets an encrypted copy.
 *
 * Approve is a WRITE — the contract is that all four identities reach the
 * service (the group share, the new member, the recipient's encrypted copy,
 * and the SESSION user as the approving sharer; the approver must never be
 * client-supplied). Deny is specified as a server-side no-op, and the test
 * for it asserts exactly that: the service is not touched at all. A deny that
 * quietly called into the service would be an unspecified mutation.
 *
 */
class GroupShareControllerTest extends TestCase {

	/**
	 * The mocked group-share service.
	 *
	 * @var GroupShareService&MockObject
	 */
	private GroupShareService&MockObject $groupShareService;

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

		$this->groupShareService = $this->createMock(GroupShareService::class);
		$this->userSession = $this->createMock(IUserSession::class);
	}//end setUp()

	/**
	 * Log a user into the mocked session, or leave it anonymous.
	 *
	 * @param string|null $uid The session user id, or null for anonymous.
	 *
	 * @return void
	 */
	private function signIn(?string $uid): void {
		if ($uid === null) {
			$this->userSession->method('getUser')->willReturn(null);
			return;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * Build the controller under test with its collaborators mocked.
	 *
	 * @return GroupShareController The controller under test.
	 */
	private function controller(): GroupShareController {
		return new GroupShareController(
			request: $this->createMock(IRequest::class),
			groupShareService: $this->groupShareService,
			userSession: $this->userSession
		);
	}//end controller()

	/**
	 * sharing-02: creating a group share answers 201 with the member fan-out
	 * and the count of members skipped for want of an encryption suite, so
	 * the sidebar can say how many received it and how many did not.
	 *
	 * @return void
	 */
	public function testCreateAnswersTheFanOutAndTheSkippedCount(): void {
		$this->signIn('alice');
		$row = new GroupShare();
		$row->setId('gs-1');
		$row->setGroupId('finance');
		$this->groupShareService->expects($this->once())->method('createGroupShare')
			->with('sec-1', 'finance', 'alice')
			->willReturn(['groupShare' => $row, 'members' => [['userId' => 'bob', 'certificate' => 'PEM']], 'skipped' => 1]);

		$response = $this->controller()->create(secretId: 'sec-1', groupId: 'finance');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(1, $response->getData()['skipped']);
		$this->assertSame('bob', $response->getData()['members'][0]['userId']);
		$this->assertSame('gs-1', $response->getData()['groupShare']['id']);
	}//end testCreateAnswersTheFanOutAndTheSkippedCount()

	/**
	 * sharing-02: a group outside the caller's reach answers 400 with the
	 * service's message and creates nothing.
	 *
	 * @return void
	 */
	public function testCreateForAGroupOutOfReachIs400(): void {
		$this->signIn('alice');
		$this->groupShareService->method('createGroupShare')
			->willThrowException(new InvalidArgumentException('Group not found'));

		$response = $this->controller()->create(secretId: 'sec-1', groupId: 'board');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'Group not found'], $response->getData());
	}//end testCreateForAGroupOutOfReachIs400()

	/**
	 * A restriction change the pending share quarantine scan cannot judge a
	 * row of answers 409 with the error code a revoke gets.
	 *
	 * @return void
	 */
	public function testCreateThatWaitsForTheQuarantineScanIs409(): void {
		$this->signIn('alice');
		$this->groupShareService->method('createGroupShare')
			->willThrowException(new ShareScanPendingException('A share of this group waits for the share quarantine scan'));

		$response = $this->controller()->create(secretId: 'sec-1', groupId: 'finance', useOnly: true);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(
			['error' => 'share_scan_pending', 'message' => 'A share of this group waits for the share quarantine scan'],
			$response->getData()
		);
	}//end testCreateThatWaitsForTheQuarantineScanIs409()

	/**
	 * A group-share revoke the pending share quarantine scan cannot judge a
	 * row of answers 409 with the error code a single revoke gets.
	 *
	 * @return void
	 */
	public function testDestroyThatWaitsForTheQuarantineScanIs409(): void {
		$this->signIn('alice');
		$this->groupShareService->method('revokeGroupShare')
			->willThrowException(new ShareScanPendingException('A share of this group waits for the share quarantine scan'));

		$response = $this->controller()->destroy(id: 'gs-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(
			['error' => 'share_scan_pending', 'message' => 'A share of this group waits for the share quarantine scan'],
			$response->getData()
		);
	}//end testDestroyThatWaitsForTheQuarantineScanIs409()

	/**
	 * The client-supplied-id group-member approval endpoint is retired
	 * (GHSA-m5p9-6pw7-pf3g): POST /group-shares/{id}/approve-new-member no
	 * longer exists, so approval can never be handed a `recipientSecretId`. A
	 * new member's copy is registered through register-batch, which mints it
	 * server-side; only the inert deny endpoint remains.
	 *
	 * @return void
	 */
	public function testApproveNewMemberEndpointIsRetired(): void {
		$this->assertFalse(
			method_exists(GroupShareController::class, 'approveNewMember'),
			'groupShare#approveNewMember must be retired'
		);
	}//end testApproveNewMemberEndpointIsRetired()

	/**
	 * Denying is specified as a server-side no-op: it answers the denied
	 * status and touches NO service method at all.
	 *
	 * @return void
	 */
	public function testDenyNewMemberIsAServerSideNoOpAndCallsNoServiceMethod(): void {
		$this->signIn('alice');

		// The ITEM: denial persists nothing — any service call here would be
		// an unspecified mutation on a path documented as inert.
		$this->groupShareService->expects($this->never())->method($this->anything());

		$response = $this->controller()->denyNewMember(
			id: 'gs-0000-4000-8000-000000000001',
			newMemberId: 'bob'
		);

		$this->assertSame(
			Http::STATUS_OK,
			$response->getStatus(),
			'a denial answers 200 so the client can dismiss the notification'
		);
		$this->assertSame(
			['status' => 'denied'],
			$response->getData(),
			'denial must be distinguishable from approval in the response body'
		);
	}//end testDenyNewMemberIsAServerSideNoOpAndCallsNoServiceMethod()

	/**
	 * Even the inert denial requires a session — an anonymous caller gets 401,
	 * not the denied acknowledgement.
	 *
	 * @return void
	 */
	public function testDenyNewMemberByAnAnonymousCallerIs401(): void {
		$this->signIn(null);

		$this->groupShareService->expects($this->never())->method($this->anything());

		$response = $this->controller()->denyNewMember(
			id: 'gs-0000-4000-8000-000000000001',
			newMemberId: 'bob'
		);

		$this->assertSame(
			Http::STATUS_UNAUTHORIZED,
			$response->getStatus(),
			'the notification-resolution surface is not public'
		);
		$this->assertSame(['message' => 'Unauthorized'], $response->getData());
	}//end testDenyNewMemberByAnAnonymousCallerIs401()

}//end class
