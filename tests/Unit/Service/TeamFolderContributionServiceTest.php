<?php

/**
 * Unit tests for TeamFolderContributionService (admin-vault-policies §4.2),
 * with the REAL grade resolver, membership resolver and share service over
 * mocked mappers.
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

use OCA\Keepiq\Db\BulkGrantShareTargetMapper;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Folder;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Db\TeamFolder;
use OCA\Keepiq\Db\TeamFolderMapper;
use OCA\Keepiq\Db\TeamFolderMember;
use OCA\Keepiq\Db\TeamFolderMemberMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\RecipientSecretCopyService;
use OCA\Keepiq\Service\SecretTypeService;
use OCA\Keepiq\Service\TeamFolderContributionService;
use OCA\Keepiq\Service\TeamFolderMembershipResolver;
use OCA\Keepiq\Service\TeamFolderQueryService;
use OCA\Keepiq\Service\TeamFolderShareService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Team folder Ops (owner iris): hank write, jack read; lee is no member.
 */
class TeamFolderContributionServiceTest extends TestCase {

	/** @var array<int,Secret> */
	private array $inserted = [];

	/** @var array<int,AuditEvent> */
	private array $events = [];

	private TeamFolderContributionService $service;

	/**
	 * Wire the real collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$teamFolder = new TeamFolder();
		$teamFolder->setId('tf-ops');
		$teamFolder->setFolderId('folder-ops');
		$teamFolder->setOwnerId('iris');
		$mapper = $this->createMock(TeamFolderMapper::class);
		$mapper->method('findById')->willReturn($teamFolder);
		$mapper->method('findByFolder')->willReturnCallback(
			static fn (string $id) => ($id === 'folder-ops' ? $teamFolder : throw new DoesNotExistException(''))
		);

		$members = [];
		foreach (['hank' => 'write', 'jack' => 'read'] as $uid => $grade) {
			$member = new TeamFolderMember();
			$member->setTeamFolderId('tf-ops');
			$member->setMemberType('user');
			$member->setMemberId($uid);
			$member->setGrade($grade);
			$members[] = $member;
		}
		$memberMapper = $this->createMock(TeamFolderMemberMapper::class);
		$memberMapper->method('findByTeamFolder')->willReturn($members);

		$folder = new Folder();
		$folder->setId('folder-ops');
		$folder->setParentId(null);
		$folderMapper = $this->createMock(FolderMapper::class);
		$folderMapper->method('findById')->willReturn($folder);
		$folderMapper->method('getSubtreeIds')->willReturn(['folder-ops', 'folder-ops-sub']);

		$secretMapper = $this->createMock(SecretMapper::class);
		$secretMapper->method('insert')->willReturnCallback(function (Secret $secret) {
			$this->inserted[] = $secret;
			return $secret;
		});
		$secretMapper->method('findById')->willReturnCallback(
			fn (string $id) => (array_values(array_filter($this->inserted, static fn ($s) => $s->getId() === $id))[0]
				?? throw new DoesNotExistException(''))
		);

		$suite = new EncryptionSuite();
		$suite->setId('suite');
		$suite->setCertificate('CERT');
		$suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
		$suiteMapper->method('findActiveByOwner')->willReturn($suite);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(function (string $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('isEnabled')->willReturn(true);
			return $user;
		});
		$groupManager = $this->createMock(IGroupManager::class);

		$shareTargetMapper = $this->createMock(ShareTargetMapper::class);
		$shareTargetMapper->method('findBySourceSecretAndTargetUser')->willThrowException(new DoesNotExistException(''));
		$shareTargetMapper->method('insert')->willReturnArgument(0);

		$typeService = $this->createMock(SecretTypeService::class);
		$typeService->method('resolveTypeForSecret')->willReturn('type-login');

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (object $event): void {
			if ($event instanceof AuditEvent) {
				$this->events[] = $event;
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

		$this->service = new TeamFolderContributionService(
			teamFolderMapper: $mapper,
			folderMapper: $folderMapper,
			queries: new TeamFolderQueryService(
				mapper: $mapper,
				memberMapper: $memberMapper,
				folderMapper: $folderMapper,
				secretMapper: $secretMapper,
				groupManager: $groupManager,
				memberships: $memberships,
			),
			memberships: $memberships,
			shares: new TeamFolderShareService(
				shareTargetMapper: $shareTargetMapper,
				bulkGrantMapper: $this->createMock(BulkGrantShareTargetMapper::class),
				copies: new RecipientSecretCopyService(
					secretMapper: $secretMapper,
					suiteMapper: $suiteMapper,
					typeService: $typeService,
				),
				notificationService: $this->createMock(NotificationService::class),
				db: $this->createMock(IDBConnection::class),
			),
			secretMapper: $secretMapper,
			suiteMapper: $suiteMapper,
			typeService: $typeService,
			eventDispatcher: $dispatcher,
		);
	}//end setUp()

	/**
	 * The request a member's browser sends.
	 *
	 * @param array<int,string> $copyFor Members the browser encrypted for
	 *
	 * @return array<string,mixed>
	 */
	private function request(array $copyFor = ['hank', 'jack']): array {
		return [
			'name' => 'db-root',
			'key' => 'RSA-FOR-IRIS',
			'copies' => array_map(
				static fn (string $uid): array => ['targetUserId' => $uid, 'encryptedKey' => 'RSA-FOR-' . $uid],
				$copyFor
			),
		];
	}//end request()

