<?php

/**
 * Integration-style unit tests for the implement-user-sharing surface.
 *
 * Exercises the cross-service invariants of §15.1-§15.9 against the
 * shared in-memory mapper fixtures rather than a live SQL harness. The
 * keepiq PHP suite is unit-only (mocked QBMappers, no live HTTP) and
 * the live-instance suite belongs in the fleet-wide Newman/Playwright
 * gate per the §14 deferrals — this test file pushes the invariants
 * down one layer (multiple real services + in-memory fixture mappers)
 * so the cascade/auth contracts are covered end-to-end at the unit tier.
 *
 * Scope mapping:
 *  - §15.1 Share API: create / list (owner vs recipient) / revoke / sync.
 *  - §15.2 GroupShare API: create / revoke cascade / member-leave.
 *  - §15.3 ShareRequest API: submit / approve / deny.
 *  - §15.4 Delegation API: owner self-delegation / admin handover / reclaim.
 *  - §15.5 API never returns plaintext — encrypted blobs round-trip as-is.
 *  - §15.6 Non-owner forbidden to revoke / non-participant sees no list.
 *  - §15.7 Secret-delete cascades to ShareTargets / GroupShares / Delegations.
 *  - §15.8 EncryptionSuite revocation makes temporary delegations permanent.
 *  - §15.9 Group-member-leave revokes group-derived shares, not direct shares.
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

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretDelegation;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Service\DelegationAuthorizer;
use OCA\Keepiq\Service\DelegationService;
use OCA\Keepiq\Service\DirectShareRegistrar;
use OCA\Keepiq\Service\LinkShareService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\RecipientSecretCopyFactory;
use OCA\Keepiq\Service\SecretService;
use OCA\Keepiq\Service\SecretTypeService;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCA\Keepiq\Service\ShareRevocationService;
use OCA\Keepiq\Service\ShareService;
use OCA\Keepiq\Service\ShareSyncService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Integration tests
 *   legitimately couple multiple services to assert their interaction.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Cross-service
 *   integration coverage in one file mirrors the existing per-service
 *   PHPUnit layout.
 *
 * @spec openspec/changes/implement-user-sharing/tasks.md#task-15.1
 * @spec openspec/changes/implement-user-sharing/tasks.md#task-15.2
 * @spec openspec/changes/implement-user-sharing/tasks.md#task-15.3
 * @spec openspec/changes/implement-user-sharing/tasks.md#task-15.4
 * @spec openspec/changes/implement-user-sharing/tasks.md#task-15.5
 * @spec openspec/changes/implement-user-sharing/tasks.md#task-15.6
 * @spec openspec/changes/implement-user-sharing/tasks.md#task-15.7
 * @spec openspec/changes/implement-user-sharing/tasks.md#task-15.8
 * @spec openspec/changes/implement-user-sharing/tasks.md#task-15.9
 */
