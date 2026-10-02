<?php

/**
 * Unit tests for TeamFolderConfirmationService (admin-auto-confirm-members §2).
 *
 * Runs the REAL membership resolver, grade resolver and share service over
 * mocked mappers, so the refusals are the ones production applies.
 *
 * @category Tests
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\BulkGrantShareTargetMapper;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Folder;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Db\TeamFolder;
use OCA\Keepiq\Db\TeamFolderMapper;
use OCA\Keepiq\Db\TeamFolderMember;
use OCA\Keepiq\Db\TeamFolderMemberMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\RecipientSecretCopyService;
use OCA\Keepiq\Service\SecretTypeService;
use OCA\Keepiq\Service\TeamFolderAuditor;
use OCA\Keepiq\Service\TeamFolderConfirmationService;
use OCA\Keepiq\Service\TeamFolderMembershipResolver;
use OCA\Keepiq\Service\TeamFolderQueryService;
use OCA\Keepiq\Service\TeamFolderService;
use OCA\Keepiq\Service\TeamFolderShareService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Folder `Ops` (owner iris): hank is a direct `write` member, jack a direct
 * `read` member, group ops-team (kim) a `read` member. Secret db-root sits in
 * the folder; hank and jack hold copies, kim waits.
 */
class TeamFolderConfirmationServiceTest extends TestCase {

	private bool $switchOn = true;

	/** @var array<string,bool> */
	private array $enabled = [];

	/** @var array<string,Secret> */
	private array $secrets = [];

	/** @var array<string,string> source|target => copy id */
	private array $shareRows = [];

	/** @var array<int,array<string,mixed>> */
	private array $notifications = [];

	/** @var array<int,AuditEvent> */
	private array $auditEvents = [];

	/** @var array<int,Secret> */
	private array $insertedCopies = [];

	private TeamFolderService&MockObject $teamFolders;

	private TeamFolderConfirmationService $service;

