<?php

/**
 * MigrationWorkPage, ImportChunkRun and PlaceholderOwnerClaim: a page of
 * suite-migration work, the folders an import chunk created, and which
 * secrets a placeholder owner claim covers.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretVersion;
use OCA\Keepiq\Db\SuiteMigration;
use OCA\Keepiq\Service\FederatedValueFields;
use OCA\Keepiq\Service\ImportChunkRun;
use OCA\Keepiq\Service\MigrationWorkPage;
use OCA\Keepiq\Service\PlaceholderOwnerClaim;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The migration work page and two small run objects.
 */
class MigrationWorkPageRecordTest extends TestCase {
	/**
	 * A migration from old-1 to new-2.
	 *
	 * @return SuiteMigration
	 */
	private function migration(): SuiteMigration {
		$migration = new SuiteMigration();
		$migration->setId('migr-1');
		$migration->setOldSuiteId('old-1');
		$migration->setNewSuiteId('new-2');
		return $migration;
	}//end migration()

	/**
	 * Requested and kept page bounds.
	 *
	 * @return array<string, array{0: int, 1: int, 2: int, 3: int}>
	 */
	public static function bounds(): array {
		return [
			'in range'        => [50, 200, 50, 200],
			'limit too large' => [500, 0, 100, 0],
			'limit zero'      => [0, 0, 1, 0],
			'limit negative'  => [-5, 0, 1, 0],
			'offset negative' => [10, -10, 10, 0],
			'the maximum'     => [100, 0, 100, 0],
		];
	}//end bounds()

	/**
	 * The page size is clamped to 1..100 and the offset to zero or more.
	 *
	 * @param int $limit The requested limit.
	 * @param int $offset The requested offset.
	 * @param int $keptLimit The kept limit.
	 * @param int $keptOffset The kept offset.
	 *
	 * @return void
	 */
	#[DataProvider('bounds')]
	public function testThePageIsClamped(int $limit, int $offset, int $keptLimit, int $keptOffset): void {
		$page = new MigrationWorkPage(migration: $this->migration(), ownerId: 'anne', limit: $limit, offset: $offset);
		$this->assertSame(expected: [$keptLimit, $keptOffset], actual: [$page->limit, $page->offset]);
	}//end testThePageIsClamped()

	/**
	 * The header names the migration, both suites, the bounds and the version window.
	 *
	 * @return void
	 */
	public function testTheHeaderNamesTheMigration(): void {
		$page = new MigrationWorkPage(migration: $this->migration(), ownerId: 'anne', limit: 250, offset: 20);
		$this->assertSame(expected: 'old-1', actual: $page->oldSuiteId());
		$this->assertSame(
			expected: ['migrationId' => 'migr-1', 'oldSuiteId' => 'old-1', 'newSuiteId' => 'new-2', 'limit' => 100, 'offset' => 20, 'versionWindow' => 5],
			actual: $page->header(versionWindow: 5)
		);
	}//end testTheHeaderNamesTheMigration()

	/**
	 * A version record carries the encrypted fields, an empty value blob reading as null.
	 *
	 * @return void
	 */
	public function testAVersionRecordCarriesTheEncryptedFields(): void {
		$version = new SecretVersion();
		$version->setId('v1');
		$version->setSecretId('s1');
		$version->setVersionNumber(3);
		$version->setKey('key-ct');
		$version->setLogin('login-ct');
		$version->setAdditionalFields(null);
		$version->setTotp('totp-ct');
		$version->setPasskeys('');
		$page   = new MigrationWorkPage(migration: $this->migration(), ownerId: 'anne', limit: 10, offset: 0);
		$blobs  = new FederatedValueFields();
		$this->assertSame(
			expected: [
				'id'               => 'v1',
				'secretId'         => 's1',
				'versionNumber'    => 3,
				'key'              => 'key-ct',
				'login'            => 'login-ct',
				'additionalFields' => null,
				'totp'             => 'totp-ct',
				'passkeys'         => null,
			],
			actual: $page->versionRecord(version: $version, blobs: $blobs)
		);
	}//end testAVersionRecordCarriesTheEncryptedFields()

	/**
	 * An import chunk remembers the folders it found and the paths it created, each once.
	 *
	 * @return void
	 */
	public function testAnImportChunkRemembersItsFolders(): void {
		$run = new ImportChunkRun(userId: 'anne');
		$this->assertFalse(condition: $run->hasFolder(path: 'Bank'));
		$run->remember(path: 'Bank', folderId: 'f1');
		$run->remember(path: 'Bank/Sparen', folderId: 'f2');
		$run->markCreated(path: 'Bank/Sparen');
		$run->markCreated(path: 'Werk');
		$run->markCreated(path: 'Bank/Sparen');
		$this->assertTrue(condition: $run->hasFolder(path: 'Bank'));
		$this->assertSame(expected: 'f2', actual: $run->folderAt(path: 'Bank/Sparen'));
		$this->assertSame(expected: ['Bank/Sparen', 'Werk'], actual: $run->createdPaths());
		$this->assertSame(expected: 'anne', actual: $run->userId);
	}//end testAnImportChunkRemembersItsFolders()

	/**
	 * A claim without an owner type covers every secret; with one it covers that owner only.
	 *
	 * @return void
	 */
	public function testAPlaceholderClaimCoversItsOwnerOnly(): void {
		$secret = new Secret();
		$secret->setOwnerType('team');
		$this->assertTrue(condition: (new PlaceholderOwnerClaim(ownerType: null, ownerId: null))->covers(secret: $secret, ownerId: 'anyone'));
		$claim = new PlaceholderOwnerClaim(ownerType: 'team', ownerId: 'team-1');
		$this->assertTrue(condition: $claim->covers(secret: $secret, ownerId: 'team-1'));
		$this->assertFalse(condition: $claim->covers(secret: $secret, ownerId: 'team-2'));
		$secret->setOwnerType('user');
		$this->assertFalse(condition: $claim->covers(secret: $secret, ownerId: 'team-1'));
	}//end testAPlaceholderClaimCoversItsOwnerOnly()
}//end class
