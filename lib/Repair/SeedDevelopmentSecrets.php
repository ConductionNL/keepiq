<?php

/**
 * Doriath Seed Development Secrets Repair Step
 *
 * Creates realistic example secrets and two folders for the development
 * user's vault. The sensitive fields are RSA-encrypted with the dev user's
 * public certificate, mirroring the production client-side encryption flow.
 * Only runs in debug mode and only when the dev EncryptionSuite exists.
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
 * Development seed data: example secrets and folders for the dev user.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A seed repair step
 *   legitimately wires the suite/secret/folder/type mappers plus the encrypt
 *   service and config to build a realistic encrypted dev vault.
 */
class SeedDevelopmentSecrets implements IRepairStep
{
    /**
     * The development user ID (matches SeedDevelopmentData).
     *
     * @var string
     */
    private const DEV_USER_ID = 'admin';

    /**
     * Constructor for SeedDevelopmentSecrets.
     *
     * @param EncryptionSuiteMapper $suiteMapper    The encryption suite mapper
     * @param SecretMapper          $secretMapper   The secret mapper
     * @param FolderMapper          $folderMapper   The folder mapper
     * @param SecretTypeMapper      $typeMapper     The secret type mapper
     * @param EncryptService        $encryptService The encrypt service
     * @param IConfig               $config         The config interface
     * @param LoggerInterface       $logger         The logger interface
     *
     * @return void
     */
    public function __construct(
        private EncryptionSuiteMapper $suiteMapper,
        private SecretMapper $secretMapper,
        private FolderMapper $folderMapper,
        private SecretTypeMapper $typeMapper,
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

        try {
            $suite = $this->suiteMapper->findActiveByOwner('user', self::DEV_USER_ID);
        } catch (DoesNotExistException) {
            $output->info('Doriath: no dev EncryptionSuite yet, skipping secret seed');
            return;
        }

        // Idempotency: skip if the dev user already has secrets.
        if ($this->secretMapper->countByOwner('user', self::DEV_USER_ID, null) > 0) {
            $output->info('Doriath: dev user already has secrets, skipping');
            return;
        }

        $certificate = $suite->getCertificate();
        $suiteId     = $suite->getId();

        $typeIds = $this->resolveTypeIds();
        if ($typeIds === []) {
            $output->info('Doriath: system types not seeded yet, skipping secret seed');
            return;
        }

        $workId     = $this->createFolder(name: 'Work', parentId: null);
        $personalId = $this->createFolder(name: 'Personal', parentId: null);

        $secrets = [
            [
                'name'   => 'GitHub',
                'url'    => 'https://github.com',
                'type'   => 'login',
                'key'    => 'gh_dev_P@ssw0rd!2024',
                'login'  => 'dev-user',
                'folder' => $workId,
            ],
            [
                'name'   => 'AWS Console',
                'url'    => 'https://aws.amazon.com',
                'type'   => 'api_key',
                'key'    => 'AKIAIOSFODNN7EXAMPLE',
                'login'  => 'dev-access-key',
                'folder' => $workId,
            ],
            [
                'name'   => 'Production Database',
                'url'    => 'postgresql://db.internal:5432/prod',
                'type'   => 'database',
                'key'    => 'Pr0d-DB-$ecret!',
                'login'  => 'app_service',
                'folder' => null,
            ],
            [
                'name'   => 'SSH Deploy Key',
                'url'    => 'ssh://git@github.com',
                'type'   => 'ssh_key',
                'key'    => "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEA...",
                'login'  => 'deploy',
                'folder' => null,
            ],
            [
                'name'   => 'TLS Wildcard Certificate',
                'url'    => 'https://example.com',
                'type'   => 'certificate',
                'key'    => "-----BEGIN CERTIFICATE-----\nMIIE...",
                'login'  => '*.example.com',
                'folder' => null,
            ],
            [
                'name'   => 'Server Room WiFi',
                'url'    => null,
                'type'   => 'note',
                'key'    => 'Combination: 42-17-89. Door code: 5523#',
                'login'  => null,
                'folder' => $personalId,
            ],
        ];

        $count = 0;
        foreach ($secrets as $spec) {
            $this->createSecret(spec: $spec, typeIds: $typeIds, certificate: $certificate, suiteId: $suiteId);
            $count++;
        }

        $output->info('Doriath: seeded '.$count.' development secrets in 2 folders');
        $this->logger->info('Doriath dev seed: created '.$count.' secrets');
    }//end run()

    /**
     * Build a name => id map of the system secret types.
     *
     * @return array<string,string>
     */
    private function resolveTypeIds(): array
    {
        $map = [];
        foreach ($this->typeMapper->findSystemTypes() as $type) {
            $map[$type->getName()] = $type->getId();
        }

        return $map;
    }//end resolveTypeIds()

    /**
     * Create a root-level dev folder and return its ID.
     *
     * @param string      $name     The folder name
     * @param string|null $parentId The parent folder ID
     *
     * @return string The created folder ID
     */
    private function createFolder(string $name, ?string $parentId): string
    {
        $now    = new DateTime();
        $folder = new Folder();
        $folder->setId(Uuid::uuid4()->toString());
        $folder->setName($name);
        $folder->setParentId($parentId);
        $folder->setOwnerType('user');
        $folder->setOwnerId(self::DEV_USER_ID);
        $folder->setCreatedAt($now);
        $folder->setUpdatedAt($now);

        $this->folderMapper->insert($folder);

        return $folder->getId();
    }//end createFolder()

    /**
     * Create a single encrypted dev secret.
     *
     * @param array<string,mixed>  $spec        The secret specification
     * @param array<string,string> $typeIds     The system type name => id map
     * @param string               $certificate The dev user's public certificate PEM
     * @param string               $suiteId     The encryption suite ID
     *
     * @return void
     */
    private function createSecret(array $spec, array $typeIds, string $certificate, string $suiteId): void
    {
        $now    = new DateTime();
        $secret = new Secret();
        $secret->setId(Uuid::uuid4()->toString());
        $secret->setName((string) $spec['name']);
        $secret->setUrl($spec['url']);
        $secret->setTypeId($typeIds[$spec['type']] ?? $typeIds['login']);
        $secret->setFolderId($spec['folder']);
        $secret->setKey($this->encryptService->rsaEncrypt((string) $spec['key'], $certificate));

        $secret->setLogin(null);
        if ($spec['login'] !== null) {
            $secret->setLogin($this->encryptService->rsaEncrypt((string) $spec['login'], $certificate));
        }

        $secret->setAdditionalFields(null);
        $secret->setEncryptionSuiteId($suiteId);
        $secret->setOwnerType('user');
        $secret->setOwnerId(self::DEV_USER_ID);
        $secret->setCreatedAt($now);
        $secret->setUpdatedAt($now);

        $this->secretMapper->insert($secret);
    }//end createSecret()
}//end class
