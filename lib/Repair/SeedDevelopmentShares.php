<?php

/**
 * Doriath Seed Development Shares Repair Step
 *
 * Creates example user-to-user share rows for the development vault so the
 * sharing UI (RecipientList, GroupShareList, DelegationManager) has data
 * to render without requiring a real owner to drive the share flow.
 *
 * The seeded rows carry placeholder recipient secret copies — they will not
 * decrypt under a real recipient login because there is only one dev
 * EncryptionSuite (`admin`) — but they ARE sufficient to drive the
 * owner-facing management UI: the list endpoint, the revoke action, the
 * group-share fan-out, and the delegation list/reclaim flow.
 *
 * Debug-only. Idempotent via `findBySourceSecret()` precheck on the first
 * dev secret.
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
use OCA\Doriath\Db\GroupShare;
use OCA\Doriath\Db\GroupShareMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\ShareTarget;
use OCA\Doriath\Db\ShareTargetMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Seed example ShareTarget + GroupShare rows for the dev vault.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Seeds couple the secret
 *   + share-target + group-share mappers, the suite mapper, IConfig and the
 *   logger by design.
 *
 * @spec openspec/changes/implement-user-sharing/tasks.md#task-1.4
 */
class SeedDevelopmentShares implements IRepairStep
{
    /**
     * The development user ID (matches SeedDevelopmentData).
     *
     * @var string
     */
    private const DEV_USER_ID = 'admin';

    /**
     * The placeholder recipient user IDs. These users do NOT need to
     * exist on the instance for the rows to render — the seed targets
     * the owner-facing management UI.
     *
     * @var string
     */
    private const DEV_RECIPIENT_DIRECT = 'dev-user-2';

    /**
     * @var string
     */
    private const DEV_RECIPIENT_GROUP_MEMBER = 'dev-user-3';

    /**
     * The placeholder dev group ID. Mirrors the GroupShare seed in
     * §1.4 — a single GroupShare against a fictional dev-group-1 with
     * one fan-out ShareTarget row for the placeholder member.
     *
     * @var string
     */
    private const DEV_GROUP_ID = 'dev-group-1';

    /**
     * Constructor.
     *
     * @param SecretMapper          $secretMapper      The secret mapper
     * @param ShareTargetMapper     $shareTargetMapper The share-target mapper
     * @param GroupShareMapper      $groupShareMapper  The group-share mapper
     * @param EncryptionSuiteMapper $suiteMapper       The suite mapper
     * @param IConfig               $config            The config
     * @param LoggerInterface       $logger            The logger
     *
     * @return void
     */
    public function __construct(
        private SecretMapper $secretMapper,
        private ShareTargetMapper $shareTargetMapper,
        private GroupShareMapper $groupShareMapper,
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
        return 'Seed Doriath development user shares (debug only)';
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
            $output->info('Doriath: no dev EncryptionSuite, skipping user-share seed');
            return;
        }

        $secrets = $this->secretMapper->findByOwner('user', self::DEV_USER_ID);
        if ($secrets === []) {
            $output->info('Doriath: no dev secrets, skipping user-share seed');
            return;
        }

        // Idempotency: skip if the first dev secret already has a share.
        $first = $secrets[0];
        if ($this->shareTargetMapper->findBySourceSecret($first->getId()) !== []) {
            $output->info('Doriath: dev user shares already seeded, skipping');
            return;
        }

        $seeded = 0;

        // Direct share #1: first secret (GitHub) shared to dev-user-2.
        $seeded += $this->seedDirectShare(
            id: $this->deterministicId('share_direct_01'),
            source: $first,
            targetUser: self::DEV_RECIPIENT_DIRECT,
        );

        // Direct share #2 — second secret (AWS) shared to dev-user-2.
        if (count($secrets) >= 2) {
            $seeded += $this->seedDirectShare(
                id: $this->deterministicId('share_direct_02'),
                source: $secrets[1],
                targetUser: self::DEV_RECIPIENT_DIRECT,
            );
        }

