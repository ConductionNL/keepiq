<?php

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCA\Keepiq\Service\ShareSync\ShareSyncGuards;
use OCA\Keepiq\Service\ShareSync\ShareWriteContext;
use OCA\Keepiq\Service\ShareSyncService;
use OCA\Keepiq\Service\SecretVersionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The fan-out writer's defence in depth (GHSA-m5p9-6pw7-pf3g): a sync may only
 * write the source row and copies genuinely held by their share target.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-sync-on-update
 */
class ShareSyncServiceTest extends TestCase {

	/** @var ShareTargetMapper&MockObject */
	private ShareTargetMapper $mapper;

	/** @var SecretMapper&MockObject */
	private SecretMapper $secretMapper;

	private ShareSyncService $sync;

	protected function setUp(): void {
		$this->mapper       = $this->createMock(ShareTargetMapper::class);
		$this->secretMapper = $this->createMock(SecretMapper::class);
		$this->sync         = new ShareSyncService(
			mapper: $this->mapper,
			secretMapper: $this->secretMapper,
			db: $this->createMock(IDBConnection::class),
			auth: $this->createMock(ShareAuthorizationService::class),
			versionService: $this->createMock(SecretVersionService::class),
			guards: $this->createMock(ShareSyncGuards::class),
			contexts: $this->createMock(ShareWriteContext::class),
		);
	}

	/**
	 * Build a secret with a user owner.
	 *
	 * @param string $id      The id
	 * @param string $ownerId The owner
	 *
	 * @return Secret
	 */
	private function secret(string $id, string $ownerId): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setOwnerType('user');
		$secret->setOwnerId($ownerId);
		return $secret;
	}

	/**
	 * Build a share row.
	 *
	 * @param string $copyId The linked copy
	 * @param string $target The recipient
	 *
	 * @return ShareTarget
	 */
	private function row(string $copyId, string $target): ShareTarget {
		$row = new ShareTarget();
		$row->setSourceSecretId('src-1');
		$row->setSecretId($copyId);
		$row->setTargetUserId($target);
		return $row;
	}

	/**
	 * A copy whose share row names a DIFFERENT owner than the copy actually has
	 * (a poisoned or drifted secret_id) is skipped, while the source row and a
	 * genuinely held copy are written.
	 *
	 * @return void
	 */
	public function testACopyNotHeldByItsTargetIsNotWritten(): void {
		$source     = $this->secret('src-1', 'alice');
		$heldCopy   = $this->secret('copy-held', 'bob');
		$victimCopy = $this->secret('victim', 'carol');

		$this->secretMapper->method('findById')->willReturnMap(
			[
				['src-1', $source],
				['copy-held', $heldCopy],
				['victim', $victimCopy],
			]
		);

		// The victim row claims bob holds it, but the secret is owned by carol.
		$this->mapper->method('findBySourceSecret')->willReturn(
			[$this->row('copy-held', 'bob'), $this->row('victim', 'bob')]
		);

		$written = [];
		$this->secretMapper->method('update')->willReturnCallback(
			static function (Secret $secret) use (&$written): Secret {
				$written[] = $secret->getId();
				return $secret;
			}
		);

		$count = $this->sync->writeCopyBlobs(
			source: $source,
			updates: [
				['secretId' => 'src-1', 'key' => 'NEW_SRC'],
				['secretId' => 'copy-held', 'key' => 'NEW_HELD'],
				['secretId' => 'victim', 'key' => 'EVIL'],
			],
			allowedIds: ['src-1' => true, 'copy-held' => true, 'victim' => true],
			savedAt: new DateTime('2026-10-21T00:00:00+00:00'),
			writerId: 'alice'
		);

		$this->assertSame(2, $count);
		$this->assertEqualsCanonicalizing(['src-1', 'copy-held'], $written);
		$this->assertNotContains('victim', $written);
	}


	/**
	 * A copy whose row is gone entirely is also skipped (there is nothing to
	 * vouch that the recipient holds it).
	 *
	 * @return void
	 */
	public function testACopyWithNoShareRowIsNotWritten(): void {
		$source = $this->secret('src-1', 'alice');
		$orphan = $this->secret('orphan', 'bob');

		$this->secretMapper->method('findById')->willReturnMap(
			[['src-1', $source], ['orphan', $orphan]]
		);
		$this->mapper->method('findBySourceSecret')->willReturn([]);

		$this->secretMapper->expects($this->never())->method('update');

		$count = $this->sync->writeCopyBlobs(
			source: $source,
			updates: [['secretId' => 'orphan', 'key' => 'EVIL']],
			allowedIds: ['orphan' => true],
			savedAt: new DateTime('2026-10-21T00:00:00+00:00'),
			writerId: 'alice'
		);

		$this->assertSame(0, $count);
	}

	/**
	 * A copy named by the update but already deleted is silently skipped.
	 *
	 * @return void
	 */
	public function testAMissingCopyIsSkipped(): void {
		$source = $this->secret('src-1', 'alice');
		$this->secretMapper->method('findById')->willReturnCallback(
			static function (string $id) use ($source): Secret {
				if ($id === 'src-1') {
					return $source;
				}

				throw new DoesNotExistException('gone');
			}
		);
		$this->mapper->method('findBySourceSecret')->willReturn([$this->row('gone', 'bob')]);
		$this->secretMapper->expects($this->never())->method('update');

		$count = $this->sync->writeCopyBlobs(
			source: $source,
			updates: [['secretId' => 'gone', 'key' => 'X']],
			allowedIds: ['gone' => true],
			savedAt: new DateTime('2026-10-21T00:00:00+00:00'),
			writerId: 'alice'
		);

		$this->assertSame(0, $count);
	}
}
