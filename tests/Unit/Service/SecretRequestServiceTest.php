<?php

/**
 * Unit tests for SecretRequestService.
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Service
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

namespace OCA\Doriath\Tests\Unit\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Doriath\Db\SecretRequest;
use OCA\Doriath\Db\SecretRequestMapper;
use OCA\Doriath\Service\SecretRequestService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SecretRequestService.
 */
class SecretRequestServiceTest extends TestCase
{
    /**
     * Service under test.
     *
     * @var SecretRequestService
     */
    private SecretRequestService $service;

    /**
     * Mock mapper.
     *
     * @var SecretRequestMapper
     */
    private SecretRequestMapper $mapper;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->mapper = $this->createMock(originalClassName: SecretRequestMapper::class);
        $logger       = $this->createMock(originalClassName: LoggerInterface::class);
        $this->service = new SecretRequestService(mapper: $this->mapper, logger: $logger);
    }

    /**
     * Test create generates a token and inserts a pending request.
     *
     * @return void
     */
    public function testCreateGeneratesTokenAndPersists(): void
    {
        $captured = null;
        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(
                static function (SecretRequest $entity) use (&$captured) {
                    $captured = $entity;
                    return $entity;
                }
            );

        $result = $this->service->create(
            secretId: 'sec-1',
            encryptionSuiteId: 'suite-1',
            requestedFields: ['password', 'login'],
            isReRequest: false,
            expiresAt: null,
            userId: 'alice'
        );

        $this->assertSame($captured, $result);
        $this->assertSame('sec-1', $result->getSecretId());
        $this->assertSame('suite-1', $result->getEncryptionSuiteId());
        $this->assertSame(SecretRequest::STATUS_PENDING, $result->getStatus());
        $this->assertFalse($result->getIsReRequest());
        $this->assertSame('["password","login"]', $result->getRequestedFields());
        $this->assertSame(32, strlen($result->getToken()), '32-char hex token expected');
        $this->assertSame('alice', $result->getCreatedBy());
        $this->assertNotNull($result->getCreatedAt());
    }

    /**
     * Test create rejects empty requestedFields.
     *
     * @return void
     */
    public function testCreateRejectsEmptyFields(): void
    {
        $this->mapper->expects($this->never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requestedFields cannot be empty');

        $this->service->create(
            secretId: 'sec-1',
            encryptionSuiteId: 'suite-1',
            requestedFields: [],
            isReRequest: false,
            expiresAt: null,
            userId: 'alice'
        );
    }

    /**
     * Test approve flips a pending request to fulfilled and sets the timestamp.
     *
     * @return void
     */
    public function testApprovePendingMarksFulfilled(): void
    {
        $entity = new SecretRequest();
        $entity->setId('req-1');
        $entity->setSecretId('sec-1');
        $entity->setStatus(SecretRequest::STATUS_PENDING);
        $entity->setCreatedBy('alice');

        $this->mapper->expects($this->once())
            ->method('findById')
            ->with('req-1')
            ->willReturn($entity);

        $this->mapper->expects($this->once())
            ->method('update')
            ->willReturnArgument(0);

        $result = $this->service->approve(requestId: 'req-1', userId: 'alice');

        $this->assertSame(SecretRequest::STATUS_FULFILLED, $result->getStatus());
        $this->assertNotNull($result->getFulfilledAt());
    }

    /**
     * Test approve rejects requests created by someone else.
     *
     * @return void
     */
    public function testApproveRejectsNonOwner(): void
    {
        $entity = new SecretRequest();
        $entity->setId('req-1');
        $entity->setStatus(SecretRequest::STATUS_PENDING);
        $entity->setCreatedBy('alice');

        $this->mapper->expects($this->once())
            ->method('findById')
            ->willReturn($entity);

        $this->mapper->expects($this->never())->method('update');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Not authorized');

        $this->service->approve(requestId: 'req-1', userId: 'mallory');
    }

    /**
     * Test approve rejects expired requests.
     *
     * @return void
     */
    public function testApproveRejectsExpired(): void
    {
        $entity = new SecretRequest();
        $entity->setId('req-1');
        $entity->setStatus(SecretRequest::STATUS_PENDING);
        $entity->setCreatedBy('alice');
        $entity->setExpiresAt(new DateTime('2000-01-01T00:00:00+00:00'));

        $this->mapper->expects($this->once())
            ->method('findById')
            ->willReturn($entity);

        $this->mapper->expects($this->never())->method('update');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Request has expired');

        $this->service->approve(requestId: 'req-1', userId: 'alice');
    }

    /**
     * Test approve rejects requests that are not pending.
     *
     * @return void
     */
    public function testApproveRejectsAlreadyFulfilled(): void
    {
        $entity = new SecretRequest();
        $entity->setId('req-1');
        $entity->setStatus(SecretRequest::STATUS_FULFILLED);
        $entity->setCreatedBy('alice');

        $this->mapper->expects($this->once())
            ->method('findById')
            ->willReturn($entity);

        $this->mapper->expects($this->never())->method('update');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not pending');

        $this->service->approve(requestId: 'req-1', userId: 'alice');
    }

    /**
     * Test decline flips a pending request to declined.
     *
     * @return void
     */
    public function testDeclinePendingMarksDeclined(): void
    {
        $entity = new SecretRequest();
        $entity->setId('req-1');
        $entity->setStatus(SecretRequest::STATUS_PENDING);
        $entity->setCreatedBy('alice');

        $this->mapper->expects($this->once())
            ->method('findById')
            ->willReturn($entity);

        $this->mapper->expects($this->once())
            ->method('update')
            ->willReturnArgument(0);

        $result = $this->service->decline(requestId: 'req-1', userId: 'alice');

        $this->assertSame(SecretRequest::STATUS_DECLINED, $result->getStatus());
    }

    /**
     * Test 404 when the request does not exist.
     *
     * @return void
     */
    public function testApproveThrowsWhenNotFound(): void
    {
        $this->mapper->expects($this->once())
            ->method('findById')
            ->willThrowException(new DoesNotExistException('nope'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Request not found');

        $this->service->approve(requestId: 'missing', userId: 'alice');
    }
}
