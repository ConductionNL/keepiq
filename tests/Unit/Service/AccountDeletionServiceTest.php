<?php

/**
 * Unit tests for AccountDeletionService — the GDPR Art. 17 cascade.
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

use OCA\Keepiq\Db\DashboardSettingMapper;
use OCA\Keepiq\Db\EmergencyContactMapper;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\FederatedShareMapper;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\LinkShareMapper;
use OCA\Keepiq\Db\PasskeyCredential;
use OCA\Keepiq\Db\PasskeyMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretDelegation;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretRequestMapper;
use OCA\Keepiq\Db\ShareSaveStageMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Db\SuiteMigrationMapper;
use OCA\Keepiq\Event\AccountDataDeletedEvent;
use OCA\Keepiq\Service\AccountDeletionService;
use OCA\Keepiq\Service\AccountShareCleanupService;
use OCA\Keepiq\Service\AccountSuiteCleanupService;
use OCA\Keepiq\Service\SecretChildDataCleaner;
use OCA\Keepiq\Db\GroupShare;
use OCA\Keepiq\Service\SecretOwnerTransferService;
use OCA\Keepiq\Service\ShareQuarantineScanner;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCA\Keepiq\Tests\Support\ShareRowJudgeFixture;
use PHPUnit\Framework\TestCase;

/**
 * Tests per cascade step + idempotency + event emission.
 */
class AccountDeletionServiceTest extends TestCase {
	use ShareRowJudgeFixture;


	/**
	 * Collaborator mocks, keyed for per-test wiring.
	 *
	 * @var array<string,object>
	 */
	private array $m = [];

	/**
	 * Build the service with fresh mocks.
	 *
	 * @return AccountDeletionService
	 */
	private function build(): AccountDeletionService {
		$this->m = [
			'secret' => $this->createMock(SecretMapper::class),
			'folder' => $this->createMock(FolderMapper::class),
			'share' => $this->createMock(ShareTargetMapper::class),
			'group' => $this->createMock(GroupShareMapper::class),
			'delegation' => $this->createMock(SecretDelegationMapper::class),
			'link' => $this->createMock(LinkShareMapper::class),
			'request' => $this->createMock(SecretRequestMapper::class),
			'suite' => $this->createMock(EncryptionSuiteMapper::class),
			'migration' => $this->createMock(SuiteMigrationMapper::class),
			'setting' => $this->createMock(DashboardSettingMapper::class),
			'dispatcher' => $this->createMock(IEventDispatcher::class),
			'emergency' => $this->createMock(EmergencyContactMapper::class),
			'passkey' => $this->createMock(PasskeyMapper::class),
			'stage' => $this->createMock(ShareSaveStageMapper::class),
			'federated' => $this->createMock(FederatedShareMapper::class),
			'scan' => $this->createMock(ShareQuarantineScanner::class),
		];

		return new AccountDeletionService(
			secretMapper: $this->m['secret'],
			folderMapper: $this->m['folder'],
			linkShareMapper: $this->m['link'],
			requestMapper: $this->m['request'],
			settingMapper: $this->m['setting'],
			dispatcher: $this->m['dispatcher'],
			shareCleanup: new AccountShareCleanupService(
				secretMapper: $this->m['secret'],
				shareMapper: $this->m['share'],
				groupShareMapper: $this->m['group'],
				delegationMapper: $this->m['delegation'],
				stageMapper: $this->m['stage'],
				ownerTransfer: new SecretOwnerTransferService(
					db: $this->createMock(IDBConnection::class),
					secretMapper: $this->m['secret'],
					federatedShares: $this->m['federated'],
					linkShares: $this->m['link'],
				),
				quarantineScan: $this->m['scan'],
				rowJudge: $this->shareRowJudge(targets: $this->m['share'], secrets: $this->m['secret'], scanner: $this->m['scan']),
			),
			suiteCleanup: new AccountSuiteCleanupService(
				suiteMapper: $this->m['suite'],
				migrationMapper: $this->m['migration'],
				emergencyMapper: $this->m['emergency'],
				passkeyMapper: $this->m['passkey'],
			),
			childData: new SecretChildDataCleaner(secretMapper: $this->m['secret']),
		);
	}//end build()

