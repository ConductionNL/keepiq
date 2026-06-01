<?php

/**
 * Doriath SecretTypeService unit tests.
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
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\SecretType;
use OCA\Doriath\Db\SecretTypeMapper;
use OCA\Doriath\Service\SecretTypeService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SecretTypeServiceTest extends TestCase
{
    private SecretTypeMapper $mapper;
    private SecretMapper $secretMapper;
    private SecretTypeService $service;

    protected function setUp(): void
    {
        $this->mapper = $this->createMock(SecretTypeMapper::class);
        $this->secretMapper = $this->createMock(SecretMapper::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->service = new SecretTypeService($this->mapper, $this->secretMapper, $logger);
    }

    public function testSystemTypeIdIsDeterministic(): void
    {
        $first = SecretTypeService::systemTypeId('login');
        $second = SecretTypeService::systemTypeId('login');
        $this->assertSame($first, $second);
        $this->assertNotSame(
            SecretTypeService::systemTypeId('login'),
            SecretTypeService::systemTypeId('api_key')
        );
    }

    public function testCreateUserType(): void
    {
        $this->mapper->method('nameExists')->willReturn(false);
        $this->mapper->expects($this->once())->method('insert');

        $type = $this->service->createType('aws', 'AWS', 'user', 'alice');

        $this->assertSame('aws', $type->getName());
        $this->assertSame('user', $type->getScope());
        $this->assertSame('alice', $type->getOwnerId());
    }

    public function testCreateGlobalTypeHasNullOwner(): void
    {
        $this->mapper->method('nameExists')->willReturn(false);

        $type = $this->service->createType('shared', 'Shared', 'global', 'admin');

        $this->assertSame('global', $type->getScope());
        $this->assertNull($type->getOwnerId());
    }

    public function testCreateDuplicateNameRejected(): void
    {
        $this->mapper->method('nameExists')->willReturn(true);

        $this->expectException(InvalidArgumentException::class);
        $this->service->createType('login', 'Login', 'user', 'alice');
    }

    public function testCreateInvalidScopeRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->createType('foo', 'Foo', 'system', 'alice');
    }

    public function testDeleteSystemTypeBlocked(): void
    {
        $type = new SecretType();
        $type->setId('id-1');
        $type->setName('login');
        $type->setScope('system');
        $this->mapper->method('findById')->willReturn($type);

        $this->expectException(InvalidArgumentException::class);
        $this->service->deleteType('id-1', 'alice', false);
    }

    public function testDeleteUserTypeReassignsSecrets(): void
    {
        $type = new SecretType();
        $type->setId('id-2');
        $type->setName('aws');
        $type->setScope('user');
        $type->setOwnerId('alice');
        $this->mapper->method('findById')->willReturn($type);

        $loginId = SecretTypeService::systemTypeId('login');
        $this->secretMapper->expects($this->once())
            ->method('reassignType')
            ->with('id-2', $loginId);
        $this->mapper->expects($this->once())->method('delete');

        $this->service->deleteType('id-2', 'alice', false);
    }

    public function testDeleteOtherUsersTypeRejected(): void
    {
        $type = new SecretType();
        $type->setId('id-3');
        $type->setScope('user');
        $type->setOwnerId('bob');
        $this->mapper->method('findById')->willReturn($type);

        $this->expectException(InvalidArgumentException::class);
        $this->service->deleteType('id-3', 'alice', false);
    }

    public function testResolveTypeFallsBackToLogin(): void
    {
        $resolved = $this->service->resolveTypeForUser(null, 'alice');
        $this->assertSame(SecretTypeService::systemTypeId('login'), $resolved);
    }

    public function testResolveUnavailableTypeRejected(): void
    {
        $this->mapper->method('findAvailableForUser')->willReturn([]);

        $this->expectException(InvalidArgumentException::class);
        $this->service->resolveTypeForUser('nonexistent', 'alice');
    }
}
