<?php

/**
 * Unit tests for when a quarantined share row needs --force to be released, and for dropping one.
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
use InvalidArgumentException;
use OCA\Keepiq\Db\GroupShare;
use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\QuarantinedShareTargetMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Db\TeamFolderMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Service\ShareQuarantineScanner;
use OCA\Keepiq\Service\ShareQuarantineService;
use OCA\Keepiq\Service\TeamFolderMembershipResolver;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * A quarantined row `{S_a -> bob, copy V}` created by alice, outside any team folder.
 *
 * @covers \OCA\Keepiq\Service\ShareQuarantineService
 * @uses \OCA\Keepiq\Db\GroupShare
 * @uses \OCA\Keepiq\Db\Secret
 * @uses \OCA\Keepiq\Db\ShareTarget
 * @uses \OCA\Keepiq\Db\StringPrimaryKey
 * @uses \OCA\Keepiq\Event\Audit\AuditEvent
 * @uses \OCA\Keepiq\Event\Audit\AuditEventFactory
 */
final class ShareQuarantineForceReasonsTest extends TestCase {

	private const NO_REASON_LEFT = 'This row is released only with --force: ';

	/**
	 * The quarantined row.
	 *
	 * @var ShareTarget
	 */
	private ShareTarget $row;

	/**
	 * The copy the row points at, or null when it is gone.
	 *
	 * @var Secret|null
	 */
	private ?Secret $copy = null;

	/**
	 * The live shares whose source is the copy.
	 *
	 * @var list<ShareTarget>
	 */
	private array $sharesOfTheCopy = [];

	/**
	 * The group share the row came from, or null when it is gone.
	 *
	 * @var GroupShare|null
	 */
	private ?GroupShare $groupShare = null;

	/**
	 * What happened, in order: 'update', 'delete', 'secret-update' and the audit events.
	 *
	 * @var list<mixed>
	 */
	private array $trace = [];

	/**
	 * Seed the row and a copy held by its creator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->row = new ShareTarget();
		$this->row->setId('row-V');
		$this->row->setSourceSecretId('S_a');
		$this->row->setTargetUserId('bob');
		$this->row->setSecretId('V');
		$this->row->setCreatedBy('alice');
		$this->row->setQuarantinedAt(new DateTime());
		$this->row->setQuarantineReason('scanner reason');

		$this->copy = self::secret(ownerType: 'user', ownerId: 'alice');
	}//end setUp()

	/**
	 * The copy V with that holder.
	 *
	 * @param string $ownerType The holder type.
	 * @param string $ownerId   The holder.
	 *
	 * @return Secret
	 */
	private static function secret(string $ownerType, string $ownerId): Secret {
		$secret = new Secret();
		$secret->setId('V');
		$secret->setOwnerType($ownerType);
		$secret->setOwnerId($ownerId);

		return $secret;
	}//end secret()

	/**
	 * The service over the seeded row, copy, group share and live shares.
	 *
	 * @return ShareQuarantineService
	 */
	private function service(): ShareQuarantineService {
		$quarantined = $this->createMock(originalClassName: QuarantinedShareTargetMapper::class);
		$quarantined->method('findQuarantinedById')->willReturnCallback(fn (): ShareTarget => $this->row);
		$quarantined->method('findQuarantined')->willReturnCallback(fn (): array => [$this->row]);
		$quarantined->method('update')->willReturnCallback(
			function (ShareTarget $row): ShareTarget {
				$this->trace[] = 'update';
				return $row;
			}
		);
		$quarantined->method('delete')->willReturnCallback(
			function (ShareTarget $row): ShareTarget {
				$this->trace[] = 'delete';
				return $row;
			}
		);

		$live = $this->createMock(originalClassName: ShareTargetMapper::class);
		$live->method('findBySourceSecretAndTargetUser')->willThrowException(new DoesNotExistException('none'));
		$live->method('findBySourceSecret')->willReturnCallback(fn (): array => $this->sharesOfTheCopy);

		$secrets = $this->createMock(originalClassName: SecretMapper::class);
		$secrets->method('findById')->willReturnCallback(
			fn (): Secret => $this->copy ?? throw new DoesNotExistException('gone')
		);
		$secrets->method('update')->willReturnCallback(
			function (Secret $secret): Secret {
				$this->trace[] = 'secret-update';
				return $secret;
			}
		);

		$groupShares = $this->createMock(originalClassName: GroupShareMapper::class);
		$groupShares->method('findById')->willReturnCallback(
			fn (): GroupShare => $this->groupShare ?? throw new DoesNotExistException('gone')
		);

		$events = $this->createMock(originalClassName: IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			function (AuditEvent $event): void {
				$this->trace[] = [$event->getEventType(), $event->getMetadata()];
			}
		);

		return new ShareQuarantineService(
			quarantined: $quarantined,
			mapper: $live,
			secretMapper: $secrets,
			groupShareMapper: $groupShares,
			eventDispatcher: $events,
			teamFolders: $this->createMock(originalClassName: TeamFolderMapper::class),
			memberships: $this->createMock(originalClassName: TeamFolderMembershipResolver::class),
		);
	}//end service()

