<?php

/**
 * Unit tests for the HoneyController alerts and unflag endpoints
 * (GET /api/v1/honey/alerts and DELETE /api/v1/secrets/{id}/honey).
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
use OCA\Doriath\Controller\HoneyController;
use OCA\Doriath\Db\HoneyAlert;
use OCA\Doriath\Service\HoneyCredentialService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Honey (decoy) credentials page a defender when a decoy is read, so both of
 * these endpoints carry a scope decision the wire contract has to pin down.
 *
 * `honey#alerts` must ask the service for the scope derived from the SESSION
 * user's admin membership — owner-scoped for an ordinary user, instance-wide
 * for an admin — and must serialise every row it gets back. A test that only
 * checked for a 200 would pass against a controller that always answered the
 * owner scope, silently blinding admins to instance-wide intrusion alerts.
 *
 * `honey#unflag` must forward the secret id, the actor and the same admin
 * override, and must keep the three refusal shapes distinct: unauthenticated
 * (403), not flagged (404) and not permitted (403 with the service's reason).
 *
 */
class HoneyControllerTest extends TestCase
{

    /**
     * The mocked honey-credential service.
     *
     * @var HoneyCredentialService&MockObject
     */
    private HoneyCredentialService&MockObject $service;

    /**
     * The mocked user session.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * The mocked group manager (admin scope).
     *
     * @var IGroupManager&MockObject
     */
    private IGroupManager&MockObject $groupManager;


    /**
     * Set up the mocks shared by every test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->service      = $this->createMock(HoneyCredentialService::class);
        $this->userSession  = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
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
     * @return HoneyController The controller under test.
     */
    private function controller(): HoneyController
    {
        return new HoneyController(
            request: $this->createMock(IRequest::class),
            service: $this->service,
            userSession: $this->userSession,
            groupManager: $this->groupManager
        );
    }//end controller()


    /**
     * Build a serialisable alert row.
     *
     * @param array<string,mixed> $payload The row the entity serialises to.
     *
     * @return HoneyAlert&MockObject
     */
    private function alert(array $payload): HoneyAlert&MockObject
    {
        $alert = $this->createMock(HoneyAlert::class);
        $alert->method('jsonSerialize')->willReturn($payload);

        return $alert;
    }//end alert()


    /**
     * An ordinary user's alert list is requested in the OWNER scope and every
     * row the service returns is serialised into the response.
     *
     * @return void
     */
    public function testAlertsRequestsTheOwnerScopeForAnOrdinaryUserAndSerialisesEveryRow(): void
    {
        $this->signIn('alice');

        $this->groupManager->expects($this->once())
            ->method('isAdmin')
            ->with('alice')
            ->willReturn(false);

        $this->service->expects($this->once())
            ->method('listAlerts')
            ->with('alice', false)
            ->willReturn(
                [
                    $this->alert(
                        [
                            'id'         => 'alert-1',
                            'accessorId' => 'mallory',
                            'acked'      => false,
                        ]
                    ),
                    $this->alert(
                        [
                            'id'         => 'alert-2',
                            'accessorId' => 'trudy',
                            'acked'      => true,
                        ]
                    ),
                ]
            );

        $response = $this->controller()->alerts();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(
            [
                [
                    'id'         => 'alert-1',
                    'accessorId' => 'mallory',
                    'acked'      => false,
                ],
                [
                    'id'         => 'alert-2',
                    'accessorId' => 'trudy',
                    'acked'      => true,
                ],
            ],
            $response->getData(),
            'every alert row must reach the caller serialised, not summarised away'
        );
    }//end testAlertsRequestsTheOwnerScopeForAnOrdinaryUserAndSerialisesEveryRow()


    /**
     * An admin's alert list is requested in the INSTANCE-WIDE scope.
     *
     * The admin flag is derived from the group manager, never from a request
     * parameter — a controller that hardcoded `false` would still answer 200.
     *
     * @return void
     */
    public function testAlertsRequestsTheInstanceWideScopeForAnAdmin(): void
    {
        $this->signIn('root');

        $this->groupManager->expects($this->once())
            ->method('isAdmin')
            ->with('root')
            ->willReturn(true);

        $this->service->expects($this->once())
            ->method('listAlerts')
            ->with('root', true)
            ->willReturn([$this->alert(['id' => 'alert-9'])]);

        $response = $this->controller()->alerts();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(
            [['id' => 'alert-9']],
            $response->getData(),
            'the admin scope must return the instance-wide rows the service supplied'
        );
    }//end testAlertsRequestsTheInstanceWideScopeForAnAdmin()


