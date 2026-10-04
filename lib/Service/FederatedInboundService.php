<?php

/**
 * Keepiq Federated Inbound Service
 *
 * The recipient's answers to federated shares (sharing-federated-recipients
 * D4): list what partners shared, accept one (this server pulls the
 * ciphertext and stores a read-only copy) or decline it. Shares arrive
 * through FederatedShareReceiver.
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

use DateTime;
use OCA\Keepiq\Db\FederatedInbound;
use OCA\Keepiq\Db\FederatedInboundMapper;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * The recipient's inbound federated shares.
 *
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-bob-accepts-a-shared-login
 */
class FederatedInboundService {
	/**
	 * Constructor for FederatedInboundService.
	 *
	 * @param FederatedInboundMapper $inboundMapper Inbound share rows
	 * @param FederatedCopyService $copies Pulls and stores the read-only copy
	 * @param FederatedShareAuditTrail $audit Identifier-only audit
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private FederatedInboundMapper $inboundMapper,
		private FederatedCopyService $copies,
		private FederatedShareAuditTrail $audit,
	) {
	}//end __construct()

	/**
	 * The user's inbound shares, newest first.
	 *
	 * @param string $userId The recipient
	 *
	 * @return FederatedInbound[]
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-bob-accepts-a-shared-login
	 */
	public function listFor(string $userId): array {
		return $this->inboundMapper->findByRecipient(recipientUid: $userId);
	}//end listFor()

	/**
	 * Accept a pending share: pull the ciphertext and store the read-only copy.
	 *
	 * @param string $id The inbound share
	 * @param string $userId The recipient
	 *
	 * @return FederatedInbound
	 *
	 * @throws NotFoundException When it is not the user's pending share
	 * @throws \RuntimeException `pull_failed` or `no_suite`
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-bob-accepts-a-shared-login
	 */
	public function accept(string $id, string $userId): FederatedInbound {
		$row = $this->pendingOf(id: $id, userId: $userId);
		$copy = $this->copies->pullNew(row: $row);

		$row->setSecretId($copy->getId());
		$row->setStatus(FederatedInbound::STATUS_ACCEPTED);
		$row->setUpdatedAt(new DateTime());
		$row = $this->inboundMapper->update(entity: $row);
		$this->audit->recordInbound(eventType: AuditEventTypes::FEDERATED_SHARE_ACCEPTED, row: $row, actorId: $userId);

		return $row;
	}//end accept()

	/**
	 * Decline a pending share. Nothing is pulled.
	 *
	 * @param string $id The inbound share
	 * @param string $userId The recipient
	 *
	 * @return FederatedInbound
	 *
	 * @throws NotFoundException When it is not the user's pending share
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-bob-accepts-a-shared-login
	 */
	public function decline(string $id, string $userId): FederatedInbound {
		$row = $this->pendingOf(id: $id, userId: $userId);
		$row->setStatus(FederatedInbound::STATUS_DECLINED);
		$row->setUpdatedAt(new DateTime());
		$row = $this->inboundMapper->update(entity: $row);
		$this->audit->recordInbound(eventType: AuditEventTypes::FEDERATED_SHARE_DECLINED, row: $row, actorId: $userId);

		return $row;
	}//end decline()

	/**
	 * The user's own pending share, or not found.
	 *
	 * @param string $id The inbound share
	 * @param string $userId The recipient
	 *
	 * @return FederatedInbound
	 *
	 * @throws NotFoundException
	 */
	private function pendingOf(string $id, string $userId): FederatedInbound {
		try {
			$row = $this->inboundMapper->findById(id: $id);
		} catch (DoesNotExistException) {
			throw new NotFoundException(message: 'Share not found');
		}

		if ($row->getRecipientUid() !== $userId || $row->getStatus() !== FederatedInbound::STATUS_PENDING) {
			throw new NotFoundException(message: 'Share not found');
		}

		return $row;
	}//end pendingOf()
}//end class
