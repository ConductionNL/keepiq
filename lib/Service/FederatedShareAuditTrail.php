<?php

/**
 * Keepiq Federated Share Audit Trail
 *
 * Audit entries for federated sharing on both sides
 * (sharing-federated-recipients task 4.3). Every entry names the share by
 * id and the other side by cloud id and partner id. Ciphertext, the shared
 * secret and its hash never reach an entry: the methods take only those
 * identifiers.
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

use OCA\Keepiq\Db\FederatedInbound;
use OCA\Keepiq\Db\FederatedShare;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Identifier-only audit of federated shares.
 *
 * @spec openspec/changes/sharing-federated-recipients/tasks.md#task-4.3
 */
class FederatedShareAuditTrail {
	/**
	 * Constructor for FederatedShareAuditTrail.
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
	 * Record an event of the sending side, on the owner's secret.
	 *
	 * @param string $eventType One of the FEDERATED_SHARE_ types
	 * @param FederatedShare $row The outbound share
	 * @param string|null $actorId The owner, or null for the system (retries, partner removal)
	 * @param array<string,string> $extra `reason` or `notification`
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sharing-federated-recipients/tasks.md#task-4.3
	 */
	public function recordOutbound(string $eventType, FederatedShare $row, ?string $actorId, array $extra = []): void {
		$metadata = array_merge(
			[
				'federatedShareId' => $row->getId(),
				'recipientCloudId' => $row->getRecipientCloudId(),
				'partnerId' => $row->getPartnerId(),
			],
			array_intersect_key($extra, array_flip(['reason', 'notification']))
		);

		$this->dispatch(
			actorId: $actorId,
			eventType: $eventType,
			objectId: $row->getSourceSecretId(),
			metadata: $metadata,
		);
	}//end recordOutbound()

	/**
	 * Record an event of the receiving side, on the recipient's copy when
	 * there is one.
	 *
	 * @param string $eventType One of the FEDERATED_ types
	 * @param FederatedInbound $row The inbound share
	 * @param string|null $actorId The recipient, or null when the sender caused it
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sharing-federated-recipients/tasks.md#task-4.3
	 */
	public function recordInbound(string $eventType, FederatedInbound $row, ?string $actorId): void {
		$metadata = [
			'inboundShareId' => $row->getId(),
			'senderCloudId' => $row->getSenderCloudId(),
			'partnerId' => $row->getPartnerId(),
		];
		if ($row->getSecretId() !== null) {
			$metadata['copyId'] = $row->getSecretId();
		}

		$this->dispatch(
			actorId: $actorId,
			eventType: $eventType,
			objectId: $row->getSecretId() ?? $row->getId(),
			metadata: $metadata,
		);
	}//end recordInbound()

	/**
	 * Dispatch one entry as the user, or as the system.
	 *
	 * @param string|null $actorId The user, or null
	 * @param string $eventType The event type
	 * @param string $objectId The secret or share
	 * @param array<string,string> $metadata Identifiers only
	 *
	 * @return void
	 */
	private function dispatch(?string $actorId, string $eventType, string $objectId, array $metadata): void {
		if ($this->eventDispatcher === null) {
			return;
		}

		$event = $this->auditEvents->forSystem(
			eventType: $eventType,
			objectType: 'secret',
			objectId: $objectId,
			metadata: $metadata,
		);
		if ($actorId !== null) {
			$event = $this->auditEvents->forUser(
				actorId: $actorId,
				eventType: $eventType,
				objectType: 'secret',
				objectId: $objectId,
				metadata: $metadata,
			);
		}

		$this->eventDispatcher->dispatchTyped($event);
	}//end dispatch()
}//end class
