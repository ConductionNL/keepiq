<?php

/**
 * Doriath Migration Service
 *
 * Tracks compromise recovery migrations (suite to suite).
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

use DateTime;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\SuiteMigration;
use OCA\Doriath\Db\SuiteMigrationMapper;
use OCA\Doriath\Event\SuiteMigrationCompletedEvent;
use OCA\Doriath\Event\SuiteMigrationStartedEvent;
use OCA\Doriath\Service\EncryptionSuiteService;
use OCA\Doriath\Service\LinkShareService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Tracks compromise recovery migrations (suite to suite).
 */
class MigrationService
{
    /**
     * Constructor for MigrationService.
     *
     * @param SuiteMigrationMapper                     $mapper          The suite migration mapper
     * @param EncryptionSuiteMapper                    $suiteMapper     The encryption suite mapper
     * @param EncryptionSuiteService                   $suiteService    The suite service (terminal markCompromised)
     * @param LinkShareService                         $linkShareService The link share service (terminal cascade-revoke)
     * @param LoggerInterface                          $logger          The logger interface
     * @param IEventDispatcher|null                    $eventDispatcher The optional event dispatcher
     * @param \OCA\Doriath\Service\PasskeyService|null $passkeyService  The passkey service (null when unwired)
     *
     * @return void
     */
    public function __construct(
        private SuiteMigrationMapper $mapper,
        private EncryptionSuiteMapper $suiteMapper,
        private EncryptionSuiteService $suiteService,
        private LinkShareService $linkShareService,
        private LoggerInterface $logger,
        private ?IEventDispatcher $eventDispatcher=null,
        private ?\OCA\Doriath\Service\PasskeyService $passkeyService=null,
    ) {
    }//end __construct()

    /**
     * Initiate a compromise recovery migration.
     *
     * @param string $oldSuiteId The old suite ID
     * @param string $newSuiteId The new suite ID
     *
     * @return SuiteMigration
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-4
     */
    public function initiateCompromiseRecovery(string $oldSuiteId, string $newSuiteId): SuiteMigration
    {
        $migration = new SuiteMigration();
        $migration->setId(Uuid::uuid4()->toString());
        $migration->setOldSuiteId($oldSuiteId);
        $migration->setNewSuiteId($newSuiteId);
        $migration->setStatus('in_progress');
        $migration->setStartedAt(new DateTime());

        $this->mapper->insert($migration);

        $this->logger->info("Doriath: Compromise recovery started, migrating from {$oldSuiteId} to {$newSuiteId}");

        if ($this->eventDispatcher !== null) {
            $this->eventDispatcher->dispatchTyped(
                new SuiteMigrationStartedEvent(
                    oldSuiteId: $oldSuiteId,
                    newSuiteId: $newSuiteId,
                    migrationId: $migration->getId()
                )
            );
        }

        return $migration;
    }//end initiateCompromiseRecovery()

