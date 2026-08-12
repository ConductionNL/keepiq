<?php

/**
 * Contract tests for the TeamFolderMemberController endpoints that carry no
 * wire proof: `teamFolderMember#members` and `teamFolderMember#approveJoin`.
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Controller
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

namespace OCA\Doriath\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Doriath\Controller\TeamFolderMemberController;
use OCA\Doriath\Db\TeamFolderMember;
use OCA\Doriath\Service\TeamFolderService;
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
 * the service, and the service's answer — an empty member list, a refusal, a
 * fan-out payload — must reach the caller intact.
 *
 */
class TeamFolderMemberControllerTest extends TestCase
{

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
    protected function setUp(): void
    {
        parent::setUp();

        $this->request           = $this->createMock(IRequest::class);
        $this->teamFolderService = $this->createMock(TeamFolderService::class);
        $this->userSession       = $this->createMock(IUserSession::class);
    }//end setUp()


    /**
     * Build the controller with a signed-in or an anonymous session.
     *
     * @param string|null $userId The session UID, or null for an anonymous caller.
     *
     * @return TeamFolderMemberController The controller under test.
     */
    private function controller(?string $userId='owner'): TeamFolderMemberController
    {
        if ($userId === null) {
            $this->userSession->method('getUser')->willReturn(null);
        } else {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($userId);
            $this->userSession->method('getUser')->willReturn($user);
        }

        return new TeamFolderMemberController(
            request: $this->request,
            teamFolderService: $this->teamFolderService,
            userSession: $this->userSession
        );
    }//end controller()


    /**
     * Build a TeamFolderMember whose serialization is known.
     *
     * @param array<string,mixed> $row The serialized row the entity reports.
     *
     * @return TeamFolderMember&MockObject The stubbed entity.
     */
    private function member(array $row): TeamFolderMember&MockObject
    {
        $entity = $this->createMock(TeamFolderMember::class);
        $entity->method('jsonSerialize')->willReturn($row);

        return $entity;
    }//end member()


    /**
     * GET /api/v1/team-folders/{id}/members must ask the service for THIS
     * folder as THIS user and return the serialized membership rows.
     *
     * @return void
     */
    public function testMembersReturnsTheSerializedMembershipRowsForTheOwner(): void
    {
        $rows = [
            $this->member(
                [
                    'id'         => 'm-1',
                    'memberType' => 'user',
                    'memberId'   => 'bob',
                    'grade'      => 'read',
                ]
            ),
            $this->member(
                [
                    'id'         => 'm-2',
                    'memberType' => 'group',
                    'memberId'   => 'finance',
                    'grade'      => 'write',
                ]
            ),
        ];

        // The ITEM: the lookup is scoped to the URL's folder AND the session user.
        $this->teamFolderService->expects($this->once())
            ->method('listMembers')
            ->with('tf-1', 'owner')
            ->willReturn($rows);

        $response = $this->controller('owner')->members(id: 'tf-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(
            [
                [
                    'id'         => 'm-1',
                    'memberType' => 'user',
                    'memberId'   => 'bob',
                    'grade'      => 'read',
                ],
                [
                    'id'         => 'm-2',
                    'memberType' => 'group',
                    'memberId'   => 'finance',
                    'grade'      => 'write',
                ],
            ],
            $response->getData(),
            'members() must serialize every row the service returned'
        );
    }//end testMembersReturnsTheSerializedMembershipRowsForTheOwner()


    /**
     * A non-owner receives an EMPTY list — the membership of someone else's
     * team folder must never leak through this endpoint.
     *
     * @return void
     */
    public function testMembersLeaksNothingToANonOwner(): void
    {
        $this->teamFolderService->expects($this->once())
            ->method('listMembers')
            ->with('tf-1', 'mallory')
            ->willReturn([]);

        $response = $this->controller('mallory')->members(id: 'tf-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame([], $response->getData(), 'a non-owner must see no membership rows');
    }//end testMembersLeaksNothingToANonOwner()


    /**
     * An anonymous caller is refused with 401 and the service is never asked.
     *
     * @return void
     */
    public function testMembersRejectsAnAnonymousCallerBeforeTheService(): void
    {
        $this->teamFolderService->expects($this->never())->method('listMembers');

        $response = $this->controller(null)->members(id: 'tf-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame(['message' => 'Unauthorized'], $response->getData());
    }//end testMembersRejectsAnAnonymousCallerBeforeTheService()


    /**
     * POST /api/v1/team-folders/{id}/approve-join must name the approved user
     * to the service and return the fan-out payload the browser needs:
     * the recipient's certificate plus the subtree secrets to encrypt.
     *
     * @return void
     */
    public function testApproveJoinReturnsTheFanOutPayloadForTheApprovedUser(): void
    {
        $payload = [
            'recipients' => [
                [
                    'userId'      => 'newbie',
                    'certificate' => 'CERT_NEWBIE',
                ],
            ],
            'secrets'    => [
                [
                    'id'   => 'secret-1',
                    'name' => 'Shared login',
                ],
            ],
        ];

        $this->teamFolderService->expects($this->once())
            ->method('approveJoin')
            ->with('tf-1', 'newbie', 'owner')
            ->willReturn($payload);

        $response = $this->controller('owner')->approveJoin(id: 'tf-1', newMemberId: 'newbie');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(
            $payload,
            $response->getData(),
            'approveJoin() must return the recipients and secrets the fan-out needs'
        );
    }//end testApproveJoinReturnsTheFanOutPayloadForTheApprovedUser()


    /**
     * Approving a user who is not covered by the folder's membership is a
     * 400 — approving must not manufacture access the membership never gave.
     *
     * @return void
     */
    public function testApproveJoinAnswers400ForAUserOutsideTheMembership(): void
    {
        $this->teamFolderService->expects($this->once())
            ->method('approveJoin')
            ->with('tf-1', 'outsider', 'owner')
            ->willThrowException(
                new InvalidArgumentException('User is not covered by this team folder\'s membership')
            );

        $response = $this->controller('owner')->approveJoin(id: 'tf-1', newMemberId: 'outsider');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame(
            ['message' => 'User is not covered by this team folder\'s membership'],
            $response->getData()
        );
    }//end testApproveJoinAnswers400ForAUserOutsideTheMembership()


    /**
     * An anonymous caller may not approve a group join.
     *
     * @return void
     */
    public function testApproveJoinRejectsAnAnonymousCallerBeforeTheService(): void
    {
        $this->teamFolderService->expects($this->never())->method('approveJoin');

        $response = $this->controller(null)->approveJoin(id: 'tf-1', newMemberId: 'newbie');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame(['message' => 'Unauthorized'], $response->getData());
    }//end testApproveJoinRejectsAnAnonymousCallerBeforeTheService()


}//end class
