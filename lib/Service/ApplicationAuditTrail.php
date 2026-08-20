<?php

/**
 * Doriath Application Audit Trail
 *
 * The one place that knows which audit event each registered-application
 * lifecycle transition emits, and who is recorded as its actor. Extracted
 * from ApplicationService so the lifecycle and read services no longer
 * carry the audit vocabulary (AuditEvent, AuditEventFactory,
 * AuditEventTypes and the dispatcher) alongside their own collaborators.
 *
 * Every dispatch is fail-soft: a missing dispatcher records nothing, as
 * the inline `$this->eventDispatcher?->dispatchTyped()` did before.
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
 * Records the audit trail of the registered-application lifecycle.
 */
class ApplicationAuditTrail {
	/**
	 * Constructor for ApplicationAuditTrail.
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
	 * Record a registration.
	 *
	 * Anonymous (null/blank) registrants have no Nextcloud actor, so the
	 * event is recorded as system-actored and the trail still captures the
	 * row.
	 *
	 * @param string|null $userId The registering user, or null/'' when anonymous
	 * @param string $applicationId The persisted application ID
	 * @param string $applicationName The persisted application name
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3
	 */
	public function recordRegistered(?string $userId, string $applicationId, string $applicationName): void {
		if ($userId !== null && $userId !== '') {
			$this->dispatch(
				event: $this->auditEvents->forUser(
					actorId: $userId,
					eventType: AuditEventTypes::APPLICATION_REGISTERED,
					objectType: 'application',
					objectId: $applicationId,
					objectName: $applicationName,
				)
			);
			return;
		}

		$this->dispatch(
			event: $this->auditEvents->forSystem(
				eventType: AuditEventTypes::APPLICATION_REGISTERED,
				objectType: 'application',
				objectId: $applicationId,
				objectName: $applicationName,
			)
		);
	}//end recordRegistered()

	/**
	 * Record an admin approval.
	 *
	 * @param string $adminUserId The approving admin
	 * @param string $applicationId The application ID
	 * @param string $applicationName The application name
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3
	 */
	public function recordApproved(string $adminUserId, string $applicationId, string $applicationName): void {
		$this->dispatch(
			event: $this->auditEvents->forUser(
				actorId: $adminUserId,
				eventType: AuditEventTypes::APPLICATION_APPROVED,
				objectType: 'application',
				objectId: $applicationId,
				objectName: $applicationName,
			)
		);
	}//end recordApproved()

	/**
	 * Record an admin rejection.
	 *
	 * @param string $adminUserId The rejecting admin
	 * @param string $applicationId The application ID
	 * @param string $applicationName The application name
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.7
	 */
	public function recordRejected(string $adminUserId, string $applicationId, string $applicationName): void {
		$this->dispatch(
			event: $this->auditEvents->forUser(
				actorId: $adminUserId,
				eventType: AuditEventTypes::APPLICATION_REJECTED,
				objectType: 'application',
				objectId: $applicationId,
				objectName: $applicationName,
			)
		);
	}//end recordRejected()

	/**
	 * Record a deletion. Deletion is admin-gated but system-actored, as it
	 * was before the extraction.
	 *
	 * @param string $applicationId The application ID
	 * @param string $applicationName The application name
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.7
	 */
	public function recordDeleted(string $applicationId, string $applicationName): void {
		$this->dispatch(
			event: $this->auditEvents->forSystem(
				eventType: AuditEventTypes::APPLICATION_DELETED,
				objectType: 'application',
				objectId: $applicationId,
				objectName: $applicationName,
			)
		);
	}//end recordDeleted()

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
