<?php

declare(strict_types=1);

namespace OCA\Doriath\Tests\Unit\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\LinkShare;
use OCA\Doriath\Db\LinkShareMapper;
use OCA\Doriath\Service\EncryptionSuiteService;
use OCA\Doriath\Service\LinkShareService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LinkShareServiceTest extends TestCase
{
    private LinkShareService $service;
    private LinkShareMapper $mapper;
    private EncryptionSuiteService $suiteService;

    protected function setUp(): void
    {
        $this->mapper = $this->createMock(LinkShareMapper::class);
        $this->suiteService = $this->createMock(EncryptionSuiteService::class);
        $logger = $this->createMock(LoggerInterface::class);

        $suite = new EncryptionSuite();
        $suite->setId('suite-1');
        $this->suiteService->method('getActiveSuite')->willReturn($suite);

        $this->service = new LinkShareService(
            $this->mapper,
            $this->suiteService,
            $logger,
        );
    }

    public function testCreateGeneratesTokenAndStoresSuite(): void
    {
        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(static fn (LinkShare $ls): LinkShare => $ls);

        $result = $this->service->create(
            'secret-1',
            'base64blob',
            'base64salt',
            3,
            null,
            'alice',
        );

        $this->assertSame('secret-1', $result->getSecretId());
        $this->assertSame(3, $result->getUsageLimit());
        $this->assertSame(0, $result->getUsageCount());
        $this->assertSame('alice', $result->getCreatedBy());
        $this->assertSame('suite-1', $result->getEncryptionSuiteId());
        // 16 random bytes -> 32 hex chars (128 bits of entropy).
        $this->assertSame(32, strlen($result->getToken()));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $result->getToken());
    }

    public function testCreateDefaultsToUsageLimitOne(): void
    {
        $this->mapper->method('insert')->willReturnCallback(static fn (LinkShare $ls): LinkShare => $ls);

        $result = $this->service->create('secret-1', 'blob', 'salt', 1, null, 'alice');

        $this->assertSame(1, $result->getUsageLimit());
    }

    /**
     * @dataProvider invalidUsageLimitProvider
     */
    public function testCreateRejectsInvalidUsageLimit(int $usageLimit): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->create('secret-1', 'blob', 'salt', $usageLimit, null, 'alice');
    }

    public static function invalidUsageLimitProvider(): array
    {
        return [
            'zero'        => [0],
            'eleven'      => [11],
            'negative'    => [-1],
            'one hundred' => [100],
        ];
    }

    public function testCreateRejectsEmptySnapshot(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->create('secret-1', '', 'salt', 1, null, 'alice');
    }

    public function testGetByTokenReturnsValidShare(): void
    {
        $share = $this->makeShare(usageLimit: 3, usageCount: 0, failedAttempts: 0, blobFetched: false);
        $this->mapper->method('findByToken')->willReturn($share);
        $this->mapper->expects($this->once())->method('update');

        $result = $this->service->getByToken('tok');

        $this->assertSame('ls-1', $result->getId());
        $this->assertTrue($result->getBlobFetched());
    }

    public function testGetByTokenThrowsWhenMissing(): void
    {
        $this->mapper->method('findByToken')->willThrowException(new DoesNotExistException('x'));
        $this->expectException(DoesNotExistException::class);
        $this->service->getByToken('nope');
    }

    public function testGetByTokenThrowsWhenUsageExhausted(): void
    {
        $share = $this->makeShare(usageLimit: 2, usageCount: 2, failedAttempts: 0, blobFetched: false);
        $this->mapper->method('findByToken')->willReturn($share);
        $this->expectException(DoesNotExistException::class);
        $this->service->getByToken('tok');
    }

    public function testGetByTokenThrowsAndDeletesWhenExpired(): void
    {
        $share = $this->makeShare(usageLimit: 3, usageCount: 0, failedAttempts: 0, blobFetched: false);
        $share->setExpiresAt(new DateTime('-1 hour'));
        $this->mapper->method('findByToken')->willReturn($share);
        $this->mapper->expects($this->once())->method('delete');

        $this->expectException(DoesNotExistException::class);
        $this->service->getByToken('tok');
    }

    public function testGetByTokenIncrementsFailedAttemptsOnRepeatFetch(): void
    {
        $share = $this->makeShare(usageLimit: 3, usageCount: 0, failedAttempts: 2, blobFetched: true);
        $this->mapper->method('findByToken')->willReturn($share);
        $this->mapper->expects($this->once())->method('update');

        $result = $this->service->getByToken('tok');

        $this->assertSame(3, $result->getFailedAttempts());
    }

    public function testGetByTokenDeletesAtBruteForceThreshold(): void
    {
        // failedAttempts 4, blobFetched true -> next fetch increments to 5 and deletes.
        $share = $this->makeShare(usageLimit: 3, usageCount: 0, failedAttempts: 4, blobFetched: true);
        $this->mapper->method('findByToken')->willReturn($share);
        $this->mapper->expects($this->once())->method('delete');

        $this->expectException(DoesNotExistException::class);
        $this->service->getByToken('tok');
    }

    public function testConfirmAccessIncrementsAndKeepsShare(): void
    {
        $share = $this->makeShare(usageLimit: 3, usageCount: 0, failedAttempts: 0, blobFetched: true);
        $refreshed = $this->makeShare(usageLimit: 3, usageCount: 1, failedAttempts: 0, blobFetched: false);

        $this->mapper->method('findByToken')->willReturn($share);
        $this->mapper->method('incrementUsageCountIfBelowLimit')->willReturn(1);
        $this->mapper->method('findById')->willReturn($refreshed);
        $this->mapper->expects($this->never())->method('delete');

        $result = $this->service->confirmAccess('tok');

        $this->assertSame(1, $result->getUsageCount());
    }

    public function testConfirmAccessDeletesWhenLimitReached(): void
    {
        $share = $this->makeShare(usageLimit: 1, usageCount: 0, failedAttempts: 0, blobFetched: true);
        $refreshed = $this->makeShare(usageLimit: 1, usageCount: 1, failedAttempts: 0, blobFetched: false);

        $this->mapper->method('findByToken')->willReturn($share);
        $this->mapper->method('incrementUsageCountIfBelowLimit')->willReturn(1);
        $this->mapper->method('findById')->willReturn($refreshed);
        $this->mapper->expects($this->once())->method('delete');

        $this->service->confirmAccess('tok');
    }

    public function testConfirmAccessThrowsWhenAlreadyExhausted(): void
    {
        $share = $this->makeShare(usageLimit: 1, usageCount: 1, failedAttempts: 0, blobFetched: true);
        $this->mapper->method('findByToken')->willReturn($share);
        $this->mapper->method('incrementUsageCountIfBelowLimit')->willReturn(0);

        $this->expectException(DoesNotExistException::class);
        $this->service->confirmAccess('tok');
    }

    public function testRecordFailedAttemptIncrements(): void
    {
        $share = $this->makeShare(usageLimit: 3, usageCount: 0, failedAttempts: 1, blobFetched: true);
        $this->mapper->method('findByToken')->willReturn($share);
        $this->mapper->expects($this->once())->method('update');
        $this->mapper->expects($this->never())->method('delete');

        $this->service->recordFailedAttempt('tok');

        $this->assertSame(2, $share->getFailedAttempts());
    }

    public function testRecordFailedAttemptDeletesAtThreshold(): void
    {
        $share = $this->makeShare(usageLimit: 3, usageCount: 0, failedAttempts: 4, blobFetched: true);
        $this->mapper->method('findByToken')->willReturn($share);
        $this->mapper->expects($this->once())->method('delete');

        $this->service->recordFailedAttempt('tok');
    }

    public function testDeleteRejectsNonOwner(): void
    {
        $share = $this->makeShare(usageLimit: 3, usageCount: 0, failedAttempts: 0, blobFetched: false);
        $share->setCreatedBy('alice');
        $this->mapper->method('findById')->willReturn($share);
        $this->mapper->expects($this->never())->method('delete');

        $this->expectException(InvalidArgumentException::class);
        $this->service->delete('ls-1', 'mallory');
    }

    public function testDeleteAllowsOwner(): void
    {
        $share = $this->makeShare(usageLimit: 3, usageCount: 0, failedAttempts: 0, blobFetched: false);
        $share->setCreatedBy('alice');
        $this->mapper->method('findById')->willReturn($share);
        $this->mapper->expects($this->once())->method('delete');

        $this->service->delete('ls-1', 'alice');
    }

    public function testListBySecretFiltersToOwner(): void
    {
        $mine = $this->makeShare(usageLimit: 3, usageCount: 0, failedAttempts: 0, blobFetched: false);
        $mine->setCreatedBy('alice');
        $other = $this->makeShare(usageLimit: 3, usageCount: 0, failedAttempts: 0, blobFetched: false);
        $other->setCreatedBy('bob');

        $this->mapper->method('findBySecretId')->willReturn([$mine, $other]);

        $result = $this->service->listBySecret('secret-1', 'alice');

        $this->assertCount(1, $result);
        $this->assertSame('alice', $result[0]->getCreatedBy());
    }

    public function testDeleteBySecretIdCascades(): void
    {
        $this->mapper->expects($this->once())->method('deleteBySecretId')->with('secret-1');
        $this->service->deleteBySecretId('secret-1');
    }

    public function testDeleteByUserIdCascades(): void
    {
        $this->mapper->expects($this->once())->method('deleteByUserId')->with('alice');
        $this->service->deleteByUserId('alice');
    }

    private function makeShare(
        int $usageLimit,
        int $usageCount,
        int $failedAttempts,
        bool $blobFetched,
    ): LinkShare {
        $share = new LinkShare();
        $share->setId('ls-1');
        $share->setSecretId('secret-1');
        $share->setToken('tok');
        $share->setEncryptedSecretSnapshot('blob');
        $share->setArgon2idSalt('salt');
        $share->setEncryptionSuiteId('suite-1');
        $share->setUsageLimit($usageLimit);
        $share->setUsageCount($usageCount);
        $share->setFailedAttempts($failedAttempts);
        $share->setBlobFetched($blobFetched);
        $share->setCreatedBy('alice');
        $share->setCreatedAt(new DateTime());
        return $share;
    }
}
