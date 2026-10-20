<?php

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use InvalidArgumentException;
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
use OCA\Keepiq\Service\ShareAuditTrail;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCA\Keepiq\Service\SecretVersionService;
use OCA\Keepiq\Service\ShareSyncService;
use OCA\Keepiq\Service\TeamFolderService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Alice owns `src`, shared with the `admin` group (Bob's copy `c-bob`) and
 * directly with Dan (`c-dan`). Mallory is in `admin` but holds no copy.
 * Another secret, `other`, is shared with the `staff` group.
 *
 * @spec openspec/specs/sharing-group/spec.md#requirement-a-group-member-saves-for-everyone-it-is-shared-with
 */
class GroupMemberWritesForEveryoneTest extends TestCase {

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

	protected function setUp(): void {
		$this->audit = $this->createMock(ShareAuditTrail::class);
		$this->versions = $this->createMock(SecretVersionService::class);
		$this->addRow(id: 'src', owner: 'alice');
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
		$secrets->method('update')->willReturnArgument(0);

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

		$this->assertSame(3, $this->sync()->syncUpdate('src', $this->everyRow(), '', 'bob'));
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

		$this->sync()->syncUpdate('src', $this->everyRow(), '', 'bob');
	}

	public function testTheOwnersSyncTakesNoSnapshotOfTheSource(): void {
		// The owner's own update through PUT /secrets/{id} keeps her version.
		$this->rows['src']->setKey('OLD');
		$this->versions->expects($this->never())->method('snapshot');

		$this->sync()->syncUpdate('src', $this->everyRow(), '', 'alice');
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
		$this->assertSame(3, $this->sync()->syncUpdate('src', $this->everyRow(), '', 'dan'));
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