    /**
     * An anonymous caller is refused before any alert is read.
     *
     * @return void
     */
    public function testAlertsByAnAnonymousCallerIs403AndReadsNoAlerts(): void
    {
        $this->signIn(null);

        $this->service->expects($this->never())->method('listAlerts');
        $this->groupManager->expects($this->never())->method('isAdmin');

        $response = $this->controller()->alerts();

        $this->assertSame(
            Http::STATUS_FORBIDDEN,
            $response->getStatus(),
            'intrusion alerts must never be readable without a session'
        );
        $this->assertSame(['message' => 'Unauthenticated'], $response->getData());
    }//end testAlertsByAnAnonymousCallerIs403AndReadsNoAlerts()


    /**
     * Unflagging forwards the URL's secret id, the session actor and the
     * derived admin override, and confirms the removal.
     *
     * @return void
     */
    public function testUnflagForwardsTheSecretIdActorAndDerivedAdminFlag(): void
    {
        $this->signIn('alice');

        $this->groupManager->expects($this->once())
            ->method('isAdmin')
            ->with('alice')
            ->willReturn(false);

        $this->service->expects($this->once())
            ->method('unflag')
            ->with('sec-0000-4000-8000-000000000001', 'alice', false);

        $response = $this->controller()->unflag(id: 'sec-0000-4000-8000-000000000001');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(
            ['unflagged' => true],
            $response->getData(),
            'the caller must be told the decoy flag was actually removed'
        );
    }//end testUnflagForwardsTheSecretIdActorAndDerivedAdminFlag()


    /**
     * An admin unflagging someone else's decoy carries the admin override into
     * the service — the override is the only reason the service permits it.
     *
     * @return void
     */
    public function testUnflagByAnAdminCarriesTheAdminOverrideIntoTheService(): void
    {
        $this->signIn('root');

        $this->groupManager->expects($this->once())
            ->method('isAdmin')
            ->with('root')
            ->willReturn(true);

        $this->service->expects($this->once())
            ->method('unflag')
            ->with('sec-0000-4000-8000-000000000002', 'root', true);

        $response = $this->controller()->unflag(id: 'sec-0000-4000-8000-000000000002');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['unflagged' => true], $response->getData());
    }//end testUnflagByAnAdminCarriesTheAdminOverrideIntoTheService()


    /**
     * Unflagging a secret that carries no flag answers 404, not a success.
     *
     * @return void
     */
    public function testUnflagOfANotFlaggedSecretAnswers404(): void
    {
        $this->signIn('alice');

        $this->groupManager->method('isAdmin')->willReturn(false);

        $this->service->expects($this->once())
            ->method('unflag')
            ->with('sec-0000-4000-8000-000000000003', 'alice', false)
            ->willThrowException(new DoesNotExistException('no flag'));

        $response = $this->controller()->unflag(id: 'sec-0000-4000-8000-000000000003');

        $this->assertSame(
            Http::STATUS_NOT_FOUND,
            $response->getStatus(),
            'a secret that was never flagged cannot report a removal'
        );
        $this->assertSame(['message' => 'Secret is not flagged'], $response->getData());
    }//end testUnflagOfANotFlaggedSecretAnswers404()


    /**
     * A caller who is neither owner nor admin gets 403 with the reason.
     *
     * @return void
     */
    public function testUnflagByANonOwnerAnswers403WithTheServiceMessage(): void
    {
        $this->signIn('mallory');

        $this->groupManager->method('isAdmin')->willReturn(false);

        $this->service->expects($this->once())
            ->method('unflag')
            ->with('sec-0000-4000-8000-000000000004', 'mallory', false)
            ->willThrowException(
                new InvalidArgumentException('Only the owner or an admin may unflag a honey secret')
            );

        $response = $this->controller()->unflag(id: 'sec-0000-4000-8000-000000000004');

        $this->assertSame(
            Http::STATUS_FORBIDDEN,
            $response->getStatus(),
            'a refused unflag must not be reported as unflagged'
        );
        $this->assertSame(
            ['message' => 'Only the owner or an admin may unflag a honey secret'],
            $response->getData()
        );
    }//end testUnflagByANonOwnerAnswers403WithTheServiceMessage()


    /**
     * An anonymous caller cannot disarm a decoy.
     *
     * @return void
     */
    public function testUnflagByAnAnonymousCallerIs403AndNeverReachesTheService(): void
    {
        $this->signIn(null);

        $this->service->expects($this->never())->method('unflag');
        $this->groupManager->expects($this->never())->method('isAdmin');

        $response = $this->controller()->unflag(id: 'sec-0000-4000-8000-000000000001');

        $this->assertSame(
            Http::STATUS_FORBIDDEN,
            $response->getStatus(),
            'an unauthenticated caller must not be able to disarm a decoy'
        );
        $this->assertSame(['message' => 'Unauthenticated'], $response->getData());
    }//end testUnflagByAnAnonymousCallerIs403AndNeverReachesTheService()


}//end class
