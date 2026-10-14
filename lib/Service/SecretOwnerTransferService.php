<?php

/**
 * Keepiq Secret Owner Transfer Service
 *
 * Hands one secret to a new owner, as the account-deletion and team
 * offboarding transfers do. Its partner shares go with it in the same
 * transaction, so the new owner's share-save writes them and the new owner
 * can revoke or suspend them.
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
use OCA\Keepiq\Db\SecretMapper;
use OCP\AppFramework\Db\TTransactional;
use OCP\IDBConnection;

/**
 * Reassigns a secret's owner together with its partner shares.
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
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private IDBConnection $db,
		private SecretMapper $secretMapper,
		private FederatedShareMapper $federatedShares,
	) {
	}//end __construct()

	/**
	 * Make `$newOwnerId` the owner of the secret and of its partner shares.
	 *
	 * @param string $secretId The secret
	 * @param string $newOwnerId The new owner's Nextcloud user ID
	 *
	 * @return void
	 *
	 * @spec openspec/specs/gdpr-compliance/spec.md
	 * @spec openspec/changes/sharing-atomic-share-save/specs/federated-sharing/spec.md#scenario-bobs-share-follows-the-secret-to-a-new-owner
	 */
	public function transfer(string $secretId, string $newOwnerId): void {
		$this->atomic(
			fn: function () use ($secretId, $newOwnerId): void {
				$this->secretMapper->reassignOwner(secretId: $secretId, newOwnerId: $newOwnerId);
				$this->federatedShares->reassignOwner(sourceSecretId: $secretId, ownerId: $newOwnerId);
			},
			db: $this->db
		);
	}//end transfer()
}//end class
