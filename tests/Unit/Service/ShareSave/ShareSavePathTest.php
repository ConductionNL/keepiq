<?php

/**
 * Unit tests for ShareSavePath.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service\ShareSave;

use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Service\ShareSave\ShareSavePath;
use PHPUnit\Framework\TestCase;

/**
 * A save is on the owner path when the row is its own source, and on the
 * copy-editor path otherwise.
 *
 * @covers \OCA\Keepiq\Service\ShareSave\ShareSavePath
 * @uses   \OCA\Keepiq\Db\Secret
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class ShareSavePathTest extends TestCase {

	/**
	 * A secret with an id.
	 *
	 * @param string $id The id.
	 *
	 * @return Secret
	 */
	private function secret(string $id): Secret {
		$secret = new Secret();
		$secret->setId($id);
		return $secret;
	}//end secret()

	/**
	 * A row that is its own source is the owner path, writing on nothing.
	 *
	 * @return void
	 */
	public function testARowThatIsItsOwnSourceIsTheOwnerPath(): void {
		$row = $this->secret('src-1');
		$path = new ShareSavePath(row: $row, source: $row);

		$this->assertFalse($path->isCopyEditor());
		$this->assertSame('owner', $path->getName());
		$this->assertNull($path->writesOn);
	}//end testARowThatIsItsOwnSourceIsTheOwnerPath()

	/**
	 * Two row objects with the same id are still the owner path.
	 *
	 * @return void
	 */
	public function testTheSameIdIsTheOwnerPath(): void {
		$path = new ShareSavePath(row: $this->secret('src-1'), source: $this->secret('src-1'));

		$this->assertSame('owner', $path->getName());
	}//end testTheSameIdIsTheOwnerPath()

	/**
	 * A copy of another source is the copy-editor path.
	 *
	 * @return void
	 */
	public function testACopyIsTheCopyEditorPath(): void {
		$path = new ShareSavePath(row: $this->secret('copy-bob'), source: $this->secret('src-1'), writesOn: 'group-1');

		$this->assertTrue($path->isCopyEditor());
		$this->assertSame('copy', $path->getName());
		$this->assertSame('group-1', $path->writesOn);
	}//end testACopyIsTheCopyEditorPath()
}//end class
