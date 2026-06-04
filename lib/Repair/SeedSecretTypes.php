<?php

/**
 * Doriath Seed Secret Types Repair Step
 *
 * Idempotently seeds the 6 immutable system SecretTypes on install and
 * upgrade, using deterministic (UUID v5) identifiers so the IDs are stable
 * across instances and re-runs.
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
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Seeds the 6 immutable system SecretTypes.
 */
class SeedSecretTypes implements IRepairStep
{
    /**
     * The fixed namespace UUID for deterministic system-type IDs.
     *
     * @var string
     */
    public const TYPE_NAMESPACE = '6f9619ff-8b86-d011-b42d-00c04fc964ff';

    /**
     * The 6 system types as name => label pairs.
     *
     * @var array<string,string>
     */
    public const SYSTEM_TYPES = [
        'login'       => 'Login',
        'api_key'     => 'API Key',
        'ssh_key'     => 'SSH Key',
        'certificate' => 'Certificate',
        'note'        => 'Secure Note',
        'database'    => 'Database',
    ];

    /**
     * Constructor for SeedSecretTypes.
     *
     * @param SecretTypeMapper $mapper The secret type mapper
     * @param LoggerInterface  $logger The logger interface
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
     * Compute the deterministic UUID v5 for a system type name.
     *
     * @param string $name The system type name
     *
     * @return string The deterministic UUID
     */
    public static function deterministicId(string $name): string
    {
        return Uuid::uuid5(self::TYPE_NAMESPACE, 'doriath:secret-type:'.$name)->toString();
    }//end deterministicId()

    /**
     * Run the repair step, upserting each system type idempotently.
     *
     * @param IOutput $output The output interface for progress reporting
     *
     * @return void
     */
    public function run(IOutput $output): void
    {
        $created = 0;

        foreach (self::SYSTEM_TYPES as $name => $label) {
            try {
                $this->mapper->findByName($name);
                // Already exists — leave it untouched (immutable system type).
                continue;
            } catch (DoesNotExistException) {
                // Not present yet — create it.
            }

            $type = new SecretType();
            $type->setId(self::deterministicId(name: $name));
            $type->setName($name);
            $type->setLabel($label);
            $type->setScope('system');
            $type->setOwnerId(null);
            $type->setCreatedAt(new DateTime());

            $this->mapper->insert($type);
            $created++;
        }

        $output->info('Doriath: seeded '.$created.' system secret types ('.count(self::SYSTEM_TYPES).' total)');
        $this->logger->info('Doriath: SeedSecretTypes created '.$created.' new system types');
    }//end run()
}//end class
