<?php

/**
 * Keepiq Owned Secret Cleanup
 *
 * What an account deletion removes from the secrets the user still owns, just before their rows go:
 * their partner shares end, each SHARE_UNSHARED left to the retry job, and the data that hangs off them goes.
 * Runs after the delegated secrets went to their delegates, whose partner shares stay with them.
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

/**
 * Ends the partner shares of a departing user's own secrets and removes their child data.
 *
 * @spec openspec/specs/gdpr-compliance/spec.md
 */
class OwnedSecretCleanup {
	/**
	 * Constructor for OwnedSecretCleanup.
	 *
	 * @param SecretChildDataCleaner $childData The attachment/version cascade
	 * @param PartnerShareRevoker $partnerShares Revokes the partner shares of the user's own secrets
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private SecretChildDataCleaner $childData,
		private PartnerShareRevoker $partnerShares,
	) {
	}//end __construct()

	/**
	 * End the partner shares of every secret the user still owns, then remove what hangs off those secrets.
	 * The shares are found through the secrets, so this runs before the secrets' rows go.
	 *
	 * @param string $userId The departing user
	 *
	 * @return void
	 *
	 * @spec openspec/specs/gdpr-compliance/spec.md
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-deleting-the-secret-for-good-removes-bobs-copy
	 */
	public function purgeForOwnerUser(string $userId): void {
		$this->partnerShares->queueForDeletedAccount(userId: $userId);
		$this->childData->purgeForOwnerUser(userId: $userId);
	}//end purgeForOwnerUser()
}//end class
