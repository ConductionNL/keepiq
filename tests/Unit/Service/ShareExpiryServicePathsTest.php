<?php

/**
 * Unit tests for the warning, ending and notification paths of ShareExpiryService.
 *
 * @category Test
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
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Service\ExpiredGrantRemover;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\ShareExpiryService;
use OCA\Keepiq\Service\ShareRestriction;
use OCA\Keepiq\Service\ShareRestrictionResolver;
use OCA\Keepiq\Service\ShareRowJudge;
use OCA\Keepiq\Service\ShareRowVerdict;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Only a copy whose access really ended is reported, to its holder and to the source's owner.
 */
#[CoversClass(ShareExpiryService::class)]
#[UsesClass(Secret::class)]
#[UsesClass(ShareTarget::class)]
#[UsesClass(ShareRestriction::class)]
#[UsesClass(ShareRowVerdict::class)]
class ShareExpiryServicePathsTest extends TestCase {

	/** @var SecretMapper&MockObject */
	private SecretMapper $secrets;

	/** @var ShareTargetMapper&MockObject */
	private ShareTargetMapper $targets;

	/** @var ShareRowJudge&MockObject */
	private ShareRowJudge $judge;

	/** @var ShareRestrictionResolver&MockObject */
	private ShareRestrictionResolver $restrictions;

	/** @var ExpiredGrantRemover&MockObject */
	private ExpiredGrantRemover $remover;

	/** @var NotificationService&MockObject */
	private NotificationService $notifications;

	/** @var array<string,Secret> Secrets that still exist, by id */
	private array $existing = [];

	/** @var array<string,ShareTarget> Share rows that still exist, by id */
	private array $rows = [];

	/** @var array<int,array<string,mixed>> */
	private array $sent = [];

	private DateTime $now;

	/**
	 * Builds the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->now           = new DateTime('2026-11-14T12:00:00+00:00');
		$this->secrets       = $this->createMock(SecretMapper::class);
		$this->targets       = $this->createMock(ShareTargetMapper::class);
		$this->judge         = $this->createMock(ShareRowJudge::class);
		$this->restrictions  = $this->createMock(ShareRestrictionResolver::class);
		$this->remover       = $this->createMock(ExpiredGrantRemover::class);
		$this->notifications = $this->createMock(NotificationService::class);

		$this->secrets->method('findById')->willReturnCallback(
			fn (string $id): Secret => $this->existing[$id] ?? throw new DoesNotExistException('gone')
		);
		$this->targets->method('findById')->willReturnCallback(
			fn (string $id): ShareTarget => $this->rows[$id] ?? throw new DoesNotExistException('gone')
		);
		$this->notifications->method('notify')->willReturnCallback(
			function (string $subject, string $recipientId, array $params = [], ?string $objectType = null, ?string $objectId = null): bool {
				$sent = ['subject' => $subject, 'recipientId' => $recipientId, 'params' => $params];
				if ($objectType !== null) {
					$sent['objectType'] = $objectType;
					$sent['objectId']   = $objectId;
				}

				$this->sent[] = $sent;
				return true;
			}
		);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return ShareExpiryService
	 */
	private function service(): ShareExpiryService {
		return new ShareExpiryService(
			secretMapper: $this->secrets,
			targetMapper: $this->targets,
			rowJudge: $this->judge,
			restrictions: $this->restrictions,
			remover: $this->remover,
			notifications: $this->notifications,
		);
	}//end service()