	/**
	 * A Secret owned by a user.
	 *
	 * @param string $id The secret ID
	 * @param string $owner The owner ID
	 *
	 * @return Secret
	 */
	private function secret(string $id, string $owner = 'alice'): Secret {
		$s = new Secret();
		$s->setId($id);
		$s->setOwnerType('user');
		$s->setOwnerId($owner);
		return $s;
	}//end secret()

	/**
	 * Stub the mappers to return nothing (empty vault baseline).
	 *
	 * @return void
	 */
	private function emptyBaseline(): void {
		$this->m['secret']->method('findByOwner')->willReturn([]);
		$this->m['delegation']->method('findByOriginalOwner')->willReturn([]);
		$this->m['share']->method('findByTargetUser')->willReturn([]);
		$this->m['link']->method('findByCreatedBy')->willReturn([]);
		$this->m['suite']->method('findByOwner')->willReturn([]);
		$this->m['secret']->method('deleteByOwnerUser')->willReturn(0);
		$this->m['folder']->method('deleteByOwnerUser')->willReturn(0);
		$this->m['request']->method('deleteByCreatedBy')->willReturn(0);
		$this->m['suite']->method('deleteByOwnerUser')->willReturn(0);
		$this->m['migration']->method('deleteBySuiteIds')->willReturn(0);
	}//end emptyBaseline()

	/**
	 * Step 1: a delegated secret transfers ownership + delegation made permanent.
	 *
	 * @return void
	 */
	public function testDelegatedSecretTransfersOwnership(): void {
		$service = $this->build();
		$secret = $this->secret('sec-1');
		$this->m['secret']->method('findByOwner')->willReturn([$secret]);
		$this->m['secret']->method('findById')->willReturn($secret);

		$delegation = new SecretDelegation();
		$delegation->setSecretId('sec-1');
		$delegation->setOriginalOwnerId('alice');
		$delegation->setDelegatedTo('bob');
		$this->m['delegation']->method('findByOriginalOwner')->willReturn([$delegation]);

		$this->m['secret']->expects($this->once())
			->method('reassignOwner')
			->with('sec-1', 'bob');
		// The secret's partner shares go to the delegate with it.
		$this->m['federated']->expects($this->once())
			->method('reassignOwner')
			->with('sec-1', 'bob');
		$this->m['delegation']->expects($this->once())
			->method('makePermanentByOriginalOwner')
			->with('alice');

		$this->m['share']->method('findBySourceSecret')->willReturn([]);
		$this->m['group']->method('findBySecret')->willReturn([]);
		$this->m['share']->method('findByTargetUser')->willReturn([]);
		$this->m['link']->method('findByCreatedBy')->willReturn([]);
		$this->m['suite']->method('findByOwner')->willReturn([]);
		$this->m['secret']->method('deleteByOwnerUser')->willReturn(0);
		$this->m['folder']->method('deleteByOwnerUser')->willReturn(0);
		$this->m['request']->method('deleteByCreatedBy')->willReturn(0);
		$this->m['suite']->method('deleteByOwnerUser')->willReturn(0);
		$this->m['migration']->method('deleteBySuiteIds')->willReturn(0);

		$report = $service->deleteAllFor('alice');
		$this->assertSame(1, $report->secretsTransferred);
	}//end testDelegatedSecretTransfersOwnership()

