<?php

/**
 * Doriath Seed Development Applications Repair Step
 *
 * Creates example registered applications for development: one active
 * internal app, one pending external app, and one active external app.
 * Active applications receive a generated EncryptionSuite; the generated
 * private keys are logged to the debug log for development use only.
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
use Exception;
use OCA\Doriath\Db\Application;
use OCA\Doriath\Db\ApplicationMapper;
use OCA\Doriath\Service\EncryptionSuiteService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Development seed data: creates example registered applications.
 * Only runs when Nextcloud debug mode is enabled.
 */
class SeedDevelopmentApplications implements IRepairStep
{
    /**
     * UUID v5 namespace for deterministic application IDs.
     *
     * @var string
     */
    private const NAMESPACE_UUID = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    /**
     * The dev admin user ID used as approver for seeded active apps.
     *
     * @var string
     */
    private const DEV_ADMIN = 'admin';

    /**
     * Constructor for SeedDevelopmentApplications.
     *
     * @param ApplicationMapper      $mapper       The application mapper
     * @param EncryptionSuiteService $suiteService The encryption suite service
     * @param IConfig                $config       The config interface
     * @param LoggerInterface        $logger       The logger interface
     *
     * @return void
     */
    public function __construct(
        private ApplicationMapper $mapper,
        private EncryptionSuiteService $suiteService,
        private IConfig $config,
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
        return 'Seed Doriath development applications (debug only)';
    }//end getName()

    /**
     * Run the repair step to seed development applications.
     *
     * Idempotent: applications are keyed by deterministic v5 UUIDs and
     * skipped when already present.
     *
     * @param IOutput $output The output interface for progress reporting
     *
     * @return void
     */
    public function run(IOutput $output): void
    {
        if ($this->config->getSystemValueBool('debug', false) === false) {
            return;
        }

        $output->info('Seeding Doriath development applications...');

        $this->seedActive(name: 'OpenConnector Dev', type: 'internal', output: $output);
        $this->seedPending(name: 'CI Pipeline Bot', type: 'external', output: $output);
        $this->seedActive(name: 'Monitoring Agent', type: 'external', output: $output);
    }//end run()

    /**
     * Seed an active application with a generated EncryptionSuite.
     *
     * @param string  $name   The application name
     * @param string  $type   The application type
     * @param IOutput $output The output interface
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UndefinedVariable) openssl_pkey_export populates $privateKeyPem
     *   via by-reference output param — PHPMD cannot trace by-ref semantics.
     */
    private function seedActive(string $name, string $type, IOutput $output): void
    {
        $id = $this->deterministicId(name: $name);
        if ($this->exists(id: $id) === true) {
            $output->info("Application '{$name}' already seeded, skipping");
            return;
        }

        $application = new Application();
        $application->setId($id);
        $application->setName($name);
        $application->setDescription('Development seed application');
        $application->setType($type);
        $application->setStatus('active');
        $application->setRegisteredBy(self::DEV_ADMIN);
        $application->setApprovedBy(self::DEV_ADMIN);
        $application->setCreatedAt(new DateTime());
        $application->setApprovedAt(new DateTime());
        $this->mapper->insert($application);

        $keyPair = openssl_pkey_new(
            options: [
                'private_key_bits' => 4096,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]
        );
        if ($keyPair === false) {
            $output->warning("Failed to generate key pair for '{$name}'");
            return;
        }

        openssl_pkey_export(key: $keyPair, output: $privateKeyPem);
        $details = openssl_pkey_get_details(key: $keyPair);

        try {
            $this->suiteService->createSuiteForApplication(
                applicationId: $id,
                publicKeyPem: $details['key'],
                commonName: 'app:'.$id
            );
        } catch (Exception $e) {
            $output->warning("CA not available for '{$name}': ".$e->getMessage());
            return;
        }

        $output->info("Seeded active application '{$name}'");
        $this->logger->info("Doriath dev seed: application '{$name}' private key (dev only):\n".$privateKeyPem);
    }//end seedActive()

    /**
     * Seed a pending application with no EncryptionSuite.
     *
     * @param string  $name   The application name
     * @param string  $type   The application type
     * @param IOutput $output The output interface
     *
     * @return void
     */
    private function seedPending(string $name, string $type, IOutput $output): void
    {
        $id = $this->deterministicId(name: $name);
        if ($this->exists(id: $id) === true) {
            $output->info("Application '{$name}' already seeded, skipping");
            return;
        }

        $application = new Application();
        $application->setId($id);
        $application->setName($name);
        $application->setDescription('Development seed application (pending approval)');
        $application->setType($type);
        $application->setStatus('pending');
        $application->setRegisteredBy(null);
        $application->setCreatedAt(new DateTime());
        $this->mapper->insert($application);

        $output->info("Seeded pending application '{$name}'");
    }//end seedPending()

    /**
     * Determine whether an application with the given ID already exists.
     *
     * @param string $id The application ID
     *
     * @return bool
     */
    private function exists(string $id): bool
    {
        try {
            $this->mapper->findById($id);
            return true;
        } catch (DoesNotExistException) {
            return false;
        }
    }//end exists()

    /**
     * Build a deterministic v5 UUID for an application name.
     *
     * @param string $name The application name
     *
     * @return string The UUID string
     */
    private function deterministicId(string $name): string
    {
        return Uuid::uuid5(self::NAMESPACE_UUID, 'doriath:application:'.$name)->toString();
    }//end deterministicId()
}//end class
