<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Whether a share save names exactly the copies and partner shares it must
 * write, and whether a stage stays within what the row can need.
 *
 * @category Tests
 * @package  OCA\Keepiq\Tests\Unit\Service\ShareSave
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-save-names-every-copy-or-is-refused
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service\ShareSave;

use DateTime;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\FederatedShare;
use OCA\Keepiq\Db\FederatedShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCA\Keepiq\Service\ShareSave\IncompleteShareSaveException;
use OCA\Keepiq\Service\ShareSave\ShareSaveEntries;
use OCA\Keepiq\Service\ShareSave\ShareSavePath;
use OCA\Keepiq\Service\ShareSave\ShareSaveRefusal;
use OCA\Keepiq\Service\ShareSave\ShareSaveScope;
use OCA\Keepiq\Service\ShareSync\ShareSyncGuards;
use OCA\Keepiq\Service\ShareSyncService;
use OCA\Keepiq\Tests\Support\ShareRowJudgeFixture;
use OCA\Keepiq\Service\JudgedShareRows;
use OCA\Keepiq\Service\ShareRowJudge;
use OCA\Keepiq\Service\ShareSync\LiveCopiesRun;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Completeness and reach of a share save.
 */
#[CoversClass(ShareSaveScope::class)]
#[UsesClass(ShareSyncGuards::class)]
#[UsesClass(LiveCopiesRun::class)]
#[UsesClass(ShareRowJudge::class)]
#[UsesClass(JudgedShareRows::class)]
#[UsesClass(ShareSavePath::class)]
#[UsesClass(ShareSaveEntries::class)]
#[UsesClass(ShareSaveRefusal::class)]
#[UsesClass(IncompleteShareSaveException::class)]
#[UsesClass(Secret::class)]
#[UsesClass(ShareTarget::class)]
#[UsesClass(FederatedShare::class)]
class ShareSaveScopeCompletenessTest extends TestCase {
	use ShareRowJudgeFixture;

	/** @var ShareTargetMapper&MockObject */
	private ShareTargetMapper $targets;

	/** @var SecretMapper&MockObject */
	private SecretMapper $secrets;

	/** @var FederatedShareMapper&MockObject */
	private FederatedShareMapper $federated;

	/** @var EncryptionSuiteMapper&MockObject */
	private EncryptionSuiteMapper $suites;

	private ShareSaveScope $scope;

	/**
	 * alice owns src-1; bob and dave hold genuine copies of it.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->targets = $this->createMock(ShareTargetMapper::class);
		$this->secrets = $this->createMock(SecretMapper::class);
		$this->federated = $this->createMock(FederatedShareMapper::class);
		$this->suites = $this->createMock(EncryptionSuiteMapper::class);

		$this->targets->method('findBySourceSecret')->willReturn([$this->row('copy-bob', 'bob'), $this->row('copy-dave', 'dave')]);
		$this->secrets->method('findByIds')->willReturn(
			['copy-bob' => $this->secret('copy-bob', 'bob'), 'copy-dave' => $this->secret('copy-dave', 'dave')]
		);

		$guards = new ShareSyncGuards(
			rowJudge: $this->shareRowJudge(targets: $this->targets, secrets: $this->secrets),
			secrets: $this->secrets,
			suites: $this->suites,
			auth: $this->createMock(ShareAuthorizationService::class),
		);
		$this->scope = new ShareSaveScope(
			rowJudge: $this->shareRowJudge(targets: $this->targets, secrets: $this->secrets),
			secrets: $this->secrets,
			federatedShares: $this->federated,
			sync: $this->createMock(ShareSyncService::class),
			guards: $guards,
		);
	}//end setUp()

	/**
	 * A user-owned secret.
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
	}//end secret()

	/**
	 * A share row of src-1.
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
	}//end row()

	/**
	 * A partner share of src-1.
	 *
	 * @param string $id      The share id
	 * @param string $ownerId Who made it
	 * @param string $status  Its status
	 *
	 * @return FederatedShare
	 */
	private function partnerShare(string $id, string $ownerId, string $status = FederatedShare::STATUS_ACTIVE): FederatedShare {
		$share = new FederatedShare();
		$share->setId($id);
		$share->setOwnerId($ownerId);
		$share->setRecipientCloudId($id . '@partner.example');
		$share->setStatus($status);
		return $share;
	}//end partnerShare()

