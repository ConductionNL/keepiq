<?php

/**
 * Unit tests for FolderCascadeService (what happens to a deleted folder's contents).
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Keepiq\Db\Folder;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Service\FolderCascadeService;
use OCA\Keepiq\Service\FolderNameGuard;
use OCA\Keepiq\Service\SecretChildDataCleaner;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Subfolder delete / move / keep cascades.
 */
#[CoversClass(FolderCascadeService::class)]
#[UsesClass(Folder::class)]
class FolderCascadeServiceTest extends TestCase {
	private FolderMapper&MockObject $mapper;

	private SecretMapper&MockObject $secretMapper;

	private SecretChildDataCleaner&MockObject $childData;

	private FolderNameGuard&MockObject $nameGuard;

	private FolderCascadeService $service;

	/**
	 * Wire the service over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(originalClassName: FolderMapper::class);
		$this->secretMapper = $this->createMock(originalClassName: SecretMapper::class);
		$this->childData = $this->createMock(originalClassName: SecretChildDataCleaner::class);
		$this->nameGuard = $this->createMock(originalClassName: FolderNameGuard::class);
		$this->service = new FolderCascadeService(
			mapper: $this->mapper,
			secretMapper: $this->secretMapper,
			childData: $this->childData,
			nameGuard: $this->nameGuard,
		);
	}//end setUp()

	/**
	 * A folder row.
	 *
	 * @param string $id The folder id
	 * @param string $name The folder name
	 * @param string|null $parentId The parent folder id
	 *
	 * @return Folder
	 */
	private function folder(string $id, string $name, ?string $parentId = null): Folder {
		$folder = new Folder();
		$folder->setId($id);
		$folder->setName($name);
		$folder->setParentId($parentId);
		$folder->setOwnerType('user');
		$folder->setOwnerId('alice');

		return $folder;
	}//end folder()

	/**
	 * Purging a folder clears child data first, then the secrets.
	 *
	 * @return void
	 */
	public function testPurgeClearsChildDataBeforeSecrets(): void {
		$calls = [];
		$this->childData->expects($this->once())->method('purgeForFolder')->with('f-1')
			->willReturnCallback(static function () use (&$calls): void {
				$calls[] = 'children';
			});
		$this->secretMapper->expects($this->once())->method('deleteByFolderId')->with('f-1')
			->willReturnCallback(static function () use (&$calls): void {
				$calls[] = 'secrets';
			});

		$this->service->purgeSecretsIn(folderId: 'f-1');

		$this->assertSame(['children', 'secrets'], $calls);
	}//end testPurgeClearsChildDataBeforeSecrets()

	/**
	 * Moving secrets re-parents them to the target.
	 *
	 * @return void
	 */
	public function testMoveSecretsRepointsToTheTarget(): void {
		$this->secretMapper->expects($this->once())->method('updateFolderForSecrets')->with('f-1', null);

		$this->service->moveSecretsFrom(folderId: 'f-1', targetId: null);
	}//end testMoveSecretsRepointsToTheTarget()

	/**
	 * An unknown action is refused up front.
	 *
	 * @return void
	 */
	public function testAssertRefusesAnUnknownAction(): void {
		$this->nameGuard->expects($this->never())->method('assertNameUnique');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown subfolder action: archive');
		$this->service->assertSubfolderAction(
			subfolder: $this->folder(id: 'sub', name: 'a'),
			action: 'archive',
			deleted: $this->folder(id: 'del', name: 'b'),
		);
	}//end testAssertRefusesAnUnknownAction()

	/**
	 * Delete and move need no name check.
	 *
	 * @return void
	 */
	public function testDeleteAndMoveNeedNoNameCheck(): void {
		$this->nameGuard->expects($this->never())->method('assertNameUnique');

		foreach (['delete', 'move'] as $action) {
			$this->service->assertSubfolderAction(
				subfolder: $this->folder(id: 'sub', name: 'a'),
				action: $action,
				deleted: $this->folder(id: 'del', name: 'b'),
			);
		}
	}//end testDeleteAndMoveNeedNoNameCheck()

	/**
	 * A kept subfolder named like the deleted folder needs no check.
	 *
	 * @return void
	 */
	public function testKeepWithTheDeletedFoldersNameNeedsNoCheck(): void {
		$this->nameGuard->expects($this->never())->method('assertNameUnique');

		$this->service->assertSubfolderAction(
			subfolder: $this->folder(id: 'sub', name: 'same'),
			action: 'keep',
			deleted: $this->folder(id: 'del', name: 'same', parentId: 'root'),
		);
	}//end testKeepWithTheDeletedFoldersNameNeedsNoCheck()

