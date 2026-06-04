<?php

/**
 * Unit tests for FolderService.
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
use OCA\Doriath\Db\Folder;
use OCA\Doriath\Db\FolderMapper;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Exception\ConflictException;
use OCA\Doriath\Exception\ForbiddenException;
use OCA\Doriath\Exception\NotFoundException;
use OCA\Doriath\Service\FolderService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for FolderService.
 */
class FolderServiceTest extends TestCase
{
    /** @var FolderService */
    private FolderService $service;

    /** @var FolderMapper */
    private $mapper;

    /** @var SecretMapper */
    private $secretMapper;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->mapper       = $this->createMock(FolderMapper::class);
        $this->secretMapper = $this->createMock(SecretMapper::class);
        $logger             = $this->createMock(LoggerInterface::class);

        $this->service = new FolderService(
            mapper: $this->mapper,
            secretMapper: $this->secretMapper,
            logger: $logger,
        );
    }//end setUp()

    /**
     * Build a folder owned by a user.
     *
     * @param string      $id       The folder ID
     * @param string|null $parentId The parent ID
     * @param string      $ownerId  The owner ID
     *
     * @return Folder
     */
    private function makeFolder(string $id = 'f-1', ?string $parentId = null, string $ownerId = 'alice'): Folder
    {
        $folder = new Folder();
        $folder->setId($id);
        $folder->setName('Folder '.$id);
        $folder->setParentId($parentId);
        $folder->setOwnerType('user');
        $folder->setOwnerId($ownerId);
        return $folder;
    }//end makeFolder()

    /**
     * Creating a root folder sets a null parent and the owner.
     *
     * @return void
     */
    public function testCreateRootFolder(): void
    {
        $this->mapper->expects($this->once())->method('insert');

        $folder = $this->service->create(name: 'Work', parentId: null, userId: 'alice');

        $this->assertSame('Work', $folder->getName());
        $this->assertNull($folder->getParentId());
        $this->assertSame('alice', $folder->getOwnerId());
    }//end testCreateRootFolder()

    /**
     * A slash in the name is rejected.
     *
     * @return void
     */
    public function testCreateFolderSlashRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->create(name: 'a/b', parentId: null, userId: 'alice');
    }//end testCreateFolderSlashRejected()

    /**
     * Creating under a non-owned parent is forbidden.
     *
     * @return void
     */
    public function testCreateUnderForeignParentForbidden(): void
    {
        $this->mapper->method('findById')->willReturn($this->makeFolder(ownerId: 'bob'));

        $this->expectException(ForbiddenException::class);
        $this->service->create(name: 'Sub', parentId: 'f-1', userId: 'alice');
    }//end testCreateUnderForeignParentForbidden()

    /**
     * Accessing a missing folder throws NotFound.
     *
     * @return void
     */
    public function testGetOwnedMissing(): void
    {
        $this->mapper->method('findById')->willThrowException(new DoesNotExistException('nope'));
        $this->expectException(NotFoundException::class);
        $this->service->getOwned(id: 'gone', userId: 'alice');
    }//end testGetOwnedMissing()

    /**
     * Renaming updates the name.
     *
     * @return void
     */
    public function testRename(): void
    {
        $this->mapper->method('findById')->willReturn($this->makeFolder());
        $this->mapper->expects($this->once())->method('update');

        $folder = $this->service->rename(id: 'f-1', name: 'Renamed', userId: 'alice');
        $this->assertSame('Renamed', $folder->getName());
    }//end testRename()

    /**
     * Moving into own subtree is rejected.
     *
     * @return void
     */
    public function testMoveIntoOwnSubtreeRejected(): void
    {
        $this->mapper->method('findById')->willReturn($this->makeFolder(id: 'f-1'));
        $this->mapper->method('getSubtreeIds')->willReturn(['f-1', 'child']);

        $this->expectException(InvalidArgumentException::class);
        $this->service->move(id: 'f-1', newParentId: 'child', userId: 'alice');
    }//end testMoveIntoOwnSubtreeRejected()

    /**
     * Deleting an empty folder removes it directly.
     *
     * @return void
     */
    public function testDeleteEmptyFolder(): void
    {
        $this->mapper->method('findById')->willReturn($this->makeFolder());
        $this->mapper->method('findChildren')->willReturn([]);
        $this->secretMapper->method('countByFolder')->willReturn(0);
        $this->mapper->expects($this->once())->method('delete');

        $this->service->delete(id: 'f-1', cascade: null, resolution: null, userId: 'alice');
    }//end testDeleteEmptyFolder()

    /**
     * Deleting a non-empty leaf without a cascade flag is a conflict.
     *
     * @return void
     */
    public function testDeleteNonEmptyWithoutCascadeConflict(): void
    {
        $this->mapper->method('findById')->willReturn($this->makeFolder());
        $this->mapper->method('findChildren')->willReturn([]);
        $this->secretMapper->method('countByFolder')->willReturn(3);

        $this->expectException(ConflictException::class);
        $this->service->delete(id: 'f-1', cascade: null, resolution: null, userId: 'alice');
    }//end testDeleteNonEmptyWithoutCascadeConflict()

    /**
     * cascade=delete removes the folder and its secrets.
     *
     * @return void
     */
    public function testDeleteCascadeDelete(): void
    {
        $this->mapper->method('findById')->willReturn($this->makeFolder());
        $this->mapper->method('findChildren')->willReturn([]);
        $this->secretMapper->method('countByFolder')->willReturn(2);
        $this->secretMapper->expects($this->once())->method('deleteByFolderId')->with('f-1');
        $this->mapper->expects($this->once())->method('delete');

        $this->service->delete(id: 'f-1', cascade: 'delete', resolution: null, userId: 'alice');
    }//end testDeleteCascadeDelete()

    /**
     * cascade=move re-parents secrets to the folder's parent.
     *
     * @return void
     */
    public function testDeleteCascadeMove(): void
    {
        $this->mapper->method('findById')->willReturn($this->makeFolder(id: 'f-1', parentId: 'parent'));
        $this->mapper->method('findChildren')->willReturn([]);
        $this->secretMapper->method('countByFolder')->willReturn(2);
        $this->secretMapper->expects($this->once())
            ->method('updateFolderForSecrets')
            ->with('f-1', 'parent');

        $this->service->delete(id: 'f-1', cascade: 'move', resolution: null, userId: 'alice');
    }//end testDeleteCascadeMove()

    /**
     * Deleting a folder with subfolders requires a resolution body.
     *
     * @return void
     */
    public function testDeleteWithSubfoldersRequiresResolution(): void
    {
        $this->mapper->method('findById')->willReturn($this->makeFolder());
        $this->mapper->method('findChildren')->willReturn([$this->makeFolder(id: 'sub')]);
        $this->secretMapper->method('countByFolder')->willReturn(0);

        $this->expectException(ConflictException::class);
        $this->service->delete(id: 'f-1', cascade: 'delete', resolution: null, userId: 'alice');
    }//end testDeleteWithSubfoldersRequiresResolution()

    /**
     * A resolution body missing a subfolder entry is rejected.
     *
     * @return void
     */
    public function testResolutionMissingSubfolderRejected(): void
    {
        $this->mapper->method('findById')->willReturn($this->makeFolder());
        $this->mapper->method('findChildren')->willReturn([$this->makeFolder(id: 'sub')]);
        $this->secretMapper->method('countByFolder')->willReturn(0);

        $this->expectException(InvalidArgumentException::class);
        $this->service->delete(
            id: 'f-1',
            cascade: null,
            resolution: ['subfolders' => ['other' => 'keep']],
            userId: 'alice',
        );
    }//end testResolutionMissingSubfolderRejected()

    /**
     * Resolution action keep re-parents the subfolder.
     *
     * @return void
     */
    public function testResolutionKeepReparentsSubfolder(): void
    {
        $parent = $this->makeFolder(id: 'f-1', parentId: 'root');
        $sub    = $this->makeFolder(id: 'sub', parentId: 'f-1');

        $this->mapper->method('findById')->willReturnMap(
            [
                ['f-1', $parent],
                ['sub', $sub],
            ]
        );
        $this->mapper->method('findChildren')->willReturn([$sub]);
        $this->secretMapper->method('countByFolder')->willReturn(0);

        // The kept subfolder is updated (re-parented) and the deleted folder removed.
        $this->mapper->expects($this->atLeastOnce())->method('update');
        $this->mapper->expects($this->atLeastOnce())->method('delete');

        $this->service->delete(
            id: 'f-1',
            cascade: null,
            resolution: ['directSecrets' => 'move', 'subfolders' => ['sub' => 'keep']],
            userId: 'alice',
        );
    }//end testResolutionKeepReparentsSubfolder()

    /**
     * Children endpoint reports direct secret count and subfolder counts.
     *
     * @return void
     */
    public function testGetChildren(): void
    {
        $this->mapper->method('findById')->willReturn($this->makeFolder(id: 'f-1'));
        $this->mapper->method('findChildren')->willReturn([$this->makeFolder(id: 'sub')]);
        $this->mapper->method('getSubtreeIds')->willReturn(['sub', 'nested']);
        $this->secretMapper->method('countByFolder')->willReturn(2);
        $this->secretMapper->method('countByFolderIds')->willReturn(3);

        $result = $this->service->getChildren(id: 'f-1', userId: 'alice');

        $this->assertSame(2, $result['directSecretCount']);
        $this->assertCount(1, $result['subfolders']);
        $this->assertSame(3, $result['subfolders'][0]['secretCount']);
        $this->assertSame(1, $result['subfolders'][0]['subfolderCount']);
    }//end testGetChildren()

    /**
     * Accessing a non-owned folder's children is forbidden.
     *
     * @return void
     */
    public function testGetChildrenForeignForbidden(): void
    {
        $this->mapper->method('findById')->willReturn($this->makeFolder(ownerId: 'bob'));

        $this->expectException(ForbiddenException::class);
        $this->service->getChildren(id: 'f-1', userId: 'alice');
    }//end testGetChildrenForeignForbidden()
}//end class
