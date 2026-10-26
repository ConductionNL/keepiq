<?php

/**
 * Keepiq Share Service
 *
 * The user-to-user secret-share lifecycle: updateRestriction (owner/delegate
 * authorization) and listSharesForSecret (owner/delegate-only view).
 *
 * The surfaces that do the heavy lifting have their own classes now: bulk
 * registration of pre-encrypted rows where the server mints each recipient
 * copy (DirectShareRegistrar), the multi-recipient re-encryption fan-out
 * (ShareSyncService) and revocation with its delete cascade
 * (ShareRevocationService). Their public entry points are re-exported
 * below so ShareController and SecretService keep one seam.
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
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Db\TeamFolderMember;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Business logic for the user-to-user secret-share lifecycle.
 *
 * Authorization is two-tier: the Secret owner is always authorized; a
 * SecretDelegation (temporary or permanent) authorizes the delegate as
 * well. Every path — creation here, plus revocation and sync in their own
 * classes — falls through ShareAuthorizationService, so the authorization
 * surface stays in one place.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Pre-existing suppression,
 *   narrowed but not yet retired. Authorization moved to
 *   ShareAuthorizationService, the bulk path to DirectShareRegistrar, the
 *   fan-out to ShareSyncService and the delete cascade to
 *   ShareRevocationService; the remaining collaborators are what
 *   updateRestriction/listSharesForSecret genuinely need plus the ones whose
 *   entry points this class re-exports so ShareController and SecretService
 *   keep one seam.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) One public method per share
 *   operation; the use-only change added the restriction update.
 */
class ShareService {

	/**
	 * Constructor for ShareService.
	 *
	 * @param ShareTargetMapper $mapper The share-target mapper
	 * @param ShareAuthorizationService $auth The share authorization service
	 * @param DirectShareRegistrar $directRegistrar The bulk direct-share registrar
	 * @param ShareSyncService $syncService The multi-recipient sync service
	 * @param ShareRevocationService $revocationService The revocation + cascade service
	 * @param ShareRestrictionResolver|null $restrictions Materialises use-only and end dates onto copies
	 *
	 * @return void
	 */
	public function __construct(
		private ShareTargetMapper $mapper,
		private ShareAuthorizationService $auth,
		private DirectShareRegistrar $directRegistrar,
		private ShareSyncService $syncService,
		private ShareRevocationService $revocationService,
		private ?ShareRestrictionResolver $restrictions = null,
	) {
	}//end __construct()

	/**
	 * Register a batch of DIRECT user-to-user shares from client-encrypted
	 * blobs (bulk-actions §6.1/§7.1).
	 *
	 * @param string $userId The sharing owner
	 * @param array<int,array<string,mixed>> $shares Rows {sourceSecretId, targetUserId, encryptedKey, encryptedLogin?, encryptedAdditionalFields?}
	 *
	 * @return array<int,array{sourceSecretId:string,targetUserId:string,status:string,recipientSecretId?:string}>
	 *
	 * @spec openspec/specs/bulk-actions/spec.md#requirement-the-four-bulk-operations
	 */
	public function registerDirectShares(string $userId, array $shares): array {
		return $this->directRegistrar->registerDirectShares(userId: $userId, shares: $shares);
	}//end registerDirectShares()

	/**
	 * The active certificates of several prospective recipients.
	 *
	 * @param string[] $targetUserIds The prospective recipients
	 *
	 * @return array<string,string> PEM certificate, keyed by user ID
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-recipient-shareability-lookup
	 */
	public function recipientCertificates(array $targetUserIds): array {
		return $this->directRegistrar->recipientCertificates(targetUserIds: $targetUserIds);
	}//end recipientCertificates()

	/**
	 * Change the use-only flag and end date of a direct share (owner or
	 * delegate only), then recompute the recipient's copy. A share that
	 * came from a group share or a team folder is changed there instead.
	 *
	 * @param string $shareId The share-target row ID
	 * @param ShareRestriction $restriction The new use-only flag and end date
	 * @param string $userId The requesting user
	 *
	 * @return ShareTarget
	 *
	 * @throws InvalidArgumentException When not found, not authorized or not a direct share
	 *
	 * @spec openspec/changes/archive/2026-10-04-sharing-use-only-and-expiring-shares/tasks.md#task-2.1
	 */
	public function updateRestriction(string $shareId, ShareRestriction $restriction, string $userId): ShareTarget {
		try {
			$entity = $this->mapper->findById($shareId);
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException(message: 'Share not found');
		}

		$source = $this->auth->loadSecret(secretId: $entity->getSourceSecretId());
		$this->auth->assertOwnerOrDelegate(secret: $source, userId: $userId);
		if ($entity->getGroupShareId() !== null || $entity->getTeamFolderId() !== null) {
			throw new InvalidArgumentException(message: 'Change this share on its group share or team folder');
		}

		$entity->setUseOnly($restriction->useOnly);
		$entity->setExpiresAt($restriction->expiresAt);
		$updated = $this->mapper->update($entity);
		$this->restrictions?->resolveTarget(target: $updated);

		return $updated;
	}//end updateRestriction()

