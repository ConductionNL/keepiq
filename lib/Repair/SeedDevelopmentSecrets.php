<?php

/**
 * Doriath Seed Development Secrets Repair Step
 *
 * Creates realistic example secrets and folders for the development user,
 * encrypted with that user's public certificate. Only runs in debug mode and
 * only after the dev EncryptionSuite (SeedDevelopmentData) exists.
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
use OCA\Doriath\Db\Folder;
use OCA\Doriath\Db\FolderMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Service\EncryptService;
use OCA\Doriath\Service\SecretTypeService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Seeds example secrets and folders for the development user (debug only).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A debug-only seed step
 *   legitimately wires together the suite, secret and folder mappers plus the
 *   encrypt service to build a realistic example vault in one place.
 */
class SeedDevelopmentSecrets implements IRepairStep
{
    private const DEV_USER_ID = 'admin';

    /**
     * Constructor for SeedDevelopmentSecrets.
     *
     * @param EncryptionSuiteMapper $suiteMapper    The encryption suite mapper
     * @param SecretMapper          $secretMapper   The secret mapper
     * @param FolderMapper          $folderMapper   The folder mapper
     * @param EncryptService        $encryptService The encrypt service
     * @param IConfig               $config         The config interface
     * @param LoggerInterface       $logger         The logger
     *
     * @return void
     */
    public function __construct(
        private EncryptionSuiteMapper $suiteMapper,
        private SecretMapper $secretMapper,
        private FolderMapper $folderMapper,
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
     * Run the repair step.
     *
     * @param IOutput $output The output interface
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
            $output->info('Doriath dev secrets: no dev EncryptionSuite yet, skipping');
            return;
        }

        // Skip if the dev user already has secrets.
        if (empty($this->secretMapper->findAllByOwner('user', self::DEV_USER_ID)) === false) {
            $output->info('Doriath dev secrets: already seeded, skipping');
            return;
        }

        $certificate = $suite->getCertificate();
        $suiteId     = $suite->getId();

        $workId     = $this->createFolder(name: 'Work');
        $personalId = $this->createFolder(name: 'Personal');

        foreach ($this->examples(workId: $workId, personalId: $personalId) as $example) {
            $this->createSecret(example: $example, suiteId: $suiteId, certificate: $certificate);
        }

        $output->info('Doriath dev secrets seeded for '.self::DEV_USER_ID);
        $this->logger->info('Doriath: SeedDevelopmentSecrets seeded example vault');
    }//end run()

    /**
     * Build the example secret definitions.
     *
     * @param string $workId     The Work folder ID
     * @param string $personalId The Personal folder ID
     *
     * @return array<int,array<string,mixed>>
     */
    private function examples(string $workId, string $personalId): array
    {
        return [
            [
                'name'     => 'GitHub',
                'url'      => 'https://github.com',
                'type'     => 'login',
                'key'      => 'gh_dev_P@ssw0rd!2024',
                'login'    => 'dev-user',
                'folderId' => $workId,
            ],
            [
                'name'     => 'AWS Console',
                'url'      => 'https://aws.amazon.com',
                'type'     => 'api_key',
                'key'      => 'AKIAIOSFODNN7EXAMPLE',
                'login'    => 'dev-access-key',
                'folderId' => $workId,
            ],
            [
                'name'     => 'Production Database',
                'url'      => 'postgresql://db.internal:5432/prod',
                'type'     => 'database',
                'key'      => 'Pr0d-DB-$ecret!',
                'login'    => 'app_service',
                'folderId' => null,
            ],
            [
                'name'     => 'SSH Deploy Key',
                'url'      => 'ssh://git@github.com',
                'type'     => 'ssh_key',
                'key'      => "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEA...",
                'login'    => 'deploy',
                'folderId' => null,
            ],
            [
                'name'     => 'TLS Wildcard Certificate',
                'url'      => 'https://example.com',
                'type'     => 'certificate',
                'key'      => "-----BEGIN CERTIFICATE-----\nMIIE...",
                'login'    => '*.example.com',
                'folderId' => null,
            ],
            [
                'name'     => 'Server Room WiFi',
                'url'      => null,
                'type'     => 'note',
                'key'      => 'Combination: 42-17-89. Door code: 5523#',
                'login'    => null,
                'folderId' => $personalId,
            ],
        ];
    }//end examples()

    /**
     * Create a dev folder and return its ID.
     *
     * @param string $name The folder name
     *
     * @return string
     */
    private function createFolder(string $name): string
    {
        $folder = new Folder();
        $folder->setId(Uuid::uuid4()->toString());
        $folder->setName($name);
        $folder->setParentId(null);
        $folder->setOwnerType('user');
        $folder->setOwnerId(self::DEV_USER_ID);
        $folder->setCreatedAt(new DateTime());
        $this->folderMapper->insert($folder);

        return $folder->getId();
    }//end createFolder()

    /**
     * Create a dev secret with encrypted fields.
     *
     * @param array<string,mixed> $example     The example definition
     * @param string              $suiteId     The encryption suite ID
     * @param string              $certificate The public certificate PEM
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.StaticAccess) SecretTypeService::systemTypeId() is a
     *   pure deterministic UUID v5 helper with no instance state.
     */
    private function createSecret(array $example, string $suiteId, string $certificate): void
    {
        $secret = new Secret();
        $secret->setId(Uuid::uuid4()->toString());
        $secret->setName($example['name']);
        $secret->setUrl($example['url']);
        $secret->setTypeId(SecretTypeService::systemTypeId($example['type']));
        $secret->setFolderId($example['folderId']);
        $secret->setSecretKey($this->encryptService->rsaEncrypt($example['key'], $certificate));

        if ($example['login'] !== null) {
            $secret->setLogin($this->encryptService->rsaEncrypt($example['login'], $certificate));
        }

        $secret->setEncryptionSuiteId($suiteId);
        $secret->setOwnerType('user');
        $secret->setOwnerId(self::DEV_USER_ID);
        $secret->setCreatedAt(new DateTime());

        $this->secretMapper->insert($secret);
    }//end createSecret()
}//end class