	/**
	 * A secret.
	 *
	 * @param string $id    The id
	 * @param string $owner The owner
	 * @param string $name  The name
	 *
	 * @return Secret
	 */
	private function secret(string $id, string $owner, string $name): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setOwnerType('user');
		$secret->setOwnerId($owner);
		$secret->setName($name);
		$secret->setUseOnly(true);
		return $secret;
	}//end secret()

	/**
	 * One ending copy held by bob, with a trusted share row when $row is true.
	 *
	 * @param bool $row Whether the copy has a trusted share row
	 *
	 * @return array{0: Secret, 1: ShareTarget}
	 */
	private function endingCopy(bool $row): array {
		$copy   = $this->secret('copy', 'bob', 'Mail');
		$target = new ShareTarget();
		$target->setId('row-1');
		$target->setSourceSecretId('src');
		$target->setSecretId('copy');
		$target->setTargetUserId('bob');
		$this->secrets->method('findAccessEndingBetween')->with(null, $this->now)->willReturn([$copy]);
		$verdict = ($row === true)
			? new ShareRowVerdict(state: ShareRowVerdict::TRUSTED, row: $target)
			: new ShareRowVerdict(state: ShareRowVerdict::NONE);
		$this->judge->method('forCopy')->with(copy: $copy)->willReturn($verdict);
		$this->remover->expects($this->once())->method('removeExpired')->with(now: $this->now);

		return [$copy, $target];
	}//end endingCopy()

	/**
	 * Every copy ending inside the window gets one warning to its holder.
	 *
	 * @return void
	 */
	public function testWarnEndingNotifiesEveryHolder(): void {
		$from = new DateTime('2026-11-14T00:00:00+00:00');
		$to   = new DateTime('2026-11-15T00:00:00+00:00');
		$copy = $this->secret('c1', 'bob', 'Mail');
		$copy->setAccessExpiresAt(new DateTime('2026-11-14T18:00:00+00:00'));
		$other = $this->secret('c2', 'carol', 'Bank');
		$this->secrets->method('findAccessEndingBetween')->with($from, $to)->willReturn([$copy, $other]);

		$this->assertSame(2, $this->service()->warnEnding(from: $from, to: $to));
		$this->assertSame(
			[
				'subject' => 'share_access_ending',
				'recipientId' => 'bob',
				'params' => ['secret_id' => 'c1', 'secret_name' => 'Mail', 'ends_at' => '2026-11-14T18:00:00+00:00'],
				'objectType' => 'secret',
				'objectId' => 'c1',
			],
			$this->sent[0]
		);
		$this->assertSame('carol', $this->sent[1]['recipientId']);
		$this->assertNull($this->sent[1]['params']['ends_at']);
	}//end testWarnEndingNotifiesEveryHolder()

	/**
	 * A copy that still exists without a trusted row is not reported.
	 *
	 * @return void
	 */
	public function testASurvivingCopyWithoutATrustedRowIsNotReported(): void {
		[$copy] = $this->endingCopy(row: false);
		$this->existing['copy'] = $copy;

		$this->assertSame(0, $this->service()->expire(now: $this->now));
		$this->assertSame([], $this->sent);
	}//end testASurvivingCopyWithoutATrustedRowIsNotReported()

	/**
	 * A removed copy without a trusted row is reported to its holder only.
	 *
	 * @return void
	 */
	public function testARemovedCopyWithoutARowTellsOnlyTheHolder(): void {
		$this->endingCopy(row: false);

		$this->assertSame(1, $this->service()->expire(now: $this->now));
		$this->assertSame(
			[['subject' => 'share_access_ended', 'recipientId' => 'bob', 'params' => ['secret_name' => 'Mail']]],
			$this->sent
		);
	}//end testARemovedCopyWithoutARowTellsOnlyTheHolder()

	/**
	 * A surviving copy whose row disappeared is reported to the holder and the source's owner.
	 *
	 * @return void
	 */
	public function testAVanishedRowTellsTheHolderAndTheOwner(): void {
		[$copy] = $this->endingCopy(row: true);
		$this->existing['copy'] = $copy;
		$this->existing['src']  = $this->secret('src', 'alice', 'Mail (source)');

		$this->assertSame(1, $this->service()->expire(now: $this->now));
		$this->assertCount(2, $this->sent);
		$this->assertSame(
			[
				'subject' => 'share_access_ended_owner',
				'recipientId' => 'alice',
				'params' => ['secret_id' => 'src', 'secret_name' => 'Mail (source)', 'recipient' => 'bob', 'use_only' => true],
				'objectType' => 'secret',
				'objectId' => 'src',
			],
			$this->sent[1]
		);
	}//end testAVanishedRowTellsTheHolderAndTheOwner()

	/**
	 * When the source is gone too, only the holder is told.
	 *
	 * @return void
	 */
	public function testAGoneSourceTellsOnlyTheHolder(): void {
		[$copy] = $this->endingCopy(row: true);
		$this->existing['copy'] = $copy;

		$this->assertSame(1, $this->service()->expire(now: $this->now));
		$this->assertCount(1, $this->sent);
		$this->assertSame('share_access_ended', $this->sent[0]['subject']);
	}//end testAGoneSourceTellsOnlyTheHolder()

	/**
	 * A row whose re-resolved access still runs, or runs forever, is left alone.
	 *
	 * @return void
	 */
	public function testARowWhoseAccessStillRunsIsLeftAlone(): void {
		[$copy, $target] = $this->endingCopy(row: true);
		$this->existing['copy'] = $copy;
		$this->rows['row-1']    = $target;
		$this->restrictions->expects($this->once())->method('resolveTarget')->with(target: $target);
		$this->restrictions->method('effectiveFor')->willReturn(new ShareRestriction(expiresAt: new DateTime('2026-12-01T00:00:00+00:00')));
		$this->remover->expects($this->never())->method('revokeTarget');

		$this->assertSame(0, $this->service()->expire(now: $this->now));
		$this->assertSame([], $this->sent);
	}//end testARowWhoseAccessStillRunsIsLeftAlone()

	/**
	 * A row with no end at all is left alone too.
	 *
	 * @return void
	 */
	public function testARowWithoutAnEndIsLeftAlone(): void {
		[$copy, $target] = $this->endingCopy(row: true);
		$this->existing['copy'] = $copy;
		$this->rows['row-1']    = $target;
		$this->restrictions->method('effectiveFor')->willReturn(new ShareRestriction());
		$this->remover->expects($this->never())->method('revokeTarget');

		$this->assertSame(0, $this->service()->expire(now: $this->now));
	}//end testARowWithoutAnEndIsLeftAlone()

	/**
	 * A row whose access ended is revoked and reported when the revoke succeeds.
	 *
	 * @return void
	 */
	public function testAnEndedRowIsRevokedAndReported(): void {
		[$copy, $target] = $this->endingCopy(row: true);
		$this->existing['copy'] = $copy;
		$this->existing['src']  = $this->secret('src', 'alice', 'Mail');
		$this->rows['row-1']    = $target;
		$this->restrictions->method('effectiveFor')->willReturn(new ShareRestriction(expiresAt: new DateTime('2026-11-14T12:00:00+00:00')));
		$this->remover->expects($this->once())->method('revokeTarget')->with(target: $target)->willReturn(true);

		$this->assertSame(1, $this->service()->expire(now: $this->now));
		$this->assertCount(2, $this->sent);
	}//end testAnEndedRowIsRevokedAndReported()

	/**
	 * A revoke that fails or throws is not reported.
	 *
	 * @return void
	 */
	public function testAFailedRevokeIsNotReported(): void {
		[$copy, $target] = $this->endingCopy(row: true);
		$this->existing['copy'] = $copy;
		$this->rows['row-1']    = $target;
		$this->restrictions->method('effectiveFor')->willReturn(new ShareRestriction(expiresAt: new DateTime('2026-11-01T00:00:00+00:00')));
		$this->remover->method('revokeTarget')->willThrowException(new RuntimeException('database down'));

		$this->assertSame(0, $this->service()->expire(now: $this->now));
		$this->assertSame([], $this->sent);
	}//end testAFailedRevokeIsNotReported()
}//end class
