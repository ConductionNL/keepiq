<?php

/**
 * Doriath Account Share Cleanup Service
 *
 * The share-shaped steps of the GDPR Art. 17 erasure cascade (secret-export-gdpr
 * D4), split out of AccountDeletionService: delegated secrets are transferred,
 * recipient copies of secrets the user granted are detached and tombstoned, and
 * the link rows naming the user as recipient are severed.
 *
 * Every step is idempotent and keyed by userId, so an interrupted run can be
 * safely re-executed. No personal data of the deleted user is ever written to a
 * recipient's copy.
 *
 * @category Service
 * @package  OCA\Doriath\Service
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

namespace OCA\Doriath\Service;

use OCA\Doriath\Db\GroupShareMapper;
use OCA\Doriath\Db\SecretDelegationMapper;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\ShareTargetMapper;

/**
 * Runs the share-related steps of the account-deletion cascade.
 */
class AccountShareCleanupService
{
    /**
     * The non-personal tombstone reason written to detached recipient copies.
     *
     * @var string
     */
    public const TOMBSTONE_REASON = 'owner-account-deleted';

    /**
     * Constructor for AccountShareCleanupService.
     *
     * @param SecretMapper           $secretMapper     The secret mapper
     * @param ShareTargetMapper      $shareMapper      The share-target mapper
     * @param GroupShareMapper       $groupShareMapper The group-share mapper
     * @param SecretDelegationMapper $delegationMapper The delegation mapper
     *
     * @return void
     *
     * @spec exclude Constructor wiring only.
     */
    public function __construct(
        private SecretMapper $secretMapper,
        private ShareTargetMapper $shareMapper,
        private GroupShareMapper $groupShareMapper,
        private SecretDelegationMapper $delegationMapper,
    ) {
    }//end __construct()

    /**
     * Step 1 — transfer secrets under an active delegation to the delegate.
     *
     * Each delegation is made permanent (per the user-sharing spec's
     * is_permanent semantics for a deleted owner) and the secret's owner is
     * reassigned to the delegate, so the delegate's access continues
     * uninterrupted. Transferred secrets are excluded from the later own-secret
     * hard delete because the owner_id no longer matches the departing user.
     *
     * @param string                            $userId       The departing user
     * @param array<int,\OCA\Doriath\Db\Secret> $ownedSecrets The user's secrets
     * @param DeletionReport                    $report       The running report
     *
     * @return void
     *
     * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
     */
    public function transferDelegatedSecrets(string $userId, array $ownedSecrets, DeletionReport $report): void
    {
        $ownedIds = [];
        foreach ($ownedSecrets as $secret) {
            $ownedIds[$secret->getId()] = true;
        }

        foreach ($this->delegationMapper->findByOriginalOwner(originalOwnerId: $userId) as $delegation) {
            $secretId = $delegation->getSecretId();
            // Only transfer secrets the departing user actually owns now.
            if (isset($ownedIds[$secretId]) === false) {
                continue;
            }

            $this->secretMapper->reassignOwner(
                secretId: $secretId,
                newOwnerId: $delegation->getDelegatedTo(),
            );
            $report->secretsTransferred++;
        }

        // Promote every temporary delegation by this owner to permanent. The
        // mapper call is idempotent (already-permanent rows are not matched).
        $this->delegationMapper->makePermanentByOriginalOwner(originalOwnerId: $userId);
    }//end transferDelegatedSecrets()

    /**
     * Step 2 — detach recipient copies of secrets the user shared.
     *
     * For each ShareTarget where one of the user's secrets is the source, the
     * recipient already holds a full Secret row encrypted under their own suite.
     * The link row is deleted (sync severed) and the recipient copy is
     * tombstoned with a non-personal reason — NO identity of the deleted user is
     * written to the recipient copy. GroupShares the user created are deleted
     * the same way (their per-member ShareTarget rows carry the groupShareId).
     *
     * @param array<int,\OCA\Doriath\Db\Secret> $ownedSecrets The user's secrets
     * @param DeletionReport                    $report       The running report
     *
     * @return void
     *
     * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
     */
    public function detachGrantedShares(array $ownedSecrets, DeletionReport $report): void
    {
        foreach ($ownedSecrets as $secret) {
            foreach ($this->shareMapper->findBySourceSecret(sourceSecretId: $secret->getId()) as $share) {
                // Tombstone the recipient's copy (display metadata only).
                $this->secretMapper->tombstone(
                    secretId: $share->getSecretId(),
                    reason: self::TOMBSTONE_REASON,
                );
                $report->sharesDetached++;
            }

            // Sever every link from this source secret (sync stops).
            $this->shareMapper->deleteBySourceSecret(sourceSecretId: $secret->getId());

            // Remove the group-share definitions for this secret.
            foreach ($this->groupShareMapper->findBySecret(secretId: $secret->getId()) as $groupShare) {
                $this->groupShareMapper->deleteBySecret(secretId: $groupShare->getSecretId());
            }
        }
    }//end detachGrantedShares()

    /**
     * Step 3 — remove shares the user received.
     *
     * The recipient copies live in the deleted user's own vault and are removed
     * by the later own-secret hard delete; here only the link rows pointing at
     * this user as recipient are severed. The original owners' source secrets
     * are never touched.
     *
     * @param string         $userId The departing user
     * @param DeletionReport $report The running report
     *
     * @return void
     *
     * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
     */
    public function removeReceivedShares(string $userId, DeletionReport $report): void
    {
        $report->sharesRemoved = count($this->shareMapper->findByTargetUser(targetUserId: $userId));
        $this->shareMapper->deleteByTargetUser(targetUserId: $userId);
    }//end removeReceivedShares()
}//end class
