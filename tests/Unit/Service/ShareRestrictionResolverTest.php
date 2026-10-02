<?php

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use OCA\Keepiq\Db\GroupShare;
use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Db\TeamFolderMember;
use OCA\Keepiq\Service\ShareRestrictionResolver;
use OCA\Keepiq\Service\TeamFolderQueryService;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Materialising use-only and the access end onto a recipient copy
 * (sharing-use-only-and-expiring-shares task 1.2).
 *
 * @spec openspec/changes/sharing-use-only-and-expiring-shares/tasks.md#task-1.2
 */
class ShareRestrictionResolverTest extends TestCase {

	/** @var SecretMapper&MockObject */
	private SecretMapper $secrets;

	/** @var GroupShareMapper&MockObject */
	private GroupShareMapper $groupShares;

	/** @var TeamFolderQueryService&MockObject */
	private TeamFolderQueryService $teamFolders;

	/** @var IGroupManager&MockObject */
	private IGroupManager $groups;

	private Secret $copy;

	private ShareRestrictionResolver $resolver;

	protected function setUp(): void {
		$this->secrets     = $this->createMock(SecretMapper::class);
		$this->groupShares = $this->createMock(GroupShareMapper::class);
		$this->teamFolders = $this->createMock(TeamFolderQueryService::class);
		$this->groups      = $this->createMock(IGroupManager::class);

		$source = new Secret();
		$source->setId('src');
		$source->setOwnerType('user');
		$source->setOwnerId('alice');
		$source->setFolderId('folder-1');

		$this->copy = new Secret();
		$this->copy->setId('copy');
		$this->copy->setOwnerType('user');
		$this->copy->setOwnerId('bob');

		$this->secrets->method('findById')->willReturnCallback(
			fn (string $id): Secret => ($id === 'src') ? $source : $this->copy
		);
		$this->groupShares->method('findBySecret')->willReturn([]);
		$this->teamFolders->method('coveringMemberships')->willReturn([]);

		$this->resolver = new ShareRestrictionResolver(
			secretMapper: $this->secrets,
			shareTargetMapper: $this->createMock(ShareTargetMapper::class),
			groupShareMapper: $this->groupShares,
			teamFolders: $this->teamFolders,
			groupManager: $this->groups,
		);
	}

	private function directTarget(bool $useOnly, ?DateTime $end): ShareTarget {
		$target = new ShareTarget();
		$target->setId('t1');
		$target->setSourceSecretId('src');
		$target->setTargetUserId('bob');
		$target->setSecretId('copy');
		$target->setUseOnly($useOnly);
		$target->setExpiresAt($end);
		return $target;
	}

	/**
	 * A single use-only, expiring direct share lands on the copy.
	 *
	 * @return void
	 */
	public function testASingleGrantIsMaterialised(): void {
		$end = new DateTime('2026-12-01T00:00:00Z');
		$this->secrets->expects($this->once())->method('update')->with($this->identicalTo($this->copy));

		$this->assertTrue($this->resolver->resolveTarget($this->directTarget(true, $end)));
		$this->assertTrue($this->copy->getUseOnly());
		$this->assertEquals($end, $this->copy->getAccessExpiresAt());
	}

	/**
	 * An unrestricted read membership covering Bob lifts use-only and the end.
	 *
	 * @return void
	 */
	public function testAnUnrestrictedSecondGrantLiftsTheRestriction(): void {
		$membership = new TeamFolderMember();
		$membership->setGrade('read');
		$membership->setUseOnly(false);
		$this->teamFolders = $this->createMock(TeamFolderQueryService::class);
		$this->teamFolders->method('coveringMemberships')->willReturn([$membership]);
		$resolver = new ShareRestrictionResolver(
			$this->secrets,
			$this->createMock(ShareTargetMapper::class),
			$this->groupShares,
			$this->teamFolders,
			$this->groups,
		);

		$effective = $resolver->effectiveFor($this->directTarget(true, new DateTime('2026-12-01T00:00:00Z')));
		$this->assertFalse($effective->useOnly);
		$this->assertNull($effective->expiresAt);
	}

	/**
	 * A group share to a group Bob is in counts; one to another group does not.
	 *
	 * @return void
	 */
	public function testOnlyGroupSharesCoveringTheRecipientCount(): void {
		$mine = new GroupShare();
		$mine->setId('g-mine');
		$mine->setGroupId('team');
		$mine->setUseOnly(false);
		$other = new GroupShare();
		$other->setId('g-other');
		$other->setGroupId('strangers');
		$other->setUseOnly(false);

		$groupShares = $this->createMock(GroupShareMapper::class);
		$groupShares->method('findBySecret')->willReturn([$other, $mine]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid): bool => $gid === 'team'
		);
		$resolver = new ShareRestrictionResolver(
			$this->secrets,
			$this->createMock(ShareTargetMapper::class),
			$groupShares,
			$this->teamFolders,
			$groups,
		);

		$this->assertFalse($resolver->effectiveFor($this->directTarget(true, null))->useOnly);

		$groups2 = $this->createMock(IGroupManager::class);
		$groups2->method('isInGroup')->willReturn(false);
		$resolver2 = new ShareRestrictionResolver(
			$this->secrets,
			$this->createMock(ShareTargetMapper::class),
			$groupShares,
			$this->teamFolders,
			$groups2,
		);
		$this->assertTrue($resolver2->effectiveFor($this->directTarget(true, null))->useOnly);
	}

	/**
	 * Clearing the last restricted grant clears the copy.
	 *
	 * @return void
	 */
	public function testRemovingTheLastRestrictionClearsTheCopy(): void {
		$this->copy->setUseOnly(true);
		$this->copy->setAccessExpiresAt(new DateTime('2026-12-01T00:00:00Z'));
		$this->secrets->expects($this->once())->method('update');

		$this->assertTrue($this->resolver->resolveTarget($this->directTarget(false, null)));
		$this->assertFalse($this->copy->getUseOnly());
		$this->assertNull($this->copy->getAccessExpiresAt());
	}

	/**
	 * Nothing changed: nothing written.
	 *
	 * @return void
	 */
	public function testAnUnchangedCopyIsNotWritten(): void {
		$this->secrets->expects($this->never())->method('update');
		$this->assertFalse($this->resolver->resolveTarget($this->directTarget(false, null)));
	}

	/**
	 * A write membership carrying the flag never makes a copy use-only.
	 *
	 * @return void
	 */
	public function testAWriteMembershipIsNeverUseOnly(): void {
		$membership = new TeamFolderMember();
		$membership->setGrade('write');
		$membership->setUseOnly(true);
		$teamFolders = $this->createMock(TeamFolderQueryService::class);
		$teamFolders->method('coveringMemberships')->willReturn([$membership]);
		$resolver = new ShareRestrictionResolver(
			$this->secrets,
			$this->createMock(ShareTargetMapper::class),
			$this->groupShares,
			$teamFolders,
			$this->groups,
		);

		$target = $this->directTarget(false, null);
		$target->setTeamFolderId('tf-1');
		$this->assertFalse($resolver->effectiveFor($target)->useOnly);
	}
}
