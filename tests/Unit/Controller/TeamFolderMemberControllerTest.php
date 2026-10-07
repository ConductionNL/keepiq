<?php

/**
 * Unit tests for TeamFolderMemberController refusals.
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

use InvalidArgumentException;
use OCA\Keepiq\Controller\TeamFolderMemberController;
use OCA\Keepiq\Db\TeamFolderMember;
use OCA\Keepiq\Exception\ManagerOnlyException;
use OCA\Keepiq\Exception\OwnerOnlyException;
use OCA\Keepiq\Middleware\OcsRefusalMiddleware;
use OCA\Keepiq\Service\TeamFolderService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A manager reaching for what only the owner governs is refused visibly (#790).
 */
class TeamFolderMemberControllerTest extends TestCase {
	private TeamFolderService&MockObject $service;
	private TeamFolderMemberController $controller;
	private OcsRefusalMiddleware $refusals;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(TeamFolderService::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('olga');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->controller = new TeamFolderMemberController(
			request: $this->createMock(IRequest::class),
			teamFolderService: $this->service,
			userSession: $session,
		);
		$this->refusals = new OcsRefusalMiddleware();
	}//end setUp()

	/**
	 * Olga, a manager, sets Bob's grade to manage: refused, and the browser
	 * receives 428 with error owner_only and the reason.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-only-the-owner-governs-managers-and-the-folder-itself
	 */
	public function testAManagerGrantingManageIsRefusedVisibly(): void {
		$this->service->method('setMemberGrade')->willThrowException(
			new OwnerOnlyException(message: 'Only the owner can make a member a manager')
		);

		$response = $this->controller->setMemberGrade(id: 'tf-1', memberId: 'mem-bob', grade: 'manage');
		$this->assertSame(403, $response->getStatus());

		$delivered = $this->refusals->afterController($this->controller, 'setMemberGrade', $response);
		$this->assertSame(428, $delivered->getStatus());
		$this->assertSame('owner_only', $delivered->getData()['error']);
		$this->assertSame('Only the owner can make a member a manager', $delivered->getData()['message']);
	}//end testAManagerGrantingManageIsRefusedVisibly()

	/**
	 * Removing a manager as a manager is refused the same way.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-only-the-owner-governs-managers-and-the-folder-itself
	 */
	public function testAManagerRemovingAManagerIsRefusedVisibly(): void {
		$this->service->method('removeMember')->willThrowException(
			new OwnerOnlyException(message: 'Only the owner can change or remove a manager')
		);

		$delivered = $this->refusals->afterController(
			$this->controller,
			'removeMember',
			$this->controller->removeMember(id: 'tf-1', memberId: 'mem-mia')
		);
		$this->assertSame(428, $delivered->getStatus());
		$this->assertSame('owner_only', $delivered->getData()['error']);
	}//end testAManagerRemovingAManagerIsRefusedVisibly()

	/**
	 * Vic, a viewer, changes Ed's grade: refused as forbidden, and the
	 * browser receives 428 with error manager_only and the reason, not 400.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#scenario-non-owner-cannot-change-a-grade
	 */
	public function testAViewerChangingAGradeIsRefusedVisibly(): void {
		$this->service->method('setMemberGrade')->willThrowException(
			new ManagerOnlyException(message: 'Not authorized to manage this team folder')
		);

		$response = $this->controller->setMemberGrade(id: 'tf-1', memberId: 'mem-ed', grade: 'read');
		$this->assertSame(403, $response->getStatus());

		$delivered = $this->refusals->afterController($this->controller, 'setMemberGrade', $response);
		$this->assertSame(428, $delivered->getStatus());
		$this->assertSame('manager_only', $delivered->getData()['error']);
		$this->assertSame('Not authorized to manage this team folder', $delivered->getData()['message']);
	}//end testAViewerChangingAGradeIsRefusedVisibly()

	/**
	 * An invalid request stays a 400.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-team-folder-membership-carries-a-read-write-or-manage-grade
	 */
	public function testAnInvalidGradeStaysABadRequest(): void {
		$this->service->method('setMemberGrade')->willThrowException(
			new InvalidArgumentException(message: 'grade must be read, write or manage')
		);

		$response = $this->controller->setMemberGrade(id: 'tf-1', memberId: 'mem-bob', grade: 'boss');
		$this->assertSame(400, $response->getStatus());
	}//end testAnInvalidGradeStaysABadRequest()

	/**
	 * The member list is the caller's view of the folder: the service is asked
	 * for the session user's list and each member is serialised.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#4.1
	 */
	public function testMembersListsTheCallersViewOfTheFolder(): void {
		$bob = new TeamFolderMember();
		$bob->setTeamFolderId('tf-1');
		$bob->setMemberType('user');
		$bob->setMemberId('bob');
		$this->service->expects($this->once())
			->method('listMembers')
			->with('tf-1', 'olga')
			->willReturn([$bob]);

		$response = $this->controller->members(id: 'tf-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertCount(1, $response->getData());
		$this->assertSame('bob', $response->getData()[0]['memberId']);
		$this->assertSame('tf-1', $response->getData()[0]['teamFolderId']);
	}//end testMembersListsTheCallersViewOfTheFolder()

	/**
	 * Approving one waiting member returns the fan-out payload for that member.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#3.1
	 */
	public function testApproveJoinReturnsTheFanOutForThatMember(): void {
		$payload = ['recipients' => [['userId' => 'bob']], 'secrets' => []];
		$this->service->expects($this->once())
			->method('approveJoin')
			->with('tf-1', 'bob', 'olga')
			->willReturn($payload);

		$response = $this->controller->approveJoin(id: 'tf-1', newMemberId: 'bob');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($payload, $response->getData());
	}//end testApproveJoinReturnsTheFanOutForThatMember()

	/**
	 * A refused approval (not a manager of the folder) is a 400 with the reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#3.1
	 */
	public function testApproveJoinRefusalIsABadRequestWithTheReason(): void {
		$this->service->method('approveJoin')->willThrowException(
			new InvalidArgumentException(message: 'Not authorized to manage this team folder')
		);

		$response = $this->controller->approveJoin(id: 'tf-1', newMemberId: 'bob');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('Not authorized to manage this team folder', $response->getData()['message']);
	}//end testApproveJoinRefusalIsABadRequestWithTheReason()
}//end class
