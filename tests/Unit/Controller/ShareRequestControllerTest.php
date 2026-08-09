<?php

/**
 * Contract tests for `shareRequest#deny`
 * (POST /api/v1/share-requests/deny).
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
use OCA\Doriath\Controller\ShareRequestController;
use OCA\Doriath\Service\ShareRequestService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A denial is a security decision: it must be attributed to the session user
 * as the owner, it must carry the exact triple the notification named, and a
 * caller who does not own the secret must be refused rather than silently
 * having the denial recorded for someone else.
 *
 */
class ShareRequestControllerTest extends TestCase
{

    /**
     * The mocked request.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * The mocked share-request service.
     *
     * @var ShareRequestService&MockObject
     */
    private ShareRequestService&MockObject $shareRequestService;

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

        $this->request             = $this->createMock(IRequest::class);
        $this->shareRequestService = $this->createMock(ShareRequestService::class);
        $this->userSession         = $this->createMock(IUserSession::class);
    }//end setUp()


    /**
     * Build the controller with a signed-in or an anonymous session.
     *
     * @param string|null $userId The session UID, or null for an anonymous caller.
     *
     * @return ShareRequestController The controller under test.
     */
    private function controller(?string $userId='owner'): ShareRequestController
    {
        if ($userId === null) {
            $this->userSession->method('getUser')->willReturn(null);
        } else {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($userId);
            $this->userSession->method('getUser')->willReturn($user);
        }

        return new ShareRequestController(
            request: $this->request,
            shareRequestService: $this->shareRequestService,
            userSession: $this->userSession
        );
    }//end controller()


    /**
     * The denial must reach the service as the full request triple, with the
     * session user as the deciding owner, and answer the caller `denied`.
     *
     * @return void
     */
    public function testDenyForwardsTheRequestTripleAttributedToTheSessionOwner(): void
    {
        // The ITEM: the params array the service receives is assembled from
        // the three request fields — not partially, not from the session.
        $this->shareRequestService->expects($this->once())
            ->method('denyShareRequest')
            ->with(
                [
                    'sourceSecretId' => 'secret-1',
                    'requesterId'    => 'bob',
                    'targetUserId'   => 'carol',
                ],
                'owner'
            );

        $response = $this->controller('owner')->deny(
            sourceSecretId: 'secret-1',
            requesterId: 'bob',
            targetUserId: 'carol'
        );

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(
            ['status' => 'denied'],
            $response->getData(),
            'deny() must confirm the denial the service recorded'
        );
    }//end testDenyForwardsTheRequestTripleAttributedToTheSessionOwner()


    /**
     * A caller who does not own the source secret is refused with 403 and the
     * service's reason reaches the caller.
     *
     * @return void
     */
    public function testDenyAnswers403WhenTheCallerDoesNotOwnTheSecret(): void
    {
        $this->shareRequestService->expects($this->once())
            ->method('denyShareRequest')
            ->willThrowException(new InvalidArgumentException('Only the owner can decide a share request'));

        $response = $this->controller('mallory')->deny(
            sourceSecretId: 'secret-1',
            requesterId: 'bob',
            targetUserId: 'carol'
        );

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $this->assertSame(
            ['message' => 'Only the owner can decide a share request'],
            $response->getData()
        );
    }//end testDenyAnswers403WhenTheCallerDoesNotOwnTheSecret()


    /**
     * An anonymous caller may not decide anyone's share request; the service
     * is never reached.
     *
     * @return void
     */
    public function testDenyRejectsAnAnonymousCallerBeforeTheService(): void
    {
        $this->shareRequestService->expects($this->never())->method('denyShareRequest');

        $response = $this->controller(null)->deny(
            sourceSecretId: 'secret-1',
            requesterId: 'bob',
            targetUserId: 'carol'
        );

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame(['message' => 'Unauthorized'], $response->getData());
    }//end testDenyRejectsAnAnonymousCallerBeforeTheService()


}//end class