    /**
     * Complete a migration (with or without errors).
     *
     * @param string $migrationId The migration ID
     * @param bool   $hasErrors   Whether the migration had errors
     *
     * @return SuiteMigration
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $hasErrors is outcome DATA forwarded
     *   from MigrationController::complete(), not a mode switch: the completion path is
     *   identical either way and the flag only selects which terminal status string is
     *   written to the row. The false default is the ordinary success call.
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-4
     */
    public function completeMigration(string $migrationId, bool $hasErrors=false): SuiteMigration
    {
        $migration = $this->mapper->findById($migrationId);

        $status = 'completed';
        if ($hasErrors === true) {
            $status = 'completed_with_errors';
        }

        $migration->setStatus($status);

        $migration->setCompletedAt(new DateTime());

        $this->mapper->update($migration);

        // Terminal work, moved here from EncryptionSuiteController::compromiseRecovery.
        // It used to run at the START of recovery, which marked the old suite
        // compromised before anything had been migrated — every read then threw
        // SuiteBlockedException and the browser could not decrypt the very
        // ciphertext it needed to migrate. None of this can safely happen until
        // the migration is over.
        $ownerId = $this->resolveOwnerId(suiteId: $migration->getOldSuiteId());

        $this->suiteService->markCompromised(
            id: $migration->getOldSuiteId(),
            compromisedBy: ($ownerId ?? '')
        );

        // The remaining terminal steps are owner-scoped. Running them with an
        // unresolved owner would target the wrong rows (or none), so skip and
        // shout rather than guess — the suite is still correctly marked
        // compromised above, which is the security-critical half.
        if ($ownerId === null) {
            $this->logger->error(
                'Doriath: migration completed but owner could not be resolved — '
                .'link shares and passkeys were NOT revoked and need manual review',
                ['migrationId' => $migrationId, 'oldSuiteId' => $migration->getOldSuiteId()]
            );
        } else {
            // Cascade-revoke every link share created by this user: the public-key
            // fingerprint baked into each share's encrypted snapshot belongs to the
            // now-compromised key pair, so any outstanding link must be force-locked
            // out and re-shared under the new suite (implement-link-sharing §5.2).
            $this->linkShareService->deleteByUserId(userId: $ownerId);

            // A new key pair invalidates every passkey unlock envelope — the wrapped
            // unlock key can never open the new suite (passkey-vault-login §D4).
            $this->passkeyService?->deleteAllOnRotation($ownerId);
        }

        // NOT re-implemented here: pending SecretRequests are unlocked and
        // re-pointed by SuiteMigrationCompletedListener, and emergency-access
        // envelopes are invalidated by EmergencyAccessSuiteRotationListener.
        // Both already listen for the event dispatched below.
        $this->logger->info(
                "Doriath: Compromise recovery completed for migration {$migrationId}",
                [
                    'hasErrors' => $hasErrors,
                ]
                );

        if ($this->eventDispatcher !== null) {
            $this->eventDispatcher->dispatchTyped(
                new SuiteMigrationCompletedEvent(
                    oldSuiteId: $migration->getOldSuiteId(),
                    newSuiteId: $migration->getNewSuiteId(),
                    migrationId: $migration->getId(),
                    hasErrors: $hasErrors,
                )
            );
        }

        return $migration;
    }//end completeMigration()

    /**
     * Resolve the owning user id for a suite.
     *
     * The terminal steps of a migration are owner-scoped (link-share revocation,
     * passkey deletion, the audit actor on markCompromised) but a migration row
     * records only suite ids, so the owner is resolved through the old suite.
     *
     * @param string $suiteId The suite id to resolve the owner of.
     *
     * @return string|null The owning user id, or null when unresolvable.
     *
     * @spec openspec/specs/encryption-suites/spec.md#requirement-suite-migration
     */
    private function resolveOwnerId(string $suiteId): ?string
    {
        try {
            $ownerId = $this->suiteMapper->findById($suiteId)->getOwnerId();
        } catch (DoesNotExistException) {
            $this->logger->error(
                'Doriath: cannot resolve migration owner — suite missing',
                ['suiteId' => $suiteId]
            );
            return null;
        }

        if ($ownerId === null || $ownerId === '') {
            return null;
        }

        return $ownerId;
    }//end resolveOwnerId()

    /**
     * Get in-progress migration for a given owner (via their old suite).
     *
     * @param string $ownerType The owner type
     * @param string $ownerId   The owner ID
     *
     * @return SuiteMigration
     *
     * @throws DoesNotExistException if no in-progress migration exists
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-4
     */
    public function getInProgressMigration(string $ownerType, string $ownerId): SuiteMigration
    {
        $suites = $this->suiteMapper->findByOwner($ownerType, $ownerId);

        foreach ($suites as $suite) {
            if ($this->mapper->hasInProgress($suite->getId()) === true) {
                return $this->mapper->findInProgressByOldSuiteId($suite->getId());
            }
        }

        throw new DoesNotExistException('No in-progress migration found');
    }//end getInProgressMigration()

    /**
     * Fetch a migration by its ID.
     *
     * @param string $migrationId The migration ID
     *
     * @return SuiteMigration
     *
     * @throws DoesNotExistException if no migration with that ID exists
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-4
     */
    public function getMigration(string $migrationId): SuiteMigration
    {
        return $this->mapper->findById($migrationId);
    }//end getMigration()

    /**
     * Check if an owner is write-locked due to an active migration.
     *
     * @param string $ownerType The owner type
     * @param string $ownerId   The owner ID
     *
     * @return bool
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-4
     */
    public function isWriteLocked(string $ownerType, string $ownerId): bool
    {
        try {
            $this->getInProgressMigration(ownerType: $ownerType, ownerId: $ownerId);
            return true;
        } catch (DoesNotExistException) {
            return false;
        }
    }//end isWriteLocked()
}//end class