	/**
	 * The departing user's link shares on a delegated secret are deleted with
	 * the rest of their link shares, not handed to the delegate with the
	 * secret.
	 *
	 * @return void
	 */
	public function testLinkSharesOnADelegatedSecretAreDeletedNotHandedOver(): void {
		$service = $this->build();
		$secret = $this->secret('sec-1');
		$this->m['secret']->method('findByOwner')->willReturn([$secret]);
		$this->m['secret']->method('findById')->willReturn($secret);

		$delegation = new SecretDelegation();
		$delegation->setSecretId('sec-1');
		$delegation->setOriginalOwnerId('alice');
		$delegation->setDelegatedTo('bob');
		$this->m['delegation']->method('findByOriginalOwner')->willReturn([$delegation]);

		// The link table: Alice's link on the delegated secret, Bob's own.
		$links = [['sec-1', 'alice'], ['sec-1', 'bob']];
		$this->m['link']->method('findByCreatedBy')->willReturnCallback(
			static function (string $userId) use (&$links): array {
				return array_values(array_filter($links, static fn (array $link): bool => $link[1] === $userId));
			}
		);
		$this->m['link']->method('deleteByUserId')->willReturnCallback(
			static function (string $userId) use (&$links): void {
				$links = array_values(array_filter($links, static fn (array $link): bool => $link[1] !== $userId));
			}
		);
		$this->m['link']->method('reassignCreator')->willReturnCallback(
			static function (string $secretId, string $fromUserId, string $toUserId) use (&$links): int {
				$moved = 0;
				foreach ($links as $i => $link) {
					if ($link === [$secretId, $fromUserId]) {
						$links[$i] = [$secretId, $toUserId];
						++$moved;
					}
				}

				return $moved;
			}
		);

		$this->m['share']->method('findBySourceSecret')->willReturn([]);
		$this->m['group']->method('findBySecret')->willReturn([]);
		$this->m['share']->method('findByTargetUser')->willReturn([]);
		$this->m['suite']->method('findByOwner')->willReturn([]);
		$this->m['secret']->method('deleteByOwnerUser')->willReturn(0);
		$this->m['folder']->method('deleteByOwnerUser')->willReturn(0);
		$this->m['request']->method('deleteByCreatedBy')->willReturn(0);
		$this->m['suite']->method('deleteByOwnerUser')->willReturn(0);
		$this->m['migration']->method('deleteBySuiteIds')->willReturn(0);

		$report = $service->deleteAllFor('alice');

		$this->assertSame(1, $report->secretsTransferred);
		$this->assertSame(1, $report->linkSharesDeleted);
		$this->assertSame([['sec-1', 'bob']], $links);
	}//end testLinkSharesOnADelegatedSecretAreDeletedNotHandedOver()

	/**
	 * A secret delegated to two users is handed over once per delegation.
	 * The second hand-over takes the secret from the first delegate, not
	 * from the departing user, so the links the first delegate made stay
	 * theirs.
	 *
	 * @return void
	 */
	public function testASecondDelegationLeavesTheFirstDelegatesLinksAlone(): void {
		$service = $this->build();
		$secret = $this->secret('sec-1');
		$this->m['secret']->method('findByOwner')->willReturn([$secret]);
		$this->m['secret']->method('findById')->willReturn($secret);
		$this->m['secret']->method('reassignOwner')->willReturnCallback(
			static function (string $secretId, string $newOwnerId) use ($secret): void {
				$secret->setOwnerId($newOwnerId);
			}
		);

		$delegations = [];
		foreach (['bob', 'carol'] as $delegate) {
			$delegation = new SecretDelegation();
			$delegation->setSecretId('sec-1');
			$delegation->setOriginalOwnerId('alice');
			$delegation->setDelegatedTo($delegate);
			$delegations[] = $delegation;
		}

		$this->m['delegation']->method('findByOriginalOwner')->willReturn($delegations);

		// The link table: Bob's link, made as Alice's delegate.
		$links = [['sec-1', 'bob']];
		$this->m['link']->method('findByCreatedBy')->willReturnCallback(
			static function (string $userId) use (&$links): array {
				return array_values(array_filter($links, static fn (array $link): bool => $link[1] === $userId));
			}
		);
		$this->m['link']->method('reassignCreator')->willReturnCallback(
			static function (string $secretId, string $fromUserId, string $toUserId) use (&$links): int {
				$moved = 0;
				foreach ($links as $i => $link) {
					if ($link === [$secretId, $fromUserId]) {
						$links[$i] = [$secretId, $toUserId];
						++$moved;
					}
				}

				return $moved;
			}
		);

		$this->m['share']->method('findBySourceSecret')->willReturn([]);
		$this->m['group']->method('findBySecret')->willReturn([]);
		$this->m['share']->method('findByTargetUser')->willReturn([]);
		$this->m['suite']->method('findByOwner')->willReturn([]);
		$this->m['secret']->method('deleteByOwnerUser')->willReturn(0);
		$this->m['folder']->method('deleteByOwnerUser')->willReturn(0);
		$this->m['request']->method('deleteByCreatedBy')->willReturn(0);
		$this->m['suite']->method('deleteByOwnerUser')->willReturn(0);
		$this->m['migration']->method('deleteBySuiteIds')->willReturn(0);

		$service->deleteAllFor('alice');

		$this->assertSame([['sec-1', 'bob']], $links);
	}//end testASecondDelegationLeavesTheFirstDelegatesLinksAlone()

