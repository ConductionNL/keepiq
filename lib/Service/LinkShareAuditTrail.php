<?php

/**
 * Keepiq Link Share Audit Trail
 *
 * The one place that knows which audit event each link-share lifecycle
 * transition emits, and how its metadata is shaped. Extracted from
 * LinkShareService so the lifecycle service no longer carries the whole
 * audit vocabulary (AuditEvent, AuditEventFactory, AuditEventTypes and
 * the dispatcher) alongside its own persistence collaborators.
 *
 * Every dispatch is fail-soft: a missing dispatcher silently records
 * nothing, exactly as the inline `$this->eventDispatcher?->dispatchTyped()`
 * did before the extraction.
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
 * Records the audit trail of the link-share lifecycle.
 */
class LinkShareAuditTrail {
	/**
	 * Constructor for LinkShareAuditTrail.
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
	 * Record the creation of a link share by its owner.
	 *
	 * @param string $userId The owning Nextcloud user ID
	 * @param string $linkShareId The link-share ID
	 * @param bool $hasPassword Whether the snapshot is password protected
	 * @param string|null $expiresAtIso The ISO-8601 expiry, or null when the link never expires
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.4
	 */
	public function recordCreated(
		string $userId,
		string $linkShareId,
		bool $hasPassword,
		?string $expiresAtIso,
	): void {
		$this->dispatch(
			event: $this->auditEvents->forUser(
				actorId: $userId,
				eventType: AuditEventTypes::LINK_SHARE_CREATED,
				objectType: 'link_share',
				objectId: $linkShareId,
				metadata: [
					'hasPassword' => $hasPassword,
					'expiresAt' => $expiresAtIso,
				],
			)
		);
	}//end recordCreated()

	/**
	 * Record a successful anonymous access of a link share.
	 *
	 * @param string $linkShareId The link-share ID
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.4
	 */
	public function recordAccessed(string $linkShareId): void {
		$this->dispatch(
			event: $this->auditEvents->forLinkVisitor(
				eventType: AuditEventTypes::LINK_SHARE_ACCESSED,
				objectType: 'link_share',
				objectId: $linkShareId,
			)
		);
	}//end recordAccessed()

	/**
	 * Record a failed password attempt against a link share.
	 *
	 * @param string $linkShareId The link-share ID
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.4
	 */
	public function recordAccessFailed(string $linkShareId): void {
		$this->dispatch(
			event: $this->auditEvents->forLinkVisitor(
				eventType: AuditEventTypes::LINK_SHARE_ACCESS_FAILED,
				objectType: 'link_share',
				objectId: $linkShareId,
				metadata: ['reason' => 'invalid_password'],
			)
		);
	}//end recordAccessFailed()

	/**
	 * Record the automatic deletion of a link share that exhausted the
	 * brute-force allowance.
	 *
	 * @param string $linkShareId The link-share ID
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.4
	 */
	public function recordAutoDeleted(string $linkShareId): void {
		$this->dispatch(
			event: $this->auditEvents->forLinkVisitor(
				eventType: AuditEventTypes::LINK_SHARE_AUTO_DELETED,
				objectType: 'link_share',
				objectId: $linkShareId,
				metadata: ['reason' => 'too_many_failed_attempts'],
			)
		);
	}//end recordAutoDeleted()

	/**
	 * Record the manual revocation of a link share by its owner.
	 *
	 * @param string $userId The revoking Nextcloud user ID
	 * @param string $linkShareId The link-share ID
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.4
	 */
	public function recordRevoked(string $userId, string $linkShareId): void {
		$this->dispatch(
			event: $this->auditEvents->forUser(
				actorId: $userId,
				eventType: AuditEventTypes::LINK_SHARE_REVOKED,
				objectType: 'link_share',
				objectId: $linkShareId,
			)
		);
	}//end recordRevoked()

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