	/**
	 * A kept subfolder is checked for a name clash in its new parent.
	 *
	 * @return void
	 */
	public function testKeepChecksTheNameInTheNewParent(): void {
		$this->nameGuard->expects($this->once())->method('assertNameUnique')->with(
			'user',
			'alice',
			'root',
			'sub-name',
			'sub',
		);

		$this->service->assertSubfolderAction(
			subfolder: $this->folder(id: 'sub', name: 'sub-name'),
			action: 'keep',
			deleted: $this->folder(id: 'del', name: 'other', parentId: 'root'),
		);
	}//end testKeepChecksTheNameInTheNewParent()

	/**
	 * Deleting a subfolder purges every folder in its subtree, deepest first.
	 *
	 * @return void
	 */
	public function testDeletePurgesTheSubtreeDeepestFirst(): void {
		$this->mapper->method('getSubtreeIds')->with('sub')->willReturn(['sub', 'child']);
		$purged = [];
		$this->childData->method('purgeForFolder')->willReturnCallback(static function (string $folderId) use (&$purged): void {
			$purged[] = $folderId;
		});
		$this->secretMapper->expects($this->never())->method('updateFolderForSecrets');
		$this->mapper->method('findById')->willReturnCallback(fn (string $id): Folder => $this->folder(id: $id, name: $id));
		$deleted = [];
		$this->mapper->method('delete')->willReturnCallback(static function (Folder $folder) use (&$deleted): Folder {
			$deleted[] = $folder->getId();
			return $folder;
		});

		$this->service->applySubfolderAction(subfolder: $this->folder(id: 'sub', name: 'a'), action: 'delete', deletedParentId: 'root');

		$this->assertSame(['sub', 'child'], $purged);
		$this->assertSame(['child', 'sub'], $deleted);
	}//end testDeletePurgesTheSubtreeDeepestFirst()

	/**
	 * Moving a subfolder lifts every secret in its subtree to the deleted folder's parent.
	 *
	 * @return void
	 */
	public function testMoveLiftsSecretsToTheDeletedParent(): void {
		$this->mapper->method('getSubtreeIds')->willReturn(['sub', 'child']);
		$moved = [];
		$this->secretMapper->method('updateFolderForSecrets')->willReturnCallback(static function (string $from, ?string $to) use (&$moved): void {
			$moved[] = [$from, $to];
		});
		$this->childData->expects($this->never())->method('purgeForFolder');
		$this->mapper->method('findById')->willReturnCallback(fn (string $id): Folder => $this->folder(id: $id, name: $id));
		$this->mapper->expects($this->exactly(2))->method('delete')->willReturnArgument(0);

		$this->service->applySubfolderAction(subfolder: $this->folder(id: 'sub', name: 'a'), action: 'move', deletedParentId: 'root');

		$this->assertSame([['sub', 'root'], ['child', 'root']], $moved);
	}//end testMoveLiftsSecretsToTheDeletedParent()

	/**
	 * Keeping a subfolder re-parents it and stamps the update.
	 *
	 * @return void
	 */
	public function testKeepReparentsTheSubfolder(): void {
		$subfolder = $this->folder(id: 'sub', name: 'a', parentId: 'del');
		$this->mapper->expects($this->never())->method('getSubtreeIds');
		$this->mapper->expects($this->once())->method('update')->with($subfolder)->willReturnArgument(0);

		$this->service->applySubfolderAction(subfolder: $subfolder, action: 'keep', deletedParentId: 'root');

		$this->assertSame('root', $subfolder->getParentId());
		$this->assertNotNull($subfolder->getUpdatedAt());
	}//end testKeepReparentsTheSubfolder()

	/**
	 * Applying an unknown action is refused without touching anything.
	 *
	 * @return void
	 */
	public function testApplyRefusesAnUnknownAction(): void {
		$this->mapper->expects($this->never())->method('update');
		$this->mapper->expects($this->never())->method('getSubtreeIds');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown subfolder action: shred');
		$this->service->applySubfolderAction(subfolder: $this->folder(id: 'sub', name: 'a'), action: 'shred', deletedParentId: null);
	}//end testApplyRefusesAnUnknownAction()

	/**
	 * A folder that vanished or is ambiguous is skipped; the rest are deleted.
	 *
	 * @return void
	 */
	public function testDeleteSubtreeSkipsMissingFolders(): void {
		$this->mapper->method('findById')->willReturnCallback(function (string $id): Folder {
			if ($id === 'gone') {
				throw new DoesNotExistException('gone');
			}

			if ($id === 'twice') {
				throw new MultipleObjectsReturnedException('twice');
			}

			return $this->folder(id: $id, name: $id);
		});
		$deleted = [];
		$this->mapper->method('delete')->willReturnCallback(static function (Folder $folder) use (&$deleted): Folder {
			$deleted[] = $folder->getId();
			return $folder;
		});

		$this->service->deleteSubtreeFolders(folderIds: ['a', 'gone', 'twice', 'b']);

		$this->assertSame(['b', 'a'], $deleted);
	}//end testDeleteSubtreeSkipsMissingFolders()
}//end class
