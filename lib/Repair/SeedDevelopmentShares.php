<?php

/**
 * Keepiq Seed Development Shares Repair Step
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
 * Debug-only. Idempotent via `findById()` prechecks on the deterministic
 * row IDs, plus an app-version marker in app config.
 *
 * @category Repair
 * @package  OCA\Keepiq\Repair
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

namespace OCA\Keepiq\Repair;

use DateTime;
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\GroupShare;
use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
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
class SeedDevelopmentShares implements IRepairStep {
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
	 * The placeholder group-member recipient user ID.
	 *
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
	 * App-config marker storing the app version this seed last ran for.
	 *
	 * The step is registered under <post-migration>, which Nextcloud
	 * executes on every `occ upgrade` / `occ maintenance:repair` — on a
	 * dev instance that is every boot. The marker gates the seed to one
	 * run per installed app version.
	 *
	 * @var string
	 */
	private const SEED_VERSION_KEY = 'dev_seed_user_shares_version';

	/**
	 * Constructor.
	 *
	 * @param SecretMapper $secretMapper The secret mapper
	 * @param ShareTargetMapper $shareTargetMapper The share-target mapper
	 * @param GroupShareMapper $groupShareMapper The group-share mapper
	 * @param EncryptionSuiteMapper $suiteMapper The suite mapper
	 * @param IConfig $config The config
	 * @param IAppConfig $appConfig The app config
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private SecretMapper $secretMapper,
		private ShareTargetMapper $shareTargetMapper,
		private GroupShareMapper $groupShareMapper,
		private EncryptionSuiteMapper $suiteMapper,
		private IConfig $config,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Repair step display name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Seed Keepiq development user shares (debug only)';
	}//end getName()

	/**
	 * Run the repair step.
	 *
	 * @param IOutput $output The output channel
	 *
	 * @return void
	 *
	 * @spec exclude idempotency fix — dev-only seed guard, no spec scenario
	 */
	public function run(IOutput $output): void {
		if ($this->config->getSystemValueBool('debug', false) === false) {
			return;
		}

		// Version gate: <post-migration> repair steps re-run on every
		// upgrade/repair; only seed once per installed app version.
		$appVersion = $this->appConfig->getValueString(Application::APP_ID, 'installed_version', '');
		if ($appVersion !== ''
			&& $this->appConfig->getValueString(Application::APP_ID, self::SEED_VERSION_KEY, '') === $appVersion
		) {
			return;
		}

		try {
			$this->suiteMapper->findActiveByOwner('user', self::DEV_USER_ID);
		} catch (DoesNotExistException) {
			$output->info('Keepiq: no dev EncryptionSuite, skipping user-share seed');
			return;
		}

		$secrets = $this->secretMapper->findByOwner('user', self::DEV_USER_ID);
		if ($secrets === []) {
			$output->info('Keepiq: no dev secrets, skipping user-share seed');
			return;
		}

		$first = $secrets[0];
		$seeded = 0;

		// Direct share #1: first secret (GitHub) shared to dev-user-2.
		$seeded += $this->seedDirectShare(
			id: $this->deterministicId(seed: 'share_direct_01'),
			source: $first,
			targetUser: self::DEV_RECIPIENT_DIRECT,
		);

		// Direct share #2 — second secret (AWS) shared to dev-user-2.
		if (count($secrets) >= 2) {
			$seeded += $this->seedDirectShare(
				id: $this->deterministicId(seed: 'share_direct_02'),
				source: $secrets[1],
				targetUser: self::DEV_RECIPIENT_DIRECT,
			);
		}

		// Group share — third secret (Production Database) shared with dev-group-1.
		if (count($secrets) >= 3) {
			$groupShareId = $this->deterministicId(seed: 'share_group_01');
			$seeded += $this->seedGroupShare(
				id: $groupShareId,
				source: $secrets[2],
				groupId: self::DEV_GROUP_ID,
			);

			// Fan-out a single ShareTarget row for the placeholder
			// member so the cascade-revoke + member-leave paths have
			// something to operate on in the dev vault.
			$seeded += $this->seedFanOutShareTarget(
				id: $this->deterministicId(seed: 'share_group_member_01'),
				source: $secrets[2],
				targetUser: self::DEV_RECIPIENT_GROUP_MEMBER,
				groupShareId: $groupShareId,
			);
		}

		$this->appConfig->setValueString(Application::APP_ID, self::SEED_VERSION_KEY, $appVersion);
		$output->info('Keepiq: seeded ' . $seeded . ' development user shares');
		$this->logger->info('Keepiq dev seed: created ' . $seeded . ' user share rows');
	}//end run()

	/**
	 * Produce a deterministic UUIDv5 from a seed string.
	 *
	 * @param string $seed The seed
	 *
	 * @return string
	 */
	private function deterministicId(string $seed): string {
		return Uuid::uuid5(Uuid::NAMESPACE_OID, 'keepiq:user-share:' . $seed)->toString();
	}//end deterministicId()

	/**
	 * Insert a direct user-to-user ShareTarget row (no group_share_id).
	 *
	 * @param string $id The deterministic ID
	 * @param Secret $source The source secret
	 * @param string $targetUser The placeholder recipient user ID
	 *
	 * @return int Rows inserted (0 or 1).
	 *
	 * @spec exclude idempotency fix — dev-only seed guard, no spec scenario
	 */
	private function seedDirectShare(string $id, Secret $source, string $targetUser): int {
		// Idempotency: the ID is a deterministic UUIDv5, so a pre-existing
		// row means this seed already ran — skip quietly instead of
		// hitting the primary-key constraint.
		try {
			$this->shareTargetMapper->findById($id);
			$this->logger->debug('Keepiq dev seed: share target ' . $id . ' already exists, skipping');
			return 0;
		} catch (DoesNotExistException) {
			// Not seeded yet — insert below.
		}

		$row = new ShareTarget();
		$row->setId($id);
		$row->setSourceSecretId($source->getId());
		$row->setTargetUserId($targetUser);
		// Placeholder recipient secret copy id — the production flow
		// creates a real Secret row encrypted to the recipient's public
		// key, but the dev seed has no recipient key material, so the
		// copy id is left as a deterministic placeholder.
		$row->setSecretId($this->deterministicId(seed: 'copy:' . $source->getId() . ':' . $targetUser));
		$row->setGroupShareId(null);
		$row->setCreatedBy(self::DEV_USER_ID);
		$row->setCreatedAt(new DateTime());

		$this->shareTargetMapper->insert($row);
		return 1;
	}//end seedDirectShare()

	/**
	 * Insert a GroupShare row.
	 *
	 * @param string $id The deterministic ID
	 * @param Secret $source The source secret
	 * @param string $groupId The placeholder group ID
	 *
	 * @return int Rows inserted (0 or 1).
	 *
	 * @spec exclude idempotency fix — dev-only seed guard, no spec scenario
	 */
	private function seedGroupShare(string $id, Secret $source, string $groupId): int {
		// Idempotency: skip quietly when the deterministic row already exists.
		try {
			$this->groupShareMapper->findById($id);
			$this->logger->debug('Keepiq dev seed: group share ' . $id . ' already exists, skipping');
			return 0;
		} catch (DoesNotExistException) {
			// Not seeded yet — insert below.
		}

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
	 * @param string $id The deterministic ID
	 * @param Secret $source The source secret
	 * @param string $targetUser The placeholder member user ID
	 * @param string $groupShareId The GroupShare row ID
	 *
	 * @return int Rows inserted (0 or 1).
	 *
	 * @spec exclude idempotency fix — dev-only seed guard, no spec scenario
	 */
	private function seedFanOutShareTarget(
		string $id,
		Secret $source,
		string $targetUser,
		string $groupShareId,
	): int {
		// Idempotency: skip quietly when the deterministic row already exists.
		try {
			$this->shareTargetMapper->findById($id);
			$this->logger->debug('Keepiq dev seed: fan-out share target ' . $id . ' already exists, skipping');
			return 0;
		} catch (DoesNotExistException) {
			// Not seeded yet — insert below.
		}

		$row = new ShareTarget();
		$row->setId($id);
		$row->setSourceSecretId($source->getId());
		$row->setTargetUserId($targetUser);
		$row->setSecretId($this->deterministicId(seed: 'copy:' . $source->getId() . ':' . $targetUser));
		$row->setGroupShareId($groupShareId);
		$row->setCreatedBy(self::DEV_USER_ID);
		$row->setCreatedAt(new DateTime());

		$this->shareTargetMapper->insert($row);
		return 1;
	}//end seedFanOutShareTarget()
}//end class