	/**
	 * The departing user is dropped as the maker of the links an earlier
	 * transfer handed to someone else, so their uid does not outlive the
	 * deletion there.
	 *
	 * @return void
	 */
	public function testTheUserIsForgottenAsTheMakerOfLinksHandedOver(): void {
		$service = $this->build();
		$this->emptyBaseline();

		$this->m['link']->expects($this->once())->method('forgetOriginalCreator')->with('alice')->willReturn(2);

		$report = $service->deleteAllFor('alice');

		$this->assertSame(2, $report->linkMakersForgotten);
		$this->assertSame(2, $report->jsonSerialize()['linkSharesMakerForgotten']);
	}//end testTheUserIsForgottenAsTheMakerOfLinksHandedOver()

	/**
	 * Step 2: a granted share detaches — link deleted, recipient copy tombstoned
	 * with a NON-PERSONAL reason (no deleted-user identity written).
	 *
	 * @return void
	 */
	public function testGrantedShareDetachedWithNonPersonalTombstone(): void {
		$service = $this->build();
		$secret = $this->secret('sec-1');
		$this->m['secret']->method('findByOwner')->willReturn([$secret]);
		$this->m['delegation']->method('findByOriginalOwner')->willReturn([]);

		$share = new ShareTarget();
		$share->setSourceSecretId('sec-1');
		$share->setTargetUserId('bob');
		$share->setSecretId('recipient-copy-1');
		$this->m['share']->method('findBySourceSecret')->willReturn([$share]);
		$this->m['group']->method('findBySecret')->willReturn([]);

		// The recipient copy is genuinely held by its target, so it is tombstoned.
		$this->m['secret']->method('findById')->with('recipient-copy-1')
			->willReturn($this->secret('recipient-copy-1', 'bob'));

		// Tombstone the recipient copy with the non-personal reason.
		$this->m['secret']->expects($this->once())
			->method('tombstone')
			->with('recipient-copy-1', AccountDeletionService::TOMBSTONE_REASON);
		// Sever the link.
		$this->m['share']->expects($this->once())
			->method('deleteBySourceSecret')
			->with('sec-1');

		$this->m['share']->method('findByTargetUser')->willReturn([]);
		$this->m['link']->method('findByCreatedBy')->willReturn([]);
		$this->m['suite']->method('findByOwner')->willReturn([]);
		$this->m['secret']->method('deleteByOwnerUser')->willReturn(0);
		$this->m['folder']->method('deleteByOwnerUser')->willReturn(0);
		$this->m['request']->method('deleteByCreatedBy')->willReturn(0);
		$this->m['suite']->method('deleteByOwnerUser')->willReturn(0);
		$this->m['migration']->method('deleteBySuiteIds')->willReturn(0);

		$report = $service->deleteAllFor('alice');
		$this->assertSame(1, $report->sharesDetached);
		// The non-personal reason must not embed the deleted user's id.
		$this->assertStringNotContainsString('alice', AccountDeletionService::TOMBSTONE_REASON);
	}//end testGrantedShareDetachedWithNonPersonalTombstone()

	/**
	 * Step 3: received shares are removed (link severed) and the original owner's
	 * secret is untouched (the service never deletes by source secret here).
	 *
	 * @return void
	 */
	public function testReceivedSharesRemoved(): void {
		$service = $this->build();
		$this->m['secret']->method('findByOwner')->willReturn([]);
		$this->m['delegation']->method('findByOriginalOwner')->willReturn([]);

		$received = new ShareTarget();
		$received->setSourceSecretId('owner-secret');
		$received->setTargetUserId('alice');
		$received->setSecretId('alice-copy');
		$this->m['share']->method('findByTargetUser')->willReturn([$received]);

		$this->m['share']->expects($this->once())
			->method('deleteByTargetUser')
			->with('alice')
			->willReturn(1);
		// Staged share-save ciphertext goes too, on either deletion path.
		$this->m['stage']->expects($this->once())->method('deleteByUser')->with('alice');

		$this->m['link']->method('findByCreatedBy')->willReturn([]);
		$this->m['suite']->method('findByOwner')->willReturn([]);
		$this->m['secret']->method('deleteByOwnerUser')->willReturn(0);
		$this->m['folder']->method('deleteByOwnerUser')->willReturn(0);
		$this->m['request']->method('deleteByCreatedBy')->willReturn(0);
		$this->m['suite']->method('deleteByOwnerUser')->willReturn(0);
		$this->m['migration']->method('deleteBySuiteIds')->willReturn(0);

		$report = $service->deleteAllFor('alice');
		$this->assertSame(1, $report->sharesRemoved);
	}//end testReceivedSharesRemoved()

