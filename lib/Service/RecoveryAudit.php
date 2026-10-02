<?php

/**
 * Keepiq RecoveryAudit
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * The audit trail of organisation account recovery, identifiers only
 * (crypto-organisation-account-recovery task 5.2). The whitelist in
 * AuditEventTypes drops anything else, and the forbidden keys refuse key
 * material outright.
 */
class RecoveryAudit {

	public const SETTINGS_CHANGED = AuditEventTypes::RECOVERY_SETTINGS_CHANGED;

	public const KEY_CREATED = AuditEventTypes::RECOVERY_KEY_CREATED;

	public const KEY_RETIRED = AuditEventTypes::RECOVERY_KEY_RETIRED;

	public const ENROLLED = AuditEventTypes::RECOVERY_ENROLLED;

	public const WITHDRAWN = AuditEventTypes::RECOVERY_WITHDRAWN;

	public const REQUESTED = AuditEventTypes::RECOVERY_REQUESTED;

	public const APPROVED = AuditEventTypes::RECOVERY_APPROVED;

	public const DECLINED = AuditEventTypes::RECOVERY_DECLINED;

	public const HANDED_OFF = AuditEventTypes::RECOVERY_HANDED_OFF;

	public const COMPLETED = AuditEventTypes::RECOVERY_COMPLETED;

	public const EXPIRED = AuditEventTypes::RECOVERY_EXPIRED;

	/**
	 * Constructor for RecoveryAudit.
	 *
	 * @param IEventDispatcher  $eventDispatcher The audit dispatcher
	 * @param AuditEventFactory $auditEvents     The audit-event factory
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private IEventDispatcher $eventDispatcher,
		private AuditEventFactory $auditEvents = new AuditEventFactory(),
	) {
	}//end __construct()

	/**
	 * Record one recovery step.
	 *
	 * @param string              $actorId   Who acted
	 * @param string              $eventType One of the constants above
	 * @param string              $objectId  The key, enrolment or request id
	 * @param array<string,mixed> $metadata  Whitelisted identifiers
	 *
	 * @return void
	 *
	 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-the-user-is-told-what-happened-and-offered-a-rotation
	 */
	public function record(string $actorId, string $eventType, string $objectId, array $metadata = []): void {
		$this->eventDispatcher->dispatchTyped(
			$this->auditEvents->forUser(
				actorId: $actorId,
				eventType: $eventType,
				objectType: 'account_recovery',
				objectId: $objectId,
				metadata: $metadata,
			)
		);
	}//end record()
}//end class
