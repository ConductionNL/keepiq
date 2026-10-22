<?php

/**
 * Keepiq Secret Owner Transfer Service
 *
 * Hands one secret to a new owner, as the account-deletion and team
 * offboarding transfers do. Its partner shares, and the link shares the user
 * it is taken from holds on it, go with it in the same transaction, so the
 * new owner can revoke or suspend them and the partner keeps pulling its
 * shares. Link shares a delegate created stay the delegate's, also when the
 * secret already passed to someone else in an earlier transfer.
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

use OCA\Keepiq\Db\FederatedShareMapper;
use OCA\Keepiq\Db\LinkShareMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\IDBConnection;

/**
 * Reassigns a secret's owner together with its partner shares and the
 * previous owner's link shares.
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/federated-sharing/spec.md#scenario-bobs-share-follows-the-secret-to-a-new-owner
 */
class SecretOwnerTransferService {
	use TTransactional;

	/**
	 * Constructor for SecretOwnerTransferService.
	 *
	 * @param IDBConnection $db The database connection
	 * @param SecretMapper $secretMapper The secret mapper
	 * @param FederatedShareMapper $federatedShares Outbound partner shares
	 * @param LinkShareMapper $linkShares Public link shares
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private IDBConnection $db,
		private SecretMapper $secretMapper,
		private FederatedShareMapper $federatedShares,
		private LinkShareMapper $linkShares,
	) {
	}//end __construct()

	/**
	 * Make `$newOwnerId` the owner of the secret, of its partner shares and
	 * of the link shares `$fromOwnerId` holds on it, including the ones an
	 * earlier transfer handed to them. The links only move while
	 * `$fromOwnerId` still owns the secret: when an earlier transfer already
	 * handed it to someone else, or the secret no longer exists, only the
	 * secret row and its partner shares are handed over.
	 *
	 * @param string $secretId The secret
	 * @param string $fromOwnerId The user the secret is taken from
	 * @param string $newOwnerId The new owner's Nextcloud user ID
	 *
	 * @return int The link shares handed over
	 *
	 * @spec openspec/specs/gdpr-compliance/spec.md
	 * @spec openspec/changes/sharing-atomic-share-save/specs/federated-sharing/spec.md#scenario-bobs-share-follows-the-secret-to-a-new-owner
	 * @spec openspec/specs/link-sharing/spec.md#requirement-manual-revocation
	 */
	public function transfer(string $secretId, string $fromOwnerId, string $newOwnerId): int {
		return $this->atomic(
			fn: function () use ($secretId, $fromOwnerId, $newOwnerId): int {
				try {
					$previousOwnerId = $this->secretMapper->findById($secretId)->getOwnerId();
				} catch (DoesNotExistException) {
					$previousOwnerId = null;
				}

				$this->secretMapper->reassignOwner(secretId: $secretId, newOwnerId: $newOwnerId);
				$this->federatedShares->reassignOwner(sourceSecretId: $secretId, ownerId: $newOwnerId);
				if ($previousOwnerId !== $fromOwnerId) {
					return 0;
				}

				return $this->linkShares->reassignCreator(secretId: $secretId, fromUserId: $fromOwnerId, toUserId: $newOwnerId);
			},
			db: $this->db
		);
	}//end transfer()
}//end class
