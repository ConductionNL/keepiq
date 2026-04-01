<?php

declare(strict_types=1);

namespace OCA\Doriath\Service;

use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\SuiteMigration;
use OCA\Doriath\Db\SuiteMigrationMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Tracks compromise recovery migrations (suite → suite).
 */
class MigrationService
{
    public function __construct(
        private SuiteMigrationMapper $mapper,
        private EncryptionSuiteMapper $suiteMapper,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Initiate a compromise recovery migration.
     */
    public function initiateCompromiseRecovery(string $oldSuiteId, string $newSuiteId): SuiteMigration
    {
        $migration = new SuiteMigration();
        $migration->setId(Uuid::uuid4()->toString());
        $migration->setOldSuiteId($oldSuiteId);
        $migration->setNewSuiteId($newSuiteId);
        $migration->setStatus('in_progress');
        $migration->setStartedAt(new \DateTime());

        $this->mapper->insert($migration);

        $this->logger->info("Doriath: Compromise recovery started, migrating from {$oldSuiteId} to {$newSuiteId}");

        return $migration;
    }//end initiateCompromiseRecovery()

    /**
     * Complete a migration (with or without errors).
     */
    public function completeMigration(string $migrationId, bool $hasErrors = false): SuiteMigration
    {
        $migration = $this->mapper->findById($migrationId);

        $migration->setStatus($hasErrors ? 'completed_with_errors' : 'completed');
        $migration->setCompletedAt(new \DateTime());

        $this->mapper->update($migration);

        // Mark the old suite as compromised.
        $oldSuite = $this->suiteMapper->findById($migration->getOldSuiteId());
        $oldSuite->setStatus('compromised');
        $this->suiteMapper->update($oldSuite);

        $this->logger->info("Doriath: Compromise recovery completed for migration {$migrationId}", [
            'hasErrors' => $hasErrors,
        ]);

        return $migration;
    }//end completeMigration()

    /**
     * Get in-progress migration for a given owner (via their old suite).
     *
     * @throws DoesNotExistException if no in-progress migration exists
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
     * Check if an owner is write-locked due to an active migration.
     */
    public function isWriteLocked(string $ownerType, string $ownerId): bool
    {
        try {
            $this->getInProgressMigration($ownerType, $ownerId);
            return true;
        } catch (DoesNotExistException) {
            return false;
        }
    }//end isWriteLocked()
}//end class
