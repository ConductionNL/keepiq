<?php

/**
 * Unit tests for GdprController.
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

use OCA\Doriath\Controller\GdprController;
use OCA\Doriath\Event\GdprExportPerformedEvent;
use OCA\Doriath\Service\AccountDeletionService;
use OCA\Doriath\Service\DeletionReport;
use OCA\Doriath\Service\GdprService;
use OCP\AppFramework\Http;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the GDPR metadata + account-deletion endpoints.
 */
class GdprControllerTest extends TestCase
{
    /**
     * Build the controller + collaborators.
     *
     * @param string|null $userId The session user (null = unauthenticated)
     *
     * @return array{0:GdprController,1:IRequest,2:GdprService,3:AccountDeletionService,4:IEventDispatcher}
     */
    private function build(?string $userId='alice'): array
    {
        $request    = $this->createMock(IRequest::class);
        $gdpr       = $this->createMock(GdprService::class);
        $deletion   = $this->createMock(AccountDeletionService::class);
        $session    = $this->createMock(IUserSession::class);
        $dispatcher = $this->createMock(IEventDispatcher::class);

        if ($userId !== null) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($userId);
            $session->method('getUser')->willReturn($user);
        } else {
            $session->method('getUser')->willReturn(null);
        }

        $controller = new GdprController($request, $gdpr, $deletion, $session, $dispatcher);
        return [$controller, $request, $gdpr, $deletion, $dispatcher];
    }//end build()

    /**
     * Metadata endpoint is self-scoped (collectMetadata called with session UID)
     * and emits GdprExportPerformedEvent.
     *
     * @return void
     */
    public function testMetadataSelfScopedAndEmitsEvent(): void
    {
        [$controller, $request, $gdpr, , $dispatcher] = $this->build('alice');
        $request->method('getParam')->with('includesVault', 'false')->willReturn('true');
        $gdpr->expects($this->once())->method('collectMetadata')->with('alice')->willReturn(['format' => 'x']);

        $dispatcher->expects($this->once())
            ->method('dispatchTyped')
            ->with($this->isInstanceOf(GdprExportPerformedEvent::class));

        $response = $controller->metadata();
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testMetadataSelfScopedAndEmitsEvent()

    /**
     * Metadata endpoint rejects an unauthenticated request.
     *
     * @return void
     */
    public function testMetadataUnauthorized(): void
    {
        [$controller] = $this->build(null);
        $response     = $controller->metadata();
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testMetadataUnauthorized()

    /**
     * Account deletion requires the exact confirmation phrase — 400 without it.
     *
     * @return void
     */
    public function testDeleteRequiresConfirmationPhrase(): void
    {
        [$controller, $request, , $deletion] = $this->build('alice');
        $request->method('getParam')->with('confirmation', '')->willReturn('nope');
        $deletion->expects($this->never())->method('deleteAllFor');

        $response = $controller->deleteAccountData();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testDeleteRequiresConfirmationPhrase()

    /**
     * Account deletion runs the cascade with the in-app trigger when the phrase
     * matches, returning the report.
     *
     * @return void
     */
    public function testDeleteRunsCascadeWithCorrectPhrase(): void
    {
        [$controller, $request, , $deletion] = $this->build('alice');
        $request->method('getParam')->with('confirmation', '')->willReturn(GdprController::CONFIRMATION_PHRASE);

        $report = new DeletionReport();
        $report->secretsDeleted = 5;
        $deletion->expects($this->once())
            ->method('deleteAllFor')
            ->with('alice', 'in-app')
            ->willReturn($report);

        $response = $controller->deleteAccountData();
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($response->getData()['deleted']);
    }//end testDeleteRunsCascadeWithCorrectPhrase()
}//end class