	/**
	 * The refusal of a release without --force, or null when it was released.
	 *
	 * @return string|null
	 */
	private function releaseWithoutForce(): ?string {
		try {
			$this->service()->release(id: 'row-V', force: false);
			return null;
		} catch (InvalidArgumentException $e) {
			$this->assertNotNull(actual: $this->row->getQuarantinedAt(), message: 'A refused row stays quarantined');
			$this->assertNotContains(needle: 'update', haystack: $this->trace);
			return $e->getMessage();
		}
	}//end releaseWithoutForce()

	/**
	 * A row whose copy its creator holds, without dates or live shares, is released without --force and audited.
	 *
	 * @return void
	 */
	public function testAPlainRowIsReleasedWithoutForce(): void {
		$this->assertNull(actual: $this->releaseWithoutForce());

		$this->assertNull(actual: $this->row->getQuarantinedAt());
		$this->assertSame(expected: 'update', actual: $this->trace[0]);
		$this->assertSame(
			expected: ['sourceSecretId' => 'S_a', 'recipientId' => 'bob', 'copyId' => 'V', 'reason' => 'scanner reason', 'forced' => false],
			actual: $this->trace[1][1]
		);
	}//end testAPlainRowIsReleasedWithoutForce()

	/**
	 * A row whose copy is gone is released without --force too.
	 *
	 * @return void
	 */
	public function testARowWhoseCopyIsGoneIsReleasedWithoutForce(): void {
		$this->copy = null;

		$this->assertNull(actual: $this->releaseWithoutForce());
	}//end testARowWhoseCopyIsGoneIsReleasedWithoutForce()

	/**
	 * A copy shared before its row existed needs --force.
	 *
	 * @return void
	 */
	public function testACopySharedBeforeTheRowNeedsForce(): void {
		$this->row->setQuarantineReason(ShareQuarantineScanner::REASON_COPY_SHARED_BEFORE_ROW);

		$this->assertStringStartsWith(
			prefix: self::NO_REASON_LEFT.'its copy was shared before the row existed, the mark of a planted row. To keep the copy, drop the row instead',
			string: (string) $this->releaseWithoutForce()
		);
	}//end testACopySharedBeforeTheRowNeedsForce()

	/**
	 * A copy held by the recipient needs --force, named as planted when it was created as a secret.
	 *
	 * @return void
	 */
	public function testACopyTheRecipientHoldsNeedsForce(): void {
		$this->copy = self::secret(ownerType: 'user', ownerId: 'bob');
		$this->assertStringContainsString(
			needle: 'its copy belongs to the recipient and may be their own secret, which revoking the released share deletes',
			haystack: (string) $this->releaseWithoutForce()
		);

		$this->row->setQuarantineReason(ShareQuarantineScanner::REASON_COPY_CREATED_AS_SECRET);
		$this->assertStringContainsString(
			needle: 'its copy was created as a secret of its own and belongs to the recipient, the mark of a planted row',
			haystack: (string) $this->releaseWithoutForce()
		);
	}//end testACopyTheRecipientHoldsNeedsForce()

	/**
	 * A copy held by a third party needs --force and names that holder.
	 *
	 * @return void
	 */
	public function testACopyAThirdPartyHoldsNeedsForce(): void {
		$this->copy = self::secret(ownerType: 'team_folder', ownerId: 'tf-9');

		$this->assertStringContainsString(
			needle: 'its copy is held by team_folder tf-9, neither the recipient nor the row\'s creator',
			haystack: (string) $this->releaseWithoutForce()
		);
	}//end testACopyAThirdPartyHoldsNeedsForce()

	/**
	 * A copy that is itself the source of a live share needs --force.
	 *
	 * @return void
	 */
	public function testACopyThatIsASourceNeedsForce(): void {
		$this->sharesOfTheCopy = [new ShareTarget()];

		$this->assertStringContainsString(needle: 'its copy is the source of a live share', haystack: (string) $this->releaseWithoutForce());
	}//end testACopyThatIsASourceNeedsForce()

