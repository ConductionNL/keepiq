<?php

/**
 * Unit tests for TeamFolderMemberController: the anonymous refusals and the
 * success paths of add, remove and grade changes.
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

use OCA\Keepiq\Controller\TeamFolderMemberController;
use OCA\Keepiq\Db\TeamFolderMember;
use OCA\Keepiq\Exception\ShareScanPendingException;
use OCA\Keepiq\Service\ShareRestriction;
use OCA\Keepiq\Service\TeamFolderService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Every membership endpoint refuses an anonymous caller with a 401 before the
 * service is touched; a signed-in caller's request reaches TeamFolderService
 * with the session uid and the parsed restriction, and the service's answer
 * is what the caller receives.
 *
 * @covers \OCA\Keepiq\Controller\TeamFolderMemberController
 * @uses   \OCA\Keepiq\Db\TeamFolderMember
 * @uses   \OCA\Keepiq\Service\ShareRestriction
 * @uses   \OCA\Keepiq\Service\ShareRestrictionRules
 */
class TeamFolderMemberControllerMoreTest extends TestCase {

	/**
	 * The mocked team-folder service.
	 *
	 * @var TeamFolderService&MockObject
	 */
	private TeamFolderService&MockObject $service;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(originalClassName: TeamFolderService::class);
	}//end setUp()

	/**
	 * The controller, signed in as $uid (or anonymous for null).
	 *
	 * @param string|null $uid The signed-in uid.
	 *
	 * @return TeamFolderMemberController
	 */
	private function controller(?string $uid = 'olga'): TeamFolderMemberController {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new TeamFolderMemberController(
			request: $this->createMock(originalClassName: IRequest::class),
			teamFolderService: $this->service,
			userSession: $session,
		);
	}//end controller()

	/**
	 * A membership row with a known serialization.
	 *
	 * @param string $memberId The member's uid.
	 * @param string $grade    The grade.
	 *
	 * @return TeamFolderMember
	 */
	private function member(string $memberId, string $grade): TeamFolderMember {
		$member = new TeamFolderMember();
		$member->setTeamFolderId('tf-1');
		$member->setMemberType('user');
		$member->setMemberId($memberId);
		$member->setGrade($grade);

		return $member;
	}//end member()

	/**
	 * Each endpoint, called anonymously.
	 *
	 * @return array<string,array{0:callable}>
	 */
	public static function anonymousCalls(): array {
		return [
			'members' => [static fn (TeamFolderMemberController $c) => $c->members(id: 'tf-1')],
			'addMember' => [static fn (TeamFolderMemberController $c) => $c->addMember(id: 'tf-1', memberType: 'user', memberId: 'bob')],
			'removeMember' => [static fn (TeamFolderMemberController $c) => $c->removeMember(id: 'tf-1', memberId: 'mem-bob')],
			'approveJoin' => [static fn (TeamFolderMemberController $c) => $c->approveJoin(id: 'tf-1', newMemberId: 'bob')],
			'setMemberGrade' => [static fn (TeamFolderMemberController $c) => $c->setMemberGrade(id: 'tf-1', memberId: 'mem-bob', grade: 'write')],
		];
	}//end anonymousCalls()

	/**
	 * An anonymous caller gets 401 and the service is never asked anything.
	 *
	 * @param callable $call The endpoint call.
	 *
	 * @return void
	 *
	 * @dataProvider anonymousCalls
	 */
	public function testAnAnonymousCallerIsRefusedBeforeTheService(callable $call): void {
		foreach (['listMembers', 'addMember', 'removeMember', 'approveJoin', 'setMemberGrade'] as $method) {
			$this->service->expects($this->never())->method($method);
		}

		$response = $call($this->controller(uid: null));

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Unauthorized'], actual: $response->getData());
	}//end testAnAnonymousCallerIsRefusedBeforeTheService()

	/**
	 * Adding a use-only member with an end date forwards the session uid and
	 * the parsed restriction, and answers 201 with the fan-out payload.
	 *
	 * @return void
	 */
	public function testAddMemberForwardsTheRestrictionAndReturnsTheFanOut(): void {
		$captured = null;
		$this->service->expects($this->once())
			->method('addMember')
			->willReturnCallback(
				function (string $teamFolderId, string $memberType, string $memberId, string $userId, ShareRestriction $restriction) use (&$captured): array {
					$captured = [$teamFolderId, $memberType, $memberId, $userId, $restriction];
					return [
						'member' => $this->member(memberId: 'bob', grade: 'read'),
						'recipients' => [['userId' => 'bob', 'certificate' => 'CERT']],
						'secrets' => [['id' => 's-1']],
					];
				}
			);

		$response = $this->controller()->addMember(
			id: 'tf-1',
			memberType: 'user',
			memberId: 'bob',
			useOnly: true,
			expiresAt: '2999-01-01T00:00:00+00:00'
		);

		$this->assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus());
		$this->assertSame(expected: ['tf-1', 'user', 'bob', 'olga'], actual: array_slice($captured, 0, 4));
		$this->assertTrue(condition: $captured[4]->useOnly);
		$this->assertSame(expected: '2999-01-01T00:00:00+00:00', actual: $captured[4]->expiresAt?->format('c'));

		$data = $response->getData();
		$this->assertSame(expected: 'bob', actual: $data['member']['memberId']);
		$this->assertSame(expected: 'tf-1', actual: $data['member']['teamFolderId']);
		$this->assertSame(expected: [['userId' => 'bob', 'certificate' => 'CERT']], actual: $data['recipients']);
		$this->assertSame(expected: [['id' => 's-1']], actual: $data['secrets']);
	}//end testAddMemberForwardsTheRestrictionAndReturnsTheFanOut()

	/**
	 * An end date in the past is a 400 with the reason, and no member is added.
	 *
	 * @return void
	 */
	public function testAddMemberWithAPastEndDateIsABadRequest(): void {
		$this->service->expects($this->never())->method('addMember');

		$response = $this->controller()->addMember(
			id: 'tf-1',
			memberType: 'user',
			memberId: 'bob',
			expiresAt: '2000-01-01T00:00:00+00:00'
		);

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'The end date must be in the future'], actual: $response->getData());
	}//end testAddMemberWithAPastEndDateIsABadRequest()

	/**
	 * Removing a member returns how many derived shares the service cascade-revoked.
	 *
	 * @return void
	 */
	public function testRemoveMemberReturnsTheRevokedShares(): void {
		$this->service->expects($this->once())
			->method('removeMember')
			->with('tf-1', 'mem-bob', 'olga')
			->willReturn(3);

		$response = $this->controller()->removeMember(id: 'tf-1', memberId: 'mem-bob');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['revoked' => 3], actual: $response->getData());
	}//end testRemoveMemberReturnsTheRevokedShares()

	/**
	 * A removal the quarantine scan has not judged yet is a 409 with its own code.
	 *
	 * @return void
	 */
	public function testRemoveMemberWhileTheScanIsPendingIsAConflict(): void {
		$this->service->method('removeMember')->willThrowException(
			new ShareScanPendingException(message: 'A share of this folder is waiting for the scan')
		);

		$response = $this->controller()->removeMember(id: 'tf-1', memberId: 'mem-bob');

		$this->assertSame(expected: Http::STATUS_CONFLICT, actual: $response->getStatus());
		$this->assertSame(
			expected: ['error' => 'share_scan_pending', 'message' => 'A share of this folder is waiting for the scan'],
			actual: $response->getData()
		);
	}//end testRemoveMemberWhileTheScanIsPendingIsAConflict()

	/**
	 * A grade change with useOnly forwards the parsed restriction and the
	 * owner's uid, and answers with the updated membership.
	 *
	 * @return void
	 */
	public function testSetMemberGradeWithUseOnlyForwardsTheRestriction(): void {
		$this->service->expects($this->once())
			->method('setMemberGrade')
			->with(
				'tf-1',
				'mem-bob',
				'write',
				'olga',
				$this->callback(
					callback: static fn ($restriction): bool => $restriction instanceof ShareRestriction
						&& $restriction->useOnly === true
						&& $restriction->expiresAt === null
				)
			)
			->willReturn($this->member(memberId: 'bob', grade: 'write'));

		$response = $this->controller()->setMemberGrade(id: 'tf-1', memberId: 'mem-bob', grade: 'write', useOnly: true);

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: 'write', actual: $response->getData()['grade']);
		$this->assertSame(expected: 'bob', actual: $response->getData()['memberId']);
	}//end testSetMemberGradeWithUseOnlyForwardsTheRestriction()

	/**
	 * Without useOnly the grade change carries no restriction at all, so both
	 * options are left as they are.
	 *
	 * @return void
	 */
	public function testSetMemberGradeWithoutUseOnlyLeavesTheRestrictionAlone(): void {
		$this->service->expects($this->once())
			->method('setMemberGrade')
			->with('tf-1', 'mem-bob', 'read', 'olga', null)
			->willReturn($this->member(memberId: 'bob', grade: 'read'));

		$response = $this->controller()->setMemberGrade(id: 'tf-1', memberId: 'mem-bob', grade: 'read');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: 'read', actual: $response->getData()['grade']);
	}//end testSetMemberGradeWithoutUseOnlyLeavesTheRestrictionAlone()
}//end class