	/**
	 * Wire the real collaborators over mocked mappers.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->enabled = ['iris' => true, 'hank' => true, 'jack' => true, 'kim' => true];

		$source = new Secret();
		$source->setId('db-root');
		$source->setName('db-root');
		$source->setOwnerType('user');
		$source->setOwnerId('iris');
		$source->setFolderId('folder-ops');
		$source->setTypeId('type-login');
		$source->setKeyUpdatedAt(new DateTime('2026-09-10'));
		$this->secrets['db-root'] = $source;
		$this->secrets['copy-hank'] = $this->copy(id: 'copy-hank', owner: 'hank', updatedAt: '2026-09-15');
		$this->secrets['copy-jack'] = $this->copy(id: 'copy-jack', owner: 'jack', updatedAt: '2026-09-15');
		$this->shareRows = ['db-root|hank' => 'copy-hank', 'db-root|jack' => 'copy-jack'];

		$teamFolder = new TeamFolder();
		$teamFolder->setId('tf-ops');
		$teamFolder->setFolderId('folder-ops');
		$teamFolder->setOwnerId('iris');

		$members = [
			$this->member(type: 'user', id: 'hank', grade: 'write'),
			$this->member(type: 'user', id: 'jack', grade: 'read'),
			$this->member(type: 'group', id: 'ops-team', grade: 'read'),
		];

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(fn (): bool => $this->switchOn);

		$mapper = $this->createMock(TeamFolderMapper::class);
		$mapper->method('findById')->willReturnCallback(
			static fn (string $id) => ($id === 'tf-ops' ? $teamFolder : throw new DoesNotExistException(''))
		);
		$mapper->method('findByOwner')->willReturnCallback(
			static fn (string $ownerId): array => ($ownerId === 'iris' ? [$teamFolder] : [])
		);
		$mapper->method('findByFolder')->willReturnCallback(
			static fn (string $folderId) => ($folderId === 'folder-ops' ? $teamFolder : throw new DoesNotExistException(''))
		);

		$memberMapper = $this->createMock(TeamFolderMemberMapper::class);
		$memberMapper->method('findByTeamFolder')->willReturn($members);
		$memberMapper->method('findUserMemberships')->willReturnCallback(
			static fn (string $uid): array => array_values(
				array_filter($members, static fn ($m) => $m->getMemberType() === 'user' && $m->getMemberId() === $uid)
			)
		);
		$memberMapper->method('findGroupMemberships')->willReturnCallback(
			static fn (string $gid): array => ($gid === 'ops-team' ? [$members[2]] : [])
		);

		$kim = $this->createMock(IUser::class);
		$kim->method('getUID')->willReturn('kim');
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$kim]);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturn($group);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid): bool => $uid === 'kim' && $gid === 'ops-team'
		);
		$groupManager->method('getUserGroupIds')->willReturnCallback(
			static fn (IUser $user): array => ($user->getUID() === 'kim' ? ['ops-team'] : [])
		);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(function (string $uid) {
			if (isset($this->enabled[$uid]) === false) {
				return null;
			}

			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('isEnabled')->willReturn($this->enabled[$uid]);
			return $user;
		});

		$folder = new Folder();
		$folder->setId('folder-ops');
		$folder->setParentId(null);
		$folderMapper = $this->createMock(FolderMapper::class);
		$folderMapper->method('getSubtreeIds')->willReturn(['folder-ops']);
		$folderMapper->method('findById')->willReturn($folder);

		$secretMapper = $this->createMock(SecretMapper::class);
		$secretMapper->method('findByOwner')->willReturnCallback(
			fn (string $type, string $owner): array => ($owner === 'iris' ? [$this->secrets['db-root']] : [])
		);
		$secretMapper->method('findById')->willReturnCallback(
			fn (string $id) => ($this->secrets[$id] ?? throw new DoesNotExistException(''))
		);
		$secretMapper->method('insert')->willReturnCallback(function (Secret $copy) {
			$this->insertedCopies[] = $copy;
			return $copy;
		});

		$suite = new EncryptionSuite();
		$suite->setId('suite');
		$suite->setCertificate('-----BEGIN CERTIFICATE-----');
		$suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
		$suiteMapper->method('findActiveByOwner')->willReturn($suite);

		$shareTargetMapper = $this->createMock(ShareTargetMapper::class);
		$shareTargetMapper->method('findBySourceSecretAndTargetUser')->willReturnCallback(
			function (string $sourceSecretId, string $targetUserId) {
				$copyId = ($this->shareRows[$sourceSecretId . '|' . $targetUserId] ?? null);
				if ($copyId === null) {
					throw new DoesNotExistException('');
				}

				$row = new ShareTarget();
				$row->setSecretId($copyId);
				return $row;
			}
		);
		$shareTargetMapper->method('insert')->willReturnArgument(0);

		$typeService = $this->createMock(SecretTypeService::class);
		$typeService->method('resolveTypeForSecret')->willReturn('type-login');

		$notificationService = $this->createMock(NotificationService::class);
		$notificationService->method('notify')->willReturnCallback(function (...$args): bool {
			$this->notifications[] = $args;
			return true;
		});

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (object $event): void {
			if ($event instanceof AuditEvent) {
				$this->auditEvents[] = $event;
			}
		});

		$memberships = new TeamFolderMembershipResolver(
			memberMapper: $memberMapper,
			folderMapper: $folderMapper,
			secretMapper: $secretMapper,
			suiteMapper: $suiteMapper,
			groupManager: $groupManager,
			userManager: $userManager,
		);
		$shares = new TeamFolderShareService(
			shareTargetMapper: $shareTargetMapper,
			bulkGrantMapper: $this->createMock(BulkGrantShareTargetMapper::class),
			copies: new RecipientSecretCopyService(
				secretMapper: $secretMapper,
				suiteMapper: $suiteMapper,
				typeService: $typeService,
			),
			notificationService: $notificationService,
			db: $this->createMock(IDBConnection::class),
		);
		$this->teamFolders = $this->createMock(TeamFolderService::class);

		$this->service = new TeamFolderConfirmationService(
			appConfig: $appConfig,
			mapper: $mapper,
			teamFolders: $this->teamFolders,
			queries: new TeamFolderQueryService(
				mapper: $mapper,
				memberMapper: $memberMapper,
				folderMapper: $folderMapper,
				secretMapper: $secretMapper,
				groupManager: $groupManager,
				memberships: $memberships,
			),
			memberships: $memberships,
			shares: $shares,
			shareTargetMapper: $shareTargetMapper,
			secretMapper: $secretMapper,
			notificationService: $notificationService,
			audit: new TeamFolderAuditor(eventDispatcher: $dispatcher),
		);
	}//end setUp()

	/**
	 * Build a recipient copy.
	 *
	 * @param string $id The copy id
	 * @param string $owner The copy holder
	 * @param string $updatedAt When the copy was last written
	 *
	 * @return Secret
	 */
	private function copy(string $id, string $owner, string $updatedAt): Secret {
		$copy = new Secret();
		$copy->setId($id);
		$copy->setOwnerType('user');
		$copy->setOwnerId($owner);
		$copy->setUpdatedAt(new DateTime($updatedAt));
		return $copy;
	}//end copy()