	/**
	 * Link shares, requests, secrets, folders, suites + migrations and
	 * settings all removed, with report counts.
	 *
	 * @return void
	 */
	public function testFullCascadeCountsAndSuiteRemoval(): void {
		$service = $this->build();
		$this->m['secret']->method('findByOwner')->willReturn([]);
		$this->m['delegation']->method('findByOriginalOwner')->willReturn([]);
		$this->m['share']->method('findByTargetUser')->willReturn([]);
		$this->m['link']->method('findByCreatedBy')->willReturn(['a', 'b']);

		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$this->m['suite']->method('findByOwner')->willReturn([$suite]);

		$this->m['link']->expects($this->once())->method('deleteByUserId')->with('alice');
		$this->m['request']->method('deleteByCreatedBy')->willReturn(4);
		$this->m['secret']->method('deleteByOwnerUser')->willReturn(200);
		$this->m['folder']->method('deleteByOwnerUser')->willReturn(7);
		$this->m['migration']->expects($this->once())->method('deleteBySuiteIds')->with(['suite-1'])->willReturn(1);
		$this->m['suite']->method('deleteByOwnerUser')->willReturn(1);
		$this->m['setting']->expects($this->once())->method('deleteByUser')->with('alice');

		$report = $service->deleteAllFor('alice');
		$this->assertSame(2, $report->linkSharesDeleted);
		$this->assertSame(4, $report->requestsDeleted);
		$this->assertSame(200, $report->secretsDeleted);
		$this->assertSame(7, $report->foldersDeleted);
		$this->assertSame(1, $report->suitesDeleted);
		$this->assertTrue($report->settingsDeleted);
	}//end testFullCascadeCountsAndSuiteRemoval()

	/**
	 * AccountDataDeletedEvent is dispatched once, on a completed run, carrying
	 * counts + trigger only.
	 *
	 * @return void
	 */
	public function testEventDispatchedOnCompletionWithCountsOnly(): void {
		$service = $this->build();
		$this->emptyBaseline();

		$captured = null;
		$this->m['dispatcher']->expects($this->once())
			->method('dispatchTyped')
			->willReturnCallback(
				function ($event) use (&$captured) {
					$captured = $event;
				}
			);

		$service->deleteAllFor('alice', 'in-app');

		$this->assertInstanceOf(AccountDataDeletedEvent::class, $captured);
		$this->assertSame('alice', $captured->getUserId());
		$this->assertSame('in-app', $captured->getTrigger());
		$meta = $captured->getMetadata();
		$this->assertSame(['trigger', 'secretCount', 'shareCount', 'requestCount', 'suiteCount'], array_keys($meta));
	}//end testEventDispatchedOnCompletionWithCountsOnly()

	/**
	 * Idempotency: running the cascade twice completes without error.
	 *
	 * @return void
	 */
	public function testIdempotentReRun(): void {
		$service = $this->build();
		$this->emptyBaseline();
		$this->m['dispatcher']->method('dispatchTyped');

		$first = $service->deleteAllFor('alice');
		$second = $service->deleteAllFor('alice');

		$this->assertSame(0, $first->secretsDeleted);
		$this->assertSame(0, $second->secretsDeleted);
	}//end testIdempotentReRun()

	/**
	 * Issue #861: the erasure removes every emergency relationship (the grantor
	 * row escrows the user's private key to the grantee) and every passkey
	 * unlock envelope, and the report counts both.
	 *
	 * @return void
	 */
	public function testErasureRemovesEscrowedEmergencyKeysAndPasskeys(): void {
		$service = $this->build();
		$this->emptyBaseline();

		$this->m['emergency']->expects($this->once())
			->method('deleteByUser')
			->with('alice')
			->willReturn(2);
		$this->m['passkey']->method('findByOwner')->with('alice')
			->willReturn([new PasskeyCredential(), new PasskeyCredential()]);
		$this->m['passkey']->expects($this->once())
			->method('deleteByOwner')
			->with('alice');

		$report = $service->deleteAllFor('alice');

		$this->assertSame(2, $report->emergencyDeleted);
		$this->assertSame(2, $report->passkeysDeleted);
		$this->assertSame(2, $report->jsonSerialize()['emergencyContactsDeleted']);
		$this->assertSame(2, $report->jsonSerialize()['passkeysDeleted']);
	}//end testErasureRemovesEscrowedEmergencyKeysAndPasskeys()

