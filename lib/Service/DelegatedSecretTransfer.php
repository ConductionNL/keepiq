<?php

/**
 * Keepiq Delegated Secret Transfer
 *
 * Step 1 of the account-deletion share cascade, split out of AccountShareCleanupService:
 * secrets under an active delegation are handed to the delegate.
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

use OCA\Keepiq\Db\SecretDelegationMapper;

/**
 * Hands the departing user's delegated secrets to their delegates.
 *
 * @spec openspec/specs/gdpr-compliance/spec.md#requirement-shared-secret-semantics-on-account-deletion
 */
class DelegatedSecretTransfer {

	/**
	 * Constructor for DelegatedSecretTransfer.
	 *
	 * @param SecretDelegationMapper     $delegationMapper The delegation mapper
	 * @param SecretOwnerTransferService $ownerTransfer    Hands a secret and its partner and link shares over
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private SecretDelegationMapper $delegationMapper,
		private SecretOwnerTransferService $ownerTransfer,
	) {
	}//end __construct()

	/**
	 * Transfer secrets under an active delegation to the delegate.
	 *
	 * Each delegation is made permanent (per the user-sharing spec's
	 * is_permanent semantics for a deleted owner) and the secret's owner is
	 * reassigned to the delegate, partner shares included, so the delegate's
	 * access continues uninterrupted. The departing user's link shares are
	 * deleted before this step, so none of them reach the delegate. Transferred
	 * secrets are excluded from the later own-secret hard delete because the
	 * owner_id no longer matches the departing user.
	 *
	 * @param string $userId The departing user
	 * @param array<int,\OCA\Keepiq\Db\Secret> $ownedSecrets The user's secrets
	 * @param DeletionReport $report The running report
	 *
	 * @return array<string,string> The new owner of each secret handed over, by secret id
	 *
	 * @spec openspec/specs/gdpr-compliance/spec.md
	 */
	public function transfer(string $userId, array $ownedSecrets, DeletionReport $report): array {
		$ownedIds = [];
		foreach ($ownedSecrets as $secret) {
			$ownedIds[$secret->getId()] = true;
		}

		$handedTo = [];
		foreach ($this->delegationMapper->findByOriginalOwner(originalOwnerId: $userId) as $delegation) {
			$secretId = $delegation->getSecretId();
			// Only transfer secrets the departing user actually owns now.
			if (isset($ownedIds[$secretId]) === false) {
				continue;
			}

			$this->ownerTransfer->transfer(
				secretId: $secretId,
				fromOwnerId: $userId,
				newOwnerId: $delegation->getDelegatedTo(),
			);
			// A second delegation of the secret hands it on again, so the last one names its owner.
			$handedTo[$secretId] = $delegation->getDelegatedTo();
			$report->secretsTransferred++;
		}

		// Promote every temporary delegation by this owner to permanent. The
		// mapper call is idempotent (already-permanent rows are not matched).
		$this->delegationMapper->makePermanentByOriginalOwner(originalOwnerId: $userId);

		return $handedTo;
	}//end transfer()
}//end class
