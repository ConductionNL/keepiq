<?php

/**
 * Doriath Seed Development Secrets Repair Step
 *
 * Creates example folders and secrets for the dev user. Debug-only.
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
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\Folder;
use OCA\Doriath\Db\FolderMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\SecretTypeMapper;
use OCA\Doriath\Service\EncryptService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Development seed data: creates example folders and secrets for the dev user.
 * Only runs when Nextcloud debug mode is enabled.
 */
class SeedDevelopmentSecrets implements IRepairStep
{

    private const DEV_USER_ID = 'admin';

    /**
     * Constructor for SeedDevelopmentSecrets.
     *
     * @param SecretMapper          $secretMapper          The secret mapper
     * @param SecretTypeMapper      $secretTypeMapper      The secret type mapper
     * @param FolderMapper          $folderMapper          The folder mapper
     * @param EncryptionSuiteMapper $encryptionSuiteMapper The encryption suite mapper
     * @param EncryptService        $encryptService        The encrypt service
     * @param IConfig               $config                The config interface
     * @param LoggerInterface       $logger                The logger interface
     *
     * @return void
     */
    public function __construct(
        private SecretMapper $secretMapper,
        private SecretTypeMapper $secretTypeMapper,
        private FolderMapper $folderMapper,
        private EncryptionSuiteMapper $encryptionSuiteMapper,
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
        return 'Seed Doriath development secrets (debug only)';
    }//end getName()

    /**
     * Run the repair step to seed development secrets and folders.
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

        $output->info('Seeding Doriath development secrets...');

        // Fetch the dev user's active EncryptionSuite.
        try {
            $suite = $this->encryptionSuiteMapper->findActiveByOwner(
                ownerType: 'user',
                ownerId: self::DEV_USER_ID
            );
        } catch (DoesNotExistException) {
            $output->warning('No active EncryptionSuite found for dev user — run SeedDevelopmentData first');
            return;
        }

        $certificate = $suite->getCertificate();
        $suiteId     = $suite->getId();

        // Check if dev secrets already exist.
        $existingSecrets = $this->secretMapper->findByOwner(
            ownerType: 'user',
            ownerId: self::DEV_USER_ID,
            limit: 1
        );

        if (count($existingSecrets) > 0) {
            $output->info('Dev secrets already exist, skipping');
            return;
        }

        // Create folders.
        $workFolder     = $this->createFolder(name: 'Work', output: $output);
        $personalFolder = $this->createFolder(name: 'Personal', output: $output);

        // Look up secret types (fall back to 'login' if a type is not yet seeded).
        $typeIds = $this->resolveTypeIds(output: $output);

        // Seed 6 example secrets.
        $secretDefinitions = [
            [
                'name'   => 'GitHub',
                'type'   => 'login',
                'folder' => $workFolder->getId(),
                'url'    => 'github.com',
                'login'  => 'dev-login',
                'key'    => 'dev-password',
                'fields' => null,
            ],
            [
                'name'   => 'AWS Console',
                'type'   => 'api_key',
                'folder' => $workFolder->getId(),
                'url'    => 'aws.amazon.com',
                'login'  => null,
                'key'    => 'AKIAIOSFODNN7EXAMPLE',
                'fields' => null,
            ],
            [
                'name'   => 'Production Database',
                'type'   => 'database',
                'folder' => $workFolder->getId(),
                'url'    => null,
                'login'  => 'db_admin',
                'key'    => 'db-secret-password',
                'fields' => '{"host":"db.example.com","port":"5432","database":"prod"}',
            ],
            [
                'name'   => 'SSH Deploy Key',
                'type'   => 'ssh_key',
                'folder' => $workFolder->getId(),
                'url'    => null,
                'login'  => null,
                'key'    => '-----BEGIN OPENSSH PRIVATE KEY-----\nexample\n-----END OPENSSH PRIVATE KEY-----',
                'fields' => null,
            ],
            [
                'name'   => 'TLS Certificate',
                'type'   => 'certificate',
                'folder' => $workFolder->getId(),
                'url'    => null,
                'login'  => null,
                'key'    => '-----BEGIN CERTIFICATE-----\nexample\n-----END CERTIFICATE-----',
                'fields' => null,
            ],
            [
                'name'   => 'Server Room WiFi',
                'type'   => 'note',
                'folder' => $personalFolder->getId(),
                'url'    => null,
                'login'  => null,
                'key'    => null,
                'fields' => '{"note":"SSID: ServerRoom\nPassword: ultra-secret-wifi-pw"}',
            ],
        ];

        foreach ($secretDefinitions as $definition) {
            try {
                $this->createSecret(
                    definition: $definition,
                    typeIds: $typeIds,
                    certificate: $certificate,
                    suiteId: $suiteId,
                    output: $output
                );
            } catch (Exception $e) {
                $output->warning('Failed to create dev secret "'.$definition['name'].'": '.$e->getMessage());
                $this->logger->warning(
                    'Doriath dev seed: secret creation failed',
                    [
                        'name'      => $definition['name'],
                        'exception' => $e->getMessage(),
                    ]
                );
            }
        }//end foreach

        $output->info('Development secrets seeded successfully');
    }//end run()

    /**
     * Create a folder for the dev user.
     *
     * @param string  $name   The folder name
     * @param IOutput $output The output interface
     *
     * @return Folder
     */
    private function createFolder(string $name, IOutput $output): Folder
    {
        $folder = new Folder();
        $folder->setId(Uuid::uuid4()->toString());
        $folder->setName($name);
        $folder->setOwnerType('user');
        $folder->setOwnerId(self::DEV_USER_ID);
        $folder->setCreatedAt(new DateTime());
        $folder->setUpdatedAt(new DateTime());

        $this->folderMapper->insert($folder);

        $output->info("Created dev folder '{$name}'");

        return $folder;
    }//end createFolder()

