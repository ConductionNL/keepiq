<?php

/**
 * Doriath SecretService unit tests.
 *
 * @category Tests
 * @package  OCA\Doriath\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Doriath\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Exception\ConflictException;
use OCA\Doriath\Exception\ForbiddenException;
use OCA\Doriath\Service\MigrationService;
use OCA\Doriath\Service\SecretService;
use OCA\Doriath\Service\SecretSuiteGuard;
use OCA\Doriath\Service\SecretTypeService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SecretServiceTest extends TestCase
{
    private SecretMapper $mapper;
    private SecretTypeService $typeService;
    private SecretSuiteGuard $suiteGuard;
    private MigrationService $migrationService;
    private SecretService $service;

    protected function setUp(): void
    {
        $this->mapper = $this->createMock(SecretMapper::class);
        $this->typeService = $this->createMock(SecretTypeService::class);
        $this->suiteGuard = $this->createMock(SecretSuiteGuard::class);
        $this->migrationService = $this->createMock(MigrationService::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->service = new SecretService(
            $this->mapper,
            $this->typeService,
            $this->suiteGuard,
            $this->migrationService,
            $logger,
        );
    }

    private function activeSuite(): EncryptionSuite
    {
        $suite = new EncryptionSuite();
        $suite->setId('suite-1');
        $suite->setStatus('active');
        return $suite;
    }

    public function testCreateLinksActiveSuiteAndDefaultType(): void
    {
        $this->migrationService->method('isWriteLocked')->willReturn(false);
        $this->suiteGuard->method('getActiveSuiteOrFail')->willReturn($this->activeSuite());
        $this->suiteGuard->method('isStatusBlocked')->willReturn(false);
        $this->typeService->method('resolveTypeForUser')->willReturn('login-type');
        $this->mapper->expects($this->once())->method('insert');

        $secret = $this->service->create(['name' => 'GitHub', 'key' => 'cipher'], 'alice');

        $this->assertSame('GitHub', $secret->getName());
        $this->assertSame('suite-1', $secret->getEncryptionSuiteId());
        $this->assertSame('login-type', $secret->getTypeId());
        $this->assertSame('cipher', $secret->getSecretKey());
        $this->assertSame('alice', $secret->getOwnerId());
    }

    public function testCreateRejectedWithoutName(): void
    {
        $this->migrationService->method('isWriteLocked')->willReturn(false);

        $this->expectException(InvalidArgumentException::class);
        $this->service->create(['key' => 'cipher'], 'alice');
    }

    public function testCreateRejectedWithoutKey(): void
    {
        $this->migrationService->method('isWriteLocked')->willReturn(false);

        $this->expectException(InvalidArgumentException::class);
        $this->service->create(['name' => 'GitHub'], 'alice');
    }

    public function testCreateRejectedDuringWriteLock(): void
    {
        $this->migrationService->method('isWriteLocked')->willReturn(true);

        $this->expectException(ConflictException::class);
        $this->service->create(['name' => 'GitHub', 'key' => 'cipher'], 'alice');
    }

    public function testCreateRejectedWhenSuiteBlocked(): void
    {
        $this->migrationService->method('isWriteLocked')->willReturn(false);
        $revoked = new EncryptionSuite();
        $revoked->setId('suite-x');
        $revoked->setStatus('revoked');
        $this->suiteGuard->method('getActiveSuiteOrFail')->willReturn($revoked);
        $this->suiteGuard->method('isStatusBlocked')->willReturn(true);

        $this->expectException(ForbiddenException::class);
        $this->service->create(['name' => 'GitHub', 'key' => 'cipher'], 'alice');
    }

    public function testUpdateRejectedDuringWriteLock(): void
    {
        $this->migrationService->method('isWriteLocked')->willReturn(true);

        $this->expectException(ConflictException::class);
        $this->service->update('s1', ['name' => 'New'], 'alice');
    }

    public function testUpdateForeignSecretRejected(): void
    {
        $this->migrationService->method('isWriteLocked')->willReturn(false);
        $secret = new Secret();
        $secret->setId('s1');
        $secret->setOwnerType('user');
        $secret->setOwnerId('bob');
        $this->mapper->method('findById')->willReturn($secret);

        $this->expectException(ForbiddenException::class);
        $this->service->update('s1', ['name' => 'New'], 'alice');
    }

    public function testDeleteForeignSecretRejected(): void
    {
        $secret = new Secret();
        $secret->setId('s1');
        $secret->setOwnerType('user');
        $secret->setOwnerId('bob');
        $this->mapper->method('findById')->willReturn($secret);

        $this->expectException(ForbiddenException::class);
        $this->service->delete('s1', 'alice');
    }

    public function testDeleteOwnSecret(): void
    {
        $secret = new Secret();
        $secret->setId('s1');
        $secret->setOwnerType('user');
        $secret->setOwnerId('alice');
        $this->mapper->method('findById')->willReturn($secret);
        $this->mapper->expects($this->once())->method('delete');

        $this->service->delete('s1', 'alice');
    }
}
