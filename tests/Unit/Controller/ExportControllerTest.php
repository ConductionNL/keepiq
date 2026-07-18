<?php

/**
 * Unit tests for ExportController.
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

use OCA\Doriath\Controller\ExportController;
use OCA\Doriath\Event\SecretExportedEvent;
use OCP\AppFramework\Http;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the client-reported export-event endpoint.
 */
class ExportControllerTest extends TestCase
{
    /**
     * Build the controller + collaborators.
     *
     * @param string|null $userId The session user
     *
     * @return array{0:ExportController,1:IRequest,2:IEventDispatcher}
     */
    private function build(?string $userId='alice'): array
    {
        $request    = $this->createMock(IRequest::class);
        $session    = $this->createMock(IUserSession::class);
        $dispatcher = $this->createMock(IEventDispatcher::class);

        if ($userId !== null) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($userId);
            $session->method('getUser')->willReturn($user);
        } else {
            $session->method('getUser')->willReturn(null);
        }

        return [new ExportController($request, $session, $dispatcher), $request, $dispatcher];
    }//end build()

    /**
     * Stub the three request params.
     *
     * @param IRequest $request The mocked request
     * @param string   $mode    The mode param
     * @param string   $scope   The scope param
     * @param mixed    $count   The secretCount param
     *
     * @return void
     */
    private function params(IRequest $request, string $mode, string $scope, $count): void
    {
        $request->method('getParam')->willReturnMap(
                [
                    ['mode', '', $mode],
                    ['scope', '', $scope],
                    ['secretCount', 0, $count],
                ]
                );
    }//end params()

    /**
     * A valid report dispatches SecretExportedEvent for the session user.
     *
     * @return void
     */
    public function testValidReportDispatchesEvent(): void
    {
        [$controller, $request, $dispatcher] = $this->build('alice');
        $this->params($request, 'encrypted-backup', 'vault', 120);

        $captured = null;
        $dispatcher->expects($this->once())
            ->method('dispatchTyped')
            ->willReturnCallback(
                    function ($e) use (&$captured) {
                        $captured = $e;

                    }
                    );

        $response = $controller->events();
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertInstanceOf(SecretExportedEvent::class, $captured);
        $this->assertSame('alice', $captured->getUserId());
        $this->assertSame('encrypted-backup', $captured->getMode());
        $this->assertSame(120, $captured->getSecretCount());
    }//end testValidReportDispatchesEvent()

    /**
     * The `cxf` export mode is accepted and dispatched — the CXF export
     * (cxf-import-export D5) reports through the same endpoint with its
     * own mode value, carrying no secret material.
     *
     * @return void
     *
     * @spec openspec/changes/cxf-import-export/specs/cxf-import-export/spec.md#requirement-cxf-export
     */
    public function testCxfModeAcceptedAndDispatched(): void
    {
        [$controller, $request, $dispatcher] = $this->build('alice');
        $this->params($request, 'cxf', 'vault', 7);
        $captured = null;
        $dispatcher->expects($this->once())->method('dispatchTyped')
            ->willReturnCallback(
                function ($e) use (&$captured) {
                    $captured = $e;
                }
            );

        $response = $controller->events();
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertInstanceOf(SecretExportedEvent::class, $captured);
        $this->assertSame('cxf', $captured->getMode());
        $this->assertSame(7, $captured->getSecretCount());
    }//end testCxfModeAcceptedAndDispatched()

    /**
     * An invalid mode is rejected with 400 and no event.
     *
     * @return void
     */
    public function testInvalidModeRejected(): void
    {
        [$controller, $request, $dispatcher] = $this->build('alice');
        $this->params($request, 'bogus-mode', 'vault', 1);
        $dispatcher->expects($this->never())->method('dispatchTyped');

        $response = $controller->events();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testInvalidModeRejected()

    /**
     * An invalid scope is rejected with 400.
     *
     * @return void
     */
    public function testInvalidScopeRejected(): void
    {
        [$controller, $request, $dispatcher] = $this->build('alice');
        $this->params($request, 'plaintext-csv', 'everything', 1);
        $dispatcher->expects($this->never())->method('dispatchTyped');

        $response = $controller->events();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testInvalidScopeRejected()

    /**
     * Unauthenticated requests are rejected.
     *
     * @return void
     */
    public function testUnauthorized(): void
    {
        [$controller] = $this->build(null);
        $response     = $controller->events();
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testUnauthorized()
}//end class