	/**
	 * Build a membership row.
	 *
	 * @param string $type user or group
	 * @param string $id The member id
	 * @param string $grade read or write
	 *
	 * @return TeamFolderMember
	 */
	private function member(string $type, string $id, string $grade): TeamFolderMember {
		$member = new TeamFolderMember();
		$member->setId('m-' . $id);
		$member->setTeamFolderId('tf-ops');
		$member->setMemberType($type);
		$member->setMemberId($id);
		$member->setGrade($grade);
		return $member;
	}//end member()

	/**
	 * A browser row for one pair.
	 *
	 * @param string $target The target user
	 * @param string $source The source secret
	 *
	 * @return array<string,string>
	 */
	private function row(string $target, string $source = 'db-root'): array {
		return [
			'sourceSecretId' => $source,
			'targetUserId' => $target,
			'encryptedKey' => 'CIPHERTEXT-FOR-' . $target,
		];
	}//end row()

	/**
	 * Scenario "Write member sees a waiting colleague": hank gets Ops with
	 * kim's pair, kim's certificate and the id of his own copy.
	 *
	 * @return void
	 */
	public function testWriteMemberSeesWaitingColleague(): void {
		$pending = $this->service->pendingConfirmations(userId: 'hank');

		$this->assertCount(1, $pending);
		$this->assertSame('tf-ops', $pending[0]['teamFolderId']);
		$this->assertSame('member', $pending[0]['role']);
		$this->assertSame(
			[['secretId' => 'db-root', 'userId' => 'kim', 'ownCopyId' => 'copy-hank']],
			$pending[0]['missing']
		);
		$this->assertSame(['kim'], array_column($pending[0]['recipients'], 'userId'));
	}//end testWriteMemberSeesWaitingColleague()

	/**
	 * The owner sees the pair too, without an own-copy id.
	 *
	 * @return void
	 */
	public function testOwnerSeesWaitingMember(): void {
		$pending = $this->service->pendingConfirmations(userId: 'iris');

		$this->assertSame('owner', $pending[0]['role']);
		$this->assertSame([['secretId' => 'db-root', 'userId' => 'kim']], $pending[0]['missing']);
	}//end testOwnerSeesWaitingMember()

	/**
	 * Scenario "Read member sees nothing", plus a non-member and the switch off.
	 *
	 * @return void
	 */
	public function testReadMemberNonMemberAndSwitchOffSeeNothing(): void {
		$this->assertSame([], $this->service->pendingConfirmations(userId: 'jack'));
		$this->assertSame([], $this->service->pendingConfirmations(userId: 'lee'));

		$this->switchOn = false;
		$this->assertSame([], $this->service->pendingConfirmations(userId: 'hank'));
		$this->assertSame([], $this->service->pendingConfirmations(userId: 'iris'));
	}//end testReadMemberNonMemberAndSwitchOffSeeNothing()

	/**
	 * A disabled recipient is never offered.
	 *
	 * @return void
	 */
	public function testDisabledRecipientIsNotOffered(): void {
		$this->enabled['kim'] = false;

		$this->assertSame([], $this->service->pendingConfirmations(userId: 'hank'));
	}//end testDisabledRecipientIsNotOffered()