	/**
	 * The rows of the D5 cases, by id: Alice's `sec-1`, Dave's copy `c-dave`
	 * and Carol's copy `c-carol`.
	 *
	 * @var array<string,Secret>
	 */
	private array $secrets = [];

	/**
	 * The live share rows, by id.
	 *
	 * @var array<string,ShareTarget>
	 */
	private array $shareRows = [];

	/**
	 * The group shares of `sec-1`.
	 *
	 * @var list<GroupShare>
	 */
	private array $groupShares = [];

	/**
	 * The copies tombstoned, in order.
	 *
	 * @var list<string>
	 */
	private array $tombstoned = [];

	/**
	 * Alice's `sec-1` is shared with Dave and Carol, and Dave holds an active
	 * delegation on it. The mappers keep their rows in this test, so a second
	 * run of the cascade sees what the first one left.
	 *
	 * @param string|null $daveGroupShareId The group share Dave's row came from, if any
	 *
	 * @return AccountDeletionService
	 */
	private function sharedWithTheDelegate(?string $daveGroupShareId = null): AccountDeletionService {
		$service = $this->build();
		$this->secrets = [
			'sec-1' => $this->secret('sec-1'),
			'c-dave' => $this->secret('c-dave', 'dave'),
			'c-carol' => $this->secret('c-carol', 'carol'),
		];
		$this->shareRows = [
			'st-dave' => $this->shareRow(id: 'st-dave', copyId: 'c-dave', target: 'dave', groupShareId: $daveGroupShareId),
			'st-carol' => $this->shareRow(id: 'st-carol', copyId: 'c-carol', target: 'carol'),
		];
		if ($daveGroupShareId !== null) {
			$groupShare = new GroupShare();
			$groupShare->setId($daveGroupShareId);
			$groupShare->setSecretId('sec-1');
			$this->groupShares = [$groupShare];
		}

		$delegation = new SecretDelegation();
		$delegation->setSecretId('sec-1');
		$delegation->setOriginalOwnerId('alice');
		$delegation->setDelegatedTo('dave');
		$this->m['delegation']->method('findByOriginalOwner')->willReturn([$delegation]);

		$this->wireSecretTable();
		$this->wireShareTable();
		$this->m['group']->method('findBySecret')->willReturnCallback(fn (string $secretId): array => array_values(
			array_filter($this->groupShares, static fn (GroupShare $share): bool => $share->getSecretId() === $secretId)
		));
		$this->m['group']->method('deleteBySecret')->willReturnCallback(function (string $secretId): void {
			$this->groupShares = array_values(array_filter($this->groupShares, static fn (GroupShare $share): bool => $share->getSecretId() !== $secretId));
		});

		$this->m['link']->method('findByCreatedBy')->willReturn([]);
		$this->m['suite']->method('findByOwner')->willReturn([]);
		$this->m['folder']->method('deleteByOwnerUser')->willReturn(0);
		$this->m['request']->method('deleteByCreatedBy')->willReturn(0);
		$this->m['suite']->method('deleteByOwnerUser')->willReturn(0);
		$this->m['migration']->method('deleteBySuiteIds')->willReturn(0);

		return $service;
	}//end sharedWithTheDelegate()

	/**
	 * A share row of `sec-1`.
	 *
	 * @param string $id The row id
	 * @param string $copyId The copy it names
	 * @param string $target Its target
	 * @param string|null $groupShareId The group share it came from, if any
	 *
	 * @return ShareTarget
	 */
	private function shareRow(string $id, string $copyId, string $target, ?string $groupShareId = null): ShareTarget {
		$row = new ShareTarget();
		$row->setId($id);
		$row->setSourceSecretId('sec-1');
		$row->setSecretId($copyId);
		$row->setTargetUserId($target);
		$row->setGroupShareId($groupShareId);

		return $row;
	}//end shareRow()

