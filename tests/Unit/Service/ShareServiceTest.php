<?php

/**
 * Unit tests for ShareService.
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

use InvalidArgumentException;
use OCA\Doriath\Db\ShareTarget;
use OCA\Doriath\Db\ShareTargetMapper;
use OCA\Doriath\Service\ShareService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ShareService.
 */
class ShareServiceTest extends TestCase
{
    /**
     * Service under test.
     *
     * @var ShareService
     */
    private ShareService $service;

    /**
     * Mock mapper.
     *
     * @var ShareTargetMapper
     */
    private ShareTargetMapper $mapper;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->mapper = $this->createMock(originalClassName: ShareTargetMapper::class);
        $logger       = $this->createMock(originalClassName: LoggerInterface::class);
        $this->service = new ShareService(mapper: $this->mapper, logger: $logger);
    }

    /**
     * Test createShare persists a new ShareTarget with a UUID and timestamps.
     *
     * @return void
     */
    public function testCreateShareInsertsRow(): void
    {
        $captured = null;
        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(
                static function (ShareTarget $entity) use (&$captured) {
                    $captured = $entity;
                    return $entity;
                }
            );

        $result = $this->service->createShare(
            sourceSecretId: 'src-1',
            targetUserId: 'bob',
            recipientSecretId: 'copy-1',
            groupShareId: null,
            userId: 'alice'
        );

        $this->assertSame($captured, $result);
        $this->assertSame('src-1', $result->getSourceSecretId());
        $this->assertSame('bob', $result->getTargetUserId());
        $this->assertSame('copy-1', $result->getSecretId());
        $this->assertSame('alice', $result->getCreatedBy());
        $this->assertNotSame('', $result->getId(), 'UUID should be generated');
        $this->assertNotNull($result->getCreatedAt());
    }

    /**
     * Test createShare rejects sharing with self.
     *
     * @return void
     */
    public function testCreateShareRejectsSelfShare(): void
    {
        $this->mapper->expects($this->never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot share a secret with yourself');

        $this->service->createShare(
            sourceSecretId: 'src-1',
            targetUserId: 'alice',
            recipientSecretId: 'copy-1',
            groupShareId: null,
            userId: 'alice'
        );
    }

    /**
     * Test createShare rejects empty source secret.
     *
     * @return void
     */
    public function testCreateShareRejectsEmptySource(): void
    {
        $this->mapper->expects($this->never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sourceSecretId is required');

        $this->service->createShare(
            sourceSecretId: '',
            targetUserId: 'bob',
            recipientSecretId: 'copy-1',
            groupShareId: null,
            userId: 'alice'
        );
    }

    /**
     * Test listSharesForSecret returns the mapper's result.
     *
     * @return void
     */
    public function testListSharesForSecretDelegatesToMapper(): void
    {
        $entity1 = new ShareTarget();
        $entity1->setId('st-1');
        $entity2 = new ShareTarget();
        $entity2->setId('st-2');

        $this->mapper->expects($this->once())
            ->method('findBySourceSecret')
            ->with('src-1')
            ->willReturn([$entity1, $entity2]);

        $result = $this->service->listSharesForSecret('src-1');

        $this->assertCount(2, $result);
        $this->assertSame('st-1', $result[0]->getId());
        $this->assertSame('st-2', $result[1]->getId());
    }

    /**
     * Test revokeShare deletes the row when caller is the creator.
     *
     * @return void
     */
    public function testRevokeShareDeletesWhenAuthorized(): void
    {
        $entity = new ShareTarget();
        $entity->setId('st-1');
        $entity->setSourceSecretId('src-1');
        $entity->setCreatedBy('alice');

        $this->mapper->expects($this->once())
            ->method('findById')
            ->with('st-1')
            ->willReturn($entity);

        $this->mapper->expects($this->once())
            ->method('delete')
            ->with($entity);

        $this->service->revokeShare(shareId: 'st-1', userId: 'alice');
    }

    /**
     * Test revokeShare rejects non-creator.
     *
     * @return void
     */
    public function testRevokeShareRejectsNonCreator(): void
    {
        $entity = new ShareTarget();
        $entity->setId('st-1');
        $entity->setCreatedBy('alice');

        $this->mapper->expects($this->once())
            ->method('findById')
            ->with('st-1')
            ->willReturn($entity);

        $this->mapper->expects($this->never())->method('delete');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Not authorized');

        $this->service->revokeShare(shareId: 'st-1', userId: 'mallory');
    }

    /**
     * Test revokeShare 404 when row is missing.
     *
     * @return void
     */
    public function testRevokeShareThrowsWhenNotFound(): void
    {
        $this->mapper->expects($this->once())
            ->method('findById')
            ->willThrowException(new DoesNotExistException('nope'));

        $this->mapper->expects($this->never())->method('delete');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Share not found');

        $this->service->revokeShare(shareId: 'missing', userId: 'alice');
    }
}
