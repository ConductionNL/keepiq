<?php

/**
 * Doriath Seed Secret Types Repair Step
 *
 * Idempotently seeds the 6 immutable system SecretTypes with deterministic
 * UUID v5 identifiers, so the `login` default type has a stable ID on every
 * instance.
 *
 * @category Repair
 * @package  OCA\Doriath\Repair
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

namespace OCA\Doriath\Repair;

use DateTime;
use OCA\Doriath\Db\SecretType;
use OCA\Doriath\Db\SecretTypeMapper;
use OCA\Doriath\Service\SecretTypeService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Seeds the 6 immutable system SecretTypes.
 */
class SeedSecretTypes implements IRepairStep
{
    /**
     * Constructor for SeedSecretTypes.
     *
     * @param SecretTypeMapper $mapper The secret type mapper
     * @param LoggerInterface  $logger The logger
     *
     * @return void
     */
    public function __construct(
        private SecretTypeMapper $mapper,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Seed Doriath system secret types';
    }//end getName()

    /**
     * Run the repair step, upserting each system type by deterministic ID.
     *
     * @param IOutput $output The output interface
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.StaticAccess) SecretTypeService::systemTypeId() is a
     *   pure deterministic UUID v5 helper with no instance state.
     */
    public function run(IOutput $output): void
    {
        $output->info('Seeding Doriath system secret types...');
        $created = 0;

        foreach (SecretTypeService::SYSTEM_TYPES as $name => $label) {
            $id = SecretTypeService::systemTypeId($name);

            try {
                $this->mapper->findById($id);
                continue;
            } catch (DoesNotExistException) {
                // Not present — create it below.
            }

            $type = new SecretType();
            $type->setId($id);
            $type->setName($name);
            $type->setLabel($label);
            $type->setScope('system');
            $type->setOwnerId(null);
            $type->setCreatedAt(new DateTime());

            $this->mapper->insert($type);
            $created++;
        }//end foreach

        $output->info("Doriath system secret types ready ({$created} created)");
        $this->logger->info("Doriath: SeedSecretTypes created {$created} system types");
    }//end run()
}//end class
