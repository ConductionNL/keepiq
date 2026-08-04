<?php

/**
 * Unit tests for SecretRequestFillController.
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

use DateTime;
use InvalidArgumentException;
use OCA\Doriath\Controller\SecretRequestFillController;
use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\SecretRequest;
use OCA\Doriath\Service\EncryptionSuiteService;
use OCA\Doriath\Service\SecretRequestService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for SecretRequestFillController.
 *
 * @spec openspec/changes/implement-secret-requests/tasks.md#12.2
 */
class SecretRequestFillControllerTest extends TestCase
{

    /**
     * The controller under test.
     *
     * @var SecretRequestFillController
     */
    private SecretRequestFillController $controller;

    /**
     * The mocked secret-request service.
     *
     * @var SecretRequestService&MockObject
     */
    private SecretRequestService&MockObject $secretRequestService;

    /**
     * The mocked encryption-suite service.
     *
     * @var EncryptionSuiteService&MockObject
     */
    private EncryptionSuiteService&MockObject $encryptionSuiteService;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $request = $this->createMock(originalClassName: IRequest::class);
        $this->secretRequestService   = $this->createMock(originalClassName: SecretRequestService::class);
        $this->encryptionSuiteService = $this->createMock(originalClassName: EncryptionSuiteService::class);

        $this->controller = new SecretRequestFillController(
            request: $request,
            secretRequestService: $this->secretRequestService,
            suiteService: $this->encryptionSuiteService,
        );

    }//end setUp()

    /**
     * Build a SecretRequest entity for the assertions below.
     *
     * @param string $status The status string.
     *
     * @return SecretRequest
     */
    private function makeRequest(string $status=SecretRequest::STATUS_PENDING): SecretRequest
    {
        $entity = new SecretRequest();
        $entity->setId('req-1');
        $entity->setSecretId('sec-1');
        $entity->setEncryptionSuiteId('suite-1');
        $entity->setToken('tok-1');
        $entity->setRequestedFields(json_encode(['key', 'login']));
        $entity->setStatus($status);
        $entity->setIsReRequest(false);
        $entity->setCreatedBy('alice');
        $entity->setCreatedAt(new DateTime('2026-01-01T00:00:00Z'));
        return $entity;

    }//end makeRequest()

    /**
     * Build an EncryptionSuite entity with a deterministic certificate.
     *
     * @return EncryptionSuite
     */
    private function makeSuite(): EncryptionSuite
    {
        $suite = new EncryptionSuite();
        $suite->setId('suite-1');
        $suite->setCertificate("-----BEGIN CERTIFICATE-----\nABC\n-----END CERTIFICATE-----");
        return $suite;

    }//end makeSuite()

    /**
     * Show returns the certificate, requested fields, and status.
     *
     * @return void
     */
    public function testShowReturnsTheCertificateAndRequestedFields(): void
    {
        $this->secretRequestService->expects($this->once())
            ->method('getByToken')->with('tok-1')->willReturn($this->makeRequest());
        $this->encryptionSuiteService->expects($this->once())
            ->method('getSuite')->with(id: 'suite-1')->willReturn($this->makeSuite());

        $response = $this->controller->show(token: 'tok-1');
        $data     = $response->getData();
        $this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
        $this->assertSame(expected: 'tok-1', actual: $data['token']);
        $this->assertSame(expected: ['key', 'login'], actual: $data['requested_fields']);
        $this->assertStringContainsString(needle: 'BEGIN CERTIFICATE', haystack: $data['public_certificate']);
        $this->assertSame(expected: 'pending', actual: $data['status']);

    }//end testShowReturnsTheCertificateAndRequestedFields()

    /**
     * Show propagates the InvalidArgumentException HTTP code (e.g. 410 Gone).
     *
     * @return void
     */
    public function testShowReturnsTheCodedErrorForExpiredOrFulfilled(): void
    {
        $this->secretRequestService->expects($this->once())
            ->method('getByToken')->willThrowException(
                new InvalidArgumentException(message: 'Request expired', code: Http::STATUS_GONE)
            );

        $response = $this->controller->show(token: 'tok-1');
        $this->assertSame(expected: Http::STATUS_GONE, actual: $response->getStatus());
        $this->assertSame(expected: 'Request expired', actual: $response->getData()['message']);

    }//end testShowReturnsTheCodedErrorForExpiredOrFulfilled()

    /**
     * Show falls back to a uniform 404 on any generic throwable.
     *
     * @return void
     */
    public function testShowFallsBackTo404WhenTheServiceCannotResolve(): void
    {
        $this->secretRequestService->expects($this->once())
            ->method('getByToken')->willThrowException(new RuntimeException(message: 'boom'));

        $response = $this->controller->show(token: 'tok-1');
        $this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());

    }//end testShowFallsBackTo404WhenTheServiceCannotResolve()

    /**
     * Show surfaces a 503 when the recipient's suite lookup fails.
     *
     * @return void
     */
    public function testShowSurfaces503WhenTheSuiteLookupFails(): void
    {
        $this->secretRequestService->expects($this->once())
            ->method('getByToken')->willReturn($this->makeRequest());
        $this->encryptionSuiteService->expects($this->once())
            ->method('getSuite')->willThrowException(new RuntimeException(message: 'no suite'));

        $response = $this->controller->show(token: 'tok-1');
        $this->assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $response->getStatus());

    }//end testShowSurfaces503WhenTheSuiteLookupFails()

    /**
     * Fill forwards the encrypted field map verbatim to the service.
     *
     * @return void
     */
    public function testFillForwardsTheEncryptedFieldsToTheService(): void
    {
        $filled = $this->makeRequest(status: SecretRequest::STATUS_FULFILLED);
        $filled->setFulfilledAt(new DateTime('2026-01-02T00:00:00Z'));
        $this->secretRequestService->expects($this->once())
            ->method('fill')
            ->with(token: 'tok-1', encryptedFields: ['key' => 'BLOBA', 'login' => 'BLOBB'])
            ->willReturn($filled);

        $response = $this->controller->fill(
            token: 'tok-1',
            encryptedFields: ['key' => 'BLOBA', 'login' => 'BLOBB']
        );
        $this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
        $this->assertSame(expected: 'fulfilled', actual: $response->getData()['status']);

    }//end testFillForwardsTheEncryptedFieldsToTheService()

    /**
     * Fill returns the coded validation error from the service.
     *
     * @return void
     */
    public function testFillReturnsTheCodedErrorForValidationFailure(): void
    {
        $this->secretRequestService->expects($this->once())
            ->method('fill')
            ->willThrowException(
                new InvalidArgumentException(message: 'Missing required field: key', code: Http::STATUS_BAD_REQUEST)
            );

        // Pass a non-empty body so the request reaches the service — the
        // controller short-circuits an empty/missing body with its own
        // 'encryptedFields is required' guard before calling fill().
        $response = $this->controller->fill(token: 'tok-1', encryptedFields: ['key' => 'CIPHER']);
        $this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
        $this->assertSame(expected: 'Missing required field: key', actual: $response->getData()['message']);

    }//end testFillReturnsTheCodedErrorForValidationFailure()

    /**
     * Fill returns a 500 on any non-InvalidArgumentException failure.
     *
     * @return void
     */
    public function testFillReturns500OnGenericFailure(): void
    {
        $this->secretRequestService->expects($this->once())
            ->method('fill')
            ->willThrowException(new RuntimeException(message: 'boom'));

        $response = $this->controller->fill(token: 'tok-1', encryptedFields: ['key' => 'B']);
        $this->assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $response->getStatus());

    }//end testFillReturns500OnGenericFailure()
}//end class
