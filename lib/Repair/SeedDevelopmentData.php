<?php

/**
 * Doriath Seed Development Data Repair Step
 *
 * Creates a test user EncryptionSuite with a known master password for development.
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
use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Service\CertificateAuthorityService;
use OCA\Doriath\Service\EncryptService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use Ramsey\Uuid\Uuid;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Development seed data: creates a test user EncryptionSuite with a known master password.
 * Only runs when Nextcloud debug mode is enabled.
 */
class SeedDevelopmentData implements IRepairStep
{
    private const DEV_USER_ID         = 'admin';
    private const DEV_MASTER_PASSWORD = 'Oj';

    /**
     * Constructor for SeedDevelopmentData.
     *
     * @param EncryptionSuiteMapper       $suiteMapper    The encryption suite mapper
     * @param CertificateAuthorityService $caService      The CA service
     * @param EncryptService              $encryptService The encrypt service
     * @param IConfig                     $config         The config interface
     * @param LoggerInterface             $logger         The logger interface
     *
     * @return void
     */
    public function __construct(
        private EncryptionSuiteMapper $suiteMapper,
        private CertificateAuthorityService $caService,
        private EncryptService $encryptService,
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
        return 'Seed Doriath development data (debug only)';
    }//end getName()

    /**
     * Run the repair step to seed development data.
     *
     * @param IOutput $output The output interface for progress reporting
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UndefinedVariable) openssl_pkey_export populates $privateKeyPem
     *   via by-reference output param — PHPMD cannot trace by-ref semantics.
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-6
     */
    public function run(IOutput $output): void
    {
        if ($this->config->getSystemValueBool('debug', false) === false) {
            return;
        }

        $output->info('Seeding Doriath development data...');

        // Check if dev user already has a suite.
        try {
            $this->suiteMapper->findActiveByOwner(ownerType: 'user', ownerId: self::DEV_USER_ID);
            $output->info('Dev user already has an EncryptionSuite, skipping');
            return;
        } catch (DoesNotExistException) {
            // Good — no suite yet.
        }

        // Generate RSA key pair.
        $keyPair = openssl_pkey_new(
            options: [
                'private_key_bits' => 4096,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]
        );

        if ($keyPair === false) {
            $output->warning('Failed to generate RSA key pair for dev seed');
            return;
        }

        openssl_pkey_export(key: $keyPair, output: $privateKeyPem);
        $keyDetails   = openssl_pkey_get_details(key: $keyPair);
        $publicKeyPem = $keyDetails['key'];

        // Sign the public key with the CA.
        try {
            $certificate = $this->caService->signPublicKey(
                publicKeyPem: $publicKeyPem,
                commonName: self::DEV_USER_ID,
                privateKeyPem: $privateKeyPem
            );
        } catch (Exception $e) {
            $output->warning('CA not available for dev seed: '.$e->getMessage());
            return;
        }

        // Encrypt the private key with the dev master password.
        $encryptedPrivateKey = $this->encryptService->encryptPrivateKey(
            pem: $privateKeyPem,
            password: self::DEV_MASTER_PASSWORD
        );

        // Create the EncryptionSuite.
        $suite = new EncryptionSuite();
        $suite->setId(Uuid::uuid4()->toString());
        $suite->setOwnerType('user');
        $suite->setOwnerId(self::DEV_USER_ID);
        $suite->setCertificate($certificate);
        $suite->setPrivateKey($encryptedPrivateKey);
        $suite->setStatus('active');
        $suite->setCreatedAt(new DateTime());

        $this->suiteMapper->insert($suite);

        $output->info('Dev EncryptionSuite created for user: '.self::DEV_USER_ID);
        $this->logger->info('Doriath dev seed: EncryptionSuite created with master password: '.self::DEV_MASTER_PASSWORD);
    }//end run()
}//end class