	/**
	 * A stale own copy cannot be handed out: nothing pending for hank.
	 *
	 * @return void
	 */
	public function testStaleCopyIsNotOffered(): void {
		$this->secrets['copy-hank'] = $this->copy(id: 'copy-hank', owner: 'hank', updatedAt: '2026-09-01');

		$this->assertSame([], $this->service->pendingConfirmations(userId: 'hank'));
	}//end testStaleCopyIsNotOffered()

	/**
	 * An accepted row stores kim's copy, notifies kim and the owner, and
	 * audits with hank as actor (§2.2, §2.3).
	 *
	 * @return void
	 */
	public function testWriteMemberRowIsAcceptedAndAnnounced(): void {
		$result = $this->service->registerShares(teamFolderId: 'tf-ops', rows: [$this->row(target: 'kim')], userId: 'hank');

		$this->assertSame(1, $result['created']);
		$this->assertCount(1, $this->insertedCopies);
		$this->assertSame('kim', $this->insertedCopies[0]->getOwnerId());
		$this->assertSame('CIPHERTEXT-FOR-kim', $this->insertedCopies[0]->getKey());

		$subjects = array_map(static fn (array $args): string => $args[0], $this->notifications);
		$this->assertSame(['team_folder_shared', 'team_folder_member_confirmed'], $subjects);
		$this->assertSame('iris', $this->notifications[1][1]);
		$this->assertSame('hank', $this->notifications[1][2]['confirmedBy']);
		$this->assertSame(['kim'], $this->notifications[1][2]['memberIds']);

		$this->assertCount(1, $this->auditEvents);
		$this->assertSame(AuditEventTypes::TEAM_FOLDER_MEMBERS_CONFIRMED, $this->auditEvents[0]->getEventType());
		$this->assertSame('hank', $this->auditEvents[0]->getActorId());
	}//end testWriteMemberRowIsAcceptedAndAnnounced()

	/**
	 * Every refusal stores nothing: stale copy, uncovered user, read
	 * grade, a source outside the folder, and the switch off.
	 *
	 * @return void
	 */
	public function testUnsafeRowsAreSkipped(): void {
		// Scenario "Uncovered user is refused": lee is in no membership row.
		$this->enabled['lee'] = true;
		$this->assertSame(0, $this->service->registerShares('tf-ops', [$this->row(target: 'lee')], 'hank')['created']);

		// A read member cannot hand a copy on.
		$this->assertSame(0, $this->service->registerShares('tf-ops', [$this->row(target: 'kim')], 'jack')['created']);

		// A source outside the folder subtree.
		$this->assertSame(
			0,
			$this->service->registerShares('tf-ops', [$this->row(target: 'kim', source: 'elsewhere')], 'hank')['created']
		);

		// Scenario "Stale copy is refused".
		$this->secrets['copy-hank'] = $this->copy(id: 'copy-hank', owner: 'hank', updatedAt: '2026-09-01');
		$this->assertSame(0, $this->service->registerShares('tf-ops', [$this->row(target: 'kim')], 'hank')['created']);

		$this->assertSame([], $this->insertedCopies);
		$this->assertSame([], $this->notifications);
	}//end testUnsafeRowsAreSkipped()

	/**
	 * With the switch off a non-owner is refused outright, as before.
	 *
	 * @return void
	 */
	public function testSwitchOffRefusesNonOwner(): void {
		$this->switchOn = false;

		$this->expectException(InvalidArgumentException::class);
		$this->service->registerShares(teamFolderId: 'tf-ops', rows: [$this->row(target: 'kim')], userId: 'hank');
	}//end testSwitchOffRefusesNonOwner()

	/**
	 * The owner keeps the plain fan-out path, switch on or off.
	 *
	 * @return void
	 */
	public function testOwnerUsesThePlainFanOut(): void {
		$this->switchOn = false;
		$this->teamFolders->expects($this->once())->method('registerFanOutShares')
			->with('tf-ops', [$this->row(target: 'kim')], 'iris')
			->willReturn(['created' => 1, 'rows' => []]);

		$result = $this->service->registerShares(teamFolderId: 'tf-ops', rows: [$this->row(target: 'kim')], userId: 'iris');

		$this->assertSame(1, $result['created']);
	}//end testOwnerUsesThePlainFanOut()
}//end class
