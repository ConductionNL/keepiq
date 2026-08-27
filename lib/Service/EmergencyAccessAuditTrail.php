<?php

/**
 * Keepiq Emergency Access Audit Trail
 *
 * The single writer of break-glass audit entries (add-emergency-access,
 * design D8). Every emergency-access transition is recorded through this
 * class with a non-sensitive actor/object reference only — the recovery
 * envelope and any key material NEVER enter an audit entry, and keeping the
 * event shapes in one file is what makes that guarantee checkable.
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

use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Emits the typed audit events of the emergency-access lifecycle.
 */
class EmergencyAccessAuditTrail {
	/**
	 * The object type used in audit entries for the emergency-access relation.
	 *
	 * @var string
	 */
	public const OBJECT_TYPE = 'emergency_access';

	/**
	 * Constructor for EmergencyAccessAuditTrail.
	 *
	 * @param IEventDispatcher $eventDispatcher The typed-event dispatcher (audit)
	 * @param AuditEventFactory $auditEvents The audit-event factory
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only — no domain logic.
	 */
	public function __construct(
		private IEventDispatcher $eventDispatcher,
		private AuditEventFactory $auditEvents = new AuditEventFactory(),
	) {
	}//end __construct()

	/**
	 * Record that a grantor designated (or re-established) a grantee.
	 *
	 * @param string $grantorUserId The grantor Nextcloud user ID
	 * @param string $granteeUserId The grantee Nextcloud user ID
	 * @param string $id The relationship ID
	 * @param string $accessLevel The granted access level
	 * @param int $waitPeriodDays The configured wait period
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-designate-emergency-contact
	 */
	public function recordGranted(
		string $grantorUserId,
		string $granteeUserId,
		string $id,
		string $accessLevel,
		int $waitPeriodDays,
	): void {
		$this->eventDispatcher->dispatchTyped(
			$this->auditEvents->forUser(
				actorId: $grantorUserId,
				eventType: AuditEventTypes::EMERGENCY_ACCESS_GRANTED,
				objectType: self::OBJECT_TYPE,
				objectId: $id,
				objectName: $granteeUserId,
				metadata: [
					'grantorUserId' => $grantorUserId,
					'granteeUserId' => $granteeUserId,
					'accessLevel' => $accessLevel,
					'waitPeriodDays' => $waitPeriodDays,
				],
			)
		);
	}//end recordGranted()

	/**
	 * Record that a grantor revoked an emergency contact.
	 *
	 * @param string $grantorUserId The grantor Nextcloud user ID
	 * @param string $granteeUserId The grantee Nextcloud user ID
	 * @param string $id The relationship ID
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-revoke-emergency-contact
	 */
	public function recordRevoked(string $grantorUserId, string $granteeUserId, string $id): void {
		$this->eventDispatcher->dispatchTyped(
			$this->auditEvents->forUser(
				actorId: $grantorUserId,
				eventType: AuditEventTypes::EMERGENCY_ACCESS_REVOKED,
				objectType: self::OBJECT_TYPE,
				objectId: $id,
				objectName: $granteeUserId,
				metadata: ['grantorUserId' => $grantorUserId, 'granteeUserId' => $granteeUserId],
			)
		);
	}//end recordRevoked()

	/**
	 * Record that a grantee initiated a break-glass request.
	 *
	 * @param string $grantorUserId The grantor Nextcloud user ID
	 * @param string $granteeUserId The grantee Nextcloud user ID
	 * @param string $id The relationship ID
	 * @param int $waitPeriodDays The wait period that now runs
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-break-glass-request-and-wait-timer
	 */
	public function recordRequested(
		string $grantorUserId,
		string $granteeUserId,
		string $id,
		int $waitPeriodDays,
	): void {
		$this->eventDispatcher->dispatchTyped(
			$this->auditEvents->forUser(
				actorId: $granteeUserId,
				eventType: AuditEventTypes::EMERGENCY_ACCESS_REQUESTED,
				objectType: self::OBJECT_TYPE,
				objectId: $id,
				objectName: $granteeUserId,
				metadata: [
					'grantorUserId' => $grantorUserId,
					'granteeUserId' => $granteeUserId,
					'waitPeriodDays' => $waitPeriodDays,
				],
			)
		);
	}//end recordRequested()

