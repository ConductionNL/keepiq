<?php

/**
 * Unit tests for the Folder entity.
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Db
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

namespace OCA\Doriath\Tests\Unit\Db;

use OCA\Doriath\Db\Folder;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Folder entity.
 */
class FolderTest extends TestCase {
	/**
	 * Regression lock for the owner_type NOT-NULL fix (Phase-0).
	 *
	 * The ownerType property defaults to '' (NOT 'user'). NC's QBMapper only
	 * writes columns that getUpdatedFields() reports as dirty, and Entity::setter
	 * skips marking a field dirty when the new value equals the current one. If
	 * the default were 'user', setOwnerType('user') would be a no-op, ownerType
	 * would NOT be in getUpdatedFields(), and the INSERT would omit it — leaving
	 * the NOT-NULL column null and failing the write. With default '', the setter
	 * marks it dirty so 'user' is persisted on INSERT.
	 *
	 * @return void
	 */
	public function testSetOwnerTypeUserMarksColumnDirtyForInsert(): void {
		$folder = new Folder();

		// Fresh entity: ownerType is the '' default and not yet dirty.
		$this->assertSame('', $folder->getOwnerType());
		$this->assertArrayNotHasKey('ownerType', $folder->getUpdatedFields());

		// Setting the common-case 'user' value MUST mark the column dirty so the
		// QBMapper INSERT writes it (the column is NOT NULL). getUpdatedFields()
		// is keyed by property name.
		$folder->setOwnerType('user');

		$this->assertSame('user', $folder->getOwnerType());
		$this->assertArrayHasKey(
			'ownerType',
			$folder->getUpdatedFields(),
			'setOwnerType("user") must mark ownerType dirty so it is written on INSERT'
		);
	}//end testSetOwnerTypeUserMarksColumnDirtyForInsert()

	/**
	 * jsonSerialize exposes the folder fields including the owner type.
	 *
	 * @return void
	 */
	public function testJsonSerializeIncludesOwnerType(): void {
		$folder = new Folder();
		$folder->setId('f-1');
		$folder->setName('Shared');
		$folder->setOwnerType('user');
		$folder->setOwnerId('alice');

		$data = $folder->jsonSerialize();

		$this->assertSame('Shared', $data['name']);
		$this->assertSame('user', $data['ownerType']);
		$this->assertSame('alice', $data['ownerId']);
	}//end testJsonSerializeIncludesOwnerType()
}//end class
