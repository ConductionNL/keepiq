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
use OCA\Keepiq\Db\QuarantinedShareTargetMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Exception\ConflictException;
use OCA\Keepiq\Exception\ShareScanPendingException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Revokes share targets and cascades their dependent rows.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Telling an owner that a
 *   revoke hit a quarantined row, or one the pending scan holds back
 *   (GHSA-m5p9-6pw7-pf3g), takes the quarantine mapper, the scanner and the
 *   conflicts they raise.
 */
class ShareRevocationService {

	/**
	 * The refusal of a revoke that hits a quarantined row.
	 *
	 * @var string
	 */
	private const QUARANTINED_MESSAGE = 'This share is quarantined for an administrator to review and cannot be revoked; '
		.'ask an administrator to release or drop it';

	/**
	 * The refusal of a revoke whose row the pending quarantine scan cannot judge.
	 *
	 * @var string
	 */
	private const REVOKE_REFUSAL = 'This share waits for the share quarantine scan and cannot be revoked yet';

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
	 * @param QuarantinedShareTargetMapper|null $quarantined Tells an owner their revoke hit a quarantined row
	 * @param ShareQuarantineScanner|null $quarantineScan Holds back the rows the pending scan catches or cannot judge
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
		private ?QuarantinedShareTargetMapper $quarantined = null,
		private ?ShareQuarantineScanner $quarantineScan = null,
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
	 * @throws ConflictException When the owner or a delegate revokes a quarantined row, or one the pending scan holds back
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-revoke-share
	 */
	public function revokeShare(string $shareId, string $userId): void {
		try {
			$entity = $this->mapper->findById($shareId);
		} catch (DoesNotExistException) {
			$this->refuseQuarantined(shareId: $shareId, userId: $userId);
			throw new InvalidArgumentException(message: 'Share not found');
		}

		$source = $this->auth->loadSecret(secretId: $entity->getSourceSecretId());
		$this->auth->assertOwnerOrDelegate(secret: $source, userId: $userId);
		// Before the transaction, so a quarantine this makes is not rolled
		// back with the refused revoke.
		$this->quarantineScan?->assertNotHeldBack(row: $entity, verb: 'revoked');

		$this->db->beginTransaction();
		try {
			// The recipient's copy and its attachment grants go with the row,
			// as in the cascade; the owner's blob and grants are untouched
			// (encrypted-attachments §3.3). Judging the row again can fail
			// where it just succeeded, which rolls the revoke back.
			$this->deleteRecipientCopy(share: $entity, refusal: self::REVOKE_REFUSAL);

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
	 * Tell the owner or a delegate that the share they revoke is quarantined
	 * (GHSA-m5p9-6pw7-pf3g), instead of a silent success or "not found": its
	 * copy may be someone else's own secret, so only an administrator may
	 * release or drop it. Anyone else gets "not found", as for an unknown id.
	 *
	 * @param string $shareId The share-target row ID
	 * @param string $userId The Nextcloud user ID requesting the revoke
	 *
	 * @return void
	 *
	 * @throws ConflictException When the row is quarantined and the caller manages its source
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-revoke-share
	 */
	private function refuseQuarantined(string $shareId, string $userId): void {
		if ($this->quarantined === null) {
			return;
		}

		try {
			$row = $this->quarantined->findQuarantinedById($shareId);
			$source = $this->auth->loadSecret(secretId: $row->getSourceSecretId());
		} catch (DoesNotExistException|InvalidArgumentException) {
			return;
		}

		if ($this->auth->isOwnerOrDelegate(secret: $source, userId: $userId) === false) {
			return;
		}

		throw new ConflictException(message: self::QUARANTINED_MESSAGE);
	}//end refuseQuarantined()

	/**
	 * End every share of a secret where nobody can be refused (its delete by
	 * a background job or another app, or a rollback): each recipient's copy
	 * goes with its share row, exactly as a single revokeShare() does
	 * (keepiq#83). Deleting only the link rows left the copies behind as
	 * ordinary-looking live secrets nobody tracked. The row of a copy the
	 * pending quarantine scan holds back goes and its copy is kept.
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
		$this->deleteAll(sourceSecretId: $sourceSecretId, refusal: null);
	}//end deleteAllForSecret()

	/**
	 * End every share of a secret at its owner's request (the secret is
	 * trashed), as deleteAllForSecret() does, except that a row the pending
	 * quarantine scan cannot judge refuses the request instead of leaving its
	 * copy behind with no row. Every row is judged before anything changes.
	 *
	 * @param string $sourceSecretId The source secret ID
	 *
	 * @return void
	 *
	 * @throws ShareScanPendingException When the scan cannot judge one of its rows
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	public function revokeAllForSecret(string $sourceSecretId): void {
		$refusal = 'A share of this secret waits for the share quarantine scan, so the secret cannot be deleted yet';
		// Before the transaction, so a quarantine this makes is not rolled
		// back with a refused delete.
		foreach ($this->mapper->findBySourceSecret($sourceSecretId) as $share) {
			$this->quarantineScan?->refuseUnjudged(row: $share, refusal: $refusal);
		}

		$this->deleteAll(sourceSecretId: $sourceSecretId, refusal: $refusal);
	}//end revokeAllForSecret()

	/**
	 * Delete every share row of a secret and the copies they hold, in one
	 * transaction.
	 *
	 * @param string      $sourceSecretId The source secret ID
	 * @param string|null $refusal        What waits for the scan when a row it cannot judge refuses the delete, or
	 *                                    null to keep that row's copy
	 *
	 * @return void
	 *
	 * @throws Throwable When a step fails; the transaction is rolled back
	 */
	private function deleteAll(string $sourceSecretId, ?string $refusal): void {
		$this->db->beginTransaction();
		try {
			foreach ($this->mapper->findBySourceSecret($sourceSecretId) as $share) {
				$this->deleteRecipientCopy(share: $share, refusal: $refusal);
			}

			$this->mapper->deleteBySourceSecret($sourceSecretId);
			$this->db->commit();
		} catch (Throwable $exception) {
			$this->db->rollBack();
			throw $exception;
		}
	}//end deleteAll()

	/**
	 * Delete one recipient copy and the attachment grants it holds. A copy
	 * that is already gone is fine. Defence in depth: a copy not genuinely held
	 * by the share's target (a poisoned or drifted `secret_id`) is kept, so a
	 * revoke or the cascade can never delete another user's secret. So is the
	 * copy of a row the pending quarantine scan holds back; with a refusal, a
	 * row it cannot judge refuses the caller's request instead.
	 *
	 * @param ShareTarget $share   The share row whose copy is being removed
	 * @param string|null $refusal What waits for the scan, or null to keep an unjudged row's copy
	 *
	 * @return void
	 *
	 * @throws ShareScanPendingException When a refusal is given and the scan cannot judge the row
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-revoke-share
	 */
	private function deleteRecipientCopy(ShareTarget $share, ?string $refusal = null): void {
		$copyId = $share->getSecretId();
		try {
			$copy = $this->secretMapper->findById($copyId);
			if ($this->keepsCopy(share: $share, copy: $copy, refusal: $refusal) === true) {
				$this->logger->warning(
					'Kept a secret its share target does not hold, or the pending quarantine scan holds back, on a revoke or cascade delete',
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

	/**
	 * Whether a revoke or cascade keeps a row's copy: its target does not
	 * hold it, or the pending quarantine scan holds the row back.
	 *
	 * @param ShareTarget $share   The share row
	 * @param Secret      $copy    The copy its `secret_id` names
	 * @param string|null $refusal What waits for the scan, or null to keep an unjudged row's copy
	 *
	 * @return bool
	 *
	 * @throws ShareScanPendingException When a refusal is given and the scan cannot judge the row
	 */
	private function keepsCopy(ShareTarget $share, Secret $copy, ?string $refusal): bool {
		if ($share->isHeldByTarget(copy: $copy) === false) {
			return true;
		}

		if ($refusal !== null) {
			return $this->quarantineScan?->refuseUnjudged(row: $share, refusal: $refusal) === true;
		}

		return $this->quarantineScan?->heldBack(row: $share) !== null;
	}//end keepsCopy()
}//end class
