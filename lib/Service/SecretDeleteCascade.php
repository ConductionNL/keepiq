<?php

/**
 * Keepiq Secret Delete Cascade
 *
 * The steps of a secret's delete for good that reach past the secret's own tables: its partner shares end before its row goes,
 * so each partner deletes its copy, and its extra website addresses go with it.
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

use OCA\Keepiq\Db\SecretUrlMapper;

/**
 * Ends a deleted secret's partner shares and removes its extra website addresses.
 *
 * @spec openspec/specs/federated-sharing/spec.md#scenario-deleting-the-secret-for-good-removes-bobs-copy
 */
class SecretDeleteCascade {
	/**
	 * Constructor for SecretDeleteCascade.
	 *
	 * @param SecretUrlMapper $urls The extra website addresses
	 * @param PartnerShareRevoker $partnerShares Revokes the partner shares of a deleted secret
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private SecretUrlMapper $urls,
		private PartnerShareRevoker $partnerShares,
	) {
	}//end __construct()

	/**
	 * End the partner shares of a secret that is deleted for good. The owner's delete tells every partner now;
	 * the trash retention job leaves that to the retry job. Runs before the secret's row goes.
	 *
	 * @param string         $secretId The secret being deleted
	 * @param SecretDeletion $deletion Who deletes it, and why when it is a purge
	 *
	 * @return void
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-deleting-the-secret-for-good-removes-bobs-copy
	 */
	public function endPartnerShares(string $secretId, SecretDeletion $deletion): void {
		$this->partnerShares->endForDeletedSecret(secretId: $secretId, deletion: $deletion);
	}//end endPartnerShares()

	/**
	 * Remove the extra website addresses of a deleted secret.
	 *
	 * @param string $secretId The deleted secret
	 *
	 * @return void
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-deleting-the-secret-for-good-removes-bobs-copy
	 */
	public function deleteUrls(string $secretId): void {
		$this->urls->deleteBySecret($secretId);
	}//end deleteUrls()
}//end class