	/**
	 * Scenario "Member saves a work login into the team folder".
	 *
	 * @return void
	 */
	public function testWriteMemberContributes(): void {
		$result = $this->service->contribute(teamFolderId: 'tf-ops', data: $this->request(), userId: 'hank');

		$owner = $this->inserted[0];
		$this->assertSame('iris', $owner->getOwnerId());
		$this->assertSame('folder-ops', $owner->getFolderId());
		$this->assertSame('RSA-FOR-IRIS', $owner->getKey());
		$this->assertSame(2, $result['copies']);
		$copies = array_slice($this->inserted, 1);
		$this->assertSame(['hank', 'jack'], array_map(static fn ($c) => $c->getOwnerId(), $copies));
		$this->assertSame(['RSA-FOR-hank', 'RSA-FOR-jack'], array_map(static fn ($c) => $c->getKey(), $copies));

		$this->assertCount(1, $this->events);
		$this->assertSame(AuditEventTypes::SECRET_CREATED, $this->events[0]->getEventType());
		$this->assertSame('hank', $this->events[0]->getActorId());
	}//end testWriteMemberContributes()

	/**
	 * Scenario "Read-grade member is refused", and a non-member: nothing stored.
	 *
	 * @return void
	 */
	public function testReadMemberAndNonMemberAreRefused(): void {
		foreach (['jack', 'lee'] as $uid) {
			try {
				$this->service->contribute(teamFolderId: 'tf-ops', data: $this->request(), userId: $uid);
				$this->fail($uid . ' contributed');
			} catch (ForbiddenException) {
				$this->addToAssertionCount(1);
			}
		}

		$this->assertSame([], $this->inserted);
	}//end testReadMemberAndNonMemberAreRefused()

	/**
	 * A copy for someone who is not a member is dropped.
	 *
	 * @return void
	 */
	public function testCopyForANonMemberIsDropped(): void {
		$result = $this->service->contribute(teamFolderId: 'tf-ops', data: $this->request(copyFor: ['hank', 'lee']), userId: 'hank');

		$this->assertSame(1, $result['copies']);
		$this->assertNotContains('lee', array_map(static fn ($s) => $s->getOwnerId(), $this->inserted));
	}//end testCopyForANonMemberIsDropped()

	/**
	 * A target folder outside the team folder is refused.
	 *
	 * @return void
	 */
	public function testFolderOutsideTheTeamFolderIsRefused(): void {
		$this->expectException(NotFoundException::class);
		$this->service->contribute(
			teamFolderId: 'tf-ops',
			data: ['folderId' => 'elsewhere'] + $this->request(),
			userId: 'hank'
		);
	}//end testFolderOutsideTheTeamFolderIsRefused()
}//end class
