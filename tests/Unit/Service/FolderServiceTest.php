<?php

/**
 * Doriath FolderService unit tests.
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
use OCA\Doriath\Db\Folder;
use OCA\Doriath\Db\FolderMapper;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Exception\ConflictException;
use OCA\Doriath\Exception\ForbiddenException;
use OCA\Doriath\Service\FolderService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FolderServiceTest extends TestCase
{
    private FolderMapper $mapper;
    private SecretMapper $secretMapper;
    private FolderService $service;

    protected function setUp(): void
    {
        $this->mapper = $this->createMock(FolderMapper::class);
        $this->secretMapper = $this->createMock(SecretMapper::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->service = new FolderService($this->mapper, $this->secretMapper, $logger);
    }

    private function makeFolder(string $id, string $owner, ?string $parent = null): Folder
    {
        $folder = new Folder();
        $folder->setId($id);
        $folder->setName('folder-'.$id);
        $folder->setParentId($parent);
        $folder->setOwnerType('user');
        $folder->setOwnerId($owner);
        return $folder;
    }

    public function testCreateRootFolder(): void
    {
        $this->mapper->expects($this->once())->method('insert');

        $folder = $this->service->create('Work', null, 'alice');

        $this->assertSame('Work', $folder->getName());
        $this->assertNull($folder->getParentId());
        $this->assertSame('alice', $folder->getOwnerId());
    }

    public function testCreateWithSlashRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->create('a/b', null, 'alice');
    }

    public function testCreateUnderNonOwnedParentRejected(): void
    {
        $this->mapper->method('findById')->willReturn($this->makeFolder('p1', 'bob'));

        $this->expectException(ForbiddenException::class);
        $this->service->create('child', 'p1', 'alice');
    }

    public function testDeleteEmptyFolder(): void
    {
        $this->mapper->method('findById')->willReturn($this->makeFolder('f1', 'alice'));
        $this->mapper->method('findChildren')->willReturn([]);
        $this->secretMapper->method('countByFolder')->willReturn(0);
        $this->mapper->expects($this->once())->method('delete');

        $this->service->delete('f1', null, null, 'alice');
    }

    public function testDeleteNonEmptyWithoutCascadeRejected(): void
    {
        $this->mapper->method('findById')->willReturn($this->makeFolder('f1', 'alice'));
        $this->mapper->method('findChildren')->willReturn([]);
        $this->secretMapper->method('countByFolder')->willReturn(3);

        $this->expectException(ConflictException::class);
        $this->service->delete('f1', null, null, 'alice');
    }

    public function testDeleteNonEmptyWithCascadeDelete(): void
    {
        $this->mapper->method('findById')->willReturn($this->makeFolder('f1', 'alice'));
        $this->mapper->method('findChildren')->willReturn([]);
        $this->secretMapper->method('countByFolder')->willReturn(3);
        $this->secretMapper->expects($this->once())->method('deleteByFolderIds')->with(['f1']);
        $this->mapper->expects($this->once())->method('delete');

        $this->service->delete('f1', 'delete', null, 'alice');
    }

    public function testDeleteNonEmptyWithCascadeMove(): void
    {
        $this->mapper->method('findById')->willReturn($this->makeFolder('f1', 'alice', 'p1'));
        $this->mapper->method('findChildren')->willReturn([]);
        $this->secretMapper->method('countByFolder')->willReturn(2);
        $this->secretMapper->expects($this->once())
            ->method('moveSecretsToFolder')
            ->with(['f1'], 'p1');

        $this->service->delete('f1', 'move', null, 'alice');
    }

    public function testDeleteWithSubfoldersRequiresResolution(): void
    {
        $this->mapper->method('findById')->willReturn($this->makeFolder('f1', 'alice'));
        $this->mapper->method('findChildren')->willReturn([$this->makeFolder('c1', 'alice', 'f1')]);
        $this->secretMapper->method('countByFolder')->willReturn(0);

        $this->expectException(ConflictException::class);
        $this->service->delete('f1', 'delete', null, 'alice');
    }

    public function testDeleteWithIncompleteResolutionRejected(): void
    {
        $this->mapper->method('findById')->willReturn($this->makeFolder('f1', 'alice'));
        $this->mapper->method('findChildren')->willReturn([$this->makeFolder('c1', 'alice', 'f1')]);
        $this->secretMapper->method('countByFolder')->willReturn(0);

        $this->expectException(InvalidArgumentException::class);
        // Resolution is non-empty but missing the c1 entry.
        $this->service->delete('f1', null, ['directSecrets' => 'delete'], 'alice');
    }

    public function testGetChildrenForeignFolderRejected(): void
    {
        $this->mapper->method('findById')->willReturn($this->makeFolder('f1', 'bob'));

        $this->expectException(ForbiddenException::class);
        $this->service->getChildren('f1', 'alice');
    }

    public function testRenameForeignFolderRejected(): void
    {
        $this->mapper->method('findById')->willReturn($this->makeFolder('f1', 'bob'));

        $this->expectException(ForbiddenException::class);
        $this->service->rename('f1', 'New name', 'alice');
    }

    public function testMoveIntoOwnSubtreeRejected(): void
    {
        $this->mapper->method('findById')->willReturn($this->makeFolder('f1', 'alice'));
        $this->mapper->method('getSubtreeIds')->willReturn(['f1', 'child']);

        $this->expectException(InvalidArgumentException::class);
        $this->service->move('f1', 'child', 'alice');
    }
}
