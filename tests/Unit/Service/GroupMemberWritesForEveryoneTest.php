<?php

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\BulkGrantShareTargetMapper;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\GroupShare;
use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretVersion;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Service\GroupShareService;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\ShareAuditTrail;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCA\Keepiq\Service\SecretVersionService;
use OCA\Keepiq\Service\ShareRevocationService;
use OCA\Keepiq\Service\ShareSyncService;
use OCA\Keepiq\Service\TeamFolderService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\Share\IManager as IShareManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Alice owns `src`, shared with the `admin` group (Bob's copy `c-bob`) and
 * directly with Dan (`c-dan`). Mallory is in `admin` but holds no copy.
 * Another secret, `other`, is shared with the `staff` group.
 *
 * @spec openspec/specs/sharing-group/spec.md#requirement-a-group-member-saves-for-everyone-it-is-shared-with
 */
class GroupMemberWritesForEveryoneTest extends TestCase {
	/** The version of `src` a sync is made from */
	private const BASE = '2026-10-01T10:00:00+00:00';

	/** The write context of a row that leads to no source the caller stands on */
	private const OWN_ROW_ONLY = [
		'sourceSecretId' => null,
		'effectiveGrade' => 'none',
		'ownerCertificate' => null,
		'sourceUpdatedAt' => null,
	];

	/** @var array<string,Secret> */
	private array $rows = [];

	/** @var array<int,ShareTarget> */
	private array $targets = [];

	/** @var array<int,GroupShare> */
	private array $groupShares = [];

	/** @var array<string,list<string>> The members of each group */
	private array $groups = ['admin' => ['bob', 'mallory']];

	/** @var array<string,string> The team-folder grade of each user on `src` */
	private array $teamGrades = [];

	private ShareAuditTrail&MockObject $audit;

	private SecretVersionService&MockObject $versions;

	/** @var array<string,list<string>>|null The members of each group once the sync holds the source lock */
	private ?array $groupsUnderLock = null;

	/** @var array<int,string> The rows the sync wrote, in order */
	private array $written = [];

	/** @var list<string> The users without a vault key */
	private array $keyless = [];

	/** @var (callable(Secret):void)|null What happens to `src` once the sync holds its lock */
	private $srcUnderLock = null;

	protected function setUp(): void {
		$this->audit = $this->createMock(ShareAuditTrail::class);
		$this->versions = $this->createMock(SecretVersionService::class);
		$this->addRow(id: 'src', owner: 'alice');
		$this->rows['src']->setUpdatedAt(new DateTime(self::BASE));
		$this->addRow(id: 'c-bob', owner: 'bob');
		$this->addRow(id: 'c-dan', owner: 'dan');
		$this->targets = [
			$this->target(copyId: 'c-bob', userId: 'bob', groupShareId: 'gs-admin'),
			$this->target(copyId: 'c-dan', userId: 'dan', groupShareId: null),
		];
		$this->groupShares = [
			$this->groupShare(id: 'gs-admin', secretId: 'src', groupId: 'admin'),
			$this->groupShare(id: 'gs-other', secretId: 'other', groupId: 'staff'),
		];
	}

	private function addRow(string $id, string $owner): void {
		$row = new Secret();
		$row->setId($id);
		$row->setName('Router');
		$row->setOwnerType('user');
		$row->setOwnerId($owner);
		$this->rows[$id] = $row;
	}

	private function target(string $copyId, string $userId, ?string $groupShareId): ShareTarget {
		$target = new ShareTarget();
		$target->setId('st-'.$copyId);
		$target->setSourceSecretId('src');
		$target->setSecretId($copyId);
		$target->setTargetUserId($userId);
		$target->setGroupShareId($groupShareId);

		return $target;
	}

	private function groupShare(string $id, string $secretId, string $groupId): GroupShare {
		$groupShare = new GroupShare();
		$groupShare->setId($id);
		$groupShare->setSecretId($secretId);
		$groupShare->setGroupId($groupId);

		return $groupShare;
	}

	private function sync(): ShareSyncService {
		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('findById')->willReturnCallback(
			fn (string $id): Secret => $this->rows[$id] ?? throw new DoesNotExistException('none')
		);
		$secrets->method('findByIds')->willReturnCallback(
			fn (array $ids): array => array_intersect_key($this->rows, array_flip($ids))
		);
		$secrets->method('advanceVersion')->willReturnCallback(
			function (): int {
				// A leave that commits after the first check, before the lock.
				if ($this->groupsUnderLock !== null) {
					$this->groups = $this->groupsUnderLock;
				}

				if ($this->srcUnderLock !== null) {
					($this->srcUnderLock)($this->rows['src']);
				}

				return 1;
			}
		);
		$secrets->method('update')->willReturnCallback(
			function (Secret $row): Secret {
				$this->written[] = $row->getId();
				return $row;
			}
		);

		$targets = $this->createMock(ShareTargetMapper::class);
		$targets->method('findBySourceSecret')->willReturnCallback(fn (): array => $this->targets);
		$targets->method('findByRecipientSecret')->willReturnCallback(
			fn (string $copyId): ShareTarget => $this->targetWhere(
				match: static fn (ShareTarget $t): bool => $t->getSecretId() === $copyId
			)
		);
		$targets->method('findBySourceSecretAndTargetUser')->willReturnCallback(
			fn (string $sourceId, string $userId): ShareTarget => $this->targetWhere(
				match: static fn (ShareTarget $t): bool => $t->getSourceSecretId() === $sourceId && $t->getTargetUserId() === $userId
			)
		);

		$groupShares = $this->createMock(GroupShareMapper::class);
		$groupShares->method('findBySecret')->willReturnCallback(
			fn (string $secretId): array => array_values(array_filter(
				$this->groupShares,
				static fn (GroupShare $share): bool => $share->getSecretId() === $secretId
			))
		);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			fn (string $userId, string $groupId): bool => in_array($userId, $this->groups[$groupId] ?? [], true)
		);
		$teamFolders = $this->createMock(TeamFolderService::class);
		$teamFolders->method('resolveGrade')->willReturnCallback(
			fn (Secret $secret, string $userId): ?string => $this->teamGrades[$userId] ?? null
		);

		$delegations = $this->createMock(SecretDelegationMapper::class);
		$delegations->method('findActiveBySecretAndUser')->willThrowException(new DoesNotExistException('none'));
		$suite = new EncryptionSuite();
		$suite->setCertificate('OWNER-CERT');
		$suites = $this->createMock(EncryptionSuiteMapper::class);
		$suites->method('findActiveByOwner')->willReturn($suite);
		$suites->method('countActiveByOwner')->willReturnCallback(
			fn (string $ownerType, string $ownerId): int => in_array($ownerId, $this->keyless, true) ? 0 : 1
		);

		return new ShareSyncService(
			mapper: $targets,
			secretMapper: $secrets,
			suiteMapper: $suites,
			db: $this->createMock(IDBConnection::class),
			auth: new ShareAuthorizationService(
				secretMapper: $secrets,
				delegationMapper: $delegations,
				suiteMapper: $suites,
				teamFolderService: $teamFolders,
				shareTargetMapper: $targets,
				groupShareMapper: $groupShares,
				groupManager: $groups,
			),
			auditTrail: $this->audit,
			versionService: $this->versions,
		);
	}

	/**
	 * @param callable(ShareTarget):bool $match Which share row to find
	 */
	private function targetWhere(callable $match): ShareTarget {
		$found = array_values(array_filter($this->targets, $match));
		if (count($found) > 1) {
			throw new MultipleObjectsReturnedException('more than one');
		}

		return $found[0] ?? throw new DoesNotExistException('none');
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	private function everyRow(): array {
		return [
			['secretId' => 'src', 'key' => 'NEW'],
			['secretId' => 'c-bob', 'key' => 'NEW'],
			['secretId' => 'c-dan', 'key' => 'NEW'],
		];
	}

	public function testAGroupMemberGetsTheWriteGradeAndTheOwnerCertificate(): void {
		$context = $this->sync()->writeContext('c-bob', 'bob');

		$this->assertSame('src', $context['sourceSecretId']);
		$this->assertSame('write', $context['effectiveGrade']);
		$this->assertSame('OWNER-CERT', $context['ownerCertificate']);
	}

	public function testAGroupMemberWritesTheSourceAndEveryCopyAndIsAuditedAsAGroupWrite(): void {
		$this->audit->expects($this->once())->method('recordTeamWriteSync')
			->with('bob', 'src', 'Router', 'group-write sync');

		$this->assertSame(3, $this->sync()->syncUpdate('src', $this->everyRow(), self::BASE, 'bob'));
	}

	public function testAGroupMembersSyncKeepsTheSourcesPreviousValueAsAVersion(): void {
		$this->rows['src']->setKey('OLD');
		$this->versions->expects($this->once())->method('snapshot')
			->with(
				$this->callback(static fn (Secret $preUpdate): bool => $preUpdate->getId() === 'src' && $preUpdate->getKey() === 'OLD'),
				'user',
				'bob'
			)
			->willReturn(new SecretVersion());

		$this->sync()->syncUpdate('src', $this->everyRow(), self::BASE, 'bob');
	}

	public function testTheOwnersSyncTakesNoSnapshotOfTheSource(): void {
		// The owner's own update through PUT /secrets/{id} keeps her version.
		$this->rows['src']->setKey('OLD');
		$this->versions->expects($this->never())->method('snapshot');

		$this->sync()->syncUpdate('src', $this->everyRow(), '', 'alice');
	}

	public function testAGroupMembersSyncWithoutABaseIsRefused(): void {
		try {
			$this->sync()->syncUpdate('src', $this->everyRow(), '', 'bob');
			$this->fail('A group member overwrote a value they had not seen');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString('expectedUpdatedAt is required', $e->getMessage());
		}

		$this->assertSame([], $this->written);
	}

	public function testAGroupMembersSyncLeavingOutACopyIsRefused(): void {
		// Dan's copy is left on the old value.
		$updates = [['secretId' => 'src', 'key' => 'NEW'], ['secretId' => 'c-bob', 'key' => 'NEW']];

		try {
			$this->sync()->syncUpdate('src', $updates, self::BASE, 'bob');
			$this->fail('A group member changed the source and left a copy behind');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString('every copy', $e->getMessage());
		}

		$this->assertSame([], $this->written);
	}

	public function testAGroupMembersSyncNamingARowThatIsNotACopyIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->sync()->syncUpdate('src', [...$this->everyRow(), ['secretId' => 'other', 'key' => 'NEW']], self::BASE, 'bob');
	}

	public function testAGroupMembersSyncSkipsACopyWhoseAccessEnded(): void {
		$this->rows['c-dan']->setAccessExpiresAt(new DateTime('-1 minute'));

		$this->assertSame(2, $this->sync()->syncUpdate('src', $this->everyRow(), self::BASE, 'bob'));
		$this->assertSame(['src', 'c-bob'], $this->written);
	}

	public function testAMemberWhoLeftTheGroupBeforeTheSyncsLockIsRefused(): void {
		$this->groupsUnderLock = ['admin' => ['mallory']];
		$this->audit->expects($this->never())->method('recordTeamWriteSync');

		try {
			$this->sync()->syncUpdate('src', $this->everyRow(), self::BASE, 'bob');
			$this->fail('A member who left the group wrote every copy');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('Not authorized to manage shares of this secret', $e->getMessage());
		}

		$this->assertSame([], $this->written);
	}

	public function testAGroupMembersSyncNamingACopyWithoutAValueIsRefused(): void {
		// Dan has a vault key: his copy would keep the old value under a new version.
		$updates = [['secretId' => 'src', 'key' => 'NEW'], ['secretId' => 'c-bob', 'key' => 'NEW'], ['secretId' => 'c-dan']];

		try {
			$this->sync()->syncUpdate('src', $updates, self::BASE, 'bob');
			$this->fail('A copy was named without a value and its holder has a vault key');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString('must get the new value', $e->getMessage());
		}

		$this->assertSame([], $this->written);
	}

	public function testAGroupMembersSyncLeavesTheCopyOfAHolderWithoutAVaultKeyUntouched(): void {
		$this->keyless = ['dan'];
		$updates = [['secretId' => 'src', 'key' => 'NEW'], ['secretId' => 'c-bob', 'key' => 'NEW'], ['secretId' => 'c-dan']];

		$this->assertSame(2, $this->sync()->syncUpdate('src', $updates, self::BASE, 'bob'));
		$this->assertSame(['src', 'c-bob'], $this->written);
		$this->assertNull($this->rows['c-dan']->getUpdatedAt());
	}

	public function testASourceMadeReadOnlyBeforeTheSyncsLockRefusesAGroupMember(): void {
		$this->srcUnderLock = static fn (Secret $src) => $src->setReadOnly(true);

		try {
			$this->sync()->syncUpdate('src', $this->everyRow(), self::BASE, 'bob');
			$this->fail('A group member wrote a source that became read-only');
		} catch (ForbiddenException) {
		}

		$this->assertSame([], $this->written);
	}

	public function testASourceMadeUseOnlyBeforeTheSyncsLockRefusesAGroupMember(): void {
		$this->srcUnderLock = static fn (Secret $src) => $src->setUseOnly(true);

		try {
			$this->sync()->syncUpdate('src', $this->everyRow(), self::BASE, 'bob');
			$this->fail('A group member wrote a source that became use-only');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('A use-only copy cannot be changed', $e->getMessage());
		}

		$this->assertSame([], $this->written);
	}

	public function testTheOwnersSyncKeepsWorkingWithoutABase(): void {
		$this->assertSame(1, $this->sync()->syncUpdate('src', [['secretId' => 'src', 'key' => 'NEW']], '', 'alice'));
	}

	public function testAMemberWhoLeftTheGroupKeepsTheirCopyButNoWriteGrade(): void {
		// The leave cleanup failed: Bob's copy and its share row are still there.
		$this->groups['admin'] = ['mallory'];

		$context = $this->sync()->writeContext('c-bob', 'bob');
		$this->assertSame('none', $context['effectiveGrade']);
		$this->assertNull($context['ownerCertificate']);

		$this->expectException(InvalidArgumentException::class);
		$this->sync()->syncUpdate('src', $this->everyRow(), '', 'bob');
	}

	public function testADirectRecipientKeepsEditingOnlyTheirOwnCopy(): void {
		$context = $this->sync()->writeContext('c-dan', 'dan');
		$this->assertSame('none', $context['effectiveGrade']);
		$this->assertNull($context['ownerCertificate']);

		$this->expectException(InvalidArgumentException::class);
		$this->sync()->syncUpdate('src', $this->everyRow(), '', 'dan');
	}

	public function testAMemberWhoseCopyCameThroughADirectShareGetsTheWriteGrade(): void {
		$this->groups['admin'][] = 'dan';
		$this->audit->expects($this->once())->method('recordTeamWriteSync')
			->with('dan', 'src', 'Router', 'group-write sync');

		$this->assertSame('write', $this->sync()->writeContext('c-dan', 'dan')['effectiveGrade']);
		$this->assertSame(3, $this->sync()->syncUpdate('src', $this->everyRow(), self::BASE, 'dan'));
	}

	public function testACopyHolderWhoJoinsASharedGroupLaterSavesForEveryone(): void {
		// Dan's copy came through a direct share; later a group admin adds him to admin.
		$this->assertSame('none', $this->sync()->writeContext('c-dan', 'dan')['effectiveGrade']);

		$notifications = $this->createMock(NotificationService::class);
		$notifications->expects($this->once())->method('notify')
			->with('group_member_added', 'alice', $this->anything(), 'secret', 'src');
		$groupShares = $this->groupShareService(notifications: $notifications);
		$this->groups['admin'][] = 'dan';
		$groupShares->handleNewGroupMember('dan', 'admin');

		// Before Alice answers the notification.
		$context = $this->sync()->writeContext('c-dan', 'dan');
		$this->assertSame('write', $context['effectiveGrade']);
		$this->assertSame('OWNER-CERT', $context['ownerCertificate']);

		// Approving it changes nothing: Dan already holds a copy.
		$groupShares->approveGroupMemberShare('gs-admin', 'dan', 'c-dan-2', 'alice');
		$this->assertCount(2, $this->targets);
	}

	private function groupShareService(NotificationService $notifications): GroupShareService {
		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('findById')->willReturnCallback(
			fn (string $id): Secret => $this->rows[$id] ?? throw new DoesNotExistException('none')
		);
		$targets = $this->createMock(ShareTargetMapper::class);
		$targets->method('findBySourceSecretAndTargetUser')->willReturnCallback(
			fn (string $sourceId, string $userId): ShareTarget => $this->targetWhere(
				match: static fn (ShareTarget $t): bool => $t->getSourceSecretId() === $sourceId && $t->getTargetUserId() === $userId
			)
		);
		$targets->method('insert')->willReturnCallback(
			function (ShareTarget $target): ShareTarget {
				$this->targets[] = $target;
				return $target;
			}
		);
		$groupShares = $this->createMock(GroupShareMapper::class);
		$groupShares->method('findById')->willReturnCallback(
			fn (string $id): GroupShare => array_values(array_filter(
				$this->groupShares,
				static fn (GroupShare $share): bool => $share->getId() === $id
			))[0] ?? throw new DoesNotExistException('none')
		);
		$groupShares->method('findByGroup')->willReturnCallback(
			fn (string $groupId): array => array_values(array_filter(
				$this->groupShares,
				static fn (GroupShare $share): bool => $share->getGroupId() === $groupId
			))
		);

		return new GroupShareService(
			mapper: $groupShares,
			shareTargetMapper: $targets,
			bulkGrantMapper: $this->createMock(BulkGrantShareTargetMapper::class),
			secretMapper: $secrets,
			suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
			delegationMapper: $this->createMock(SecretDelegationMapper::class),
			groupManager: $this->createMock(IGroupManager::class),
			notificationService: $notifications,
			logger: $this->createMock(LoggerInterface::class),
			shareManager: $this->createMock(IShareManager::class),
			revocationService: $this->createMock(ShareRevocationService::class),
		);
	}

	public function testAMemberWhoseCopyCameThroughATeamFolderGetsTheWriteGrade(): void {
		// Dan's copy came with his `read` membership of a team folder; he is in `admin` too.
		$this->targets[1]->setTeamFolderId('tf-1');
		$this->teamGrades['dan'] = 'read';
		$this->groups['admin'][] = 'dan';
		$this->audit->expects($this->once())->method('recordTeamWriteSync')
			->with('dan', 'src', 'Router', 'group-write sync');

		$this->assertSame('write', $this->sync()->writeContext('c-dan', 'dan')['effectiveGrade']);
		$this->assertSame(3, $this->sync()->syncUpdate('src', $this->everyRow(), self::BASE, 'dan'));
	}

	public function testAMemberWhoLeftOneGroupButIsStillInAnotherGetsTheWriteGrade(): void {
		// Bob's copy is tied to the admin share; the ops share reaches him too.
		$this->groupShares[] = $this->groupShare(id: 'gs-ops', secretId: 'src', groupId: 'ops');
		$this->groups = ['admin' => ['mallory'], 'ops' => ['bob']];

		$this->assertSame('write', $this->sync()->writeContext('c-bob', 'bob')['effectiveGrade']);
	}

	public function testADirectCopyCarryingAnotherSecretsGroupShareIdGivesNone(): void {
		$this->targets[1]->setGroupShareId('gs-other');
		$this->groups['staff'] = ['dan'];

		$this->assertSame('none', $this->sync()->writeContext('c-dan', 'dan')['effectiveGrade']);

		$this->expectException(InvalidArgumentException::class);
		$this->sync()->syncUpdate('src', $this->everyRow(), '', 'dan');
	}

	public function testARevokedGroupShareGivesNone(): void {
		// The revoke removed the group share; Bob's copy is still there.
		$this->groupShares = [];

		$this->assertSame('none', $this->sync()->writeContext('c-bob', 'bob')['effectiveGrade']);

		$this->expectException(InvalidArgumentException::class);
		$this->sync()->syncUpdate('src', $this->everyRow(), '', 'bob');
	}

	public function testAnExpiredGroupShareGivesNone(): void {
		$this->groupShares[0]->setExpiresAt(new DateTime('-1 minute'));

		$this->assertSame('none', $this->sync()->writeContext('c-bob', 'bob')['effectiveGrade']);
	}

	public function testAFullDirectSharePlusAUseOnlyGroupShareGivesNone(): void {
		// Dan's direct share is full, so his copy is not use-only.
		$this->groupShares[0]->setUseOnly(true);
		$this->groups['admin'][] = 'dan';

		$this->assertSame('none', $this->sync()->writeContext('c-dan', 'dan')['effectiveGrade']);

		$this->expectException(InvalidArgumentException::class);
		$this->sync()->syncUpdate('src', $this->everyRow(), '', 'dan');
	}

	public function testAMemberOfAUseOnlyAndAFullGroupShareGetsTheWriteGrade(): void {
		$this->groupShares[0]->setUseOnly(true);
		$this->groupShares[] = $this->groupShare(id: 'gs-ops', secretId: 'src', groupId: 'ops');
		$this->groups['ops'] = ['bob'];

		$this->assertSame('write', $this->sync()->writeContext('c-bob', 'bob')['effectiveGrade']);
	}

	public function testATeamReadMemberWithAUseOnlyGroupShareGetsRead(): void {
		// Bob's team `read` membership lifts the use-only flag of his copy.
		$this->groupShares[0]->setUseOnly(true);
		$this->teamGrades['bob'] = 'read';

		$this->assertSame('read', $this->sync()->writeContext('c-bob', 'bob')['effectiveGrade']);
	}

	public function testATeamReadMemberStillGetsRead(): void {
		$this->teamGrades['tina'] = 'read';

		$context = $this->sync()->writeContext('src', 'tina');
		$this->assertSame('read', $context['effectiveGrade']);
		$this->assertNull($context['ownerCertificate']);
	}

	public function testATeamReadMemberInTheGroupWithACopyGetsWrite(): void {
		$this->teamGrades['bob'] = 'read';

		$context = $this->sync()->writeContext('c-bob', 'bob');
		$this->assertSame('write', $context['effectiveGrade']);
		$this->assertSame('OWNER-CERT', $context['ownerCertificate']);
	}

	public function testAUseOnlyGroupCopyGivesNoWriteGrade(): void {
		$this->rows['c-bob']->setUseOnly(true);

		$this->assertSame('none', $this->sync()->writeContext('c-bob', 'bob')['effectiveGrade']);
	}

	public function testAGroupCopyWhoseAccessEndedIsAnsweredAsAnUnknownSecret(): void {
		$this->rows['c-bob']->setAccessExpiresAt(new DateTime('-1 minute'));

		$this->expectExceptionObject(new InvalidArgumentException('Secret not found'));
		$this->sync()->writeContext('c-bob', 'bob');
	}

	public function testACopyWithTwoShareRowsIsAnsweredAsAnUnknownSecret(): void {
		$this->targets[] = $this->target(copyId: 'c-bob', userId: 'bob', groupShareId: null);

		// Even the owner, who reaches the source, gets the answer of an unknown id.
		$this->expectExceptionObject(new InvalidArgumentException('Secret not found'));
		$this->sync()->writeContext('c-bob', 'alice');
	}

	public function testTheHolderOfACopyWithTwoShareRowsGetsNoneForTheirOwnRow(): void {
		$this->targets[] = $this->target(copyId: 'c-bob', userId: 'bob', groupShareId: null);

		$this->assertSame(self::OWN_ROW_ONLY, $this->sync()->writeContext('c-bob', 'bob'));
	}

	public function testAHolderWithTwoShareRowsForTheSourceGetsNoneForTheirOwnRow(): void {
		// A second share to Bob, raced past the first, made him a second copy.
		$this->addRow(id: 'c-bob-2', owner: 'bob');
		$this->targets[] = $this->target(copyId: 'c-bob-2', userId: 'bob', groupShareId: null);

		$this->assertSame(self::OWN_ROW_ONLY, $this->sync()->writeContext('c-bob', 'bob'));
	}

	public function testAnOwnRowBehindAShareRowKeyedToSomeoneElseGetsNoneAndNoSource(): void {
		// Erin's own secret was named as Frank's copy of Alice's secret.
		$this->addRow(id: 'e-own', owner: 'erin');
		$this->targets[] = $this->target(copyId: 'e-own', userId: 'frank', groupShareId: null);

		$this->assertSame(self::OWN_ROW_ONLY, $this->sync()->writeContext('e-own', 'erin'));
	}

	public function testSomeoneElsesRowBehindAShareRowKeyedToAnotherUserIsAnsweredAsAnUnknownSecret(): void {
		$this->addRow(id: 'e-own', owner: 'erin');
		$this->targets[] = $this->target(copyId: 'e-own', userId: 'frank', groupShareId: null);

		$this->expectExceptionObject(new InvalidArgumentException('Secret not found'));
		$this->sync()->writeContext('e-own', 'mallory');
	}

	public function testACallerWithoutAccessIsAnsweredAsAnUnknownSecret(): void {
		$this->expectExceptionObject(new InvalidArgumentException('Secret not found'));
		$this->sync()->writeContext('src', 'eve');
	}

	public function testKnowingAGroupCopysIdGivesNoAnswer(): void {
		// Mallory is in the group but holds no copy; Bob's copy id is all she has.
		$this->expectExceptionObject(new InvalidArgumentException('Secret not found'));
		$this->sync()->writeContext('c-bob', 'mallory');
	}

	public function testTheOwnerStillGetsTheOwnerGradeThroughAnyCopy(): void {
		$context = $this->sync()->writeContext('c-bob', 'alice');

		$this->assertSame('owner', $context['effectiveGrade']);
		$this->assertSame('OWNER-CERT', $context['ownerCertificate']);
	}
}
