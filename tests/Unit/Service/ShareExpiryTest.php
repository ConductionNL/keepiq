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
use OCA\Keepiq\Db\TeamFolder;
use OCA\Keepiq\Db\TeamFolderMapper;
use OCA\Keepiq\Db\TeamFolderMember;
use OCA\Keepiq\Db\TeamFolderMemberMapper;
use OCA\Keepiq\Service\ExpiredGrantRemover;
use OCA\Keepiq\Service\GroupShareService;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\ShareExpiryService;
use OCA\Keepiq\Service\ShareRestriction;
use OCA\Keepiq\Service\ShareRestrictionResolver;
use OCA\Keepiq\Service\ShareRevocationService;
use OCA\Keepiq\Service\TeamFolderService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The expiry job's work (sharing-use-only-and-expiring-shares tasks 5.2, 5.3).
 *
 * @spec openspec/specs/expiring-shares/spec.md#requirement-a-background-job-removes-expired-access
 */
class ShareExpiryTest extends TestCase {

	/** @var SecretMapper&MockObject */
	private SecretMapper $secrets;

	/** @var ShareTargetMapper&MockObject */
	private ShareTargetMapper $targets;

	/** @var GroupShareMapper&MockObject */
	private GroupShareMapper $groupShares;

	/** @var TeamFolderMemberMapper&MockObject */
	private TeamFolderMemberMapper $members;

	/** @var TeamFolderMapper&MockObject */
	private TeamFolderMapper $teamFolders;

	/** @var ShareRestrictionResolver&MockObject */
	private ShareRestrictionResolver $resolver;

	/** @var ShareRevocationService&MockObject */
	private ShareRevocationService $revocation;

	/** @var GroupShareService&MockObject */
	private GroupShareService $groupShareService;

	/** @var TeamFolderService&MockObject */
	private TeamFolderService $teamFolderService;

	private DateTime $now;

	protected function setUp(): void {
		$this->secrets           = $this->createMock(SecretMapper::class);
		$this->targets           = $this->createMock(ShareTargetMapper::class);
		$this->groupShares       = $this->createMock(GroupShareMapper::class);
		$this->members           = $this->createMock(TeamFolderMemberMapper::class);
		$this->teamFolders       = $this->createMock(TeamFolderMapper::class);
		$this->resolver          = $this->createMock(ShareRestrictionResolver::class);
		$this->revocation        = $this->createMock(ShareRevocationService::class);
		$this->groupShareService = $this->createMock(GroupShareService::class);
		$this->teamFolderService = $this->createMock(TeamFolderService::class);
		$this->now               = new DateTime('2026-10-02T12:00:00Z');

		$source = new Secret();
		$source->setId('src');
		$source->setName('Payroll portal');
		$source->setOwnerId('alice');
		$this->secrets->method('findById')->willReturnCallback(
			static fn (string $id): Secret => ($id === 'src') ? $source : throw new DoesNotExistException('gone')
		);
	}