	/**
	 * The earliest access end of row, copy and group share is named, marked when it has passed.
	 *
	 * @return void
	 */
	public function testTheEarliestAccessEndNeedsForce(): void {
		$future = new DateTime('+30 days');
		$sooner = new DateTime('+3 days');
		$this->row->setExpiresAt($future);
		$this->copy->setAccessExpiresAt(new DateTime('+10 days'));
		$this->row->setGroupShareId('gs-1');
		$this->groupShare = new GroupShare();
		$this->groupShare->setExpiresAt($sooner);

		$message = (string) $this->releaseWithoutForce();
		$this->assertStringContainsString(needle: 'carries an access end date ('.$sooner->format(DATE_ATOM).')', haystack: $message);

		$past = new DateTime('-1 day');
		$this->row->setExpiresAt($past);
		$this->assertStringContainsString(needle: '('.$past->format(DATE_ATOM).', already passed)', haystack: (string) $this->releaseWithoutForce());
	}//end testTheEarliestAccessEndNeedsForce()

	/**
	 * A gone group share's end date does not count.
	 *
	 * @return void
	 */
	public function testAGoneGroupShareAddsNoEndDate(): void {
		$this->row->setGroupShareId('gs-gone');

		$this->assertNull(actual: $this->releaseWithoutForce());
	}//end testAGoneGroupShareAddsNoEndDate()

	/**
	 * Several reasons are all named, joined, and --force releases the row anyway, audited as forced.
	 *
	 * @return void
	 */
	public function testEveryReasonIsNamedAndForceOverridesThem(): void {
		$this->row->setQuarantineReason(ShareQuarantineScanner::REASON_COPY_SHARED_BEFORE_ROW);
		$this->sharesOfTheCopy = [new ShareTarget()];

		$message = (string) $this->releaseWithoutForce();
		$this->assertStringContainsString(needle: 'the mark of a planted row; its copy is the source of a live share', haystack: $message);

		$this->service()->release(id: 'row-V', force: true);
		$this->assertNull(actual: $this->row->getQuarantinedAt());
		$this->assertTrue(condition: end($this->trace)[1]['forced']);
	}//end testEveryReasonIsNamedAndForceOverridesThem()

	/**
	 * Dropping deletes the row and audits it; restrictions are cleared only on a copy the recipient holds.
	 *
	 * @return void
	 */
	public function testDropClearsRestrictionsOnlyOnTheRecipientsCopy(): void {
		$this->copy->setUseOnly(true);
		$this->assertFalse(condition: $this->service()->drop(id: 'row-V', clearRestrictions: true));
		$this->assertSame(expected: 'delete', actual: $this->trace[0]);
		$this->assertSame(expected: false, actual: $this->trace[1][1]['restrictionsCleared']);
		$this->assertTrue(condition: $this->copy->getUseOnly());

		$this->trace = [];
		$this->copy  = self::secret(ownerType: 'user', ownerId: 'bob');
		$this->copy->setUseOnly(true);
		$this->copy->setAccessExpiresAt(new DateTime('+1 day'));
		$this->assertTrue(condition: $this->service()->drop(id: 'row-V', clearRestrictions: true));

		$this->assertSame(expected: ['secret-update', 'delete'], actual: [$this->trace[0], $this->trace[1]]);
		$this->assertFalse(condition: $this->copy->getUseOnly());
		$this->assertNull(actual: $this->copy->getAccessExpiresAt());
		$this->assertTrue(condition: $this->trace[2][1]['restrictionsCleared']);
	}//end testDropClearsRestrictionsOnlyOnTheRecipientsCopy()

	/**
	 * Without the flag a drop leaves the copy alone.
	 *
	 * @return void
	 */
	public function testDropWithoutTheFlagLeavesTheCopy(): void {
		$this->copy = self::secret(ownerType: 'user', ownerId: 'bob');
		$this->copy->setUseOnly(true);

		$this->assertFalse(condition: $this->service()->drop(id: 'row-V'));
		$this->assertSame(expected: ['delete'], actual: [$this->trace[0]]);
		$this->assertTrue(condition: $this->copy->getUseOnly());
	}//end testDropWithoutTheFlagLeavesTheCopy()

	/**
	 * The list pairs each quarantined row with its copy.
	 *
	 * @return void
	 */
	public function testTheListPairsRowsWithTheirCopies(): void {
		$this->assertSame(expected: [['row' => $this->row, 'copy' => $this->copy]], actual: $this->service()->list());
	}//end testTheListPairsRowsWithTheirCopies()
}//end class
