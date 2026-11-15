<?php

/**
 * Query-shape unit tests for FolderMapper, without a database.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Db
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

namespace OCA\Keepiq\Tests\Unit\Db;

use OCA\Keepiq\Db\Folder;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use PHPUnit\Framework\TestCase;

/**
 * FolderMapper against a recording connection.
 *
 * @covers \OCA\Keepiq\Db\FolderMapper
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @uses   \OCA\Keepiq\Db\Folder
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class FolderMapperTest extends TestCase {
	use RecordingDatabase;

	/**
	 * A sibling with the same name under a parent, excluding the folder itself, exists.
	 *
	 * @return void
	 */
	public function testExistsInParentWithParentAndExclusion(): void {
		$this->queueRows([['c' => '1']]);

		$this->assertTrue((new FolderMapper($this->recordingConnection()))->existsInParent(ownerType: 'user', ownerId: 'alice', parentId: 'p1', name: 'Work', excludeId: 'f9'));
		$this->assertSame(
			[
				'select(COUNT(*))', 'from(keepiq_folders)', 'where(eq(owner_type, :p0))', 'andWhere(eq(owner_id, :p1))',
				'andWhere(eq(name, :p2))', 'andWhere(eq(parent_id, :p3))', 'andWhere(neq(id, :p4))',
			],
			$this->opsOf(0)
		);
		$this->assertSame([':p0' => 'user', ':p1' => 'alice', ':p2' => 'Work', ':p3' => 'p1', ':p4' => 'f9'], $this->paramsOf(0));
	}//end testExistsInParentWithParentAndExclusion()

	/**
	 * At the root the parent must be null; a zero count is false.
	 *
	 * @return void
	 */
	public function testExistsInParentAtRoot(): void {
		$this->queueRows([['c' => '0']]);

		$this->assertFalse((new FolderMapper($this->recordingConnection()))->existsInParent(ownerType: 'user', ownerId: 'alice', parentId: null, name: 'Work'));
		$this->assertSame('andWhere(isNull(parent_id))', $this->opsOf(0)[5]);
		$this->assertCount(6, $this->opsOf(0));
	}//end testExistsInParentAtRoot()

	/**
	 * The path walks up the parents and stops at a missing folder.
	 *
	 * @return void
	 */
	public function testGetPathWalksUpToTheRoot(): void {
		$this->queueRows([['id' => 'c', 'name' => 'Child', 'parent_id' => 'b']]);
		$this->queueRows([['id' => 'b', 'name' => 'Mid', 'parent_id' => 'a']]);
		$this->queueRows([['id' => 'a', 'name' => 'Top', 'parent_id' => null]]);
		$mapper = new FolderMapper($this->recordingConnection());

		$this->assertSame('Top/Mid/Child', $mapper->getPath(folderId: 'c'));
		$this->assertSame([':p0' => 'c'], $this->paramsOf(0));
		$this->assertSame([':p2' => 'a'], $this->paramsOf(2));

		$this->queueRows([['id' => 'x', 'name' => 'Orphan', 'parent_id' => 'gone']]);
		$this->assertSame('Orphan', $mapper->getPath(folderId: 'x'));
	}//end testGetPathWalksUpToTheRoot()

	/**
	 * The subtree is collected breadth-first and a cycle does not loop.
	 *
	 * @return void
	 */
	public function testGetSubtreeIdsIsBreadthFirstAndCycleSafe(): void {
		$this->queueRows([['id' => 'b'], ['id' => 'c']]);
		$this->queueRows([['id' => 'd'], ['id' => 'a']]);
		$this->queueRows([]);
		$this->queueRows([]);

		$this->assertSame(['a', 'b', 'c', 'd'], (new FolderMapper($this->recordingConnection()))->getSubtreeIds(folderId: 'a'));
		$this->assertSame(['select(*)', 'from(keepiq_folders)', 'where(eq(parent_id, :p0))', 'orderBy(name, ASC)'], $this->opsOf(0));
		$this->assertCount(4, $this->queries);
	}//end testGetSubtreeIdsIsBreadthFirstAndCycleSafe()

	/**
	 * Owner and root listings are name-ordered and scoped to the owner.
	 *
	 * @return void
	 */
	public function testOwnerAndRootListings(): void {
		$this->queueRows([['id' => 'f1', 'name' => 'A']]);
		$mapper = new FolderMapper($this->recordingConnection());

		$folders = $mapper->findByOwner(ownerType: 'user', ownerId: 'alice');
		$mapper->findRootFolders(ownerType: 'user', ownerId: 'alice');

		$this->assertInstanceOf(Folder::class, $folders[0]);
		$this->assertSame(['select(*)', 'from(keepiq_folders)', 'where(eq(owner_type, :p0))', 'andWhere(eq(owner_id, :p1))', 'orderBy(name, ASC)'], $this->opsOf(0));
		$this->assertSame(
			['select(*)', 'from(keepiq_folders)', 'where(isNull(parent_id))', 'andWhere(eq(owner_type, :p2))', 'andWhere(eq(owner_id, :p3))', 'orderBy(name, ASC)'],
			$this->opsOf(1)
		);
	}//end testOwnerAndRootListings()

	/**
	 * Deleting a user's folders targets owner_type user.
	 *
	 * @return void
	 */
	public function testDeleteByOwnerUser(): void {
		$this->affected = [6];

		$this->assertSame(6, (new FolderMapper($this->recordingConnection()))->deleteByOwnerUser(ownerId: 'alice'));
		$this->assertSame([':p0' => 'user', ':p1' => 'alice'], $this->paramsOf(0));
		$this->assertSame('delete(keepiq_folders)', $this->opsOf(0)[0]);
	}//end testDeleteByOwnerUser()
}//end class
