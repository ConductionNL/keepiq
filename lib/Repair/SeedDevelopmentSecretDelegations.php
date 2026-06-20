<?php

/**
 * Doriath Seed Development Secret Delegations Repair Step
 *
 * Creates one example SecretDelegation row for the dev vault so the
 * DelegationManager UI has a temporary delegation to render. Debug-only.
 *
 * Per §1.4 — one SecretDelegation with `dev-user-2` as delegate for the
 * first dev secret (GitHub). The row is created with
 * `is_permanent=false` so the reclaim flow has something to operate on.
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
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretDelegation;
use OCA\Doriath\Db\SecretDelegationMapper;
use OCA\Doriath\Db\SecretMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Seed example SecretDelegation rows for the dev vault.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Seeds couple the secret
 *   + delegation mappers, the suite mapper, IConfig and the logger by
 *   design.
 *
 * @spec openspec/changes/implement-user-sharing/tasks.md#task-1.4
 */
class SeedDevelopmentSecretDelegations implements IRepairStep
{
    /**
     * The development user ID.
     *
     * @var string
     */
    private const DEV_USER_ID = 'admin';

    /**
     * The placeholder delegate user ID (mirrors the user-share seed).
     *
     * @var string
     */
    private const DEV_DELEGATE = 'dev-user-2';

    /**
     * Constructor.
     *
     * @param SecretMapper           $secretMapper     The secret mapper
     * @param SecretDelegationMapper $delegationMapper The delegation mapper
     * @param EncryptionSuiteMapper  $suiteMapper      The suite mapper
     * @param IConfig                $config           The config
     * @param LoggerInterface        $logger           The logger
     *
     * @return void
     */
    public function __construct(
        private SecretMapper $secretMapper,
        private SecretDelegationMapper $delegationMapper,
        private EncryptionSuiteMapper $suiteMapper,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Repair step display name.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Seed Doriath development secret delegations (debug only)';
    }//end getName()

    /**
     * Run the repair step.
     *
     * @param IOutput $output The output channel
     *
     * @return void
     */
    public function run(IOutput $output): void
    {
        if ($this->config->getSystemValueBool('debug', false) === false) {
            return;
        }

        try {
            $this->suiteMapper->findActiveByOwner('user', self::DEV_USER_ID);
        } catch (DoesNotExistException) {
            $output->info('Doriath: no dev EncryptionSuite, skipping delegation seed');
            return;
        }

        $secrets = $this->secretMapper->findByOwner('user', self::DEV_USER_ID);
        if ($secrets === []) {
            $output->info('Doriath: no dev secrets, skipping delegation seed');
            return;
        }

        $first = $secrets[0];

        // Idempotency: skip if the first dev secret already has a
        // delegation.
        if ($this->delegationMapper->findBySecret($first->getId()) !== []) {
            $output->info('Doriath: dev delegations already seeded, skipping');
            return;
        }

        $this->seedDelegation(
            id: $this->deterministicId(seed: 'delegation_temp_01'),
            source: $first,
            delegate: self::DEV_DELEGATE,
        );

        $output->info('Doriath: seeded 1 development secret delegation');
        $this->logger->info('Doriath dev seed: created 1 SecretDelegation row');
    }//end run()

    /**
     * Produce a deterministic UUIDv5 from a seed string.
     *
     * @param string $seed The seed
     *
     * @return string
     */
    private function deterministicId(string $seed): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_OID, 'doriath:secret-delegation:'.$seed)->toString();
    }//end deterministicId()

    /**
     * Insert one temporary SecretDelegation row.
     *
     * @param string $id       The deterministic ID
     * @param Secret $source   The source secret
     * @param string $delegate The placeholder delegate user ID
     *
     * @return void
     */
    private function seedDelegation(string $id, Secret $source, string $delegate): void
    {
        $row = new SecretDelegation();
        $row->setId($id);
        $row->setSecretId($source->getId());
        $row->setOriginalOwnerId(self::DEV_USER_ID);
        $row->setDelegatedTo($delegate);
        $row->setDelegatedAt(new DateTime());
        $row->setInitiatedBy(self::DEV_USER_ID);
        $row->setIsPermanent(false);

        $this->delegationMapper->insert($row);
    }//end seedDelegation()
}//end class