	private function remover(): ExpiredGrantRemover {
		return new ExpiredGrantRemover(
			$this->secrets,
			$this->targets,
			$this->groupShares,
			$this->members,
			$this->teamFolders,
			$this->resolver,
			$this->revocation,
			$this->groupShareService,
			$this->teamFolderService,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function directShare(): ShareTarget {
		$target = new ShareTarget();
		$target->setId('share-1');
		$target->setSourceSecretId('src');
		$target->setTargetUserId('carla');
		$target->setSecretId('copy');
		$target->setExpiresAt(new DateTime('2026-10-02T11:00:00Z'));
		return $target;
	}

	/**
	 * An expired direct share is revoked as the owner of its source.
	 *
	 * @return void
	 */
	public function testAnExpiredDirectShareIsRevokedAsTheOwner(): void {
		$this->targets->method('findEndingBetween')->willReturn([$this->directShare()]);
		$this->groupShares->method('findEndingBetween')->willReturn([]);
		$this->members->method('findEndingBetween')->willReturn([]);
		$this->resolver->method('effectiveFor')->willReturn(new ShareRestriction(false, new DateTime('2026-10-02T11:00:00Z')));
		$this->revocation->expects($this->once())->method('revokeShare')->with('share-1', 'alice');

		$this->assertSame(1, $this->remover()->removeExpired($this->now));
	}

	/**
	 * A direct share whose recipient is still covered by an open grant is
	 * not revoked; the copy is recomputed instead.
	 *
	 * @return void
	 */
	public function testAnExpiredDirectShareStillCoveredElsewhereIsKept(): void {
		$this->targets->method('findEndingBetween')->willReturn([$this->directShare()]);
		$this->groupShares->method('findEndingBetween')->willReturn([]);
		$this->members->method('findEndingBetween')->willReturn([]);
		$this->resolver->method('effectiveFor')->willReturn(new ShareRestriction(false, null));
		$this->resolver->expects($this->once())->method('resolveTarget');
		$this->revocation->expects($this->never())->method('revokeShare');

		$this->assertSame(0, $this->remover()->removeExpired($this->now));
	}

	/**
	 * An expired group share and an expired membership go through their own
	 * removal paths, as the source owner and the folder owner.
	 *
	 * @return void
	 */
	public function testExpiredGroupSharesAndMembershipsUseTheirOwnPaths(): void {
		$groupShare = new GroupShare();
		$groupShare->setId('gs-1');
		$groupShare->setSecretId('src');
		$membership = new TeamFolderMember();
		$membership->setId('mem-1');
		$membership->setTeamFolderId('tf-1');
		$teamFolder = new TeamFolder();
		$teamFolder->setId('tf-1');
		$teamFolder->setOwnerId('olga');

		$this->targets->method('findEndingBetween')->willReturn([]);
		$this->groupShares->method('findEndingBetween')->willReturn([$groupShare]);
		$this->members->method('findEndingBetween')->willReturn([$membership]);
		$this->teamFolders->method('findById')->willReturn($teamFolder);
		$this->groupShareService->expects($this->once())->method('revokeGroupShare')->with('gs-1', 'alice');
		$this->teamFolderService->expects($this->once())->method('removeMember')->with('tf-1', 'mem-1', 'olga');

		$this->assertSame(2, $this->remover()->removeExpired($this->now));
	}

	/**
	 * The holder is told, and the owner is told with the rotation hint that
	 * fits: the holder of a normal share could see the password.
	 *
	 * @return void
	 */
	public function testHolderAndOwnerAreToldWhenAccessEnded(): void {
		$copy = new Secret();
		$copy->setId('copy');
		$copy->setName('Payroll portal');
		$copy->setOwnerId('carla');
		$copy->setUseOnly(false);
		$copy->setAccessExpiresAt(new DateTime('2026-10-02T11:00:00Z'));
		$this->secrets->method('findAccessEndingBetween')->willReturn([$copy]);
		$this->targets->method('findByRecipientSecret')->willReturn($this->directShare());

		$remover = $this->createMock(ExpiredGrantRemover::class);
		$remover->expects($this->once())->method('removeExpired');
		$notifications = $this->createMock(NotificationService::class);
		$sent = [];
		$notifications->method('notify')->willReturnCallback(
			function (string $subject, string $recipientId, array $params = []) use (&$sent): bool {
				$sent[] = [$subject, $recipientId, $params];
				return true;
			}
		);

		$service = new ShareExpiryService($this->secrets, $this->targets, $this->resolver, $remover, $notifications);
		$this->assertSame(1, $service->expire($this->now));

		$this->assertSame('share_access_ended', $sent[0][0]);
		$this->assertSame('carla', $sent[0][1]);
		$this->assertSame('share_access_ended_owner', $sent[1][0]);
		$this->assertSame('alice', $sent[1][1]);
		$this->assertSame('carla', $sent[1][2]['recipient']);
		$this->assertFalse($sent[1][2]['use_only']);
	}

	/**
	 * Holders whose access ends within the window are warned once each.
	 *
	 * @return void
	 */
	public function testHoldersAreWarnedADayAhead(): void {
		$copy = new Secret();
		$copy->setId('copy');
		$copy->setName('Payroll portal');
		$copy->setOwnerId('carla');
		$copy->setAccessExpiresAt(new DateTime('2026-10-03T11:00:00Z'));
		$from = new DateTime('2026-10-02T12:00:00Z');
		$to   = new DateTime('2026-10-03T12:00:00Z');
		$this->secrets->expects($this->once())->method('findAccessEndingBetween')->with($from, $to)->willReturn([$copy]);

		$notifications = $this->createMock(NotificationService::class);
		$notifications->expects($this->once())->method('notify')
			->with('share_access_ending', 'carla', $this->callback(static fn (array $p): bool => $p['secret_id'] === 'copy'));

		$service = new ShareExpiryService(
			$this->secrets,
			$this->targets,
			$this->resolver,
			$this->createMock(ExpiredGrantRemover::class),
			$notifications
		);
		$this->assertSame(1, $service->warnEnding($from, $to));
	}
}
