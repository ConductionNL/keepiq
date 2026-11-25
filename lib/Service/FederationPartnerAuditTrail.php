<?php

/**
 * Keepiq Federation Partner Audit Trail
 *
 * Audit entries for changes an administrator makes to a federation partner, and for a change in the value fields a
 * partner advertises.
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

use OCA\Keepiq\Db\FederationPartner;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Audit of federation partner changes.
 *
 * @spec openspec/changes/partner-share-temporary-refusals/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
 */
class FederationPartnerAuditTrail {
	/**
	 * Constructor for FederationPartnerAuditTrail.
	 *
	 * @param IEventDispatcher|null $eventDispatcher The audit event dispatcher
	 * @param AuditEventFactory $auditEvents Builds the events
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private ?IEventDispatcher $eventDispatcher = null,
		private AuditEventFactory $auditEvents = new AuditEventFactory(),
	) {
	}//end __construct()

	/**
	 * Record a stored re-pin of a partner's root, with the administrator as the actor.
	 *
	 * @param FederationPartner $partner The partner, already carrying its new fingerprint
	 * @param string $oldFingerprint The fingerprint pinned before
	 * @param string $adminId The administrator
	 *
	 * @return void
	 *
	 * @spec openspec/changes/partner-share-temporary-refusals/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	public function repinned(FederationPartner $partner, string $oldFingerprint, string $adminId): void {
		$this->eventDispatcher?->dispatchTyped(
			$this->auditEvents->forUser(
				actorId: $adminId,
				eventType: AuditEventTypes::FEDERATION_PARTNER_REPINNED,
				objectType: 'federation_partner',
				objectId: $partner->getId(),
				objectName: $partner->getHost(),
				metadata: [
					'partnerId' => $partner->getId(),
					'oldFingerprint' => $oldFingerprint,
					'newFingerprint' => $partner->getRootFingerprint(),
				],
			)
		);
	}//end repinned()

	/**
	 * Record a change in the value fields a partner advertised in its lookup answer, by field name, with no actor.
	 *
	 * @param FederationPartner $partner The partner
	 * @param list<string> $from The fields it advertised before
	 * @param list<string> $to The fields it advertises now
	 *
	 * @return void
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
	 */
	public function valueFieldsChanged(FederationPartner $partner, array $from, array $to): void {
		$this->eventDispatcher?->dispatchTyped(
			$this->auditEvents->forSystem(
				eventType: AuditEventTypes::FEDERATION_PARTNER_VALUE_FIELDS_CHANGED,
				objectType: 'federation_partner',
				objectId: $partner->getId(),
				metadata: ['partnerId' => $partner->getId(), 'from' => $from, 'to' => $to],
			)
		);
	}//end valueFieldsChanged()
}//end class
