<?php

/**
 * Unit tests for DashboardController.
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
 */

declare(strict_types=1);

namespace OCA\Doriath\Tests\Unit\Controller;

use OCA\Doriath\Controller\DashboardController;
use OCA\Doriath\Service\DashboardService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DashboardController.
 */
class DashboardControllerTest extends TestCase
{

    /**
     * Build a controller with the supplied session user.
     *
     * @param IUser|null       $user    The session user, or null for anonymous.
     * @param bool             $isAdmin Whether the user is an admin.
     * @param DashboardService $service The dashboard service mock.
     *
     * @return DashboardController
     */
    private function controller(?IUser $user, bool $isAdmin, DashboardService $service): DashboardController
    {
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn($isAdmin);

        return new DashboardController(
            $this->createMock(IRequest::class),
            $service,
            $session,
            $groupManager,
        );
    }//end controller()

    /**
     * summary() returns 401 when there is no session user.
     *
     * @return void
     */
    public function testSummaryUnauthorized(): void
    {
        $service = $this->createMock(DashboardService::class);
        $service->expects($this->never())->method('fetchSummary');

        $response = $this->controller(null, false, $service)->summary();

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testSummaryUnauthorized()

    /**
     * summary() delegates to the service with the user's id and admin flag.
     *
     * @return void
     */
    public function testSummaryDelegatesToService(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');

        $payload = ['total_secrets' => 3];
        $service = $this->createMock(DashboardService::class);
        $service->expects($this->once())
            ->method('fetchSummary')
            ->with('alice', true)
            ->willReturn($payload);

        $response = $this->controller($user, true, $service)->summary();

        self::assertSame($payload, $response->getData());
    }//end testSummaryDelegatesToService()
}//end class
