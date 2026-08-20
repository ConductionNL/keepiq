<?php

/**
 * Doriath Team Folder Auditor
 *
 * The audit vocabulary of the team-folder lifecycle. Every team-folder event
 * this app records is named by a method here, which knows the event type, the
 * object shape and the exact metadata whitelist that goes with it. Callers
 * state WHAT happened; they never touch the event factory, the event class or
 * the dispatcher, and they cannot invent a new metadata key by accident.
 *
 * Dispatch is fail-soft: an instance without an event dispatcher records
 * nothing rather than breaking the operation being audited.
 *
 * @category Service
 * @package  OCA\Doriath\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Doriath\Service;

use OCA\Doriath\Event\Audit\AuditEvent;
use OCA\Doriath\Event\Audit\AuditEventFactory;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Records the audit events of the team-folder lifecycle.
 */
class TeamFolderAuditor {
	/**
	 * The audited object type of every event recorded here.
	 *
	 * @var string
	 */
	private const OBJECT_TYPE = 'team_folder';

	/**
	 * Constructor for TeamFolderAuditor.
	 *
	 * @param IEventDispatcher|null $eventDispatcher The audit event dispatcher
	 * @param AuditEventFactory $auditEvents The audit-event factory
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private ?IEventDispatcher $eventDispatcher = null,
		private AuditEventFactory $auditEvents = new AuditEventFactory(),
	) {
	}//end __construct()

	/**
	 * Dispatch an audit event when a dispatcher is wired.
	 *
	 * @param AuditEvent $event The audit event
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3
	 */
	private function dispatch(AuditEvent $event): void {
		$this->eventDispatcher?->dispatchTyped($event);
	}//end dispatch()

	/**
	 * Record a shared team folder.
	 *
	 * @param string $actorId The acting user
	 * @param string $teamFolderId The TeamFolder UUID
	 * @param string $folderName The shared folder's display name
	 * @param string $folderId The underlying Folder UUID
	 *
	 * @return void
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.1
	 */
	public function folderShared(string $actorId, string $teamFolderId, string $folderName, string $folderId): void {
		$this->dispatch(
			event: $this->auditEvents->forUser(
				actorId: $actorId,
				eventType: AuditEventTypes::TEAM_FOLDER_SHARED,
				objectType: self::OBJECT_TYPE,
				objectId: $teamFolderId,
				objectName: $folderName,
				metadata: ['folderId' => $folderId],
			)
		);
	}//end folderShared()

	/**
	 * Record an unshared team folder and the size of its cascade.
	 *
	 * @param string $actorId The acting user
	 * @param string $teamFolderId The TeamFolder UUID
	 * @param string $folderId The underlying Folder UUID
	 * @param int $revoked The number of derived shares revoked
	 *
	 * @return void
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.4
	 */
	public function folderUnshared(string $actorId, string $teamFolderId, string $folderId, int $revoked): void {
		$this->dispatch(
			event: $this->auditEvents->forUser(
				actorId: $actorId,
				eventType: AuditEventTypes::TEAM_FOLDER_UNSHARED,
				objectType: self::OBJECT_TYPE,
				objectId: $teamFolderId,
				objectName: '',
				metadata: [
					'folderId' => $folderId,
					'revokedCount' => $revoked,
				],
			)
		);
	}//end folderUnshared()

	/**
	 * Record a membership added to a team folder.
	 *
	 * @param string $actorId The acting user
	 * @param string $teamFolderId The TeamFolder UUID
	 * @param string $memberType The member type (`user`|`group`)
	 * @param string $memberId The Nextcloud user or group ID
	 *
	 * @return void
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.2
	 */
	public function memberAdded(string $actorId, string $teamFolderId, string $memberType, string $memberId): void {
		$this->dispatch(
			event: $this->auditEvents->forUser(
				actorId: $actorId,
				eventType: AuditEventTypes::TEAM_FOLDER_MEMBER_ADDED,
				objectType: self::OBJECT_TYPE,
				objectId: $teamFolderId,
				objectName: '',
				metadata: [
					'memberType' => $memberType,
					'memberId' => $memberId,
				],
			)
		);
	}//end memberAdded()

	/**
	 * Record a membership removed from a team folder and its revocations.
	 *
	 * @param string $actorId The acting user
	 * @param string $teamFolderId The TeamFolder UUID
	 * @param string $memberType The member type (`user`|`group`)
	 * @param string $memberId The Nextcloud user or group ID
	 * @param int $revoked The number of derived shares revoked
	 *
	 * @return void
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.2
	 */
	public function memberRemoved(
		string $actorId,
		string $teamFolderId,
		string $memberType,
		string $memberId,
		int $revoked,
	): void {
		$this->dispatch(
			event: $this->auditEvents->forUser(
				actorId: $actorId,
				eventType: AuditEventTypes::TEAM_FOLDER_MEMBER_REMOVED,
				objectType: self::OBJECT_TYPE,
				objectId: $teamFolderId,
				objectName: '',
				metadata: [
					'memberType' => $memberType,
					'memberId' => $memberId,
					'revokedCount' => $revoked,
				],
			)
		);
	}//end memberRemoved()

	/**
	 * Record a membership permission-grade change.
	 *
	 * @param string $actorId The acting user (the folder owner)
	 * @param string $teamFolderId The TeamFolder UUID
	 * @param string $memberType The member type (`user`|`group`)
	 * @param string $memberId The Nextcloud user or group ID
	 * @param string $grade The new grade (`read`|`write`)
	 *
	 * @return void
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-grade-changes-and-non-owner-writes-are-audited
	 */
	public function gradeChanged(
		string $actorId,
		string $teamFolderId,
		string $memberType,
		string $memberId,
		string $grade,
	): void {
		$this->dispatch(
			event: $this->auditEvents->forUser(
				actorId: $actorId,
				eventType: AuditEventTypes::TEAM_FOLDER_GRADE_CHANGED,
				objectType: self::OBJECT_TYPE,
				objectId: $teamFolderId,
				objectName: '',
				metadata: [
					'memberType' => $memberType,
					'memberId' => $memberId,
					'grade' => $grade,
				],
			)
		);
	}//end gradeChanged()

	/**
	 * Record a completed offboarding run.
	 *
	 * @param string $adminId The admin who ran the offboarding
	 * @param string $leavingUserId The offboarded user
	 * @param string $successorUserId The successor taking ownership
	 * @param int $revoked The number of derived shares revoked
	 * @param int $transferred The number of secrets transferred
	 *
	 * @return void
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.5
	 */
	public function offboarded(
		string $adminId,
		string $leavingUserId,
		string $successorUserId,
		int $revoked,
		int $transferred,
	): void {
		$this->dispatch(
			event: $this->auditEvents->forUser(
				actorId: $adminId,
				eventType: AuditEventTypes::TEAM_FOLDER_OFFBOARDED,
				objectType: self::OBJECT_TYPE,
				objectId: $leavingUserId,
				objectName: '',
				metadata: [
					'leavingUserId' => $leavingUserId,
					'successorUserId' => $successorUserId,
					'revokedCount' => $revoked,
					'transferredCount' => $transferred,
				],
			)
		);
	}//end offboarded()
}//end class
