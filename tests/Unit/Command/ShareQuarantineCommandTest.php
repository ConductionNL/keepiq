<?php

/**
 * Database-backed tests for `occ keepiq:share-quarantine`
 * (GHSA-m5p9-6pw7-pf3g): an administrator lists the quarantined rows,
 * releases one the scan caught by mistake, or drops one while its copy is
 * kept, and each release and drop is audited.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Command
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

namespace OCA\Keepiq\Tests\Unit\Command;

use DateTime;
use OCA\Keepiq\Command\ShareQuarantine;
use OCA\Keepiq\Db\GroupShare;
use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\QuarantinedShareTargetMapper;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Service\DirectShareRegistrar;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCA\Keepiq\Service\ShareQuarantineScanner;
use OCA\Keepiq\Service\ShareQuarantineService;
use OCA\Keepiq\Service\ShareRevocationService;
use OCA\Keepiq\Service\ShareService;
use OCA\Keepiq\Service\ShareSyncService;
use OCA\Keepiq\Tests\Support\ShareTargetDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for the ShareQuarantine command and ShareQuarantineService.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
 */
class ShareQuarantineCommandTest extends TestCase {
	use ShareTargetDatabase;

	/**
	 * The audit events dispatched.
	 *
	 * @var list<AuditEvent>
	 */
	private array $events = [];

	/**
	 * The owner of the shared source.
	 *
	 * @var string
	 */
	private string $owner = '';

	/**
	 * The quarantined row.
	 *
	 * @var ShareTarget
	 */
	private ShareTarget $row;

	/**
	 * Open the database and seed a quarantined share of the owner's secret,
	 * whose copy the owner holds, as a legacy two-step client left it.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->openDatabase();

		$this->owner = $this->user('owner');
		$recipient   = $this->user('recipient');
		$this->row   = $this->insertShareTarget(
			sourceSecretId: $this->insertSecret(ownerId: $this->owner, createdAt: new DateTime('-1 day'))->getId(),
			targetUserId: $recipient,
			secretId: $this->insertSecret(ownerId: $this->owner, createdAt: new DateTime('-1 day'))->getId(),
			createdBy: $this->owner,
			createdAt: new DateTime('-1 hour'),
			quarantined: true,
		);
	}//end setUp()

	/**
	 * Roll back.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->closeDatabase();
		parent::tearDown();
	}//end tearDown()

	/**
	 * The command on the real mapper, with a recording audit dispatcher.
	 *
	 * @return CommandTester
	 */
	private function command(): CommandTester {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function ($event): void {
			$this->events[] = $event;
		});