    /**
     * Resolve type name to ID map from the database.
     *
     * Falls back gracefully if a type has not been seeded yet.
     *
     * @param IOutput $output The output interface
     *
     * @return array<string,string>
     */
    private function resolveTypeIds(IOutput $output): array
    {
        $typeNames = ['login', 'api_key', 'ssh_key', 'certificate', 'note', 'database'];
        $typeIds   = [];
        $fallback  = null;

        foreach ($typeNames as $typeName) {
            try {
                $secretType         = $this->secretTypeMapper->findByName(name: $typeName);
                $typeIds[$typeName] = $secretType->getId();

                if ($fallback === null) {
                    $fallback = $secretType->getId();
                }
            } catch (DoesNotExistException) {
                $output->warning("Secret type '{$typeName}' not found — run SeedSecretTypes first");
            }
        }//end foreach

        // Fill missing types with the fallback so no secret is left without a type ID.
        foreach ($typeNames as $typeName) {
            if (isset($typeIds[$typeName]) === false && $fallback !== null) {
                $typeIds[$typeName] = $fallback;
            }
        }

        return $typeIds;
    }//end resolveTypeIds()

    /**
     * Create a single dev secret with encrypted fields.
     *
     * @param array<string,mixed>  $definition  The secret definition
     * @param array<string,string> $typeIds     The type name to ID map
     * @param string               $certificate The dev user's public certificate PEM
     * @param string               $suiteId     The dev user's EncryptionSuite ID
     * @param IOutput              $output      The output interface
     *
     * @return void
     */
    private function createSecret(
        array $definition,
        array $typeIds,
        string $certificate,
        string $suiteId,
        IOutput $output
    ): void {
        $typeName = $definition['type'];
        $typeId   = ($typeIds[$typeName] ?? null);

        if ($typeId === null) {
            $output->warning("Cannot create secret '{$definition['name']}': no type ID available");
            return;
        }

        $encryptedKey    = null;
        $encryptedLogin  = null;
        $encryptedFields = null;

        if ($definition['key'] !== null) {
            $encryptedKey = $this->encryptService->rsaEncrypt(
                plaintext: $definition['key'],
                publicKeyPem: $certificate
            );
        }

        if ($definition['login'] !== null) {
            $encryptedLogin = $this->encryptService->rsaEncrypt(
                plaintext: $definition['login'],
                publicKeyPem: $certificate
            );
        }

        if ($definition['fields'] !== null) {
            $encryptedFields = $this->encryptService->rsaEncrypt(
                plaintext: $definition['fields'],
                publicKeyPem: $certificate
            );
        }

        $secret = new Secret();
        $secret->setId(Uuid::uuid4()->toString());
        $secret->setName($definition['name']);
        $secret->setUrl($definition['url']);
        $secret->setTypeId($typeId);
        $secret->setFolderId($definition['folder']);
        $secret->setKey($encryptedKey);
        $secret->setLogin($encryptedLogin);
        $secret->setAdditionalFields($encryptedFields);
        $secret->setEncryptionSuiteId($suiteId);
        $secret->setOwnerType('user');
        $secret->setOwnerId(self::DEV_USER_ID);
        $secret->setCreatedAt(new DateTime());
        $secret->setUpdatedAt(new DateTime());

        $this->secretMapper->insert($secret);

        $output->info("Created dev secret '{$definition['name']}'");
    }//end createSecret()
}//end class
