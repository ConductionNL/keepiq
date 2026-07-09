<?php

/**
 * Doriath AccountDeletionService
 *
 * Orchestrates the GDPR Art. 17 (right-to-erasure) cascade that removes all of
 * a user's Doriath data, with defined semantics for shared and delegated
 * secrets (secret-export-gdpr D4). One implementation serves both triggers:
 * the in-app re-auth flow and the automatic UserDeletedEvent listener.
 *
 * The cascade is ordered and every step is idempotent and keyed by userId, so
 * an interrupted run can be safely re-executed. The AccountDataDeletedEvent is
 * dispatched ONLY on a completed run, carrying counts and the trigger — never
 * any secret material.
 *
 * Shared-secret semantics:
 *  - Delegated secrets transfer ownership to the delegate (delegation made
 *    permanent, owner reassigned).
 *  - Recipient copies of secrets the user shared are detached: the link is
 *    severed and the copy is tombstoned with a non-personal reason. No personal
 *    data of the deleted user is ever written to a recipient's copy.
 *  - Received share copies in the deleted user's vault are hard-deleted; the
 *    original owners' secrets are untouched.
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

use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\FolderMapper;
use OCA\Doriath\Db\GroupShareMapper;
use OCA\Doriath\Db\LinkShareMapper;
use OCA\Doriath\Db\SecretDelegationMapper;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\SecretRequestMapper;
use OCA\Doriath\Db\ShareTargetMapper;
use OCA\Doriath\Db\SuiteMigrationMapper;
use OCA\Doriath\Db\DashboardSettingMapper;
use OCA\Doriath\Event\AccountDataDeletedEvent;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Runs the ordered, idempotent account-deletion cascade.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The cascade necessarily
 *   touches every entity that references a user; one orchestrator with the full
 *   set of mappers is clearer than scattering the ordered steps across services.
 */
class AccountDeletionService
{
    /**
     * The non-personal tombstone reason written to detached recipient copies.
     *
     * @var string
     */
    public const TOMBSTONE_REASON = 'owner-account-deleted';

    /**
     * Constructor for AccountDeletionService.
     *
     * @param SecretMapper           $secretMapper     The secret mapper
     * @param FolderMapper           $folderMapper     The folder mapper
     * @param ShareTargetMapper      $shareMapper      The share-target mapper
     * @param GroupShareMapper       $groupShareMapper The group-share mapper
     * @param SecretDelegationMapper $delegationMapper The delegation mapper
     * @param LinkShareMapper        $linkShareMapper  The link-share mapper
     * @param SecretRequestMapper    $requestMapper    The secret-request mapper
     * @param EncryptionSuiteMapper  $suiteMapper      The encryption-suite mapper
     * @param SuiteMigrationMapper   $migrationMapper  The suite-migration mapper
     * @param DashboardSettingMapper $settingMapper    The settings mapper
     * @param IEventDispatcher       $dispatcher       The event dispatcher
     *
     * @return void
     */
    public function __construct(
        private SecretMapper $secretMapper,
        private FolderMapper $folderMapper,
        private ShareTargetMapper $shareMapper,
        private GroupShareMapper $groupShareMapper,
        private SecretDelegationMapper $delegationMapper,
        private LinkShareMapper $linkShareMapper,
        private SecretRequestMapper $requestMapper,
        private EncryptionSuiteMapper $suiteMapper,
        private SuiteMigrationMapper $migrationMapper,
        private DashboardSettingMapper $settingMapper,
        private IEventDispatcher $dispatcher,
    ) {
    }//end __construct()

    /**
     * Delete all Doriath data for a user, running the ordered cascade and
     * dispatching AccountDataDeletedEvent on completion.
     *
     * @param string $userId  The Nextcloud user ID
     * @param string $trigger The trigger ('in-app' | 'user-deleted')
     *
     * @return DeletionReport The per-entity counts
     *
     * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
     */
    public function deleteAllFor(string $userId, string $trigger='user-deleted'): DeletionReport
    {
        $report = new DeletionReport();

        // Snapshot the user's own secrets once; reused across the share steps.
        $ownedSecrets = $this->secretMapper->findByOwner(
            ownerType: 'user',
            ownerId: $userId,
            limit: 100000,
        );

        $this->transferDelegatedSecrets(userId: $userId, ownedSecrets: $ownedSecrets, report: $report);
        $this->detachGrantedShares(ownedSecrets: $ownedSecrets, report: $report);
        $this->removeReceivedShares(userId: $userId, report: $report);

        // Link shares + secret requests via the existing per-user cascade methods.
        // deleteByUserId returns void, so count first for the report.
        $report->linkSharesDeleted = count($this->linkShareMapper->findByCreatedBy(userId: $userId));
        $this->linkShareMapper->deleteByUserId(userId: $userId);
        $report->requestsDeleted = $this->requestMapper->deleteByCreatedBy(userId: $userId);

        // Own secrets + folders.
        $report->secretsDeleted = $this->secretMapper->deleteByOwnerUser(ownerId: $userId);
        $report->foldersDeleted = $this->folderMapper->deleteByOwnerUser(ownerId: $userId);

        // Suites (cert + encrypted private key) and their migration records.
        $this->removeSuites(userId: $userId, report: $report);

        // Settings / preferences.
        $this->settingMapper->deleteByUser(userId: $userId);
        $report->settingsDeleted = true;

        // Emit the completion event (counts only, never secret material).
        $this->dispatcher->dispatchTyped(
            new AccountDataDeletedEvent(userId: $userId, trigger: $trigger, report: $report)
        );

        return $report;
    }//end deleteAllFor()

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
     */
    private function transferDelegatedSecrets(string $userId, array $ownedSecrets, DeletionReport $report): void
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
     */
    private function detachGrantedShares(array $ownedSecrets, DeletionReport $report): void
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
     */
    private function removeReceivedShares(string $userId, DeletionReport $report): void
    {
        $report->sharesRemoved = count($this->shareMapper->findByTargetUser(targetUserId: $userId));
        $this->shareMapper->deleteByTargetUser(targetUserId: $userId);
    }//end removeReceivedShares()

    /**
     * Step 6 — remove the user's suites and their migration records.
     *
     * @param string         $userId The departing user
     * @param DeletionReport $report The running report
     *
     * @return void
     */
    private function removeSuites(string $userId, DeletionReport $report): void
    {
        $suites   = $this->suiteMapper->findByOwner(ownerType: 'user', ownerId: $userId);
        $suiteIds = [];
        foreach ($suites as $suite) {
            $suiteIds[] = $suite->getId();
        }

        $this->migrationMapper->deleteBySuiteIds(suiteIds: $suiteIds);
        $report->suitesDeleted = $this->suiteMapper->deleteByOwnerUser(ownerId: $userId);
    }//end removeSuites()
}//end class
