<?php

/**
 * Doriath Seed Development Link Shares Repair Step
 *
 * Creates example LinkShare rows for the dev user's secrets so the
 * frontend can render the list / revoke UI without a real owner driving
 * the create flow. Debug-only, idempotent.
 *
 * The seeded rows carry placeholder encryptedSecretSnapshot + Argon2id
 * salt blobs — they will not unlock from the public access page (that
 * requires real Argon2id + AES-GCM round-trips against a known password)
 * but are sufficient to drive the owner-facing management UI: the
 * /api/v1/secrets/{secretId}/link-shares list response, the revoke
 * action, and the empty-state vs populated-state rendering.
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

use DateInterval;
use DateTime;
use OCA\Doriath\AppInfo\Application;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\LinkShare;
use OCA\Doriath\Db\LinkShareMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Seed example LinkShare rows for the development vault.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Seeds couple the LinkShare
 *  mapper, dev Secret + Suite lookup, IConfig, and the logger by design.
 *
 * @spec openspec/changes/implement-link-sharing/tasks.md#task-1.2
 */
class SeedDevelopmentLinkShares implements IRepairStep {
	/**
	 * The development user ID.
	 *
	 * @var string
	 */
	private const DEV_USER_ID = 'admin';

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
	private const SEED_VERSION_KEY = 'dev_seed_link_shares_version';

	/**
	 * Constructor.
	 *
	 * @param LinkShareMapper $linkShareMapper The link share mapper
	 * @param SecretMapper $secretMapper The secret mapper
	 * @param EncryptionSuiteMapper $suiteMapper The suite mapper
	 * @param IConfig $config The config
	 * @param IAppConfig $appConfig The app config
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private LinkShareMapper $linkShareMapper,
		private SecretMapper $secretMapper,
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
		return 'Seed Doriath development link shares (debug only)';
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
			$suite = $this->suiteMapper->findActiveByOwner('user', self::DEV_USER_ID);
		} catch (DoesNotExistException) {
			$output->info('Doriath: no dev EncryptionSuite, skipping link-share seed');
			return;
		}

		$secrets = $this->secretMapper->findByOwner('user', self::DEV_USER_ID);
		if ($secrets === []) {
			$output->info('Doriath: no dev secrets, skipping link-share seed');
			return;
		}

		$first = $secrets[0];
		$suiteId = $suite->getId();
		$seeded = 0;
		$now = new DateTime();

		// Single-use link, expires in 7 days.
		$seeded += $this->seedLinkShare(
			id: $this->deterministicId(seed: 'dev_link_single_01'),
			secret: $first,
			suiteId: $suiteId,
			usageLimit: 1,
			usageCount: 0,
			expiresAt: (clone $now)->add(new DateInterval('P7D')),
		);

		// Multi-use link with one access already recorded.
		if (count($secrets) >= 2) {
			$seeded += $this->seedLinkShare(
				id: $this->deterministicId(seed: 'dev_link_multi_01'),
				secret: $secrets[1],
				suiteId: $suiteId,
				usageLimit: 5,
				usageCount: 1,
				expiresAt: (clone $now)->add(new DateInterval('P30D')),
			);
		}

		// Expired single-use link — should render the expired badge.
		if (count($secrets) >= 3) {
			$seeded += $this->seedLinkShare(
				id: $this->deterministicId(seed: 'dev_link_expired_01'),
				secret: $secrets[2],
				suiteId: $suiteId,
				usageLimit: 1,
				usageCount: 0,
				expiresAt: (clone $now)->sub(new DateInterval('P1D')),
			);
		}

		$this->appConfig->setValueString(Application::APP_ID, self::SEED_VERSION_KEY, $appVersion);
		$output->info('Doriath: seeded ' . $seeded . ' development link shares');
		$this->logger->info('Doriath dev seed: created ' . $seeded . ' link shares');
	}//end run()

	/**
	 * Produce a deterministic UUIDv5 from a seed string.
	 *
	 * @param string $seed The seed
	 *
	 * @return string
	 */
	private function deterministicId(string $seed): string {
		return Uuid::uuid5(Uuid::NAMESPACE_OID, 'doriath:link-share:' . $seed)->toString();
	}//end deterministicId()

	/**
	 * Insert one LinkShare row with placeholder crypto blobs.
	 *
	 * The encryptedSecretSnapshot + Argon2id salt fields are populated
	 * with base64-encoded random bytes so the column NOT-NULL constraints
	 * hold and the management endpoint shape is stable; the public access
	 * page will reject the placeholder blob with a generic 404 (which is
	 * the documented brute-force-resistant failure path).
	 *
	 * @param string $id The deterministic ID
	 * @param Secret $secret The target secret
	 * @param string $suiteId The encryption suite ID
	 * @param int $usageLimit The usage limit (1-10)
	 * @param int $usageCount The current usage count
	 * @param DateTime $expiresAt The expiry timestamp
	 *
	 * @return int Number of rows seeded (0 or 1).
	 *
	 * @spec exclude idempotency fix — dev-only seed guard, no spec scenario
	 */
	private function seedLinkShare(
		string $id,
		Secret $secret,
		string $suiteId,
		int $usageLimit,
		int $usageCount,
		DateTime $expiresAt,
	): int {
		// Idempotency: the ID is a deterministic UUIDv5, so a pre-existing
		// row means this seed already ran — skip quietly instead of
		// hitting the primary-key constraint.
		try {
			$this->linkShareMapper->findById($id);
			$this->logger->debug('Doriath dev seed: link share ' . $id . ' already exists, skipping');
			return 0;
		} catch (DoesNotExistException) {
			// Not seeded yet — insert below.
		}

		$linkShare = new LinkShare();
		$linkShare->setId($id);
		$linkShare->setSecretId($secret->getId());
		$linkShare->setToken(bin2hex(random_bytes(16)));
		$linkShare->setEncryptedSecretSnapshot(base64_encode(random_bytes(96)));
		$linkShare->setArgon2idSalt(base64_encode(random_bytes(16)));
		$linkShare->setEncryptionSuiteId($suiteId);
		$linkShare->setUsageLimit($usageLimit);
		$linkShare->setUsageCount($usageCount);
		$linkShare->setFailedAttempts(0);
		$linkShare->setCreatedBy(self::DEV_USER_ID);
		$linkShare->setCreatedAt(new DateTime());
		$linkShare->setExpiresAt($expiresAt);

		$this->linkShareMapper->insert($linkShare);

		return 1;
	}//end seedLinkShare()
}//end class