	/**
	 * The secret mapper over $this->secrets.
	 *
	 * @return void
	 */
	private function wireSecretTable(): void {
		$secrets = $this->m['secret'];
		$secrets->method('findByOwner')->willReturnCallback(fn (string $ownerType, string $ownerId): array => array_values(
			array_filter($this->secrets, static fn (Secret $secret): bool => $secret->getOwnerId() === $ownerId)
		));
		$secrets->method('findById')->willReturnCallback(
			fn (string $id): Secret => ($this->secrets[$id] ?? throw new DoesNotExistException('none'))
		);
		$secrets->method('reassignOwner')->willReturnCallback(function (string $secretId, string $newOwnerId): void {
			$this->secrets[$secretId]->setOwnerId($newOwnerId);
		});
		$secrets->method('tombstone')->willReturnCallback(function (string $secretId): void {
			$this->tombstoned[] = $secretId;
		});
		$secrets->method('deleteByOwnerUser')->willReturnCallback(function (string $ownerId): int {
			$before = count($this->secrets);
			$this->secrets = array_filter($this->secrets, static fn (Secret $secret): bool => $secret->getOwnerId() !== $ownerId);
			return $before - count($this->secrets);
		});
	}//end wireSecretTable()

	/**
	 * The share-target mapper over $this->shareRows.
	 *
	 * @return void
	 */
	private function wireShareTable(): void {
		$shares = $this->m['share'];
		$bySource = fn (string $sourceId): array => array_values(
			array_filter($this->shareRows, static fn (ShareTarget $row): bool => $row->getSourceSecretId() === $sourceId)
		);
		$shares->method('findBySourceSecret')->willReturnCallback($bySource);
		$shares->method('findBySourceSecretAndTargetUser')->willReturnCallback(
			function (string $sourceId, string $targetUserId) use ($bySource): ShareTarget {
				$rows = array_values(array_filter($bySource($sourceId), static fn (ShareTarget $row): bool => $row->getTargetUserId() === $targetUserId));
				if (count($rows) > 1) {
					throw new MultipleObjectsReturnedException('two');
				}

				return ($rows[0] ?? throw new DoesNotExistException('none'));
			}
		);
		$shares->method('findByRecipientSecret')->willThrowException(new DoesNotExistException('none'));
		$shares->method('deleteBySourceSecret')->willReturnCallback(function (string $sourceId): void {
			$this->shareRows = array_filter($this->shareRows, static fn (ShareTarget $row): bool => $row->getSourceSecretId() !== $sourceId);
		});
		$shares->method('deleteBySourceSecretExcept')->willReturnCallback(function (string $sourceId, string $keepId): void {
			$this->shareRows = array_filter(
				$this->shareRows,
				static fn (ShareTarget $row): bool => $row->getSourceSecretId() !== $sourceId || $row->getId() === $keepId
			);
		});
		$shares->method('update')->willReturnCallback(function (ShareTarget $row): ShareTarget {
			$this->shareRows[$row->getId()] = $row;
			return $row;
		});
		$shares->method('findByTargetUser')->willReturn([]);
		$shares->method('deleteByTargetUser')->willReturn(0);
	}//end wireShareTable()

	/**
	 * The delegate's copy stays linked and untouched; the other recipient's
	 * copy is detached as before.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sharing-share-save-missed-partner-shares/specs/gdpr-compliance/spec.md#scenario-the-delegate-keeps-their-linked-copy
	 */
	public function testTheDelegateKeepsTheirLinkedCopy(): void {
		$service = $this->sharedWithTheDelegate();

		$report = $service->deleteAllFor('alice');

		$this->assertSame('dave', $this->secrets['sec-1']->getOwnerId());
		$this->assertSame(['st-dave'], array_keys($this->shareRows));
		$this->assertSame('c-dave', $this->shareRows['st-dave']->getSecretId());
		$this->assertSame(['c-carol'], $this->tombstoned);
		$this->assertSame(1, $report->sharesDetached);
	}//end testTheDelegateKeepsTheirLinkedCopy()

