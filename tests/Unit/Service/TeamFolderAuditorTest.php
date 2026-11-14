<?php

/**
 * Unit tests for TeamFolderAuditor (the audit rows of team-folder changes).
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
use OCA\Keepiq\Db\TeamFolderMember;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Service\TeamFolderAuditor;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Each team-folder change dispatches one user audit event with its metadata.
 */
#[CoversClass(TeamFolderAuditor::class)]
#[UsesClass(AuditEvent::class)]
#[UsesClass(AuditEventFactory::class)]
#[UsesClass(TeamFolderMember::class)]
class TeamFolderAuditorTest extends TestCase {
	/**
	 * The events the auditor dispatched.
	 *
	 * @var AuditEvent[]
	 */
	private array $events = [];

	private TeamFolderAuditor $auditor;

	/**
	 * Capture every dispatched event.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (AuditEvent $event): void {
			$this->events[] = $event;
		});
		$this->auditor = new TeamFolderAuditor(eventDispatcher: $dispatcher);
	}//end setUp()

	/**
	 * The single captured event, checked for the common team-folder shape.
	 *
	 * @param string $eventType The expected event type
	 * @param string $actorId The expected actor
	 * @param string $objectId The expected object id
	 *
	 * @return AuditEvent
	 */
	private function onlyEvent(string $eventType, string $actorId, string $objectId): AuditEvent {
		$this->assertCount(1, $this->events);
		$event = $this->events[0];
		$this->assertSame($eventType, $event->getEventType());
		$this->assertSame(AuditEvent::ACTOR_USER, $event->getActorType());
		$this->assertSame($actorId, $event->getActorId());
		$this->assertSame('team_folder', $event->getObjectType());
		$this->assertSame($objectId, $event->getObjectId());

		return $event;
	}//end onlyEvent()

	/**
	 * Sharing a folder names the folder and links its folder id.
	 *
	 * @return void
	 */
	public function testFolderShared(): void {
		$this->auditor->folderShared(actorId: 'alice', teamFolderId: 'tf-1', folderName: 'Ops', folderId: 'f-1');

		$event = $this->onlyEvent(eventType: AuditEventTypes::TEAM_FOLDER_SHARED, actorId: 'alice', objectId: 'tf-1');
		$this->assertSame('Ops', $event->getObjectName());
		$this->assertSame(['folderId' => 'f-1'], $event->getMetadata());
	}//end testFolderShared()

	/**
	 * Unsharing records how many grants were revoked.
	 *
	 * @return void
	 */
	public function testFolderUnshared(): void {
		$this->auditor->folderUnshared(actorId: 'alice', teamFolderId: 'tf-1', folderId: 'f-1', revoked: 3);

		$event = $this->onlyEvent(eventType: AuditEventTypes::TEAM_FOLDER_UNSHARED, actorId: 'alice', objectId: 'tf-1');
		$this->assertSame(['folderId' => 'f-1', 'revokedCount' => 3], $event->getMetadata());
	}//end testFolderUnshared()

	/**
	 * Adding a member records who was added.
	 *
	 * @return void
	 */
	public function testMemberAdded(): void {
		$this->auditor->memberAdded(actorId: 'alice', teamFolderId: 'tf-1', memberType: 'group', memberId: 'ops');

		$event = $this->onlyEvent(eventType: AuditEventTypes::TEAM_FOLDER_MEMBER_ADDED, actorId: 'alice', objectId: 'tf-1');
		$this->assertSame(['memberType' => 'group', 'memberId' => 'ops'], $event->getMetadata());
	}//end testMemberAdded()

	/**
	 * Removing a member records the revoked grants.
	 *
	 * @return void
	 */
	public function testMemberRemoved(): void {
		$this->auditor->memberRemoved(actorId: 'alice', teamFolderId: 'tf-1', memberType: 'user', memberId: 'bob', revoked: 2);

		$event = $this->onlyEvent(eventType: AuditEventTypes::TEAM_FOLDER_MEMBER_REMOVED, actorId: 'alice', objectId: 'tf-1');
		$this->assertSame(['memberType' => 'user', 'memberId' => 'bob', 'revokedCount' => 2], $event->getMetadata());
	}//end testMemberRemoved()

