<?php

/**
 * Contract tests for `siemSink#test`
 * (POST /api/v1/siem/sinks/{id}/test).
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

use OCA\Doriath\Controller\SiemSinkController;
use OCA\Doriath\Service\SiemService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The test-fire endpoint carries `#[NoAdminRequired]`, so its ONLY protection
 * is the in-body admin gate. These tests assert that the gate runs before any
 * sink logic (siem-audit-export §6.2), that the probe is attributed to the
 * admin who fired it, and that the delivery outcome reaches the caller instead
 * of a blanket success.
 *
 * @covers \OCA\Doriath\Controller\SiemSinkController
 */
class SiemSinkControllerTest extends TestCase
{

    /**
     * The mocked request.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * The mocked SIEM service.
     *
     * @var SiemService&MockObject
     */
    private SiemService&MockObject $service;

    /**
     * The mocked user session.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * The mocked group manager (the admin gate).
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

        $this->request      = $this->createMock(IRequest::class);
        $this->service      = $this->createMock(SiemService::class);
        $this->userSession  = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
    }//end setUp()


    /**
     * Build the controller for a given session and admin posture.
     *
     * @param string|null $userId  The session UID, or null for an anonymous caller.
     * @param boolean     $isAdmin Whether that user is an instance admin.
     *
     * @return SiemSinkController The controller under test.
     */
    private function controller(?string $userId='admin', bool $isAdmin=true): SiemSinkController
    {
        if ($userId === null) {
            $this->userSession->method('getUser')->willReturn(null);
        } else {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($userId);
            $this->userSession->method('getUser')->willReturn($user);
            $this->groupManager->method('isAdmin')->with($userId)->willReturn($isAdmin);
        }

        return new SiemSinkController(
            request: $this->request,
            service: $this->service,
            userSession: $this->userSession,
            groupManager: $this->groupManager
        );
    }//end controller()


    /**
     * An admin test-fire must reach the service with BOTH the firing admin's
     * uid (the probe event is attributed to them) and the URL's sink id, and
     * the service's delivery report must reach the caller unaltered.
     *
     * @return void
     */
    public function testSiemSinkTestDeliversAProbeEventToTheConfiguredSink(): void
    {
        $report = [
            'delivered'  => true,
            'sinkId'     => 'sink-1',
            'statusCode' => 204,
            'latencyMs'  => 42,
        ];

        // The ITEM: the probe is fired at the requested sink, as this admin.
        $this->service->expects($this->once())
            ->method('testSink')
            ->with('admin', 'sink-1')
            ->willReturn($report);

        $response = $this->controller('admin', true)->test(id: 'sink-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(
            $report,
            $response->getData(),
            'the caller must receive the real delivery outcome, not a blanket success'
        );
    }//end testSiemSinkTestDeliversAProbeEventToTheConfiguredSink()


    /**
     * A failed delivery is still a 200 report — but it must say so. Reporting
     * a failure as a success would make a dead sink look configured.
     *
     * @return void
     */
    public function testSiemSinkTestReportsAFailedDeliveryAsAFailure(): void
    {
        $this->service->expects($this->once())
            ->method('testSink')
            ->with('admin', 'sink-1')
            ->willReturn(
                [
                    'delivered' => false,
                    'sinkId'    => 'sink-1',
                    'error'     => 'Connection refused',
                ]
            );

        $response = $this->controller('admin', true)->test(id: 'sink-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertFalse(
            $response->getData()['delivered'],
            'a refused connection must not be reported as a delivered probe'
        );
        $this->assertSame('Connection refused', $response->getData()['error']);
    }//end testSiemSinkTestReportsAFailedDeliveryAsAFailure()


    /**
     * A non-admin authenticated caller is refused with 403 and no probe is
     * fired — the gate runs before any sink logic.
     *
     * @return void
     */
    public function testSiemSinkTestRefusesANonAdminCallerBeforeFiringAnything(): void
    {
        $this->service->expects($this->never())->method('testSink');

        $response = $this->controller('bob', false)->test(id: 'sink-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $this->assertSame(
            ['message' => 'SIEM sink management is admin-only'],
            $response->getData()
        );
    }//end testSiemSinkTestRefusesANonAdminCallerBeforeFiringAnything()


    /**
     * An anonymous caller is refused by the same gate.
     *
     * @return void
     */
    public function testSiemSinkTestRefusesAnAnonymousCallerBeforeFiringAnything(): void
    {
        $this->service->expects($this->never())->method('testSink');

        $response = $this->controller(null)->test(id: 'sink-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $this->assertSame(
            ['message' => 'SIEM sink management is admin-only'],
            $response->getData()
        );
    }//end testSiemSinkTestRefusesAnAnonymousCallerBeforeFiringAnything()


    /**
     * An unknown sink id is a 404, distinct from a delivered probe.
     *
     * @return void
     */
    public function testSiemSinkTestAnswers404ForAnUnknownSink(): void
    {
        $this->service->expects($this->once())
            ->method('testSink')
            ->with('admin', 'ghost')
            ->willThrowException(new DoesNotExistException('no such sink'));

        $response = $this->controller('admin', true)->test(id: 'ghost');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame(['message' => 'Sink not found'], $response->getData());
    }//end testSiemSinkTestAnswers404ForAnUnknownSink()


}//end class