        // Group share — third secret (Production Database) shared with dev-group-1.
        if (count($secrets) >= 3) {
            $groupShareId = $this->deterministicId('share_group_01');
            $seeded      += $this->seedGroupShare(
                id: $groupShareId,
                source: $secrets[2],
                groupId: self::DEV_GROUP_ID,
            );

            // Fan-out a single ShareTarget row for the placeholder
            // member so the cascade-revoke + member-leave paths have
            // something to operate on in the dev vault.
            $seeded += $this->seedFanOutShareTarget(
                id: $this->deterministicId('share_group_member_01'),
                source: $secrets[2],
                targetUser: self::DEV_RECIPIENT_GROUP_MEMBER,
                groupShareId: $groupShareId,
            );
        }

        $output->info('Doriath: seeded '.$seeded.' development user shares');
        $this->logger->info('Doriath dev seed: created '.$seeded.' user share rows');
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
        return Uuid::uuid5(Uuid::NAMESPACE_OID, 'doriath:user-share:'.$seed)->toString();
    }//end deterministicId()

    /**
     * Insert a direct user-to-user ShareTarget row (no group_share_id).
     *
     * @param string $id         The deterministic ID
     * @param Secret $source     The source secret
     * @param string $targetUser The placeholder recipient user ID
     *
     * @return int Rows inserted (always 1).
     */
    private function seedDirectShare(string $id, Secret $source, string $targetUser): int
    {
        $row = new ShareTarget();
        $row->setId($id);
        $row->setSourceSecretId($source->getId());
        $row->setTargetUserId($targetUser);
        // Placeholder recipient secret copy id — the production flow
        // creates a real Secret row encrypted to the recipient's public
        // key, but the dev seed has no recipient key material, so the
        // copy id is left as a deterministic placeholder.
        $row->setSecretId($this->deterministicId('copy:'.$source->getId().':'.$targetUser));
        $row->setGroupShareId(null);
        $row->setCreatedBy(self::DEV_USER_ID);
        $row->setCreatedAt(new DateTime());

        $this->shareTargetMapper->insert($row);
        return 1;
    }//end seedDirectShare()

    /**
     * Insert a GroupShare row.
     *
     * @param string $id      The deterministic ID
     * @param Secret $source  The source secret
     * @param string $groupId The placeholder group ID
     *
     * @return int Rows inserted (always 1).
     */
    private function seedGroupShare(string $id, Secret $source, string $groupId): int
    {
        $row = new GroupShare();
        $row->setId($id);
        $row->setSecretId($source->getId());
        $row->setGroupId($groupId);
        $row->setCreatedBy(self::DEV_USER_ID);
        $row->setCreatedAt(new DateTime());

        $this->groupShareMapper->insert($row);
        return 1;
    }//end seedGroupShare()

    /**
     * Insert a fan-out ShareTarget for a GroupShare (one row per member).
     *
     * @param string $id           The deterministic ID
     * @param Secret $source       The source secret
     * @param string $targetUser   The placeholder member user ID
     * @param string $groupShareId The GroupShare row ID
     *
     * @return int Rows inserted (always 1).
     */
    private function seedFanOutShareTarget(
        string $id,
        Secret $source,
        string $targetUser,
        string $groupShareId,
    ): int {
        $row = new ShareTarget();
        $row->setId($id);
        $row->setSourceSecretId($source->getId());
        $row->setTargetUserId($targetUser);
        $row->setSecretId($this->deterministicId('copy:'.$source->getId().':'.$targetUser));
        $row->setGroupShareId($groupShareId);
        $row->setCreatedBy(self::DEV_USER_ID);
        $row->setCreatedAt(new DateTime());

        $this->shareTargetMapper->insert($row);
        return 1;
    }//end seedFanOutShareTarget()
}//end class
