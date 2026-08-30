<?php

/**
 * Keepiq Share Audit Trail
 *
 * The one place that knows which audit event each user-to-user share
 * transition emits and what identifiers its metadata carries. Extracted
 * from ShareService so the lifecycle, bulk-registration, revocation and
 * sync services no longer each carry the audit vocabulary (AuditEvent,
 * AuditEventFactory, AuditEventTypes and the dispatcher).
 *
 * Metadata is identifiers only — never key material (ADR-003).
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Records the audit trail of the user-to-user share lifecycle.
 */
class ShareAuditTrail {
	/**
	 * Constructor for ShareAuditTrail.
	 *
	 * @param IEventDispatcher|null $eventDispatcher The audit dispatcher
	 * @param AuditEventFactory $auditEvents The audit-event factory
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; the recorded transitions carry the spec anchors.
	 */
	public function __construct(
		private ?IEventDispatcher $eventDispatcher = null,
		private AuditEventFactory $auditEvents = new AuditEventFactory(),
	) {
	}//end __construct()

	/**
	 * Record a share granted through the bulk direct-share path, where the
	 * audited object is the SOURCE SECRET (bulk-actions §6.1).
	 *
	 * @param string $userId The sharing owner
	 * @param string $sourceSecretId The owner's source secret
	 * @param string $secretName The source secret's name
	 * @param string $recipientId The recipient Nextcloud user ID
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bulk-actions/spec.md#requirement-the-four-bulk-operations
	 */
	public function recordBulkShareGranted(
		string $userId,
		string $sourceSecretId,
		string $secretName,
		string $recipientId,
	): void {
		$this->dispatch(
			event: $this->auditEvents->forUser(
				actorId: $userId,
				eventType: AuditEventTypes::SHARE_GRANTED,
				objectType: 'secret',
				objectId: $sourceSecretId,
				objectName: $secretName,
				metadata: [
					'recipientType' => 'user',
					'recipientId' => $recipientId,
				],
			)
		);
	}//end recordBulkShareGranted()

	/**
	 * Record a single share grant, where the audited object is the
	 * SHARE TARGET row.
	 *
	 * @param string $userId The sharing owner or delegate
	 * @param string $shareId The persisted share-target row ID
	 * @param string $secretName The source secret's name
	 * @param string $recipientId The recipient Nextcloud user ID
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-share-a-secret
	 */
	public function recordShareGranted(
		string $userId,
		string $shareId,
		string $secretName,
		string $recipientId,
	): void {
		$this->dispatch(
			event: $this->auditEvents->forUser(
				actorId: $userId,
				eventType: AuditEventTypes::SHARE_GRANTED,
				objectType: 'share',
				objectId: $shareId,
				objectName: $secretName,
				metadata: [
					'recipientType' => 'user',
					'recipientId' => $recipientId,
				],
			)
		);
	}//end recordShareGranted()

	/**
	 * Record a share revocation.
	 *
	 * @param string $userId The revoking owner or delegate
	 * @param string $shareId The share-target row ID
	 * @param string $secretName The source secret's name
	 * @param string $recipientId The recipient Nextcloud user ID
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-revoke-share
	 */
	public function recordShareRevoked(
		string $userId,
		string $shareId,
		string $secretName,
		string $recipientId,
	): void {
		$this->dispatch(
			event: $this->auditEvents->forUser(
				actorId: $userId,
				eventType: AuditEventTypes::SHARE_REVOKED,
				objectType: 'share',
				objectId: $shareId,
				objectName: $secretName,
				metadata: [
					'recipientType' => 'user',
					'recipientId' => $recipientId,
				],
			)
		);
	}//end recordShareRevoked()

	/**
	 * Record a non-owner (team write-grade) sync of a secret, attributed
	 * to the writer (folder-permission-grades §3.3) — identifiers only,
	 * never key material.
	 *
	 * @param string $userId The writing team member
	 * @param string $secretId The source secret ID
	 * @param string $secretName The source secret's name
	 *
	 * @return void
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-grade-changes-and-non-owner-writes-are-audited
	 */
	public function recordTeamWriteSync(string $userId, string $secretId, string $secretName): void {
		$this->dispatch(
			event: $this->auditEvents->forUser(
				actorId: $userId,
				eventType: AuditEventTypes::SECRET_UPDATED,
				objectType: 'secret',
				objectId: $secretId,
				objectName: $secretName,
				metadata: ['changedFields' => 'team-write sync'],
			)
		);
	}//end recordTeamWriteSync()

	/**
	 * Dispatch a typed audit event, fail-soft.
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
}//end class
