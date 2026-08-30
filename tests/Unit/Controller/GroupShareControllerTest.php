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
	 * Approving must forward the URL's group-share id, the new member, the
	 * recipient's encrypted copy and the SESSION user as the approver.
	 *
	 * @return void
	 */
	public function testApproveNewMemberForwardsAllThreeIdentifiersAndTheSessionApprover(): void {
		$this->signIn('alice');

		$this->groupShareService->expects($this->once())
			->method('approveGroupMemberShare')
			->with(
				'gs-0000-4000-8000-000000000001',
				'bob',
				'sec-0000-4000-8000-000000000009',
				'alice'
			);

		$response = $this->controller()->approveNewMember(
			id: 'gs-0000-4000-8000-000000000001',
			newMemberId: 'bob',
			recipientSecretId: 'sec-0000-4000-8000-000000000009'
		);

		$this->assertSame(
			Http::STATUS_OK,
			$response->getStatus(),
			'a completed approval answers 200'
		);
		$this->assertSame(
			['status' => 'approved'],
			$response->getData(),
			'the client dismisses the notification on the approved status'
		);
	}//end testApproveNewMemberForwardsAllThreeIdentifiersAndTheSessionApprover()

	/**
	 * An anonymous caller must not be able to approve a member's access.
	 *
	 * @return void
	 */
	public function testApproveNewMemberByAnAnonymousCallerIs401AndApprovesNothing(): void {
		$this->signIn(null);

		$this->groupShareService->expects($this->never())->method('approveGroupMemberShare');

		$response = $this->controller()->approveNewMember(
			id: 'gs-0000-4000-8000-000000000001',
			newMemberId: 'bob',
			recipientSecretId: 'sec-0000-4000-8000-000000000009'
		);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Unauthorized'], $response->getData());
	}//end testApproveNewMemberByAnAnonymousCallerIs401AndApprovesNothing()

	/**
	 * A refused approval (unknown share, wrong sharer, mismatched copy) must
	 * surface as 400 with the service's own reason.
	 *
	 * @return void
	 */
	public function testApproveNewMemberRefusalAnswers400WithTheServiceMessage(): void {
		$this->signIn('mallory');

		$this->groupShareService->expects($this->once())
			->method('approveGroupMemberShare')
			->with(
				'gs-0000-4000-8000-000000000001',
				'bob',
				'sec-0000-4000-8000-000000000009',
				'mallory'
			)
			->willThrowException(new InvalidArgumentException('Group share not found'));

		$response = $this->controller()->approveNewMember(
			id: 'gs-0000-4000-8000-000000000001',
			newMemberId: 'bob',
			recipientSecretId: 'sec-0000-4000-8000-000000000009'
		);

		$this->assertSame(
			Http::STATUS_BAD_REQUEST,
			$response->getStatus(),
			'a refused approval must not be reported as approved'
		);
		$this->assertSame(
			['message' => 'Group share not found'],
			$response->getData(),
			'the caller must be told why the approval was refused'
		);
	}//end testApproveNewMemberRefusalAnswers400WithTheServiceMessage()

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
