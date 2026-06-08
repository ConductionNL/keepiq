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
use OCA\Doriath\Exception\DuplicateFolderNameException;
use OCA\Doriath\Service\FolderService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for FolderService.
 */
class FolderServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var FolderService
     */
    private FolderService $service;

    /**
     * The mocked folder mapper.
     *
     * @var FolderMapper
     */
    private FolderMapper $folderMapper;

    /**
     * The mocked secret mapper.
     *
     * @var SecretMapper
     */
    private SecretMapper $secretMapper;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->folderMapper = $this->createMock(originalClassName: FolderMapper::class);
        $this->secretMapper = $this->createMock(originalClassName: SecretMapper::class);
        $logger             = $this->createMock(originalClassName: LoggerInterface::class);

        $this->service = new FolderService(
            folderMapper: $this->folderMapper,
            secretMapper: $this->secretMapper,
            logger: $logger,
        );
    }//end setUp()

    /**
     * Test that create inserts a new folder with the correct name set.
     *
     * @return void
     */
    public function testCreateFolderSuccess(): void
    {
        $this->folderMapper->expects($this->once())->method('insert');

        $result = $this->service->create('My Folder', null, 'user', 'testuser');

        $this->assertEquals(expected: 'My Folder', actual: $result->getName());
        $this->assertEquals(expected: 'user', actual: $result->getOwnerType());
        $this->assertEquals(expected: 'testuser', actual: $result->getOwnerId());
        $this->assertNull(actual: $result->getParentId());
    }//end testCreateFolderSuccess()

    /**
     * Test that create throws when the folder name contains a slash.
     *
     * @return void
     */
    public function testCreateFolderWithSlashRejected(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessageMatches(regularExpression: '/slashes/');

        $this->service->create('Invalid/Name', null, 'user', 'testuser');
    }//end testCreateFolderWithSlashRejected()

    /**
     * Test that rename updates the name and updatedAt on the folder.
     *
     * @return void
     */
    public function testRenameFolderSuccess(): void
    {
        $folder = new Folder();
        $folder->setId('folder-1');
        $folder->setName('Old Name');
        $folder->setOwnerType('user');
        $folder->setOwnerId('testuser');

        $this->folderMapper->method('findById')->willReturn($folder);
        $this->folderMapper->expects($this->once())->method('update');

        $result = $this->service->rename('folder-1', 'New Name', 'testuser');

        $this->assertEquals(expected: 'New Name', actual: $result->getName());
        $this->assertNotNull(actual: $result->getUpdatedAt());
    }//end testRenameFolderSuccess()

    /**
     * Test that move updates the parentId on the folder.
     *
     * @return void
     */
    public function testMoveFolderSuccess(): void
    {
        $folder = new Folder();
        $folder->setId('folder-1');
        $folder->setName('My Folder');
        $folder->setOwnerType('user');
        $folder->setOwnerId('testuser');
        $folder->setParentId(null);

        $newParent = new Folder();
        $newParent->setId('parent-folder-id');
        $newParent->setOwnerType('user');
        $newParent->setOwnerId('testuser');

        $this->folderMapper->method('findById')
            ->willReturnMap([
                ['folder-1', $folder],
                ['parent-folder-id', $newParent],
            ]);

        $this->folderMapper->expects($this->once())->method('update');

        $result = $this->service->move('folder-1', 'parent-folder-id', 'testuser');

        $this->assertEquals(expected: 'parent-folder-id', actual: $result->getParentId());
    }//end testMoveFolderSuccess()

    /**
     * Test that delete on an empty folder calls delete directly.
     *
     * @return void
     */
    public function testDeleteEmptyFolder(): void
    {
        $folder = new Folder();
        $folder->setId('folder-1');
        $folder->setOwnerType('user');
        $folder->setOwnerId('testuser');

        $this->folderMapper->method('findById')->willReturn($folder);
        $this->folderMapper->method('countSecrets')->willReturn(0);
        $this->folderMapper->method('findChildren')->willReturn([]);
        $this->folderMapper->expects($this->once())->method('delete');

        $this->service->delete('folder-1', null, null, 'testuser');
    }//end testDeleteEmptyFolder()

    /**
     * Test that delete without cascade throws when the folder contains secrets.
     *
     * @return void
     */
    public function testDeleteNonEmptyWithoutCascadeThrows(): void
    {
        $folder = new Folder();
        $folder->setId('folder-1');
        $folder->setOwnerType('user');
        $folder->setOwnerId('testuser');

        $this->folderMapper->method('findById')->willReturn($folder);
        $this->folderMapper->method('countSecrets')->willReturn(3);
        $this->folderMapper->method('findChildren')->willReturn([]);

        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessageMatches(regularExpression: '/cascade/');

        $this->service->delete('folder-1', null, null, 'testuser');
    }//end testDeleteNonEmptyWithoutCascadeThrows()

    /**
     * Test that delete with cascade='delete' calls deleteByFolderId on secrets.
     *
     * @return void
     */
    public function testDeleteWithCascadeDelete(): void
    {
        $folder = new Folder();
        $folder->setId('folder-1');
        $folder->setOwnerType('user');
        $folder->setOwnerId('testuser');
        $folder->setParentId(null);

        $this->folderMapper->method('findById')->willReturn($folder);
        $this->folderMapper->method('countSecrets')->willReturn(2);
        $this->folderMapper->method('findChildren')->willReturn([]);

        $this->secretMapper->expects($this->once())
            ->method('deleteByFolderId')
            ->with('folder-1');

        $this->folderMapper->expects($this->once())->method('delete');

        $this->service->delete('folder-1', 'delete', null, 'testuser');
    }//end testDeleteWithCascadeDelete()

    /**
     * Test that delete with cascade='move' calls updateFolderForSecrets on secrets.
     *
     * @return void
     */
    public function testDeleteWithCascadeMove(): void
    {
        $folder = new Folder();
        $folder->setId('folder-1');
        $folder->setOwnerType('user');
        $folder->setOwnerId('testuser');
        $folder->setParentId('parent-id');

        $this->folderMapper->method('findById')->willReturn($folder);
        $this->folderMapper->method('countSecrets')->willReturn(1);
        $this->folderMapper->method('findChildren')->willReturn([]);

        $this->secretMapper->expects($this->once())
            ->method('updateFolderForSecrets')
            ->with('folder-1', 'parent-id');

        $this->folderMapper->expects($this->once())->method('delete');

        $this->service->delete('folder-1', 'move', null, 'testuser');
    }//end testDeleteWithCascadeMove()

    /**
     * Test that getChildren returns directSecretCount and subfolder summaries.
     *
     * @return void
     */
    public function testGetChildrenReturnsDirectCountAndSubfolders(): void
    {
        $folder = new Folder();
        $folder->setId('folder-1');
        $folder->setOwnerType('user');
        $folder->setOwnerId('testuser');

        $subfolder = new Folder();
        $subfolder->setId('subfolder-1');
        $subfolder->setName('Sub');

        $this->folderMapper->method('findById')->willReturn($folder);
        $this->folderMapper->method('countSecrets')->willReturn(4);
        $this->folderMapper->method('findChildren')
            ->willReturnMap([
                ['folder-1', [$subfolder]],
                ['subfolder-1', []],
            ]);
        $this->folderMapper->method('countSecretsRecursive')->willReturn(2);

        $result = $this->service->getChildren('folder-1', 'testuser');

        $this->assertEquals(expected: 4, actual: $result['directSecretCount']);
        $this->assertCount(expectedCount: 1, haystack: $result['subfolders']);
        $this->assertEquals(expected: 'subfolder-1', actual: $result['subfolders'][0]['id']);
        $this->assertEquals(expected: 'Sub', actual: $result['subfolders'][0]['name']);
        $this->assertEquals(expected: 2, actual: $result['subfolders'][0]['secretCount']);
        $this->assertEquals(expected: 0, actual: $result['subfolders'][0]['subfolderCount']);
    }//end testGetChildrenReturnsDirectCountAndSubfolders()

    /**
     * Test that validateOwnership throws when the folder belongs to a different user.
     *
     * @return void
     */
    public function testValidateOwnershipThrowsForWrongUser(): void
    {
        $folder = new Folder();
        $folder->setId('folder-1');
        $folder->setOwnerType('user');
        $folder->setOwnerId('userA');

        $this->folderMapper->method('findById')->willReturn($folder);

        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessageMatches(regularExpression: '/do not own/');

        $this->service->validateOwnership('folder-1', 'userB');
    }//end testValidateOwnershipThrowsForWrongUser()

    /**
     * Test that create rejects a name that already exists in the parent.
     *
     * @return void
     */
    public function testCreateDuplicateNameRejected(): void
    {
        $this->folderMapper->method('existsInParent')->willReturn(true);
        $this->folderMapper->expects($this->never())->method('insert');

        $this->expectException(exception: DuplicateFolderNameException::class);
        $this->expectExceptionMessageMatches(regularExpression: '/already exists/');

        $this->service->create('Duplicate', null, 'user', 'testuser');
    }//end testCreateDuplicateNameRejected()

    /**
     * Test that rename rejects a name that already exists among siblings.
     *
     * @return void
     */
    public function testRenameDuplicateNameRejected(): void
    {
        $folder = new Folder();
        $folder->setId('folder-1');
        $folder->setName('Old Name');
        $folder->setOwnerType('user');
        $folder->setOwnerId('testuser');
        $folder->setParentId('parent-1');

        $this->folderMapper->method('findById')->willReturn($folder);
        $this->folderMapper->method('existsInParent')
            ->with('user', 'testuser', 'parent-1', 'New Name', 'folder-1')
            ->willReturn(true);
        $this->folderMapper->expects($this->never())->method('update');

        $this->expectException(exception: DuplicateFolderNameException::class);

        $this->service->rename('folder-1', 'New Name', 'testuser');
    }//end testRenameDuplicateNameRejected()

    /**
     * Test that move rejects when the new parent already contains the name.
     *
     * @return void
     */
    public function testMoveDuplicateNameRejected(): void
    {
        $folder = new Folder();
        $folder->setId('folder-1');
        $folder->setName('My Folder');
        $folder->setOwnerType('user');
        $folder->setOwnerId('testuser');
        $folder->setParentId(null);

        $newParent = new Folder();
        $newParent->setId('parent-folder-id');
        $newParent->setOwnerType('user');
        $newParent->setOwnerId('testuser');

        $this->folderMapper->method('findById')
            ->willReturnMap([
                ['folder-1', $folder],
                ['parent-folder-id', $newParent],
            ]);
        $this->folderMapper->method('existsInParent')
            ->with('user', 'testuser', 'parent-folder-id', 'My Folder', 'folder-1')
            ->willReturn(true);
        $this->folderMapper->expects($this->never())->method('update');

        $this->expectException(exception: DuplicateFolderNameException::class);

        $this->service->move('folder-1', 'parent-folder-id', 'testuser');
    }//end testMoveDuplicateNameRejected()

    /**
     * Test that deleting with a 'keep' resolution rejects a colliding subfolder.
     *
     * @return void
     */
    public function testDeleteKeepDuplicateNameRejected(): void
    {
        $folder = new Folder();
        $folder->setId('folder-1');
        $folder->setName('Parent');
        $folder->setOwnerType('user');
        $folder->setOwnerId('testuser');
        $folder->setParentId('grandparent-1');

        $subfolder = new Folder();
        $subfolder->setId('subfolder-1');
        $subfolder->setName('Sub');
        $subfolder->setOwnerType('user');
        $subfolder->setOwnerId('testuser');
        $subfolder->setParentId('folder-1');

        $this->folderMapper->method('findById')->willReturn($folder);
        $this->folderMapper->method('countSecrets')->willReturn(0);
        $this->folderMapper->method('findChildren')->willReturn([$subfolder]);
        $this->folderMapper->method('existsInParent')
            ->with('user', 'testuser', 'grandparent-1', 'Sub', 'subfolder-1')
            ->willReturn(true);

        $this->expectException(exception: DuplicateFolderNameException::class);

        $this->service->delete('folder-1', null, ['subfolder-1' => 'keep'], 'testuser');
    }//end testDeleteKeepDuplicateNameRejected()
}//end class
