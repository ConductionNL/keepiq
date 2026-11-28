<?php

/**
 * TeamFolderMapper against a recording connection.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Db;

use OCA\Keepiq\Db\TeamFolder;
use OCA\Keepiq\Db\TeamFolderMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * The SQL each TeamFolderMapper query builds: lookup by id, by vault folder, and every team
 * folder of an owner.
 *
 * @covers \OCA\Keepiq\Db\TeamFolderMapper
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @uses   \OCA\Keepiq\Db\TeamFolder
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class TeamFolderMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over the recording connection.
	 *
	 * @return TeamFolderMapper
	 */
	private function mapper(): TeamFolderMapper {
		return new TeamFolderMapper(db: $this->recordingConnection());
	}//end mapper()

	/**
	 * Lookup by id hydrates the row; no row throws.
	 *
	 * @return void
	 */
	public function testFindById(): void {
		$this->queueRows(rows: [['id' => 'r1']]);
		$mapper = $this->mapper();

		$this->assertSame(expected: 'r1', actual: $mapper->findById(id: 'r1')->getId());
		$this->assertSame(expected: ['select(*)', 'from(keepiq_team_folders)', 'where(eq(id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'r1'], actual: $this->paramsOf(index: 0));

		$this->expectException(exception: DoesNotExistException::class);
		$mapper->findById(id: 'gone');
	}//end testFindById()

	/**
	 * The team folder of a vault folder; no row throws.
	 *
	 * @return void
	 */
	public function testFindByFolder(): void {
		$this->queueRows(rows: [['id' => 'r1']]);
		$mapper = $this->mapper();

		$this->assertSame(expected: 'r1', actual: $mapper->findByFolder(folderId: 'f1')->getId());
		$this->assertSame(
			expected: [
				'select(*)', 'from(keepiq_team_folders)', 'where(eq(folder_id, :p0))'
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'f1'], actual: $this->paramsOf(index: 0));

		$this->expectException(exception: DoesNotExistException::class);
		$mapper->findByFolder(folderId: 'f2');
	}//end testFindByFolder()

	/**
	 * Every team folder of one owner.
	 *
	 * @return void
	 */
	public function testFindByOwner(): void {
		$this->queueRows(rows: [['id' => 'r1'], ['id' => 'r2']]);

		$rows = $this->mapper()->findByOwner(ownerId: 'alice');

		$this->assertSame(expected: ['r1', 'r2'], actual: array_map(static fn (TeamFolder $e): string => $e->getId(), $rows));
		$this->assertSame(
			expected: [
				'select(*)', 'from(keepiq_team_folders)', 'where(eq(owner_id, :p0))'
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'alice'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'query', actual: $this->ranOf(index: 0));
	}//end testFindByOwner()
}//end class
