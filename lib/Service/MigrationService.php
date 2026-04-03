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
use Ramsey\Uuid\Uuid;
use OCA\Doriath\Db\SuiteMigration;
use OCA\Doriath\Db\SuiteMigrationMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * Tracks compromise recovery migrations (suite to suite).
 */
class MigrationService
{
    /**
     * Constructor for MigrationService.
     *
     * @param SuiteMigrationMapper  $mapper      The suite migration mapper
     * @param EncryptionSuiteMapper $suiteMapper The encryption suite mapper
     * @param LoggerInterface       $logger      The logger interface
     *
     * @return void
     */
    public function __construct(
        private SuiteMigrationMapper $mapper,
        private EncryptionSuiteMapper $suiteMapper,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Initiate a compromise recovery migration.
     *
     * @param string $oldSuiteId The old suite ID
     * @param string $newSuiteId The new suite ID
     *
     * @return SuiteMigration
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
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
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

        // Note: the old suite is already marked compromised when recovery
        // is initiated (EncryptionSuiteService::markCompromised). We do not
        // set it again here to avoid overwriting audit fields.
        $this->logger->info(
                "Doriath: Compromise recovery completed for migration {$migrationId}",
                [
                    'hasErrors' => $hasErrors,
                ]
                );

        return $migration;
    }//end completeMigration()

    /**
     * Get in-progress migration for a given owner (via their old suite).
     *
     * @param string $ownerType The owner type
     * @param string $ownerId   The owner ID
     *
     * @return SuiteMigration
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
     *
     * @param string $ownerType The owner type
     * @param string $ownerId   The owner ID
     *
     * @return bool
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

    /**
     * Get an EncryptionSuite by ID (for migration status enrichment).
     *
     * @param string $id The suite ID
     *
     * @return \OCA\Doriath\Db\EncryptionSuite
     *
     * @throws DoesNotExistException
     */
    public function getSuiteById(string $id): \OCA\Doriath\Db\EncryptionSuite
    {
        return $this->suiteMapper->findById(id: $id);
    }//end getSuiteById()
}//end class
