<?php

/**
 * Unit tests for the EphemeralSendAccessController failure endpoint
 * (POST /api/v1/public/sends/{token}/failure).
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

use OCA\Doriath\Controller\EphemeralSendAccessController;
use OCA\Doriath\Service\EphemeralSendService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * `ephemeralSendAccess#failure` is the brute-force brake on the anonymous
 * recipient surface: each failed password attempt is reported here and the
 * send burns permanently at the cap. The wire contract is therefore that the
 * URL token actually reaches the service (a swallowed report is an unlimited
 * guessing budget), that the attempt verdict — burned + attempts left —
 * reaches the recipient, and that a missing/expired/burned send answers the
 * SAME 404 shape so the endpoint is not an existence oracle.
 *
 * @covers \OCA\Doriath\Controller\EphemeralSendAccessController
 */
class EphemeralSendAccessControllerTest extends TestCase
{

    /**
     * The mocked ephemeral-send service.
     *
     * @var EphemeralSendService&MockObject
     */
    private EphemeralSendService&MockObject $service;


    /**
     * Set up the mocks shared by every test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->createMock(EphemeralSendService::class);
    }//end setUp()


    /**
     * Build the controller under test with its collaborators mocked.
     *
     * @return EphemeralSendAccessController The controller under test.
     */
    private function controller(): EphemeralSendAccessController
    {
        return new EphemeralSendAccessController(
            request: $this->createMock(IRequest::class),
            service: $this->service
        );
    }//end controller()


    /**
     * A reported failure must reach the service with the URL's own token and
     * return the remaining budget verbatim.
     *
     * @return void
     */
    public function testFailureForwardsTheUrlTokenAndReturnsTheRemainingAttempts(): void
    {
        $this->service->expects($this->once())
            ->method('reportFailure')
            ->with('f00dcafe-token-0001')
            ->willReturn(
                [
                    'burned'       => false,
                    'attemptsLeft' => 4,
                ]
            );

        $response = $this->controller()->failure(token: 'f00dcafe-token-0001');

        $this->assertSame(
            Http::STATUS_OK,
            $response->getStatus(),
            'a recorded failed attempt answers 200'
        );
        $this->assertSame(
            [
                'burned'       => false,
                'attemptsLeft' => 4,
            ],
            $response->getData(),
            'the recipient must be told how many attempts remain — a bare success hides the brake'
        );
    }//end testFailureForwardsTheUrlTokenAndReturnsTheRemainingAttempts()


    /**
     * The burn verdict at the cap must reach the recipient unchanged.
     *
     * If the controller flattened this to a generic success, the access page
     * could not tell a still-usable send from one that just burned.
     *
     * @return void
     */
    public function testFailureAtTheCapReportsTheBurnAndAnExhaustedBudget(): void
    {
        $this->service->expects($this->once())
            ->method('reportFailure')
            ->with('f00dcafe-token-0002')
            ->willReturn(
                [
                    'burned'       => true,
                    'attemptsLeft' => 0,
                ]
            );

        $response = $this->controller()->failure(token: 'f00dcafe-token-0002');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue(
            $response->getData()['burned'],
            'the burn at the cap must be reported to the recipient'
        );
        $this->assertSame(
            0,
            $response->getData()['attemptsLeft'],
            'a burned send has no attempts left'
        );
    }//end testFailureAtTheCapReportsTheBurnAndAnExhaustedBudget()


    /**
     * A missing, expired or already-burned send answers the single 404 shape.
     *
     * @return void
     */
    public function testFailureOnAnUnknownTokenAnswersTheIndistinguishable404(): void
    {
        $this->service->expects($this->once())
            ->method('reportFailure')
            ->with('f00dcafe-token-unknown')
            ->willThrowException(new DoesNotExistException('gone'));

        $response = $this->controller()->failure(token: 'f00dcafe-token-unknown');

        $this->assertSame(
            Http::STATUS_NOT_FOUND,
            $response->getStatus(),
            'a send that is not live must answer 404'
        );
        $this->assertSame(
            ['message' => 'Send not found'],
            $response->getData(),
            'missing, expired and burned sends must be indistinguishable — no existence oracle'
        );
    }//end testFailureOnAnUnknownTokenAnswersTheIndistinguishable404()


    /**
     * The endpoint must stay anonymously reachable AND rate-limited.
     *
     * Nextcloud's SecurityMiddleware reads the dispatched method's own
     * attributes: dropping `#[PublicPage]` would make every recipient's failed
     * attempt a 401 (so the send would never burn), and dropping
     * `#[AnonRateLimit]` would remove the only throttle on an unauthenticated
     * endpoint.
     *
     * @return void
     */
    public function testFailureRemainsPublicUnthrottledOnlyByItsAnonRateLimit(): void
    {
        $reflection = new ReflectionMethod(EphemeralSendAccessController::class, 'failure');

        $this->assertCount(
            1,
            $reflection->getAttributes(PublicPage::class),
            'the anonymous recipient surface must declare #[PublicPage] on failure()'
        );
        $this->assertCount(
            1,
            $reflection->getAttributes(NoCSRFRequired::class),
            'an anonymous POST has no CSRF token to present'
        );

        $rateLimit = $reflection->getAttributes(AnonRateLimit::class);
        $this->assertCount(
            1,
            $rateLimit,
            'the unauthenticated failure report must stay rate-limited'
        );
        $this->assertSame(
            [
                'limit'  => 15,
                'period' => 60,
            ],
            $rateLimit[0]->getArguments(),
            'the anonymous throttle must keep its declared budget'
        );
    }//end testFailureRemainsPublicUnthrottledOnlyByItsAnonRateLimit()


}//end class