	/**
	 * List all share targets for a given source secret.
	 *
	 * Only the owner or an active delegate sees the recipient list; for
	 * recipients and non-participants the return is an empty array (so
	 * UI can hide the section without raising a 403).
	 *
	 * @param string $sourceSecretId The source secret ID
	 * @param string $userId The requesting user ID
	 *
	 * @return ShareTarget[]
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#3.5
	 */
	public function listSharesForSecret(string $sourceSecretId, string $userId = ''): array {
		if ($userId === '') {
			// Back-compat: callers that do not provide a userId get the
			// raw list (scaffold semantics, used by the cascade path).
			return $this->mapper->findBySourceSecret($sourceSecretId);
		}

		try {
			$source = $this->auth->loadSecret(secretId: $sourceSecretId);
		} catch (InvalidArgumentException) {
			return [];
		}

		if ($this->auth->isOwnerOrDelegate(secret: $source, userId: $userId) === false) {
			// A write-grade team member needs the recipient list (+
			// certificates) to run the re-encrypt fan-out
			// (folder-permission-grades §2.3); read grades see nothing.
			if (in_array($this->auth->resolveGrade(secret: $source, userId: $userId), TeamFolderMember::WRITE_GRADES, true) === false) {
				return [];
			}
		}

		return $this->mapper->findBySourceSecret($sourceSecretId);
	}//end listSharesForSecret()

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
	 * @throws \OCA\Keepiq\Exception\ConflictException When the row is quarantined
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-revoke-share
	 */
	public function revokeShare(string $shareId, string $userId): void {
		$this->revocationService->revokeShare(shareId: $shareId, userId: $userId);
	}//end revokeShare()

	/**
	 * Push an updated encrypted blob to every recipient.
	 *
	 * @param string $secretId The source secret ID
	 * @param array<int,array<string,string|null>> $updates The per-recipient blobs
	 * @param string $expectedUpdatedAt The owner-side expected ISO timestamp
	 * @param string $userId The requesting user
	 *
	 * @return int Number of recipient copies updated.
	 *
	 * @throws InvalidArgumentException When validation or optimistic-lock check fails
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-sync-on-update
	 */
	public function syncUpdate(
		string $secretId,
		array $updates,
		string $expectedUpdatedAt,
		string $userId,
	): int {
		return $this->syncService->syncUpdate(
			secretId: $secretId,
			updates: $updates,
			expectedUpdatedAt: $expectedUpdatedAt,
			userId: $userId,
		);
	}//end syncUpdate()

	/**
	 * The write context of a secret for the current user
	 * (folder-permission-grades §4).
	 *
	 * @param string $secretId The secret (source or recipient copy) UUID
	 * @param string $userId The requesting user
	 *
	 * @return array{sourceSecretId:string|null, effectiveGrade:string, ownerCertificate:string|null, sourceUpdatedAt:string|null}
	 *
	 * @throws InvalidArgumentException When the secret does not exist or the caller does not reach it
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-a-write-grade-member-may-update-a-folder-secret-for-all-recipients
	 * @spec openspec/specs/sharing-group/spec.md#requirement-a-group-member-saves-for-everyone-it-is-shared-with
	 */
	public function writeContext(string $secretId, string $userId): array {
		return $this->syncService->writeContext(secretId: $secretId, userId: $userId);
	}//end writeContext()

	/**
	 * Cascade-delete all share targets for a secret (called on secret delete).
	 *
	 * @param string $sourceSecretId The source secret ID
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-revoke-share
	 */
	public function deleteAllForSecret(string $sourceSecretId): void {
		$this->revocationService->deleteAllForSecret(sourceSecretId: $sourceSecretId);
	}//end deleteAllForSecret()
}//end class
