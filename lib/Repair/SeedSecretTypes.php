<?php

/**
 * Doriath Seed Secret Types Repair Step
 *
 * Seeds the 6 system-scoped SecretTypes on install/upgrade.
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
 * Seeds the 6 system-scoped SecretTypes. Idempotent — skips types that already exist.
 */
class SeedSecretTypes implements IRepairStep
{

    /**
     * System secret types to seed: name => label.
     *
     * @var array<string,string>
     */
    private const SYSTEM_TYPES = [
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
     * @param SecretTypeMapper $secretTypeMapper The secret type mapper
     * @param LoggerInterface  $logger           The logger interface
     *
     * @return void
     */
    public function __construct(
        private SecretTypeMapper $secretTypeMapper,
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
     * Run the repair step to seed system secret types.
     *
     * @param IOutput $output The output interface for progress reporting
     *
     * @return void
     */
    public function run(IOutput $output): void
    {
        $output->info('Seeding Doriath system secret types...');

        $namespaceUuid = Uuid::uuid5(Uuid::NAMESPACE_DNS, 'doriath.conduction.nl');

        foreach (self::SYSTEM_TYPES as $name => $label) {
            try {
                $this->secretTypeMapper->findByName(name: $name);
                $output->info("Secret type '{$name}' already exists, skipping");
                continue;
            } catch (DoesNotExistException) {
                // Type does not exist yet — create it.
            }

            $typeId = Uuid::uuid5($namespaceUuid, $name)->toString();

            $secretType = new SecretType();
            $secretType->setId($typeId);
            $secretType->setName($name);
            $secretType->setLabel($label);
            $secretType->setScope('system');
            $secretType->setCreatedAt(new DateTime());

            $this->secretTypeMapper->insert($secretType);

            $output->info("Created system secret type '{$name}' ({$typeId})");
            $this->logger->info(
                'Doriath: seeded system secret type',
                [
                    'name' => $name,
                    'id'   => $typeId,
                ]
            );
        }//end foreach

        $output->info('System secret types seeded successfully');
    }//end run()
}//end class