		return new CommandTester(new ShareQuarantine(
			quarantine: new ShareQuarantineService(
				quarantined: new QuarantinedShareTargetMapper(db: $this->db),
				mapper: new ShareTargetMapper(db: $this->db),
				secretMapper: new SecretMapper(db: $this->db),
				groupShareMapper: new GroupShareMapper(db: $this->db),
				eventDispatcher: $dispatcher,
			)
		));
	}//end command()

	/**
	 * Hand the row's copy to another user.
	 *
	 * @param string $ownerId The new holder
	 *
	 * @return void
	 */
	private function holdCopyAs(string $ownerId): void {
		$secrets = new SecretMapper(db: $this->db);
		$copy    = $secrets->findById($this->row->getSecretId());
		$copy->setOwnerId($ownerId);
		$secrets->update($copy);
	}//end holdCopyAs()

	/**
	 * Record a scan reason on the row.
	 *
	 * @param string $reason The quarantine reason
	 *
	 * @return void
	 */
	private function giveReason(string $reason): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('keepiq_share_targets')
			->set('quarantine_reason', $qb->createNamedParameter($reason))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($this->row->getId())));
		$qb->executeStatement();
	}//end giveReason()

	/**
	 * The ids in the owner's recipient listing of the row's source.
	 *
	 * @return list<string>
	 */
	private function ownersListing(): array {
		$delegations = $this->createMock(SecretDelegationMapper::class);
		$delegations->method('findActiveBySecretAndUser')->willThrowException(new DoesNotExistException('none'));
		$mapper  = new ShareTargetMapper(db: $this->db);
		$service = new ShareService(
			mapper: $mapper,
			auth: new ShareAuthorizationService(
				secretMapper: new SecretMapper(db: $this->db),
				delegationMapper: $delegations,
				shareTargetMapper: $mapper,
			),
			directRegistrar: $this->createMock(DirectShareRegistrar::class),
			syncService: $this->createMock(ShareSyncService::class),
			revocationService: $this->createMock(ShareRevocationService::class),
		);

		return array_map(
			static fn (ShareTarget $row): string => $row->getId(),
			$service->listSharesForSecret(sourceSecretId: $this->row->getSourceSecretId(), userId: $this->owner)
		);
	}//end ownersListing()

	/**
	 * `list` shows the quarantined row with its reason and who holds its copy.
	 *
	 * @return void
	 */
	public function testListShowsTheQuarantinedRow(): void {
		$tester = $this->command();
		$this->assertSame(0, $tester->execute(['action' => 'list']));
		$this->assertStringContainsString($this->row->getId(), $tester->getDisplay());
		$this->assertStringContainsString($this->row->getSecretId(), $tester->getDisplay());
		$this->assertStringContainsString('Copy held by', $tester->getDisplay());
		$this->assertStringContainsString('user '.$this->owner, $tester->getDisplay());
		$this->assertDoesNotMatchRegularExpression('/\| recipient\s+\|/', $tester->getDisplay());

		$this->holdCopyAs(ownerId: $this->row->getTargetUserId());
		$this->assertSame(0, $tester->execute(['action' => 'list']));
		$this->assertMatchesRegularExpression('/\| recipient\s+\|/', $tester->getDisplay());
	}//end testListShowsTheQuarantinedRow()

	/**
	 * `release` makes the row a live share again: the owner's listing shows it,
	 * and the release is audited.
	 *
	 * @return void
	 */
	public function testReleaseShowsTheRowInTheOwnersListingAgain(): void {
		$this->assertSame([], $this->ownersListing(), 'a quarantined row is not listed');

		$this->assertSame(0, $this->command()->execute(['action' => 'release', 'id' => $this->row->getId()]));

		$this->assertSame([$this->row->getId()], $this->ownersListing());
		$this->assertCount(1, $this->events);
		$this->assertSame(AuditEventTypes::SHARE_QUARANTINE_RELEASED, $this->events[0]->getEventType());
		$this->assertSame($this->row->getId(), $this->events[0]->getObjectId());
		$this->assertSame('test', $this->events[0]->getMetadata()['reason']);
		$this->assertFalse($this->events[0]->getMetadata()['forced']);
	}//end testReleaseShowsTheRowInTheOwnersListingAgain()

	/**
	 * A row caught because its copy was shared before the row is a plant by
	 * all evidence: `release` refuses it without --force and releases it with
	 * it, recording that it was forced.
	 *
	 * @return void
	 */
	public function testReleasingARowCaughtOnTheStrongSignalNeedsForce(): void {
		$this->giveReason(reason: ShareQuarantineScanner::REASON_COPY_SHARED_BEFORE_ROW);
		$tester = $this->command();

		$this->assertSame(1, $tester->execute(['action' => 'release', 'id' => $this->row->getId()]));
		$this->assertStringContainsString('--force', $tester->getDisplay());
		$this->assertSame([], $this->ownersListing());
		$this->assertSame([], $this->events);

		$this->assertSame(0, $tester->execute(['action' => 'release', 'id' => $this->row->getId(), '--force' => true]));
		$this->assertSame([$this->row->getId()], $this->ownersListing());
		$this->assertCount(1, $this->events);
		$this->assertTrue($this->events[0]->getMetadata()['forced']);
		$this->assertSame(ShareQuarantineScanner::REASON_COPY_SHARED_BEFORE_ROW, $this->events[0]->getMetadata()['reason']);
	}//end testReleasingARowCaughtOnTheStrongSignalNeedsForce()

	/**
	 * A row caught because its copy was created as a secret of its own needs
	 * --force when the recipient owns that copy, as only a plant links a
	 * secret its recipient already owns. A legacy two-step client created its
	 * copy as the sharer: that row is released without --force.
	 *
	 * @return void
	 */
	public function testReleasingARowWhoseCopyWasCreatedAsASecretNeedsForceWhenTheRecipientOwnsIt(): void {
		$this->giveReason(reason: ShareQuarantineScanner::REASON_COPY_CREATED_AS_SECRET);
		$this->holdCopyAs(ownerId: $this->row->getTargetUserId());
		$tester = $this->command();

		$this->assertSame(1, $tester->execute(['action' => 'release', 'id' => $this->row->getId()]));
		$this->assertStringContainsString('created as a secret of its own and belongs to the recipient', $tester->getDisplay());
		$this->assertSame([], $this->events);

		$this->holdCopyAs(ownerId: $this->owner);
		$this->assertSame(0, $tester->execute(['action' => 'release', 'id' => $this->row->getId()]));
		$this->assertFalse($this->events[0]->getMetadata()['forced']);
	}//end testReleasingARowWhoseCopyWasCreatedAsASecretNeedsForceWhenTheRecipientOwnsIt()

	/**
	 * A row caught only because its copy predates it needs --force as well
	 * when the recipient holds that copy: it may be the recipient's own
	 * secret, which revoking the released share would delete.
	 *
	 * @return void
	 */
	public function testReleasingARowWhoseCopyPredatesItNeedsForceWhenTheRecipientHoldsIt(): void {
		$this->giveReason(reason: ShareQuarantineScanner::REASON_COPY_PREDATES_ROW);
		$this->holdCopyAs(ownerId: $this->row->getTargetUserId());
		$tester = $this->command();

		$this->assertSame(1, $tester->execute(['action' => 'release', 'id' => $this->row->getId()]));
		$this->assertStringContainsString('belongs to the recipient and may be their own secret', $tester->getDisplay());
		$this->assertSame([], $this->ownersListing());
		$this->assertSame([], $this->events);

		$this->assertSame(0, $tester->execute(['action' => 'release', 'id' => $this->row->getId(), '--force' => true]));
		$this->assertSame([$this->row->getId()], $this->ownersListing());
		$this->assertTrue($this->events[0]->getMetadata()['forced']);
	}//end testReleasingARowWhoseCopyPredatesItNeedsForceWhenTheRecipientHoldsIt()

	/**
	 * A legacy two-step row whose copy predates it and is held by the sharer
	 * is released without --force.
	 *
	 * @return void
	 */
	public function testReleasingARowWhoseCopyPredatesItAndIsHeldByTheSharerNeedsNoForce(): void {
		$this->giveReason(reason: ShareQuarantineScanner::REASON_COPY_PREDATES_ROW);

		$this->assertSame(0, $this->command()->execute(['action' => 'release', 'id' => $this->row->getId()]));
		$this->assertSame([$this->row->getId()], $this->ownersListing());
		$this->assertFalse($this->events[0]->getMetadata()['forced']);
	}//end testReleasingARowWhoseCopyPredatesItAndIsHeldByTheSharerNeedsNoForce()

	/**
	 * A row whose copy is held by neither the recipient nor the row's creator
	 * needs --force: the copy may be that third party's own secret, which a
	 * live row would pass off as a received copy.
	 *
	 * @return void
	 */
	public function testReleasingARowWhoseCopyAThirdPartyHoldsNeedsForce(): void {
		$this->giveReason(reason: ShareQuarantineScanner::REASON_COPY_CREATED_AS_SECRET);
		$carol = $this->user('carol');
		$this->holdCopyAs(ownerId: $carol);
		$tester = $this->command();

		$this->assertSame(1, $tester->execute(['action' => 'release', 'id' => $this->row->getId()]));
		$this->assertStringContainsString('held by user '.$carol.', neither the recipient nor the row\'s creator', $tester->getDisplay());
		$this->assertSame([], $this->ownersListing());
		$this->assertSame([], $this->events);

		$this->assertSame(0, $tester->execute(['action' => 'release', 'id' => $this->row->getId(), '--force' => true]));
		$this->assertSame([$this->row->getId()], $this->ownersListing());
		$this->assertTrue($this->events[0]->getMetadata()['forced']);
	}//end testReleasingARowWhoseCopyAThirdPartyHoldsNeedsForce()

	/**
	 * A copy owned by an application whose id equals the row's creator is not
	 * that user's copy: the release needs --force.
	 *
	 * @return void
	 */
	public function testReleasingARowWhoseCopyAnApplicationHoldsNeedsForce(): void {
		$secrets = new SecretMapper(db: $this->db);
		$copy    = $secrets->findById($this->row->getSecretId());
		$copy->setOwnerType('application');
		$secrets->update($copy);
		$tester = $this->command();

		$this->assertSame(1, $tester->execute(['action' => 'release', 'id' => $this->row->getId()]));
		$this->assertStringContainsString('held by application '.$this->owner, $tester->getDisplay());
		$this->assertSame([], $this->events);
	}//end testReleasingARowWhoseCopyAnApplicationHoldsNeedsForce()

	/**
	 * `drop --clear-restrictions` on a row whose copy is gone drops the row
	 * and clears nothing.
	 *
	 * @return void
	 */
	public function testDropWithClearRestrictionsOnAGoneCopyClearsNothing(): void {
		$secrets = new SecretMapper(db: $this->db);
		$secrets->delete($secrets->findById($this->row->getSecretId()));
		$tester = $this->command();

		$this->assertSame(0, $tester->execute(['action' => 'drop', 'id' => $this->row->getId(), '--clear-restrictions' => true]));
		$this->assertStringContainsString('left as they are', $tester->getDisplay());
		$this->assertFalse($this->events[0]->getMetadata()['restrictionsCleared']);
	}//end testDropWithClearRestrictionsOnAGoneCopyClearsNothing()

	/**
	 * A row whose copy is gone is released without --force.
	 *
	 * @return void
	 */
	public function testReleasingARowWhoseCopyIsGoneNeedsNoForce(): void {
		$secrets = new SecretMapper(db: $this->db);
		$secrets->delete($secrets->findById($this->row->getSecretId()));

		$this->assertSame(0, $this->command()->execute(['action' => 'release', 'id' => $this->row->getId()]));
		$this->assertSame([$this->row->getId()], $this->ownersListing());
		$this->assertFalse($this->events[0]->getMetadata()['forced']);
	}//end testReleasingARowWhoseCopyIsGoneNeedsNoForce()

	/**
	 * A row whose copy is the source of a live share needs --force: a share
	 * copy may no longer be shared on, so that copy is most likely its
	 * holder's own secret.
	 *
	 * @return void
	 */
	public function testReleasingARowWhoseCopyIsALiveSharesSourceNeedsForce(): void {
		$colleague = $this->user('colleague');
		$this->insertShareTarget(
			sourceSecretId: $this->row->getSecretId(),
			targetUserId: $colleague,
			secretId: $this->insertSecret(ownerId: $colleague, createdAt: new DateTime())->getId(),
			createdBy: $this->owner,
			createdAt: new DateTime(),
		);
		$tester = $this->command();

		$this->assertSame(1, $tester->execute(['action' => 'release', 'id' => $this->row->getId()]));
		$this->assertStringContainsString('source of a live share', $tester->getDisplay());
		$this->assertStringNotContainsString('belongs to the recipient', $tester->getDisplay());
		$this->assertSame([], $this->events);

		$this->assertSame(0, $tester->execute(['action' => 'release', 'id' => $this->row->getId(), '--force' => true]));
		$this->assertTrue($this->events[0]->getMetadata()['forced']);
	}//end testReleasingARowWhoseCopyIsALiveSharesSourceNeedsForce()

	/**
	 * `release` is refused while the owner shares the source with the same
	 * recipient again, as that would give the pair two live rows.
	 *
	 * @return void
	 */
	public function testReleaseIsRefusedWhenALiveShareReplacesTheRow(): void {
		$this->insertShareTarget(
			sourceSecretId: $this->row->getSourceSecretId(),
			targetUserId: $this->row->getTargetUserId(),
			secretId: $this->insertSecret(ownerId: $this->row->getTargetUserId(), createdAt: new DateTime())->getId(),
			createdBy: $this->owner,
			createdAt: new DateTime(),
		);
		$tester = $this->command();

		$this->assertSame(1, $tester->execute(['action' => 'release', 'id' => $this->row->getId()]));
		$this->assertStringContainsString('drop this row instead', $tester->getDisplay());
		$this->assertSame([], $this->events);
	}//end testReleaseIsRefusedWhenALiveShareReplacesTheRow()

	/**
	 * `drop` removes the row and keeps its copy, and is audited.
	 *
	 * @return void
	 */
	public function testDropRemovesTheRowAndKeepsItsCopy(): void {
		$this->assertSame(0, $this->command()->execute(['action' => 'drop', 'id' => $this->row->getId()]));

		$this->assertNotContains(
			$this->row->getId(),
			array_map(
				static fn (ShareTarget $row): string => $row->getId(),
				(new QuarantinedShareTargetMapper(db: $this->db))->findQuarantined()
			)
		);
		$this->assertSame([], $this->ownersListing());
		$copy = (new SecretMapper(db: $this->db))->findById($this->row->getSecretId());
		$this->assertSame($this->owner, $copy->getOwnerId(), 'the copy is kept');
		$this->assertCount(1, $this->events);
		$this->assertSame(AuditEventTypes::SHARE_QUARANTINE_DROPPED, $this->events[0]->getEventType());
		$this->assertSame($this->row->getSecretId(), $this->events[0]->getMetadata()['copyId']);
	}//end testDropRemovesTheRowAndKeepsItsCopy()

	/**
	 * Give the row's copy the restriction a planted row wrote onto it.
	 *
	 * @param DateTime|null $accessEndsAt The copy's access end date
	 *
	 * @return Secret The copy
	 */
	private function restrictCopy(?DateTime $accessEndsAt): Secret {
		$secrets = new SecretMapper(db: $this->db);
		$copy    = $secrets->findById($this->row->getSecretId());
		$copy->setUseOnly(true);
		$copy->setAccessExpiresAt($accessEndsAt);

		return $secrets->update($copy);
	}//end restrictCopy()

	/**
	 * `list` shows the use-only flag and access end date the copy carries, and
	 * the share's own end date.
	 *
	 * @return void
	 */
	public function testListShowsTheRestrictionsTheRowAndCopyCarry(): void {
		$this->restrictCopy(accessEndsAt: new DateTime('2026-12-01 09:00:00'));
		$this->row->setExpiresAt(new DateTime('2026-12-02 09:00:00'));
		(new QuarantinedShareTargetMapper(db: $this->db))->update($this->row);

		$tester = $this->command();
		$this->assertSame(0, $tester->execute(['action' => 'list']));

		$display = $tester->getDisplay();
		$this->assertStringContainsString('copy use-only', $display);
		$this->assertStringContainsString('copy access ends 2026-12-01 09:00:00', $display);
		$this->assertStringContainsString('share ends 2026-12-02 09:00:00', $display);
	}//end testListShowsTheRestrictionsTheRowAndCopyCarry()

	/**
	 * A row whose share, linked group share or copy carries an access end date
	 * is released only with --force: once the date passes, the expiry job
	 * revokes the share and deletes its copy, which may be the recipient's own
	 * secret. A date already passed is called out.
	 *
	 * @return void
	 */
	public function testReleasingARowWithAnAccessEndDateNeedsForce(): void {
		$this->restrictCopy(accessEndsAt: new DateTime('-1 day'));
		$tester = $this->command();

		$this->assertSame(1, $tester->execute(['action' => 'release', 'id' => $this->row->getId()]));
		$this->assertStringContainsString('already passed', $tester->getDisplay());
		$this->assertStringContainsString('deletes its copy', $tester->getDisplay());
		$this->assertSame([], $this->ownersListing());
		$this->assertSame([], $this->events);

		$this->assertSame(0, $tester->execute(['action' => 'release', 'id' => $this->row->getId(), '--force' => true]));
		$this->assertSame([$this->row->getId()], $this->ownersListing());
		$this->assertTrue($this->events[0]->getMetadata()['forced']);
	}//end testReleasingARowWithAnAccessEndDateNeedsForce()

	/**
	 * The share's own end date and that of the group share it is linked to need
	 * --force as well, also when the copy carries none.
	 *
	 * @return void
	 */
	public function testAShareOrGroupShareEndDateNeedsForce(): void {
		$quarantined = new QuarantinedShareTargetMapper(db: $this->db);
		$this->row->setExpiresAt(new DateTime('+1 week'));
		$quarantined->update($this->row);
		$tester = $this->command();
		$this->assertSame(1, $tester->execute(['action' => 'release', 'id' => $this->row->getId()]));
		$this->assertStringContainsString('--force', $tester->getDisplay());
		$this->assertStringNotContainsString('already passed', $tester->getDisplay());

		$groupShare = new GroupShare();
		$groupShare->setId(Uuid::uuid4()->toString());
		$groupShare->setSecretId($this->row->getSourceSecretId());
		$groupShare->setGroupId('qt-group');
		$groupShare->setCreatedBy($this->owner);
		$groupShare->setCreatedAt(new DateTime());
		$groupShare->setExpiresAt(new DateTime('+1 week'));
		(new GroupShareMapper(db: $this->db))->insert($groupShare);
		$this->row->setExpiresAt(null);
		$this->row->setGroupShareId($groupShare->getId());
		$quarantined->update($this->row);
		$this->assertSame(1, $tester->execute(['action' => 'release', 'id' => $this->row->getId()]));
		$this->assertStringContainsString('--force', $tester->getDisplay());
		$this->assertSame([], $this->events);
	}//end testAShareOrGroupShareEndDateNeedsForce()

	/**
	 * A refusal names every reason the row needs --force, and the earliest of
	 * the end dates, as that is when the expiry job acts.
	 *
	 * @return void
	 */
	public function testTheRefusalNamesEveryReasonAndTheEarliestEndDate(): void {
		$this->holdCopyAs(ownerId: $this->row->getTargetUserId());
		$this->restrictCopy(accessEndsAt: new DateTime('2030-01-15 09:00:00'));
		$groupShare = new GroupShare();
		$groupShare->setId(Uuid::uuid4()->toString());
		$groupShare->setSecretId($this->row->getSourceSecretId());
		$groupShare->setGroupId('qt-group');
		$groupShare->setCreatedBy($this->owner);
		$groupShare->setCreatedAt(new DateTime());
		$groupShare->setExpiresAt(new DateTime('2030-01-08 09:00:00'));
		(new GroupShareMapper(db: $this->db))->insert($groupShare);
		$this->row->setGroupShareId($groupShare->getId());
		(new QuarantinedShareTargetMapper(db: $this->db))->update($this->row);
		$this->giveReason(reason: ShareQuarantineScanner::REASON_COPY_SHARED_BEFORE_ROW);
		$tester = $this->command();

		$this->assertSame(1, $tester->execute(['action' => 'release', 'id' => $this->row->getId()]));
		$display = $tester->getDisplay();
		$this->assertStringContainsString('shared before the row existed', $display);
		$this->assertStringContainsString('belongs to the recipient', $display);
		$this->assertStringContainsString('access end date (2030-01-08T09:00:00', $display);
		$this->assertStringNotContainsString('2030-01-15', $display);
	}//end testTheRefusalNamesEveryReasonAndTheEarliestEndDate()

	/**
	 * `drop --clear-restrictions` clears the use-only flag and access end date
	 * a planted row wrote onto the recipient's own secret, and the drop audit
	 * records it.
	 *
	 * @return void
	 */
	public function testDropCanClearTheCopysRestrictions(): void {
		$this->holdCopyAs(ownerId: $this->row->getTargetUserId());
		$this->restrictCopy(accessEndsAt: new DateTime('+1 week'));
		$tester = $this->command();

		$this->assertSame(0, $tester->execute(['action' => 'drop', 'id' => $this->row->getId(), '--clear-restrictions' => true]));

		$copy = (new SecretMapper(db: $this->db))->findById($this->row->getSecretId());
		$this->assertFalse($copy->getUseOnly());
		$this->assertNull($copy->getAccessExpiresAt());
		$this->assertStringContainsString('were cleared', $tester->getDisplay());
		$this->assertTrue($this->events[0]->getMetadata()['restrictionsCleared']);
	}//end testDropCanClearTheCopysRestrictions()

	/**
	 * A copy the row's recipient does not hold keeps its restrictions: the
	 * drop goes ahead, says so, and its audit records nothing was cleared. A
	 * plain drop clears nothing either.
	 *
	 * @return void
	 */
	public function testDropClearsNoRestrictionsOfACopyTheRecipientDoesNotHold(): void {
		$secrets = new SecretMapper(db: $this->db);
		$copy    = $this->restrictCopy(accessEndsAt: new DateTime('+1 week'));
		$copy->setOwnerId($this->user('someone-else'));
		$secrets->update($copy);
		$tester = $this->command();

		$this->assertSame(0, $tester->execute(['action' => 'drop', 'id' => $this->row->getId(), '--clear-restrictions' => true]));

		$this->assertTrue($secrets->findById($this->row->getSecretId())->getUseOnly());
		$this->assertStringContainsString('left as they are', $tester->getDisplay());
		$this->assertFalse($this->events[0]->getMetadata()['restrictionsCleared']);
	}//end testDropClearsNoRestrictionsOfACopyTheRecipientDoesNotHold()

	/**
	 * Without --clear-restrictions the copy's restrictions stay.
	 *
	 * @return void
	 */
	public function testAPlainDropKeepsTheCopysRestrictions(): void {
		$this->restrictCopy(accessEndsAt: null);

		$this->assertSame(0, $this->command()->execute(['action' => 'drop', 'id' => $this->row->getId()]));

		$this->assertTrue((new SecretMapper(db: $this->db))->findById($this->row->getSecretId())->getUseOnly());
		$this->assertFalse($this->events[0]->getMetadata()['restrictionsCleared']);
	}//end testAPlainDropKeepsTheCopysRestrictions()

	/**
	 * An unknown id, or a live row's id, is refused and nothing is audited.
	 *
	 * @return void
	 */
	public function testAnIdThatIsNotQuarantinedIsRefused(): void {
		$colleague = $this->user('colleague');
		$live      = $this->insertShareTarget(
			sourceSecretId: $this->row->getSourceSecretId(),
			targetUserId: $colleague,
			secretId: $this->insertSecret(ownerId: $colleague, createdAt: new DateTime())->getId(),
			createdBy: $this->owner,
			createdAt: new DateTime(),
		);
		$tester = $this->command();
		$this->assertSame(1, $tester->execute(['action' => 'drop', 'id' => 'no-such-row']));
		$this->assertSame(1, $tester->execute(['action' => 'drop', 'id' => $live->getId()]));
		$this->assertSame(1, $tester->execute(['action' => 'release', 'id' => $live->getId(), '--force' => true]));
		$this->assertSame($live->getId(), (new ShareTargetMapper(db: $this->db))->findById($live->getId())->getId(), 'the live row is untouched');
		$this->assertSame(1, $tester->execute(['action' => 'frobnicate', 'id' => $this->row->getId()]));
		$this->assertSame([], $this->events);
	}//end testAnIdThatIsNotQuarantinedIsRefused()

	/**
	 * `release` and `drop` without an id print the usage and change nothing.
	 *
	 * @return void
	 */
	public function testReleaseOrDropWithoutAnIdIsAUsageError(): void {
		$tester = $this->command();

		foreach (['release', 'drop'] as $action) {
			$this->assertSame(1, $tester->execute(['action' => $action]));
			$this->assertStringContainsString('Use: keepiq:share-quarantine list | release <id>', $tester->getDisplay());
		}

		$this->assertSame([], $this->events);
	}//end testReleaseOrDropWithoutAnIdIsAUsageError()

	/**
	 * `release` is refused when the owner shares the source with the same
	 * recipient twice over again, as when once.
	 *
	 * @return void
	 */
	public function testReleaseIsRefusedWhenTwoLiveSharesReplaceTheRow(): void {
		for ($i = 0; $i < 2; $i++) {
			$this->insertShareTarget(
				sourceSecretId: $this->row->getSourceSecretId(),
				targetUserId: $this->row->getTargetUserId(),
				secretId: $this->insertSecret(ownerId: $this->row->getTargetUserId(), createdAt: new DateTime())->getId(),
				createdBy: $this->owner,
				createdAt: new DateTime(),
			);
		}

		$tester = $this->command();

		$this->assertSame(1, $tester->execute(['action' => 'release', 'id' => $this->row->getId()]));
		$this->assertStringContainsString('drop this row instead', $tester->getDisplay());
		$this->assertSame([], $this->events);
	}//end testReleaseIsRefusedWhenTwoLiveSharesReplaceTheRow()

	/**
	 * A row linked to a group share that is gone carries no group end date,
	 * so it is released without --force.
	 *
	 * @return void
	 */
	public function testAGroupShareThatIsGoneSetsNoEndDate(): void {
		$this->row->setGroupShareId(Uuid::uuid4()->toString());
		(new QuarantinedShareTargetMapper(db: $this->db))->update($this->row);

		$this->assertSame(0, $this->command()->execute(['action' => 'release', 'id' => $this->row->getId()]));
		$this->assertSame([$this->row->getId()], $this->ownersListing());
	}//end testAGroupShareThatIsGoneSetsNoEndDate()
}//end class
