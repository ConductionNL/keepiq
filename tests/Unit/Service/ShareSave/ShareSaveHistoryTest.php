<?php

/**
 * Unit tests for ShareSaveHistory and ShareSavePath.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service\ShareSave
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

namespace OCA\Keepiq\Tests\Unit\Service\ShareSave;

use InvalidArgumentException;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretVersion;
use OCA\Keepiq\Service\SecretVersionService;
use OCA\Keepiq\Service\ShareAuditTrail;
use OCA\Keepiq\Service\ShareSave\ShareSaveHistory;
use OCA\Keepiq\Service\ShareSave\ShareSavePath;
use OCA\Keepiq\Service\ShareSave\ShareSaveRefusal;
use OCA\Keepiq\Service\ShareSyncService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The version and audit side of a share save: which version may be
 * restored onto a row, and whose team write is recorded with which
 * skipped holders.
 */
#[CoversClass(ShareSaveHistory::class)]
#[CoversClass(ShareSavePath::class)]
#[UsesClass(ShareSaveRefusal::class)]
#[UsesClass(Secret::class)]
#[UsesClass(SecretVersion::class)]
class ShareSaveHistoryTest extends TestCase {
	private SecretVersionService&MockObject $versions;
	private ShareAuditTrail&MockObject $auditTrail;
	private ShareSaveHistory $history;

	protected function setUp(): void {
		$this->versions = $this->createMock(SecretVersionService::class);
		$this->auditTrail = $this->createMock(ShareAuditTrail::class);
		$this->history = new ShareSaveHistory(versions: $this->versions, auditTrail: $this->auditTrail);
	}

	private static function secret(string $id, string $ownerId = 'alice', string $name = 'Router'): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setOwnerId($ownerId);
		$secret->setName($name);

		return $secret;
	}

	private static function version(string $secretId): SecretVersion {
		$version = new SecretVersion();
		$version->setId('v-1');
		$version->setSecretId($secretId);

		return $version;
	}

	public function testPathNamesOwnerAndCopyEditor(): void {
		$source = self::secret('src');
		$owner = new ShareSavePath(row: $source, source: $source);
		$copy = new ShareSavePath(row: self::secret('copy'), source: $source, writesOn: 'key');

		$this->assertFalse($owner->isCopyEditor());
		$this->assertSame('owner', $owner->getName());
		$this->assertNull($owner->writesOn);
		$this->assertTrue($copy->isCopyEditor());
		$this->assertSame('copy', $copy->getName());
		$this->assertSame('key', $copy->writesOn);
	}

	public function testAVersionOfTheRowIsReturned(): void {
		$version = self::version('row-1');
		$this->versions->expects($this->once())->method('getVersion')->with('v-1', 'bob')->willReturn($version);

		$this->assertSame($version, $this->history->versionToRestore('v-1', self::secret('row-1'), 'bob'));
	}

	public function testAVersionOfAnotherSecretIsRefusedAsNotFound(): void {
		$this->versions->method('getVersion')->willReturn(self::version('other'));

		try {
			$this->history->versionToRestore('v-1', self::secret('row-1'), 'bob');
			$this->fail('A foreign version was accepted');
		} catch (ShareSaveRefusal $refusal) {
			$this->assertSame('version_refused', $refusal->getError());
			$this->assertSame('Version not found', $refusal->getMessage());
			$this->assertSame(400, $refusal->getStatus());
		}
	}

	public function testAnUnreadableVersionIsRefusedWithTheGuardMessage(): void {
		$this->versions->method('getVersion')->willThrowException(new InvalidArgumentException('Version not readable'));

		try {
			$this->history->versionToRestore('v-1', self::secret('row-1'), 'bob');
			$this->fail('An unreadable version was accepted');
		} catch (ShareSaveRefusal $refusal) {
			$this->assertSame('version_refused', $refusal->getError());
			$this->assertSame('Version not readable', $refusal->getMessage());
			$this->assertSame(400, $refusal->getStatus());
		}
	}

	public function testRecordAndLogRestoreDelegateWithTheShareSaveChannel(): void {
		$secret = self::secret('row-1');
		$version = self::version('row-1');
		$this->versions->expects($this->once())->method('recordRestored')->with($secret, $version, 'bob', 'share-save');
		$this->versions->expects($this->once())->method('logRestored')->with($secret, $version, 'bob');

		$this->history->recordRestore($secret, $version, 'bob');
		$this->history->logRestore($secret, $version, 'bob');
	}

	public function testAnOwnerWriteRecordsNoTeamWrite(): void {
		$source = self::secret('src');
		$this->auditTrail->expects($this->never())->method('recordTeamWriteSync');

		$this->history->recordTeamWrite(new ShareSavePath(row: $source, source: $source), 'alice', [['secretId' => 'c']], ['c' => ['targetUserId' => 'x', 'holder' => 'target']]);
	}

	public function testACopyEditorWriteRecordsTheSkippedHoldersOnce(): void {
		$path = new ShareSavePath(row: self::secret('copy'), source: self::secret('src', 'alice', 'Router'));
		$this->auditTrail->expects($this->once())->method('recordTeamWriteSync')->with(
			'bob',
			'src',
			'Router',
			ShareSyncService::DELEGATE_WRITE,
			['carol', 'alice'],
		);

		$this->history->recordTeamWrite(
			$path,
			'bob',
			[['secretId' => 'c-1'], ['secretId' => 'c-2'], ['secretId' => 'c-3'], ['secretId' => 'unknown']],
			[
				'c-1' => ['targetUserId' => 'carol', 'holder' => 'target'],
				'c-2' => ['targetUserId' => 'dave', 'holder' => 'owner'],
				'c-3' => ['targetUserId' => 'carol', 'holder' => 'target'],
			],
		);
	}

	public function testACopyEditorWriteUsesTheFieldsItWritesOn(): void {
		$path = new ShareSavePath(row: self::secret('copy'), source: self::secret('src'), writesOn: 'login');
		$this->auditTrail->expects($this->once())->method('recordTeamWriteSync')->with('bob', 'src', 'Router', 'login', []);

		$this->history->recordTeamWrite($path, 'bob');
	}
}
