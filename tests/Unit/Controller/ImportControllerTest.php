<?php

/**
 * Unit tests for ImportController.
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

use OCA\Doriath\Controller\ImportController;
use OCA\Doriath\Exception\SuiteBlockedException;
use OCA\Doriath\Service\ImportService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the batch-import endpoint.
 */
class ImportControllerTest extends TestCase
{
    /**
     * Build the controller + mocked import service.
     *
     * @param string|null $userId The session user
     *
     * @return array{0:ImportController,1:ImportService}
     */
    private function build(?string $userId = 'alice'): array
    {
        $request = $this->createMock(IRequest::class);
        $session = $this->createMock(IUserSession::class);
        $service = $this->createMock(ImportService::class);

        if ($userId !== null) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($userId);
            $session->method('getUser')->willReturn($user);
        } else {
            $session->method('getUser')->willReturn(null);
        }

        return [new ImportController($request, $service, $session), $service];
    }

    /**
     * An anonymous request is unauthorized.
     *
     * @return void
     */
    public function testUnauthenticatedReturns401(): void
    {
        [$controller] = $this->build(userId: null);
        $response = $controller->batchCreate([['name' => 'X']]);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }

    /**
     * An empty body is a 400.
     *
     * @return void
     */
    public function testEmptyBodyReturns400(): void
    {
        [$controller] = $this->build();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $controller->batchCreate(null)->getStatus());
        $this->assertSame(Http::STATUS_BAD_REQUEST, $controller->batchCreate([])->getStatus());
    }

    /**
     * A chunk over the cap is a 413.
     *
     * @return void
     */
    public function testOverCapReturns413(): void
    {
        [$controller] = $this->build();
        $items    = array_fill(0, (ImportService::MAX_ITEMS + 1), ['name' => 'X']);
        $response = $controller->batchCreate($items);
        $this->assertSame(413, $response->getStatus());
    }

    /**
     * No active suite maps to 412.
     *
     * @return void
     */
    public function testNoActiveSuiteReturns412(): void
    {
        [$controller, $service] = $this->build();
        $service->method('commitChunk')->willThrowException(new SuiteBlockedException('no suite'));
        $response = $controller->batchCreate([['name' => 'X']]);
        $this->assertSame(412, $response->getStatus());
    }

    /**
     * A successful commit returns the per-index results with HTTP 200, and the
     * session user is forwarded (no owner param accepted from the body).
     *
     * @return void
     */
    public function testSuccessReturnsResultsWithSessionOwner(): void
    {
        [$controller, $service] = $this->build('alice');
        $service->expects($this->once())
            ->method('commitChunk')
            ->with($this->anything(), 'alice')
            ->willReturn(['results' => [['index' => 0, 'status' => 'created', 'secretId' => 's1']], 'foldersCreated' => []]);

        $response = $controller->batchCreate([['name' => 'X', 'key' => 'cipher', 'ownerId' => 'mallory']]);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('created', $data['results'][0]['status']);
    }
}
