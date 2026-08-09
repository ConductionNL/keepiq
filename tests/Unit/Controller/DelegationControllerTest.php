<?php

/**
 * Unit tests for the DelegationController reclaim endpoint
 * (POST /api/v1/secrets/{secretId}/delegations/reclaim).
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
use OCA\Doriath\Controller\DelegationController;
use OCA\Doriath\Service\DelegationService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * `delegation#reclaim` is a REVOCATION: it withdraws every temporary
 * delegation on a secret. The wire contract has three obligations —
 * the reclaim must reach the service with the URL's own secret id AND the
 * session user as the claimed owner (never a client-supplied owner), the
 * caller must be told how many delegations were actually removed, and a
 * caller who does not own the secret must be refused instead of silently
 * reclaiming nothing.
 *
 */
class DelegationControllerTest extends TestCase
{

    /**
     * The mocked delegation service.
     *
     * @var DelegationService&MockObject
     */
    private DelegationService&MockObject $delegationService;

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

        $this->delegationService = $this->createMock(DelegationService::class);
        $this->userSession       = $this->createMock(IUserSession::class);
    }//end setUp()


    /**
     * Log a user into the mocked session, or leave it anonymous.
     *
     * @param string|null $uid The session user id, or null for anonymous.
     *
     * @return void
     */
    private function signIn(?string $uid): void
    {
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
     * @return DelegationController The controller under test.
     */
    private function controller(): DelegationController
    {
        return new DelegationController(
            request: $this->createMock(IRequest::class),
            delegationService: $this->delegationService,
            userSession: $this->userSession
        );
    }//end controller()


    /**
     * The reclaim must carry the URL's secret id and the SESSION user as owner,
     * and report back the number of delegations the service actually removed.
     *
     * A response that reported success without the count would be identical
     * whether three delegations were withdrawn or none were.
     *
     * @return void
     */
    public function testReclaimForwardsTheSecretIdAndSessionOwnerAndReturnsTheRemovedCount(): void
    {
        $this->signIn('alice');

        $this->delegationService->expects($this->once())
            ->method('reclaimDelegation')
            ->with('a1b2c3d4-0000-4000-8000-000000000001', 'alice')
            ->willReturn(3);

        $response = $this->controller()->reclaim(secretId: 'a1b2c3d4-0000-4000-8000-000000000001');

        $this->assertSame(
            Http::STATUS_OK,
            $response->getStatus(),
            'a completed reclaim answers 200'
        );
        $this->assertSame(
            ['removed' => 3],
            $response->getData(),
            'the caller must learn how many delegations were actually withdrawn'
        );
    }//end testReclaimForwardsTheSecretIdAndSessionOwnerAndReturnsTheRemovedCount()


    /**
     * A reclaim that withdrew nothing must still report the honest zero.
     *
     * @return void
     */
    public function testReclaimReportsAnHonestZeroWhenNothingWasDelegated(): void
    {
        $this->signIn('alice');

        $this->delegationService->expects($this->once())
            ->method('reclaimDelegation')
            ->with('a1b2c3d4-0000-4000-8000-000000000002', 'alice')
            ->willReturn(0);

        $response = $this->controller()->reclaim(secretId: 'a1b2c3d4-0000-4000-8000-000000000002');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(
            ['removed' => 0],
            $response->getData(),
            'zero removals must not be dressed up as a generic success'
        );
    }//end testReclaimReportsAnHonestZeroWhenNothingWasDelegated()


    /**
     * An anonymous caller is refused BEFORE the service is consulted.
     *
     * @return void
     */
    public function testReclaimByAnAnonymousCallerIs401AndNeverReachesTheService(): void
    {
        $this->signIn(null);

        $this->delegationService->expects($this->never())->method('reclaimDelegation');

        $response = $this->controller()->reclaim(secretId: 'a1b2c3d4-0000-4000-8000-000000000001');

        $this->assertSame(
            Http::STATUS_UNAUTHORIZED,
            $response->getStatus(),
            'an unauthenticated reclaim must be refused with 401'
        );
        $this->assertSame(['message' => 'Unauthorized'], $response->getData());
    }//end testReclaimByAnAnonymousCallerIs401AndNeverReachesTheService()


    /**
     * A caller who does not own the secret gets 403 with the service's reason.
     *
     * @return void
     */
    public function testReclaimByANonOwnerAnswers403WithTheServiceMessage(): void
    {
        $this->signIn('mallory');

        $this->delegationService->expects($this->once())
            ->method('reclaimDelegation')
            ->with('a1b2c3d4-0000-4000-8000-000000000001', 'mallory')
            ->willThrowException(new InvalidArgumentException('Not authorized to reclaim this delegation'));

        $response = $this->controller()->reclaim(secretId: 'a1b2c3d4-0000-4000-8000-000000000001');

        $this->assertSame(
            Http::STATUS_FORBIDDEN,
            $response->getStatus(),
            'a refused reclaim must answer 403, not a success envelope'
        );
        $this->assertSame(
            ['message' => 'Not authorized to reclaim this delegation'],
            $response->getData(),
            'the caller must be told why the reclaim was refused'
        );
    }//end testReclaimByANonOwnerAnswers403WithTheServiceMessage()


}//end class
