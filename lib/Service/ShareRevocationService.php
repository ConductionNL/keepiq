<?php

/**
 * Keepiq Share Revocation Service
 *
 * Undoing a user-to-user share (implement-user-sharing §3.3): the
 * recipient's encrypted Secret copy, its attachment grants and the
 * share-target row all die together in one transaction, and the
 * cascade used when the source secret itself is deleted.
 *
 * Extracted from ShareService — revocation is the only path that owns a
 * delete cascade across three tables, and it is the only reason the
 * share lifecycle would otherwise need the attachment service.
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

use InvalidArgumentException;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Revokes share targets and cascades their dependent rows.
 */
class ShareRevocationService {

	/**
	 * The share audit trail.
	 *
	 * @var ShareAuditTrail
	 */
	private ShareAuditTrail $auditTrail;

	/**
	 * Constructor for ShareRevocationService.
	 *
	 * @param ShareTargetMapper $mapper The share-target mapper
	 * @param SecretMapper $secretMapper The Secret mapper (recipient copy)
	 * @param IDBConnection $db The DB connection (revoke transaction)
	 * @param LoggerInterface $logger The logger interface
	 * @param ShareAuthorizationService $auth The share authorization service
	 * @param AttachmentService|null $attachmentService The attachment service (revoke cascade)
	 * @param ShareAuditTrail|null $auditTrail The share audit trail
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; the revocation behaviour carries the spec anchors.
	 */
	public function __construct(
		private ShareTargetMapper $mapper,
		private SecretMapper $secretMapper,
		private IDBConnection $db,
		private LoggerInterface $logger,
		private ShareAuthorizationService $auth,
		private ?AttachmentService $attachmentService = null,
		?ShareAuditTrail $auditTrail = null,
	) {
		$this->auditTrail = ($auditTrail ?? new ShareAuditTrail());
	}//end __construct()

	/**
	 * Revoke a single share target — deletes the recipient's encrypted
	 * Secret copy and the share-target row in one transaction.
	 *
	 * @param string $shareId The share-target row ID
	 * @param string $userId The Nextcloud user ID requesting the revoke
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the row does not exist or
	 *                                  the caller is not authorized.
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-revoke-share
	 */
	public function revokeShare(string $shareId, string $userId): void {
		try {
			$entity = $this->mapper->findById($shareId);
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException(message: 'Share not found');
		}

		$source = $this->auth->loadSecret(secretId: $entity->getSourceSecretId());
		$this->auth->assertOwnerOrDelegate(secret: $source, userId: $userId);

		// GHSA-m5p9-6pw7-pf3g: a quarantined row may name a victim's own secret,
		// so a revoke must never reach it. Leave both the secret and the row (the
		// row is the audit record); clearing quarantined_at restores the revoke.
		if ($entity->isQuarantined() === true) {
			$this->logger->warning(
				'Refused to revoke a quarantined share; its secret and row were left intact',
				['app' => 'keepiq', 'shareId' => $shareId, 'secretId' => $entity->getSecretId()]
			);
			return;
		}

		$this->db->beginTransaction();
		try {
			// Delete the recipient's encrypted Secret copy (best-effort —
			// a stale row is harmless because the Secret is already
			// unreachable through the share index, but a clean delete
			// keeps the table small). Defence in depth: a copy that is not
			// genuinely held by this share's target (a poisoned or drifted
			// `secret_id`) is kept, so a revoke can never delete another
			// user's secret; the share row still goes.
			try {
				$recipientCopy = $this->secretMapper->findById($entity->getSecretId());
				if ($entity->isHeldByTarget(copy: $recipientCopy) === true) {
					$this->secretMapper->delete($recipientCopy);
					// Attachment grants of the revoked copy go with it; the
					// owner's blob and grants are untouched (encrypted-attachments
					// §3.3).
					$this->attachmentService?->deleteGrantsForSecretCopy($entity->getSecretId());
				} else {
					$this->logger->warning(
						'Kept a secret not held by the revoked share target',
						['app' => 'keepiq', 'shareId' => $shareId, 'secretId' => $entity->getSecretId()]
					);
				}
			} catch (DoesNotExistException) {
				// Already gone — its leftover grants, if any, go with it.
				$this->attachmentService?->deleteGrantsForSecretCopy($entity->getSecretId());
			}

			$this->mapper->delete($entity);
			$this->db->commit();
		} catch (Throwable $exception) {
			$this->db->rollBack();
			throw $exception;
		}//end try

		$this->logger->info(
			'Revoked share ' . $shareId . ' for source ' . $entity->getSourceSecretId(),
			['app' => 'keepiq']
		);

		$this->auditTrail->recordShareRevoked(
			userId: $userId,
			shareId: $shareId,
			secretName: $source->getName(),
			recipientId: $entity->getTargetUserId(),
		);
	}//end revokeShare()

	/**
	 * End every share of a secret (called when the secret is trashed or
	 * deleted): each recipient's copy goes with its share row, exactly as a
	 * single revokeShare() does (keepiq#83). Deleting only the link rows left
	 * the copies behind as ordinary-looking live secrets nobody tracked.
	 *
	 * @param string $sourceSecretId The source secret ID
	 *
	 * @return void
	 *
	 * @throws Throwable When a step fails; the transaction is rolled back
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-revoke-share
	 */
	public function deleteAllForSecret(string $sourceSecretId): void {
		$this->db->beginTransaction();
		try {
			foreach ($this->mapper->findBySourceSecret($sourceSecretId) as $share) {
				$this->deleteRecipientCopy(share: $share);
			}

			$this->mapper->deleteBySourceSecret($sourceSecretId);
			$this->db->commit();
		} catch (Throwable $exception) {
			$this->db->rollBack();
			throw $exception;
		}
	}//end deleteAllForSecret()

	/**
	 * Delete one recipient copy and the attachment grants it holds. A copy
	 * that is already gone is fine. Defence in depth: a copy not genuinely held
	 * by the share's target (a poisoned or drifted `secret_id`) is kept, so the
	 * cascade can never delete another user's secret.
	 *
	 * @param ShareTarget $share The share row whose copy is being removed
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-revoke-share
	 */
	private function deleteRecipientCopy(ShareTarget $share): void {
		$copyId = $share->getSecretId();
		// GHSA-m5p9-6pw7-pf3g: a quarantined row may name a victim's own secret,
		// so the cascade must never delete its secret.
		if ($share->isQuarantined() === true) {
			$this->logger->warning(
				'Kept a quarantined share target\'s secret during cascade delete',
				['app' => 'keepiq', 'shareId' => $share->getId(), 'secretId' => $copyId]
			);
			return;
		}

		try {
			$copy = $this->secretMapper->findById($copyId);
			if ($share->isHeldByTarget(copy: $copy) === false) {
				$this->logger->warning(
					'Kept a secret not held by the share target during cascade delete',
					['app' => 'keepiq', 'shareId' => $share->getId(), 'secretId' => $copyId]
				);
				return;
			}

			$this->secretMapper->delete($copy);
		} catch (DoesNotExistException) {
			// Already gone; its leftover grants go with it below.
		}

		$this->attachmentService?->deleteGrantsForSecretCopy($copyId);
	}//end deleteRecipientCopy()
}//end class