	/**
	 * The owner's path on src-1, last changed at a fixed time.
	 *
	 * @return ShareSavePath
	 */
	private function ownerPath(): ShareSavePath {
		$source = $this->secret('src-1', 'alice');
		$source->setUpdatedAt(new DateTime('2026-10-01T10:00:00+00:00'));
		return new ShareSavePath(row: $source, source: $source);
	}//end ownerPath()

	/**
	 * The refusal assertComplete throws for these entries.
	 *
	 * @param ShareSavePath    $path    The path
	 * @param ShareSaveEntries $entries The entries
	 *
	 * @return array<string,mixed> The refusal body
	 */
	private function incomplete(ShareSavePath $path, ShareSaveEntries $entries): array {
		try {
			$this->scope->assertComplete(path: $path, entries: $entries, userId: 'alice');
		} catch (IncompleteShareSaveException $refusal) {
			$this->assertSame(409, $refusal->getStatus());
			return $refusal->getBody();
		}
		$this->fail('The save was accepted');
	}//end incomplete()

	/**
	 * Naming every copy and the owner's own active partner share is complete.
	 *
	 * @return void
	 */
	public function testNamingEveryCopyAndPartnerShareIsComplete(): void {
		$this->federated->method('findBySourceSecret')->willReturn(
			[
				$this->partnerShare('f-1', 'alice'),
				$this->partnerShare('f-2', 'erin'),
				$this->partnerShare('f-3', 'alice', 'revoked'),
			]
		);
		$entries = new ShareSaveEntries(
			copies: [['secretId' => 'copy-bob'], ['secretId' => 'copy-dave']],
			federated: [['shareId' => 'f-1']],
		);

		$required = $this->scope->assertComplete(path: $this->ownerPath(), entries: $entries, userId: 'alice');

		$this->assertSame(['copy-bob', 'copy-dave'], array_keys($required['copies']));
		$this->assertSame(['f-1'], array_keys($required['federated']));
		$this->assertFalse($required['ownerOnly']);
	}//end testNamingEveryCopyAndPartnerShareIsComplete()

	/**
	 * A missing copy, a stray copy, a missing and a stray partner share are all listed in the refusal.
	 *
	 * @return void
	 */
	public function testEveryGapIsListedInTheRefusal(): void {
		$this->federated->method('findBySourceSecret')->willReturn([$this->partnerShare('f-1', 'alice')]);
		$entries = new ShareSaveEntries(
			copies: [['secretId' => 'copy-bob'], ['secretId' => 'stray']],
			federated: [['shareId' => 'f-9']],
		);

		$body = $this->incomplete(path: $this->ownerPath(), entries: $entries);

		$this->assertSame('incomplete', $body['error']);
		$this->assertSame('owner', $body['path']);
		$this->assertSame('2026-10-01T10:00:00+00:00', $body['sourceUpdatedAt']);
		$this->assertSame(['copy-dave'], $body['copies']['missing']);
		$this->assertSame(['stray'], $body['copies']['unexpected']);
		$this->assertSame([], $body['copies']['skipRefused']);
		$this->assertSame(
			[['secretId' => 'copy-bob', 'holder' => 'recipient', 'targetUserId' => 'bob'], ['secretId' => 'copy-dave', 'holder' => 'recipient', 'targetUserId' => 'dave']],
			$body['copies']['required']
		);
		$this->assertSame(
			['required' => [['shareId' => 'f-1', 'recipientCloudId' => 'f-1@partner.example']], 'missing' => ['f-1'], 'unexpected' => ['f-9']],
			$body['federated']
		);
	}//end testEveryGapIsListedInTheRefusal()

