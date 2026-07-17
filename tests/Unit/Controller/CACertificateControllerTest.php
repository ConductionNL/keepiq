<?php

/**
 * Unit tests for CACertificateController.
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

use OCA\Doriath\Controller\CACertificateController;
use OCA\Doriath\Service\CertificateAuthorityService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for CACertificateController.
 */
class CACertificateControllerTest extends TestCase
{

    private CACertificateController $controller;

    private CertificateAuthorityService&MockObject $caService;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $request         = $this->createMock(IRequest::class);
        $this->caService = $this->createMock(CertificateAuthorityService::class);

        $this->controller = new CACertificateController(
            $request,
            $this->caService,
        );
    }//end setUp()

    /**
     * Test getStatus returns CA status.
     *
     * @return void
     */
    public function testGetStatusReturnsCaStatus(): void
    {
        $statusData = [
            'status'       => 'healthy',
            'root'         => ['id' => 'root-1', 'type' => 'root'],
            'intermediate' => ['id' => 'int-1', 'type' => 'intermediate'],
        ];

        $this->caService->method('getStatus')
            ->willReturn($statusData);

        $response = $this->controller->getStatus();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('healthy', $response->getData()['status']);
    }//end testGetStatusReturnsCaStatus()

    /**
     * Test retryBootstrap delegates and returns status.
     *
     * @return void
     */
    public function testRetryBootstrapReturnsStatus(): void
    {
        $statusData = [
            'status'       => 'healthy',
            'root'         => ['id' => 'root-1'],
            'intermediate' => ['id' => 'int-1'],
        ];

        $this->caService->expects($this->once())->method('retryBootstrap');
        $this->caService->method('getStatus')->willReturn($statusData);

        $response = $this->controller->retryBootstrap();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('healthy', $response->getData()['status']);
    }//end testRetryBootstrapReturnsStatus()

    /**
     * Test retryBootstrap returns 500 on failure.
     *
     * @return void
     */
    public function testRetryBootstrapReturns500OnFailure(): void
    {
        $this->caService->method('retryBootstrap')
            ->willThrowException(new RuntimeException('Bootstrap failed'));

        $response = $this->controller->retryBootstrap();

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $this->assertSame('Bootstrap failed', $response->getData()['message']);
    }//end testRetryBootstrapReturns500OnFailure()

    /**
     * Test renewIntermediate returns count and status.
     *
     * @return void
     */
    public function testRenewIntermediateReturnsCountAndStatus(): void
    {
        $this->caService->method('renewIntermediate')
            ->with(true)
            ->willReturn(5);

        $statusData = [
            'status'       => 'healthy',
            'root'         => ['id' => 'root-1'],
            'intermediate' => ['id' => 'int-2'],
        ];
        $this->caService->method('getStatus')->willReturn($statusData);

        $response = $this->controller->renewIntermediate();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame(5, $data['resignedCount']);
        $this->assertArrayHasKey('status', $data);
        $this->assertStringContainsString('5', $data['message']);
    }//end testRenewIntermediateReturnsCountAndStatus()

    /**
     * Test renewIntermediate returns 500 on failure.
     *
     * @return void
     */
    public function testRenewIntermediateReturns500OnFailure(): void
    {
        $this->caService->method('renewIntermediate')
            ->willThrowException(new RuntimeException('Renew failed'));

        $response = $this->controller->renewIntermediate();

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
    }//end testRenewIntermediateReturns500OnFailure()

    /**
     * Test renewRoot returns count and status.
     *
     * @return void
     */
    public function testRenewRootReturnsCountAndStatus(): void
    {
        $this->caService->method('renewRoot')->willReturn(10);

        $statusData = [
            'status'       => 'healthy',
            'root'         => ['id' => 'root-2'],
            'intermediate' => ['id' => 'int-3'],
        ];
        $this->caService->method('getStatus')->willReturn($statusData);

        $response = $this->controller->renewRoot();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame(10, $data['resignedCount']);
        $this->assertArrayHasKey('status', $data);
    }//end testRenewRootReturnsCountAndStatus()

    /**
     * Test renewRoot returns 500 on failure.
     *
     * @return void
     */
    public function testRenewRootReturns500OnFailure(): void
    {
        $this->caService->method('renewRoot')
            ->willThrowException(new RuntimeException('Root renew failed'));

        $response = $this->controller->renewRoot();

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
    }//end testRenewRootReturns500OnFailure()
}//end class
