<?php

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service\ShareSave;

use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\FederatedShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCA\Keepiq\Service\ShareSave\ShareSaveEntries;
use OCA\Keepiq\Service\ShareSave\ShareSavePath;
use OCA\Keepiq\Service\ShareSave\ShareSaveScope;
use OCA\Keepiq\Service\ShareSync\ShareSyncGuards;
use OCA\Keepiq\Service\ShareSyncService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The share-save scope's defence in depth (GHSA-m5p9-6pw7-pf3g): a copy not
 * genuinely held by its target is dropped from the write set, so a save can
 * never overwrite another user's secret.
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-save-names-every-copy-or-is-refused
 */
class ShareSaveScopeTest extends TestCase {

	/** @var ShareTargetMapper&MockObject */
	private ShareTargetMapper $targets;

	/** @var SecretMapper&MockObject */
	private SecretMapper $secrets;

	/** @var FederatedShareMapper&MockObject */
	private FederatedShareMapper $federated;

	private ShareSaveScope $scope;

	protected function setUp(): void {
		$this->targets   = $this->createMock(ShareTargetMapper::class);
		$this->secrets   = $this->createMock(SecretMapper::class);
		$this->federated = $this->createMock(FederatedShareMapper::class);
		$this->federated->method('findBySourceSecret')->willReturn([]);

		// A real ShareSyncGuards so liveCopies() runs the defence-in-depth guard
		// (GHSA-m5p9-6pw7-pf3g) against the mocked targets and secrets.
		$guards = new ShareSyncGuards(
			targets: $this->targets,
			secrets: $this->secrets,
			suites: $this->createMock(EncryptionSuiteMapper::class),
			auth: $this->createMock(ShareAuthorizationService::class),
		);

		$this->scope = new ShareSaveScope(
			targets: $this->targets,
			secrets: $this->secrets,
			federatedShares: $this->federated,
			sync: $this->createMock(ShareSyncService::class),
			guards: $guards,
		);
	}

	/**
	 * Build a user-owned secret.
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
	 * A poisoned copy (owned by someone other than the row's target) is never
	 * required by the save and is listed as ignored, so naming it or leaving it
	 * out never writes it; a genuinely held copy stays required.
	 *
	 * @return void
	 */
	public function testACopyNotHeldByItsTargetIsDroppedFromTheWriteSet(): void {
		$source = $this->secret('src-1', 'alice');
		$path   = new ShareSavePath(row: $source, source: $source);

		$this->targets->method('findBySourceSecret')->willReturn(
			[$this->row('copy-bob', 'bob'), $this->row('victim', 'bob')]
		);
		$this->secrets->method('findByIds')->willReturn(
			[
				'copy-bob' => $this->secret('copy-bob', 'bob'),
				// Owned by carol, though the row claims bob is the target.
				'victim' => $this->secret('victim', 'carol'),
			]
		);

		$entries = new ShareSaveEntries(copies: [['secretId' => 'copy-bob', 'key' => 'NEW']]);

		$required = $this->scope->assertComplete(path: $path, entries: $entries, userId: 'alice');

		$this->assertSame(['copy-bob'], array_keys($required['copies']));
		$this->assertArrayHasKey('victim', $required['ignored']);
	}
}