	/**
	 * A delegate's row from a group share stays their link once the group
	 * share is removed: it no longer names the group share, so no grant
	 * reader looks for one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sharing-share-save-missed-partner-shares/specs/gdpr-compliance/spec.md#requirement-shared-secret-semantics-on-account-deletion
	 */
	public function testTheDelegatesRowFromAGroupShareStaysTheirLink(): void {
		$service = $this->sharedWithTheDelegate(daveGroupShareId: 'gs-1');

		$service->deleteAllFor('alice');

		$this->assertSame([], $this->groupShares);
		$this->assertSame(['st-dave'], array_keys($this->shareRows));
		$this->assertNull($this->shareRows['st-dave']->getGroupShareId());
		$this->assertSame(['c-carol'], $this->tombstoned);
	}//end testTheDelegatesRowFromAGroupShareStaysTheirLink()

	/**
	 * Every way the delegate's share row is not trusted by the row judge.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function untrustedDelegateRows(): array {
		return [
			'the pending scan quarantines it' => ['quarantined'],
			'the pending scan cannot judge it' => ['unjudged'],
			'it names a copy the delegate does not hold' => ['foreign'],
			'two rows name the delegate' => ['ambiguous'],
		];
	}//end untrustedDelegateRows()

	/**
	 * A delegate's row the row judge does not trust is detached like any
	 * other: nothing is kept, and the copy is tombstoned only as before.
	 *
	 * @param string $how How the row is untrusted
	 *
	 * @return void
	 *
	 * @dataProvider untrustedDelegateRows
	 *
	 * @spec openspec/changes/sharing-share-save-missed-partner-shares/specs/gdpr-compliance/spec.md#requirement-shared-secret-semantics-on-account-deletion
	 */
	public function testAnUntrustedDelegateRowIsDetached(string $how): void {
		$service = $this->sharedWithTheDelegate();
		$expectTombstoned = ['c-dave', 'c-carol'];
		if ($how === 'quarantined' || $how === 'unjudged') {
			$held = ShareQuarantineScanner::HELD_QUARANTINED;
			if ($how === 'unjudged') {
				$held = ShareQuarantineScanner::HELD_UNJUDGED;
			}

			$this->m['scan']->method('heldBack')->willReturnCallback(
				static fn (ShareTarget $row): ?string => ($row->getId() === 'st-dave') ? $held : null
			);
			// The copy of a row the scan holds back is left as it is.
			$expectTombstoned = ['c-carol'];
		} elseif ($how === 'foreign') {
			$this->secrets['c-dave']->setOwnerId('mallory');
			$expectTombstoned = ['c-carol'];
		} else {
			$this->secrets['c-dave-2'] = $this->secret('c-dave-2', 'dave');
			$this->shareRows['st-dave-2'] = $this->shareRow(id: 'st-dave-2', copyId: 'c-dave-2', target: 'dave', groupShareId: 'gs-1');
			$expectTombstoned = ['c-dave', 'c-carol', 'c-dave-2'];
		}

		$this->m['share']->expects($this->never())->method('deleteBySourceSecretExcept');
		$this->m['share']->expects($this->never())->method('update');

		$service->deleteAllFor('alice');

		$this->assertSame('dave', $this->secrets['sec-1']->getOwnerId());
		$this->assertSame([], $this->shareRows);
		$this->assertSame($expectTombstoned, $this->tombstoned);
	}//end testAnUntrustedDelegateRowIsDetached()

	/**
	 * Running the cascade again after the delegate kept their copy changes
	 * nothing more: the secret is the delegate's, so it is not Alice's to
	 * detach.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/gdpr-compliance/spec.md
	 */
	public function testARerunAfterTheDelegateKeptTheirCopyChangesNothing(): void {
		$service = $this->sharedWithTheDelegate(daveGroupShareId: 'gs-1');
		$service->deleteAllFor('alice');
		$after = [
			'owner' => $this->secrets['sec-1']->getOwnerId(),
			'rows' => array_map(static fn (ShareTarget $row): array => $row->jsonSerialize(), $this->shareRows),
			'tombstoned' => $this->tombstoned,
			'groupShares' => $this->groupShares,
		];

		$report = $service->deleteAllFor('alice');

		$this->assertSame($after, [
			'owner' => $this->secrets['sec-1']->getOwnerId(),
			'rows' => array_map(static fn (ShareTarget $row): array => $row->jsonSerialize(), $this->shareRows),
			'tombstoned' => $this->tombstoned,
			'groupShares' => $this->groupShares,
		]);
		$this->assertSame(0, $report->secretsTransferred);
		$this->assertSame(0, $report->sharesDetached);
	}//end testARerunAfterTheDelegateKeptTheirCopyChangesNothing()
}//end class
