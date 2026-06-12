<?php

/**
 * Unit tests for ShareRequestService.
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
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\ShareTarget;
use OCA\Doriath\Db\ShareTargetMapper;
use OCA\Doriath\Service\NotificationService;
use OCA\Doriath\Service\ShareRequestService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ShareRequestService.
 */
class ShareRequestServiceTest extends TestCase
{
    /**
     * Service under test.
     *
     * @var ShareRequestService
     */
    private ShareRequestService $service;

    /**
     * @var ShareTargetMapper&MockObject
     */
    private ShareTargetMapper $shareTargetMapper;

    /**
     * @var SecretMapper&MockObject
     */
    private SecretMapper $secretMapper;

    /**
     * @var NotificationService&MockObject
     */
    private NotificationService $notificationService;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->shareTargetMapper   = $this->createMock(originalClassName: ShareTargetMapper::class);
        $this->secretMapper        = $this->createMock(originalClassName: SecretMapper::class);
        $this->notificationService = $this->createMock(originalClassName: NotificationService::class);
        $logger                    = $this->createMock(originalClassName: LoggerInterface::class);

        $this->service = new ShareRequestService(
            shareTargetMapper: $this->shareTargetMapper,
            secretMapper: $this->secretMapper,
            notificationService: $this->notificationService,
            logger: $logger
        );
    }

    /**
     * Helper: build an owner Secret.
     *
     * @param string $id      Secret ID
     * @param string $ownerId Owner UID
     *
     * @return Secret
     */
    private function makeOwnerSecret(string $id, string $ownerId): Secret
    {
        $secret = new Secret();
        $secret->setId($id);
        $secret->setOwnerType('user');
        $secret->setOwnerId($ownerId);
        $secret->setName('demo');
        return $secret;
    }

    /**
     * Test submitShareRequest fires a notification at the owner when the
     * requester holds a share.
     *
     * @return void
     */
    public function testSubmitShareRequestFiresOwnerNotification(): void
    {
        $secret = $this->makeOwnerSecret('src-1', 'alice');
        $this->secretMapper->method('findById')->willReturn($secret);
        $this->shareTargetMapper->method('findBySourceSecretAndTargetUser')
            ->willReturn(new ShareTarget());

        $this->notificationService->expects($this->once())
            ->method('notify')
            ->with('share_request', 'alice');

        $this->service->submitShareRequest(
            sourceSecretId: 'src-1',
            targetUserId: 'carol',
            requesterId: 'bob'
        );
    }

    /**
     * Test submitShareRequest rejects requester who does not hold a share.
     *
     * @return void
     */
    public function testSubmitShareRequestRejectsNonRecipient(): void
    {
        $secret = $this->makeOwnerSecret('src-1', 'alice');
        $this->secretMapper->method('findById')->willReturn($secret);
        $this->shareTargetMapper->method('findBySourceSecretAndTargetUser')
            ->willThrowException(new DoesNotExistException('no'));

        $this->notificationService->expects($this->never())->method('notify');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('existing share recipients');

        $this->service->submitShareRequest(
            sourceSecretId: 'src-1',
            targetUserId: 'carol',
            requesterId: 'mallory'
        );
    }

    /**
     * Test submitShareRequest rejects when the requester IS the owner.
     *
     * @return void
     */
    public function testSubmitShareRequestRejectsOwnerSubmitter(): void
    {
        $secret = $this->makeOwnerSecret('src-1', 'alice');
        $this->secretMapper->method('findById')->willReturn($secret);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Owners share directly');

        $this->service->submitShareRequest(
            sourceSecretId: 'src-1',
            targetUserId: 'carol',
            requesterId: 'alice'
        );
    }

    /**
     * Test submitShareRequest rejects requesting to share with owner.
     *
     * @return void
     */
    public function testSubmitShareRequestRejectsTargetOwner(): void
    {
        $secret = $this->makeOwnerSecret('src-1', 'alice');
        $this->secretMapper->method('findById')->willReturn($secret);
        $this->shareTargetMapper->method('findBySourceSecretAndTargetUser')
            ->willReturn(new ShareTarget());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('with the secret owner');

        $this->service->submitShareRequest(
            sourceSecretId: 'src-1',
            targetUserId: 'alice',
            requesterId: 'bob'
        );
    }

    /**
     * Test approveShareRequest returns the parameters for the share flow.
     *
     * @return void
     */
    public function testApproveShareRequestReturnsParameters(): void
    {
        $secret = $this->makeOwnerSecret('src-1', 'alice');
        $this->secretMapper->method('findById')->willReturn($secret);

        $result = $this->service->approveShareRequest(
            params: [
                'sourceSecretId' => 'src-1',
                'requesterId'    => 'bob',
                'targetUserId'   => 'carol',
            ],
            ownerId: 'alice'
        );

        $this->assertSame('src-1', $result['sourceSecretId']);
        $this->assertSame('bob', $result['requesterId']);
        $this->assertSame('carol', $result['targetUserId']);
    }

    /**
     * Test approveShareRequest rejects non-owner.
     *
     * @return void
     */
    public function testApproveShareRequestRejectsNonOwner(): void
    {
        $secret = $this->makeOwnerSecret('src-1', 'alice');
        $this->secretMapper->method('findById')->willReturn($secret);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Not authorized');

        $this->service->approveShareRequest(
            params: [
                'sourceSecretId' => 'src-1',
                'requesterId'    => 'bob',
                'targetUserId'   => 'carol',
            ],
            ownerId: 'mallory'
        );
    }

    /**
     * Test denyShareRequest fires a result notification at the requester.
     *
     * @return void
     */
    public function testDenyShareRequestNotifiesRequester(): void
    {
        $secret = $this->makeOwnerSecret('src-1', 'alice');
        $this->secretMapper->method('findById')->willReturn($secret);

        $this->notificationService->expects($this->once())
            ->method('notify')
            ->with('share_request_result', 'bob');

        $this->service->denyShareRequest(
            params: [
                'sourceSecretId' => 'src-1',
                'requesterId'    => 'bob',
                'targetUserId'   => 'carol',
            ],
            ownerId: 'alice'
        );
    }

    /**
     * Test denyShareRequest rejects non-owner.
     *
     * @return void
     */
    public function testDenyShareRequestRejectsNonOwner(): void
    {
        $secret = $this->makeOwnerSecret('src-1', 'alice');
        $this->secretMapper->method('findById')->willReturn($secret);

        $this->notificationService->expects($this->never())->method('notify');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Not authorized');

        $this->service->denyShareRequest(
            params: [
                'sourceSecretId' => 'src-1',
                'requesterId'    => 'bob',
                'targetUserId'   => 'carol',
            ],
            ownerId: 'mallory'
        );
    }
}
