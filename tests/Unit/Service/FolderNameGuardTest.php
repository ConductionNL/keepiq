<?php

/**
 * Keepiq - FolderNameGuard tests
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Exception\ConflictException;
use OCA\Keepiq\Exception\DuplicateFolderNameException;
use OCA\Keepiq\Service\FolderNameGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * A folder name is unique within its parent.
 */
#[CoversClass(FolderNameGuard::class)]
#[UsesClass(DuplicateFolderNameException::class)]
#[UsesClass(ConflictException::class)]
class FolderNameGuardTest extends TestCase {
	/**
	 * A free name passes and the mapper is asked with every argument.
	 *
	 * @return void
	 */
	public function testAFreeNamePasses(): void {
		$mapper = $this->createMock(FolderMapper::class);
		$mapper->expects($this->once())->method('existsInParent')
			->with('user', 'alice', 'parent-1', 'Work', 'folder-9')
			->willReturn(false);

		(new FolderNameGuard(mapper: $mapper))->assertNameUnique(
			ownerType: 'user',
			ownerId: 'alice',
			parentId: 'parent-1',
			name: 'Work',
			excludeId: 'folder-9'
		);
		$this->addToAssertionCount(1);
	}//end testAFreeNamePasses()

	/**
	 * A taken name is refused with the name in the message.
	 *
	 * @return void
	 */
	public function testATakenNameIsRefused(): void {
		$mapper = $this->createMock(FolderMapper::class);
		$mapper->expects($this->once())->method('existsInParent')
			->with('team', 'team-1', null, 'Shared', null)
			->willReturn(true);

		$this->expectException(DuplicateFolderNameException::class);
		$this->expectExceptionMessage("A folder named 'Shared' already exists in this location");

		(new FolderNameGuard(mapper: $mapper))->assertNameUnique(ownerType: 'team', ownerId: 'team-1', parentId: null, name: 'Shared');
	}//end testATakenNameIsRefused()
}//end class