	/**
	 * Record that a grantor vetoed a pending break-glass request.
	 *
	 * @param string $grantorUserId The grantor Nextcloud user ID
	 * @param string $granteeUserId The grantee Nextcloud user ID
	 * @param string $id The relationship ID
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-grantor-decline-veto
	 */
	public function recordDeclined(string $grantorUserId, string $granteeUserId, string $id): void {
		$this->eventDispatcher->dispatchTyped(
			$this->auditEvents->forUser(
				actorId: $grantorUserId,
				eventType: AuditEventTypes::EMERGENCY_ACCESS_DECLINED,
				objectType: self::OBJECT_TYPE,
				objectId: $id,
				objectName: $granteeUserId,
				metadata: ['grantorUserId' => $grantorUserId, 'granteeUserId' => $granteeUserId],
			)
		);
	}//end recordDeclined()

	/**
	 * Record that a grantee actually fetched the recovery envelope.
	 *
	 * @param string $grantorUserId The grantor Nextcloud user ID
	 * @param string $granteeUserId The grantee Nextcloud user ID
	 * @param string $id The relationship ID
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-approval-by-timeout-and-grantee-view-access
	 */
	public function recordAccessed(string $grantorUserId, string $granteeUserId, string $id): void {
		$this->eventDispatcher->dispatchTyped(
			$this->auditEvents->forUser(
				actorId: $granteeUserId,
				eventType: AuditEventTypes::EMERGENCY_ACCESS_ACCESSED,
				objectType: self::OBJECT_TYPE,
				objectId: $id,
				objectName: $granteeUserId,
				metadata: ['grantorUserId' => $grantorUserId, 'granteeUserId' => $granteeUserId],
			)
		);
	}//end recordAccessed()

	/**
	 * Record the system-actor approval of a request whose wait period elapsed.
	 *
	 * @param string $grantorUserId The grantor Nextcloud user ID
	 * @param string $granteeUserId The grantee Nextcloud user ID
	 * @param string $id The relationship ID
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-approval-by-timeout-and-grantee-view-access
	 */
	public function recordApproved(string $grantorUserId, string $granteeUserId, string $id): void {
		$this->eventDispatcher->dispatchTyped(
			$this->auditEvents->forSystem(
				eventType: AuditEventTypes::EMERGENCY_ACCESS_APPROVED,
				objectType: self::OBJECT_TYPE,
				objectId: $id,
				objectName: $granteeUserId,
				metadata: [
					'grantorUserId' => $grantorUserId,
					'granteeUserId' => $granteeUserId,
				],
			)
		);
	}//end recordApproved()

	/**
	 * Record that an envelope was invalidated or cleared by a key change.
	 *
	 * @param string $grantorUserId The grantor Nextcloud user ID
	 * @param string $granteeUserId The grantee Nextcloud user ID
	 * @param string $id The relationship ID
	 * @param string $reason The invalidation reason tag
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-envelope-invalidation-on-key-change
	 */
	public function recordInvalidated(
		string $grantorUserId,
		string $granteeUserId,
		string $id,
		string $reason,
	): void {
		$this->eventDispatcher->dispatchTyped(
			$this->auditEvents->forSystem(
				eventType: AuditEventTypes::EMERGENCY_ACCESS_INVALIDATED,
				objectType: self::OBJECT_TYPE,
				objectId: $id,
				objectName: $granteeUserId,
				metadata: [
					'grantorUserId' => $grantorUserId,
					'granteeUserId' => $granteeUserId,
					'reason' => $reason,
				],
			)
		);
	}//end recordInvalidated()
}//end class