	/**
	 * A grade change without use-only data carries no use-only keys.
	 *
	 * @return void
	 */
	public function testGradeChangedWithoutUseOnly(): void {
		$this->auditor->gradeChanged(actorId: 'alice', teamFolderId: 'tf-1', memberType: 'user', memberId: 'bob', grade: 'write');

		$event = $this->onlyEvent(eventType: AuditEventTypes::TEAM_FOLDER_GRADE_CHANGED, actorId: 'alice', objectId: 'tf-1');
		$this->assertSame(['memberType' => 'user', 'memberId' => 'bob', 'grade' => 'write'], $event->getMetadata());
	}//end testGradeChangedWithoutUseOnly()

	/**
	 * A membership change reports the effective grade, use-only flag and expiry.
	 *
	 * @return void
	 */
	public function testMembershipChangedReportsTheEffectiveGrade(): void {
		$membership = new TeamFolderMember();
		$membership->setTeamFolderId('tf-1');
		$membership->setMemberType('user');
		$membership->setMemberId('bob');
		$membership->setGrade('nonsense');
		$membership->setUseOnly(true);
		$membership->setExpiresAt(new DateTime('2030-05-06T07:08:09+00:00'));

		$this->auditor->membershipChanged(actorId: 'alice', membership: $membership);

		$event = $this->onlyEvent(eventType: AuditEventTypes::TEAM_FOLDER_GRADE_CHANGED, actorId: 'alice', objectId: 'tf-1');
		$this->assertSame(
			[
				'memberType' => 'user',
				'memberId' => 'bob',
				'grade' => 'read',
				'useOnly' => true,
				'expiresAt' => '2030-05-06T07:08:09+00:00',
			],
			$event->getMetadata()
		);
	}//end testMembershipChangedReportsTheEffectiveGrade()

	/**
	 * Offboarding is recorded against the leaving user with every count.
	 *
	 * @return void
	 */
	public function testOffboarded(): void {
		$this->auditor->offboarded(
			adminId: 'root',
			leavingUserId: 'bob',
			successorUserId: 'carol',
			revoked: 4,
			transferred: 5,
			linksTransferred: 1,
			membershipsRemoved: 2,
			coveringGroupIds: ['ops'],
		);

		$event = $this->onlyEvent(eventType: AuditEventTypes::TEAM_FOLDER_OFFBOARDED, actorId: 'root', objectId: 'bob');
		$this->assertSame(
			[
				'leavingUserId' => 'bob',
				'successorUserId' => 'carol',
				'revokedCount' => 4,
				'transferredCount' => 5,
				'linkSharesTransferredCount' => 1,
				'membershipsRemovedCount' => 2,
				'coveringGroupIds' => ['ops'],
			],
			$event->getMetadata()
		);
	}//end testOffboarded()

	/**
	 * Confirming members records how many of how many.
	 *
	 * @return void
	 */
	public function testMembersConfirmed(): void {
		$this->auditor->membersConfirmed(actorId: 'alice', teamFolderId: 'tf-1', confirmedCount: 2, memberCount: 7);

		$event = $this->onlyEvent(eventType: AuditEventTypes::TEAM_FOLDER_MEMBERS_CONFIRMED, actorId: 'alice', objectId: 'tf-1');
		$this->assertSame(['confirmedCount' => 2, 'memberCount' => 7], $event->getMetadata());
	}//end testMembersConfirmed()

	/**
	 * Without a dispatcher the auditor is a silent no-op.
	 *
	 * @return void
	 */
	public function testWithoutDispatcherNothingHappens(): void {
		$auditor = new TeamFolderAuditor();
		$auditor->folderShared(actorId: 'alice', teamFolderId: 'tf-1', folderName: 'Ops', folderId: 'f-1');

		$this->assertSame([], $this->events);
	}//end testWithoutDispatcherNothingHappens()
}//end class
