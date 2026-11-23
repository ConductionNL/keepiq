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

use OCA\Keepiq\Db\BulkGrantShareTargetMapper;
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
use OCA\Keepiq\Db\TeamFolderMember;
use OCA\Keepiq\Service\DelegateCopyKeeper;
use OCA\Keepiq\Service\DelegatedSecretTransfer;
use OCA\Keepiq\Service\ExpiredGrantRemover;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\RecipientSecretCopyService;
use OCA\Keepiq\Service\SecretOwnerTransferService;
use OCA\Keepiq\Service\ShareExpiryService;
use OCA\Keepiq\Service\ShareGrantReader;
use OCA\Keepiq\Service\ShareQuarantineScanner;
use OCA\Keepiq\Service\ShareRestrictionResolver;
use OCA\Keepiq\Service\TeamFolderQueryService;
use OCA\Keepiq\Service\TeamFolderShareService;
use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCA\Keepiq\Tests\Support\ShareRowJudgeFixture;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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
	 * The grant reader over the mocks, as the cascade and the resolver read grants.
	 *
	 * @var ShareGrantReader
	 */
	private ShareGrantReader $grants;

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
			'teamFolders' => $this->createMock(TeamFolderQueryService::class),
			'groups' => $this->createMock(IGroupManager::class),
		];
		$this->grants = new ShareGrantReader(
			secretMapper: $this->m['secret'],
			groupShareMapper: $this->m['group'],
			teamFolders: $this->m['teamFolders'],
			groupManager: $this->m['groups'],
		);

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
				stageMapper: $this->m['stage'],
				delegatedTransfer: new DelegatedSecretTransfer(
					delegationMapper: $this->m['delegation'],
					ownerTransfer: new SecretOwnerTransferService(
						db: $this->createMock(IDBConnection::class),
						secretMapper: $this->m['secret'],
						federatedShares: $this->m['federated'],
						linkShares: $this->m['link'],
					),
				),
				quarantineScan: $this->m['scan'],
				delegateCopies: new DelegateCopyKeeper(
					rowJudge: $this->shareRowJudge(targets: $this->m['share'], secrets: $this->m['secret'], scanner: $this->m['scan']),
					grants: $this->grants,
					secretMapper: $this->m['secret'],
					shareMapper: $this->m['share'],
				),
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
	 * The members of each group, by group id.
	 *
	 * @var array<string,list<string>>
	 */
	private array $groupMembers = [];

	/**
	 * The team-folder memberships covering `sec-1` for each user.
	 *
	 * @var array<string,list<TeamFolderMember>>
	 */
	private array $memberships = [];

	/**
	 * The secrets written by an update, in order.
	 *
	 * @var list<string>
	 */
	private array $updated = [];

	/**
	 * Alice's `sec-1` is shared with Dave and Carol, and Dave holds an active delegation on it.
	 * The mappers keep their rows in this test, so a second run of the cascade sees what the first one left.
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
		$this->m['groups']->method('isInGroup')->willReturnCallback(
			fn (string $userId, string $groupId): bool => in_array($userId, ($this->groupMembers[$groupId] ?? []), true)
		);
		$this->m['teamFolders']->method('coveringMemberships')->willReturnCallback(
			fn (Secret $secret, string $userId): array => ($this->memberships[$userId] ?? [])
		);

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
		$row->setCreatedBy('alice');

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
		$secrets->method('update')->willReturnCallback(function (Secret $secret): Secret {
			$this->updated[] = $secret->getId();
			$this->secrets[$secret->getId()] = $secret;
			return $secret;
		});
		$secrets->method('findAccessEndingBetween')->willReturnCallback(fn (?DateTime $from, DateTime $to): array => array_values(
			array_filter($this->secrets, static fn (Secret $secret): bool => $secret->getAccessExpiresAt() !== null && $secret->getAccessExpiresAt() <= $to)
		));
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
		$shares->method('findByRecipientSecret')->willReturnCallback(function (string $recipientSecretId): ShareTarget {
			$rows = array_values(array_filter($this->shareRows, static fn (ShareTarget $row): bool => $row->getSecretId() === $recipientSecretId));
			if (count($rows) > 1) {
				throw new MultipleObjectsReturnedException('two');
			}

			return ($rows[0] ?? throw new DoesNotExistException('none'));
		});
		$shares->method('findById')->willReturnCallback(
			fn (string $id): ShareTarget => ($this->shareRows[$id] ?? throw new DoesNotExistException('none'))
		);
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
	 * The delegate's copy stays linked and untouched; the other recipient's copy is detached as before.
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
	 * A delegate's row from a group share stays their link once the group share is removed:
	 * it no longer names the group share, so no grant reader looks for one.
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
	 * A delegate's row the row judge does not trust is detached like any other:
	 * nothing is kept, and the copy is tombstoned only as before.
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
	 * Running the cascade again after the delegate kept their copy changes nothing more:
	 * the secret is the delegate's, so it is not Alice's to detach.
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

	/**
	 * Every way something limits the delegate's copy: the copy itself, the row's own fields, or a grant reaching it.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function limitedDelegateCopies(): array {
		return [
			'a use-only direct row' => ['use-only'],
			'an expiring direct row' => ['expiring'],
			'a read-only copy' => ['read-only'],
			'a group copy use-only through the group share only' => ['group use-only'],
			'a group copy expiring through the group share only' => ['group expiring'],
			'a direct row whose own limit is not on the copy yet' => ['row limit only'],
			'a copy limited without a grant saying so' => ['copy limit only'],
			'a team-folder copy through a use-only membership' => ['folder use-only'],
			'a group row use-only on its own beside a full group share' => ['group row use-only'],
			'a group row expiring on its own beside a full group share' => ['group row expiring'],
		];
	}//end limitedDelegateCopies()

	/**
	 * The D5 setup with the delegate's copy limited one way.
	 *
	 * @param string $case How the copy is limited, as limitedDelegateCopies() names it
	 *
	 * @return AccountDeletionService
	 */
	private function limitedDelegateCopy(string $case): AccountDeletionService {
		$fromGroup = in_array($case, ['group use-only', 'group expiring', 'group row use-only', 'group row expiring'], true);
		$service   = $this->sharedWithTheDelegate(daveGroupShareId: ($fromGroup === true ? 'gs-1' : null));
		$row  = $this->shareRows['st-dave'];
		$copy = $this->secrets['c-dave'];
		$end  = new DateTime('+1 week');
		switch ($case) {
			case 'use-only':
				$row->setUseOnly(true);
				$copy->setUseOnly(true);
				break;
			case 'expiring':
				$row->setExpiresAt($end);
				$copy->setAccessExpiresAt($end);
				break;
			case 'read-only':
				$copy->setReadOnly(true);
				break;
			case 'group use-only':
				$this->groupShares[0]->setUseOnly(true);
				$copy->setUseOnly(true);
				break;
			case 'group expiring':
				$this->groupShares[0]->setExpiresAt($end);
				$copy->setAccessExpiresAt($end);
				break;
			case 'row limit only':
				$row->setUseOnly(true);
				break;
			case 'copy limit only':
				$copy->setAccessExpiresAt($end);
				break;
			case 'group row use-only':
				// Linked to the full group share, so no grant carries the row's own flag.
				$row->setUseOnly(true);
				break;
			case 'group row expiring':
				$row->setExpiresAt($end);
				break;
			default:
				$row->setTeamFolderId('tf-1');
				$membership = new TeamFolderMember();
				$membership->setGrade('read');
				$membership->setUseOnly(true);
				$this->memberships['dave'] = [$membership];
				$copy->setUseOnly(true);
		}

		return $service;
	}//end limitedDelegateCopy()

	/**
	 * The limits a copy carries.
	 *
	 * @param Secret $copy The copy
	 *
	 * @return array{useOnly:bool,ends:string|null,readOnly:bool}
	 */
	private function limitsOf(Secret $copy): array {
		return [
			'useOnly' => ($copy->getUseOnly() === true),
			'ends' => $copy->getAccessExpiresAt()?->format(DATE_ATOM),
			'readOnly' => ($copy->getReadOnly() === true),
		];
	}//end limitsOf()

	/**
	 * The restriction resolver over the mocks, as every later resolve runs it.
	 *
	 * @return ShareRestrictionResolver
	 */
	private function resolver(): ShareRestrictionResolver {
		return new ShareRestrictionResolver(
			secretMapper: $this->m['secret'],
			shareTargetMapper: $this->m['share'],
			groupShareMapper: $this->m['group'],
			teamFolders: $this->m['teamFolders'],
			groupManager: $this->m['groups'],
			quarantineScan: $this->m['scan'],
			grants: $this->grants,
		);
	}//end resolver()

	/**
	 * A delegate's copy that anything limits is detached as before, and keeps its limits through a later resolve:
	 * keeping it would leave a row with no limit of its own, and the next resolve would lift the copy's.
	 *
	 * @param string $case How the copy is limited
	 *
	 * @return void
	 *
	 * @dataProvider limitedDelegateCopies
	 *
	 * @spec openspec/changes/sharing-share-save-missed-partner-shares/specs/gdpr-compliance/spec.md#requirement-shared-secret-semantics-on-account-deletion
	 */
	public function testALimitedDelegateCopyIsDetached(string $case): void {
		$service = $this->limitedDelegateCopy(case: $case);
		$before  = $this->limitsOf(copy: $this->secrets['c-dave']);

		$report = $service->deleteAllFor('alice');
		$this->resolver()->resolveSource(sourceSecretId: 'sec-1');

		$this->assertSame('dave', $this->secrets['sec-1']->getOwnerId());
		$this->assertSame([], $this->shareRows);
		$this->assertSame([], $this->groupShares);
		$this->assertSame(['c-dave', 'c-carol'], $this->tombstoned);
		$this->assertSame(2, $report->sharesDetached);
		$this->assertSame($before, $this->limitsOf(copy: $this->secrets['c-dave']));
		$this->assertNotContains('c-dave', $this->updated);
	}//end testALimitedDelegateCopyIsDetached()

	/**
	 * A full grant beside a limited one, both ways round.
	 *
	 * @return array<string,array{0:bool}>
	 */
	public static function fullBesideLimitedGrants(): array {
		return [
			'a full direct row and a use-only group share' => [false],
			'a use-only direct row and a full group share' => [true],
		];
	}//end fullBesideLimitedGrants()

	/**
	 * A full grant beside a limited one gives the delegate a full copy, as the most generous grant wins.
	 * One grant that is not unlimited detaches the copy anyway: it keeps the full limits it had, so it never ends up less restricted,
	 * and it cannot end up more restricted either, as a kept row standing on its own limited fields would.
	 *
	 * @param bool $rowLimited Whether the direct row is the limited grant (else the group share is)
	 *
	 * @return void
	 *
	 * @dataProvider fullBesideLimitedGrants
	 *
	 * @spec openspec/changes/sharing-share-save-missed-partner-shares/specs/gdpr-compliance/spec.md#requirement-shared-secret-semantics-on-account-deletion
	 */
	public function testAFullGrantBesideALimitedOneDetachesTheCopyAsItWas(bool $rowLimited): void {
		$service = $this->sharedWithTheDelegate();
		$this->shareRows['st-dave']->setUseOnly($rowLimited);
		$groupShare = new GroupShare();
		$groupShare->setId('gs-2');
		$groupShare->setSecretId('sec-1');
		$groupShare->setGroupId('team');
		$groupShare->setUseOnly($rowLimited === false);
		$this->groupShares = [$groupShare];
		$this->groupMembers['team'] = ['dave'];
		$this->resolver()->resolveSource(sourceSecretId: 'sec-1');
		$before = $this->limitsOf(copy: $this->secrets['c-dave']);
		$this->assertSame(['useOnly' => false, 'ends' => null, 'readOnly' => false], $before);

		$service->deleteAllFor('alice');
		$this->resolver()->resolveSource(sourceSecretId: 'sec-1');

		$this->assertSame([], $this->shareRows);
		$this->assertSame(['c-dave', 'c-carol'], $this->tombstoned);
		$this->assertSame($before, $this->limitsOf(copy: $this->secrets['c-dave']));
	}//end testAFullGrantBesideALimitedOneDetachesTheCopyAsItWas()

	/**
	 * A delegate's team-folder row whose membership is full is kept like any other unlimited row,
	 * and stands as a direct share: it no longer names the team folder.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sharing-share-save-missed-partner-shares/specs/gdpr-compliance/spec.md#scenario-the-delegate-keeps-their-linked-copy
	 */
	public function testAFullTeamFolderRowOfTheDelegateIsKept(): void {
		$service = $this->sharedWithTheDelegate();
		$this->shareRows['st-dave']->setTeamFolderId('tf-1');
		$membership = new TeamFolderMember();
		$membership->setGrade('write');
		$this->memberships['dave'] = [$membership];

		$service->deleteAllFor('alice');
		$this->resolver()->resolveSource(sourceSecretId: 'sec-1');

		$this->assertSame(['st-dave'], array_keys($this->shareRows));
		$this->assertNull($this->shareRows['st-dave']->getTeamFolderId());
		$this->assertNull($this->shareRows['st-dave']->getGroupShareId());
		$this->assertSame(['c-carol'], $this->tombstoned);
		$this->assertSame(['useOnly' => false, 'ends' => null, 'readOnly' => false], $this->limitsOf(copy: $this->secrets['c-dave']));
	}//end testAFullTeamFolderRowOfTheDelegateIsKept()

	/**
	 * The ways the delegate drops out of the deleted user's team folder afterwards.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function laterTeamFolderDepartures(): array {
		return [
			'the delegate leaves the folder or its group' => ['leave'],
			'the delegate is removed from the folder' => ['remove'],
		];
	}//end laterTeamFolderDepartures()

	/**
	 * The deleted user's team-folder rows and memberships outlive her folders,
	 * so the delegate leaving or being removed from one later must not remove the kept row or its copy.
	 *
	 * @param string $how How the delegate drops out of the folder
	 *
	 * @return void
	 *
	 * @dataProvider laterTeamFolderDepartures
	 *
	 * @spec openspec/changes/sharing-share-save-missed-partner-shares/specs/gdpr-compliance/spec.md#requirement-shared-secret-semantics-on-account-deletion
	 */
	public function testALaterTeamFolderDepartureKeepsTheKeptCopy(string $how): void {
		$service = $this->sharedWithTheDelegate();
		$this->shareRows['st-dave']->setTeamFolderId('tf-1');
		$membership = new TeamFolderMember();
		$membership->setGrade('write');
		$this->memberships['dave'] = [$membership];
		$service->deleteAllFor('alice');

		$bulk = $this->createMock(BulkGrantShareTargetMapper::class);
		$bulk->method('findByTeamFolderAndTargetUser')->willReturnCallback(fn (string $teamFolderId, string $targetUserId): array => array_values(
			array_filter(
				$this->shareRows,
				static fn (ShareTarget $row): bool => $row->getTeamFolderId() === $teamFolderId && $row->getTargetUserId() === $targetUserId
			)
		));
		$copies = $this->createMock(RecipientSecretCopyService::class);
		$copies->expects($this->never())->method('deleteByShare');
		$this->m['share']->expects($this->never())->method('delete');
		$teamShares = new TeamFolderShareService(
			shareTargetMapper: $this->m['share'],
			rowJudge: $this->shareRowJudge(targets: $this->m['share'], secrets: $this->m['secret'], scanner: $this->m['scan']),
			bulkGrantMapper: $bulk,
			copies: $copies,
			notificationService: $this->createMock(NotificationService::class),
			db: $this->createMock(IDBConnection::class),
			logger: $this->createMock(LoggerInterface::class),
		);

		$revoked = match ($how) {
			'leave' => $teamShares->releaseForMember(teamFolderId: 'tf-1', targetUserId: 'dave'),
			default => $teamShares->revokeForMember(teamFolderId: 'tf-1', targetUserId: 'dave'),
		};

		$this->assertSame(0, $revoked);
		$this->assertSame(['st-dave'], array_keys($this->shareRows));
		$this->assertSame('c-dave', $this->shareRows['st-dave']->getSecretId());
		$this->assertArrayHasKey('c-dave', $this->secrets);
		// A later fan-out matches the pair, not the folder, so it adds no second row beside the kept one.
		$this->assertSame(
			[],
			$teamShares->missingPairs(secrets: [['id' => 'sec-1', 'name' => 'x']], recipients: [['userId' => 'dave', 'certificate' => 'c']])
		);
	}//end testALaterTeamFolderDepartureKeepsTheKeptCopy()

	/**
	 * The kept row names the deleted account's marker as its maker instead of the deleted user,
	 * and the delegate still follows it: the row judge trusts it from both the pair and the copy.
	 * A maker who is someone else stays.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sharing-share-save-missed-partner-shares/specs/gdpr-compliance/spec.md#requirement-shared-secret-semantics-on-account-deletion
	 */
	public function testAKeptRowNamesTheDeletedAccountAsItsMaker(): void {
		$service = $this->sharedWithTheDelegate(daveGroupShareId: 'gs-1');

		$service->deleteAllFor('alice');

		$row = $this->shareRows['st-dave'];
		$this->assertSame(LinkShareMapper::DELETED_ACCOUNT_MARKER, $row->getCreatedBy());
		$this->assertNotContains('alice', $row->jsonSerialize());
		$judge = $this->shareRowJudge(targets: $this->m['share'], secrets: $this->m['secret'], scanner: $this->m['scan']);
		$this->assertSame($row, $judge->forPair(sourceId: 'sec-1', targetUserId: 'dave')->trustedRow());
		$this->assertSame($row, $judge->forCopy(copy: $this->secrets['c-dave'])->trustedRow());

		$other = $this->sharedWithTheDelegate();
		$this->shareRows['st-dave']->setCreatedBy('erin');
		$other->deleteAllFor('alice');
		$this->assertSame('erin', $this->shareRows['st-dave']->getCreatedBy());
	}//end testAKeptRowNamesTheDeletedAccountAsItsMaker()

	/**
	 * An expiring group copy and a full one.
	 *
	 * @return array<string,array{0:bool}>
	 */
	public static function expiringOrFullGroupCopies(): array {
		return [
			'an expiring group copy is detached' => [true],
			'a full group copy is kept' => [false],
		];
	}//end expiringOrFullGroupCopies()

	/**
	 * An expiry run after the end date leaves the detached expiring copy with its end date, so reads refuse it;
	 * it is not lifted to a permanent copy. The kept full copy stays linked and full.
	 *
	 * @param bool $expiring Whether the group share, and so the copy, ends
	 *
	 * @return void
	 *
	 * @dataProvider expiringOrFullGroupCopies
	 *
	 * @spec openspec/changes/sharing-share-save-missed-partner-shares/specs/gdpr-compliance/spec.md#requirement-shared-secret-semantics-on-account-deletion
	 */
	public function testAnExpiryRunAfterTheEndDateNeverLiftsTheDelegatesCopy(bool $expiring): void {
		$service = $this->sharedWithTheDelegate(daveGroupShareId: 'gs-1');
		$end     = new DateTime('+1 day');
		if ($expiring === true) {
			$this->groupShares[0]->setExpiresAt($end);
			$this->secrets['c-dave']->setAccessExpiresAt($end);
		}

		$service->deleteAllFor('alice');
		$expiry = new ShareExpiryService(
			secretMapper: $this->m['secret'],
			targetMapper: $this->m['share'],
			rowJudge: $this->shareRowJudge(targets: $this->m['share'], secrets: $this->m['secret'], scanner: $this->m['scan']),
			restrictions: $this->resolver(),
			remover: $this->createMock(ExpiredGrantRemover::class),
			notifications: $this->createMock(NotificationService::class),
		);
		$ended = $expiry->expire(now: new DateTime('+2 days'));

		$this->assertSame(0, $ended);
		$this->assertNotContains('c-dave', $this->updated);
		if ($expiring === true) {
			$this->assertSame([], $this->shareRows);
			$this->assertSame(
				['useOnly' => false, 'ends' => $end->format(DATE_ATOM), 'readOnly' => false],
				$this->limitsOf(copy: $this->secrets['c-dave'])
			);
			return;
		}

		$this->assertSame(['st-dave'], array_keys($this->shareRows));
		$this->assertSame(['useOnly' => false, 'ends' => null, 'readOnly' => false], $this->limitsOf(copy: $this->secrets['c-dave']));
	}//end testAnExpiryRunAfterTheEndDateNeverLiftsTheDelegatesCopy()
}//end class