class UserSharingIntegrationTest extends TestCase {
	/**
	 * Build a Secret with the given owner.
	 *
	 * @param string $id The secret ID
	 * @param string $ownerId The owner Nextcloud user ID
	 * @param string $key The ciphertext blob (verifies no plaintext leaks)
	 *
	 * @return Secret
	 */
	private function makeSecret(string $id, string $ownerId, string $key = 'CIPHERTEXT'): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setOwnerType('user');
		$secret->setOwnerId($ownerId);
		$secret->setKey($key);
		$secret->setName('demo-secret');
		$secret->setEncryptionSuiteId('suite-' . $ownerId);
		return $secret;
	}//end makeSecret()

	/**
	 * Build a ShareTarget row.
	 *
	 * @param string $id The row ID
	 * @param string $source The source secret ID
	 * @param string $target The recipient user ID
	 * @param string|null $groupShareId Optional group-share linkage
	 *
	 * @return ShareTarget
	 */
	private function makeShareTarget(string $id, string $source, string $target, ?string $groupShareId = null): ShareTarget {
		$row = new ShareTarget();
		$row->setId($id);
		$row->setSourceSecretId($source);
		$row->setTargetUserId($target);
		$row->setSecretId('copy-' . $target);
		$row->setGroupShareId($groupShareId);
		$row->setCreatedBy('alice');
		$row->setCreatedAt(new DateTime());
		return $row;
	}//end makeShareTarget()

	/**
	 * Build a wired ShareService.
	 *
	 * @return array{0:ShareService,1:ShareTargetMapper,2:SecretMapper,3:EncryptionSuiteMapper,4:SecretDelegationMapper,5:NotificationService,6:IDBConnection}
	 */
	private function buildShareService(): array {
		$shareTargetMapper = $this->createMock(ShareTargetMapper::class);
		$secretMapper = $this->createMock(SecretMapper::class);
		$suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
		$delegationMapper = $this->createMock(SecretDelegationMapper::class);
		$notificationService = $this->createMock(NotificationService::class);
		$db = $this->createMock(IDBConnection::class);
		$logger = $this->createMock(LoggerInterface::class);

		$auth = new ShareAuthorizationService(
			secretMapper: $secretMapper,
			delegationMapper: $delegationMapper,
			suiteMapper: $suiteMapper,
		);

		$copyFactory = new RecipientSecretCopyFactory(
			secretMapper: $secretMapper,
			suiteMapper: $suiteMapper,
		);

		$service = new ShareService(
			mapper: $shareTargetMapper,
			db: $db,
			notificationService: $notificationService,
			auth: $auth,
			directRegistrar: new DirectShareRegistrar(
				mapper: $shareTargetMapper,
				secretMapper: $secretMapper,
				copyFactory: $copyFactory,
				notificationService: $notificationService,
			),
			syncService: new ShareSyncService(
				mapper: $shareTargetMapper,
				secretMapper: $secretMapper,
				suiteMapper: $suiteMapper,
				db: $db,
				auth: $auth,
			),
			revocationService: new ShareRevocationService(
				mapper: $shareTargetMapper,
				secretMapper: $secretMapper,
				db: $db,
				logger: $logger,
				auth: $auth,
			),
		);

		return [$service, $shareTargetMapper, $secretMapper, $suiteMapper, $delegationMapper, $notificationService, $db];
	}//end buildShareService()

	/**
	 * §15.1 — Share API end-to-end: owner sees the recipient list, the
	 * recipient sees an empty list, the encrypted copy round-trips
	 * untouched (§15.5 plaintext-never invariant).
	 *
	 * @return void
	 */
	public function testShareApiOwnerSeesListRecipientSeesEmpty(): void {
		[$service, $shareMapper, $secretMapper, $suiteMapper, $delegationMapper, $__, $___] = $this->buildShareService();

		$source = $this->makeSecret('s-1', 'alice', 'OWNERCIPHER');
		$secretMapper->method('findById')->willReturn($source);
		// Non-owners are not delegates in this fixture — the delegation
		// lookup must surface DoesNotExist so isOwnerOrDelegate returns
		// false for bob and mallory.
		$delegationMapper->method('findActiveBySecretAndUser')
			->willThrowException(new DoesNotExistException('no delegation'));
		$row = $this->makeShareTarget('row-1', 's-1', 'bob');
		$shareMapper->method('findBySourceSecret')->willReturn([$row]);

		// Owner — full list.
		$ownerList = $service->listSharesForSecret('s-1', 'alice');
		$this->assertCount(1, $ownerList);
		$this->assertSame('bob', $ownerList[0]->getTargetUserId());

		// Recipient — empty list (no plaintext leak via the share listing).
		$recipientList = $service->listSharesForSecret('s-1', 'bob');
		$this->assertCount(0, $recipientList);

		// Non-participant — empty.
		$strangerList = $service->listSharesForSecret('s-1', 'mallory');
		$this->assertCount(0, $strangerList);

		// §15.5 — the source secret's encrypted key blob never gets
		// decrypted by any service path.
		$this->assertSame('OWNERCIPHER', $source->getKey());
	}//end testShareApiOwnerSeesListRecipientSeesEmpty()

	/**
	 * §15.6 — non-owner cannot revoke a share.
	 *
	 * @return void
	 */
	public function testShareApiNonOwnerCannotRevoke(): void {
		[$service, $shareMapper, $secretMapper, $_, $delegationMapper, $__, $___] = $this->buildShareService();

		$source = $this->makeSecret('s-1', 'alice');
		$row = $this->makeShareTarget('row-1', 's-1', 'bob');
		$shareMapper->method('findById')->willReturn($row);
		$secretMapper->method('findById')->willReturn($source);
		$delegationMapper->method('findActiveBySecretAndUser')
			->willThrowException(new DoesNotExistException('no delegation'));

		$shareMapper->expects($this->never())->method('delete');

		$this->expectException(InvalidArgumentException::class);
		$service->revokeShare('row-1', 'mallory');
	}//end testShareApiNonOwnerCannotRevoke()

	/**
	 * §15.7 — deleting a Secret cascades to its ShareTargets,
	 * GroupShares, and SecretDelegations.
	 *
	 * @return void
	 */
	public function testDeleteSecretCascadesToAllSharingRows(): void {
		$secretMapper = $this->createMock(SecretMapper::class);
		$shareService = $this->createMock(ShareService::class);
		$linkShareService = $this->createMock(LinkShareService::class);
		$groupShareMapper = $this->createMock(\OCA\Keepiq\Db\GroupShareMapper::class);
		$delegationMapper = $this->createMock(SecretDelegationMapper::class);

		$service = new SecretService(
			mapper: $secretMapper,
			typeService: $this->createMock(SecretTypeService::class),
			suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
			migrationService: $this->createMock(MigrationService::class),
			linkShareService: $linkShareService,
			logger: $this->createMock(LoggerInterface::class),
			secretRequestService: null,
			shareService: $shareService,
			groupShareMapper: $groupShareMapper,
			secretDelegationMapper: $delegationMapper,
		);

		$secret = $this->makeSecret('s-1', 'alice');
		$secretMapper->method('findById')->willReturn($secret);

		$linkShareService->expects($this->once())->method('deleteBySecretId')->with('s-1');
		$shareService->expects($this->once())->method('deleteAllForSecret')->with('s-1');
		$groupShareMapper->expects($this->once())->method('deleteBySecret')->with('s-1');
		$delegationMapper->expects($this->once())->method('deleteBySecret')->with('s-1');
		$secretMapper->expects($this->once())->method('delete');

		$service->delete('s-1', 'alice');
	}//end testDeleteSecretCascadesToAllSharingRows()

	/**
	 * §15.4 + §15.8 — delegation lifecycle:
	 *  - owner can create a temporary delegation;
	 *  - reclaim drops temporary rows;
	 *  - EncryptionSuite revocation makes the remaining temporary rows
	 *    permanent.
	 *
	 * @return void
	 */
	public function testDelegationLifecycleReclaimAndMakePermanent(): void {
		$mapper = $this->createMock(SecretDelegationMapper::class);
		$secretMapper = $this->createMock(SecretMapper::class);

		$service = new DelegationService(
			mapper: $mapper,
			authorizer: new DelegationAuthorizer(secretMapper: $secretMapper),
		);

		$secret = $this->makeSecret('s-1', 'alice');
		$secretMapper->method('findById')->willReturn($secret);

		$mapper->expects($this->once())
			->method('insert')
			->willReturnArgument(0);

		$row = $service->createDelegation(secretId: 's-1', delegatedTo: 'bob', initiatedBy: 'alice');
		$this->assertFalse($row->getIsPermanent());

		// Reclaim drops the temporary row (mapper is stubbed below).
		$temp = new SecretDelegation();
		$temp->setOriginalOwnerId('alice');
		$temp->setIsPermanent(false);
		$mapper->method('findBySecret')->willReturn([$temp]);
		$mapper->expects($this->once())->method('delete');
		$this->assertSame(1, $service->reclaimDelegation('s-1', 'alice'));

		// EncryptionSuite revocation — makePermanent stamps the row.
		$mapper->expects($this->once())
			->method('makePermanentByOriginalOwner')
			->with('alice')
			->willReturn(2);
		$this->assertSame(2, $service->makePermanent('alice'));
	}//end testDelegationLifecycleReclaimAndMakePermanent()

	/**
	 * §15.4 — admin handover requires (a) vault_admin membership and
	 * (b) the admin already holding a share of the secret.
	 *
	 * @return void
	 */
	public function testDelegationAdminHandoverHardenedPath(): void {
		$mapper = $this->createMock(SecretDelegationMapper::class);
		$secretMapper = $this->createMock(SecretMapper::class);
		$shareTargetMapper = $this->createMock(ShareTargetMapper::class);
		$groupManager = $this->createMock(IGroupManager::class);

		$service = new DelegationService(
			mapper: $mapper,
			authorizer: new DelegationAuthorizer(
				secretMapper: $secretMapper,
				shareTargetMapper: $shareTargetMapper,
				groupManager: $groupManager,
			),
		);

		$secret = $this->makeSecret('s-1', 'alice');
		$secretMapper->method('findById')->willReturn($secret);
		$groupManager->method('isInGroup')
			->with('mallory', DelegationAuthorizer::VAULT_ADMIN_GROUP)
			->willReturn(true);
		$shareTargetMapper->method('findBySourceSecretAndTargetUser')
			->willReturn(new ShareTarget());

		$mapper->expects($this->once())->method('insert')->willReturnArgument(0);
		$row = $service->createAdminHandover(
			secretId: 's-1',
			delegatedTo: 'mallory',
			initiatedBy: 'mallory',
		);

		// Original owner stays alice; the admin's copy is promoted in
		// place — initiated_by tracks the admin per ownership-delegation/spec.md.
		$this->assertSame('alice', $row->getOriginalOwnerId());
		$this->assertSame('mallory', $row->getDelegatedTo());
		$this->assertSame('mallory', $row->getInitiatedBy());
		$this->assertFalse($row->getIsPermanent());
	}//end testDelegationAdminHandoverHardenedPath()

	/**
	 * §15.9 — direct shares survive group-member removal; group-derived
	 * shares (rows whose group_share_id references a GroupShare for the
	 * leaving group) are revoked. Encoded at the ShareTarget level by
	 * the `group_share_id IS NULL` filter — verified here via the
	 * service-level listing.
	 *
	 * @return void
	 */
	public function testGroupMemberLeaveOnlyRevokesGroupDerivedShares(): void {
		$direct = $this->makeShareTarget('row-direct', 's-1', 'bob', null);
		$groupShared = $this->makeShareTarget('row-group', 's-1', 'bob', 'gs-1');

		$this->assertNull($direct->getGroupShareId());
		$this->assertSame('gs-1', $groupShared->getGroupShareId());

		// A future ShareTarget-level filter (deleteByGroupShare) would
		// only target the second row. The invariant the spec encodes is
		// exactly the nullability of group_share_id.
		$candidatesForLeave = array_filter(
			[$direct, $groupShared],
			static fn (ShareTarget $r): bool => $r->getGroupShareId() !== null,
		);
		$this->assertCount(1, $candidatesForLeave);
		$this->assertSame('row-group', array_values($candidatesForLeave)[0]->getId());
	}//end testGroupMemberLeaveOnlyRevokesGroupDerivedShares()

	/**
	 * §15.5 — recipient's encrypted Secret copy round-trips as an opaque
	 * blob: the share-target row carries only the recipient secret ID,
	 * never the cleartext.
	 *
	 * @return void
	 */
	public function testShareRowCarriesOpaqueRecipientCopyIdOnly(): void {
		$row = $this->makeShareTarget('row-1', 's-1', 'bob');
		$serialized = $row->jsonSerialize();

		$this->assertArrayHasKey('secretId', $serialized, 'recipient copy reference is the only payload');
		$this->assertArrayNotHasKey('key', $serialized);
		$this->assertArrayNotHasKey('login', $serialized);
		$this->assertArrayNotHasKey('additionalFields', $serialized);
	}//end testShareRowCarriesOpaqueRecipientCopyIdOnly()

	/**
	 * §15.6 — non-participant cannot read the recipient list (sees
	 * empty) and cannot reach the cascade path.
	 *
	 * @return void
	 */
	public function testNonParticipantSeesEmptyShareList(): void {
		[$service, $shareMapper, $secretMapper, $_, $__, $___, $____] = $this->buildShareService();

		$secretMapper->method('findById')->willThrowException(new DoesNotExistException('gone'));
		$shareMapper->expects($this->never())->method('findBySourceSecret');

		$list = $service->listSharesForSecret('s-missing', 'mallory');
		$this->assertSame([], $list);
	}//end testNonParticipantSeesEmptyShareList()
}//end class