	/**
	 * A copy may be skipped only when its holder has no vault key to re-encrypt it with.
	 *
	 * @return void
	 */
	public function testASkipIsRefusedWhenTheHolderHasAVaultKey(): void {
		$this->federated->method('findBySourceSecret')->willReturn([]);
		$this->suites->method('countActiveByOwner')->willReturnCallback(
			static fn (string $ownerType, string $ownerId): int => ($ownerId === 'bob' ? 1 : 0)
		);

		$body = $this->incomplete(
			path: $this->ownerPath(),
			entries: new ShareSaveEntries(skipped: [['secretId' => 'copy-bob'], ['secretId' => 'copy-dave']])
		);

		$this->assertSame(['copy-bob'], $body['copies']['skipRefused']);
		$this->assertSame([], $body['copies']['missing']);

		$required = $this->scope->assertComplete(
			path: $this->ownerPath(),
			entries: new ShareSaveEntries(copies: [['secretId' => 'copy-bob']], skipped: [['secretId' => 'copy-dave']]),
			userId: 'alice'
		);
		$this->assertCount(2, $required['copies']);
	}//end testASkipIsRefusedWhenTheHolderHasAVaultKey()

	/**
	 * A copy editor must also name the source, never the partner shares, and learns only that they exist.
	 *
	 * @return void
	 */
	public function testACopyEditorNamesTheSourceButNoPartnerShares(): void {
		$this->federated->method('findBySourceSecret')->willReturn([$this->partnerShare('f-1', 'alice')]);
		$source = $this->secret('src-1', 'alice');
		$path = new ShareSavePath(row: $this->secret('copy-bob', 'bob'), source: $source, writesOn: 'bob');

		$required = $this->scope->assertComplete(
			path: $path,
			entries: new ShareSaveEntries(copies: [['secretId' => 'src-1'], ['secretId' => 'copy-dave']]),
			userId: 'bob'
		);

		$this->assertSame(['src-1', 'copy-dave'], array_keys($required['copies']));
		$this->assertSame(['secretId' => 'src-1', 'holder' => 'owner', 'targetUserId' => null], $required['copies']['src-1']);
		$this->assertSame([], $required['federated']);
		$this->assertTrue($required['ownerOnly']);
	}//end testACopyEditorNamesTheSourceButNoPartnerShares()

	/**
	 * A stage may not name more rows than exist, nor use more chunks than those rows need.
	 *
	 * @return void
	 */
	public function testAStageStaysWithinReach(): void {
		$this->federated->method('findBySourceSecret')->willReturn([$this->partnerShare('f-1', 'alice')]);
		$path = $this->ownerPath();
		$fits = new ShareSaveEntries(copies: [['secretId' => 'copy-bob'], ['secretId' => 'copy-dave']], federated: [['shareId' => 'f-1']]);

		$this->scope->assertWithinReach(path: $path, entries: $fits, chunks: 1, userId: 'alice');

		$cases = [
			['too_many_entries', new ShareSaveEntries(copies: [['secretId' => 'a'], ['secretId' => 'b'], ['secretId' => 'c']]), 1],
			['too_many_entries', new ShareSaveEntries(federated: [['shareId' => 'f-1']], suspend: [['shareId' => 'f-2']]), 1],
			['too_many_chunks', $fits, 2],
		];
		foreach ($cases as [$error, $entries, $chunks]) {
			try {
				$this->scope->assertWithinReach(path: $path, entries: $entries, chunks: $chunks, userId: 'alice');
				$this->fail($error . ' was accepted');
			} catch (ShareSaveRefusal $refusal) {
				$this->assertSame([$error, 400], [$refusal->getError(), $refusal->getStatus()]);
			}
		}
	}//end testAStageStaysWithinReach()
}//end class
