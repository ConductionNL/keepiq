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
        $migration->setId($this->generateUuid());
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

        // Mark the old suite as compromised.
        $oldSuite = $this->suiteMapper->findById($migration->getOldSuiteId());
        $oldSuite->setStatus('compromised');
        $this->suiteMapper->update($oldSuite);

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
     * Generate a version-4 UUID string.
     *
     * @return string
     */
    private function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }//end generateUuid()
}//end class
