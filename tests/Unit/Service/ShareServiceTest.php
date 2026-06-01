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
use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\SecretShare;
use OCA\Doriath\Db\SecretShareMapper;
use OCA\Doriath\Service\NotificationService;
use OCA\Doriath\Service\SecretCopyGateway;
use OCA\Doriath\Service\SecretOwnershipResolver;
use OCA\Doriath\Service\ShareService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for ShareService.
 */
class ShareServiceTest extends TestCase
{
    private ShareService $service;

    private SecretShareMapper $shareMapper;

    private SecretOwnershipResolver $ownership;

    private SecretCopyGateway $copyGateway;

    private NotificationService $notificationService;

    /**
     * Set up the service under test with mocked collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->shareMapper         = $this->createMock(SecretShareMapper::class);
        $this->ownership           = $this->createMock(SecretOwnershipResolver::class);
        $this->copyGateway         = $this->createMock(SecretCopyGateway::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $db                        = $this->createMock(IDBConnection::class);

        $this->service = new ShareService(
            $this->shareMapper,
            $this->ownership,
            $this->copyGateway,
            $this->notificationService,
            $db,
        );
    }//end setUp()

    /**
     * createShare succeeds for an authorized owner and recipient with a suite.
     *
     * @return void
     */
    public function testCreateShareSucceeds(): void
    {
        $suite = new EncryptionSuite();
        $suite->setId('suite-1');

        $this->ownership->method('canManageShares')->willReturn(true);
        $this->ownership->method('getActiveSuiteForUser')->willReturn($suite);
        $this->shareMapper->method('findBySourceSecretAndTargetUser')->willReturn(null);
        $this->copyGateway->method('createCopy')->willReturn('copy-1');
        $this->shareMapper->expects($this->once())->method('insert')->willReturnArgument(0);
        $this->notificationService->expects($this->once())->method('notify');

        $share = $this->service->createShare('secret-1', 'bob', ['name' => 'GitHub'], 'alice');

        $this->assertSame('secret-1', $share->getSourceSecretId());
        $this->assertSame('bob', $share->getTargetUserId());
        $this->assertSame('copy-1', $share->getSecretId());
    }//end testCreateShareSucceeds()

    /**
     * createShare rejects sharing with self.
     *
     * @return void
     */
    public function testCreateShareWithSelfThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->createShare('secret-1', 'alice', [], 'alice');
    }//end testCreateShareWithSelfThrows()

    /**
     * createShare rejects a recipient without an active suite.
     *
     * @return void
     */
    public function testCreateShareNoSuiteThrows(): void
    {
        $this->ownership->method('canManageShares')->willReturn(true);
        $this->ownership->method('getActiveSuiteForUser')->willReturn(null);

        $this->expectException(RuntimeException::class);
        $this->service->createShare('secret-1', 'bob', [], 'alice');
    }//end testCreateShareNoSuiteThrows()

    /**
     * createShare rejects an unauthorized caller.
     *
     * @return void
     */
    public function testCreateShareUnauthorizedThrows(): void
    {
        $this->ownership->method('canManageShares')->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->service->createShare('secret-1', 'bob', [], 'mallory');
    }//end testCreateShareUnauthorizedThrows()

    /**
     * getSharesForSecret returns the full list for an owner.
     *
     * @return void
     */
    public function testGetSharesForSecretOwnerSeesAll(): void
    {
        $this->ownership->method('canManageShares')->willReturn(true);
        $share = new SecretShare();
        $share->setTargetUserId('bob');
        $this->shareMapper->method('findBySourceSecret')->willReturn([$share]);

        $result = $this->service->getSharesForSecret('secret-1', 'alice');
        $this->assertCount(1, $result);
    }//end testGetSharesForSecretOwnerSeesAll()

    /**
     * getSharesForSecret returns an empty list for a non-owner recipient.
     *
     * @return void
     */
    public function testGetSharesForSecretRecipientSeesEmpty(): void
    {
        $this->ownership->method('canManageShares')->willReturn(false);

        $result = $this->service->getSharesForSecret('secret-1', 'bob');
        $this->assertCount(0, $result);
    }//end testGetSharesForSecretRecipientSeesEmpty()

    /**
     * revokeShare deletes the copy and the share record for an owner.
     *
     * @return void
     */
    public function testRevokeShareSucceeds(): void
    {
        $share = new SecretShare();
        $share->setId('share-1');
        $share->setSourceSecretId('secret-1');
        $share->setSecretId('copy-1');

        $this->shareMapper->method('findById')->willReturn($share);
        $this->ownership->method('canManageShares')->willReturn(true);
        $this->copyGateway->expects($this->once())->method('deleteCopy')->with('copy-1');
        $this->shareMapper->expects($this->once())->method('delete')->with($share);

        $this->service->revokeShare('share-1', 'alice');
    }//end testRevokeShareSucceeds()

    /**
     * submitShareRequest rejects a caller who holds no share of the secret.
     *
     * @return void
     */
    public function testSubmitShareRequestNonRecipientThrows(): void
    {
        $this->shareMapper->method('findBySourceSecretAndTargetUser')->willReturn(null);

        $this->expectException(RuntimeException::class);
        $this->service->submitShareRequest('secret-1', 'carol', 'mallory');
    }//end testSubmitShareRequestNonRecipientThrows()
}//end class
