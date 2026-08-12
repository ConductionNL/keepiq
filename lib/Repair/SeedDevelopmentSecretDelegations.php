<?php

/**
 * Doriath Seed Development Secret Delegations Repair Step
 *
 * Creates one example SecretDelegation row for the dev vault so the
 * DelegationManager UI has a temporary delegation to render.
 *
 * Per §1.4 — one SecretDelegation with `dev-user-2` as delegate for the
 * first dev secret (GitHub). The row is created with
 * `is_permanent=false` so the reclaim flow has something to operate on.
 *
 * Debug-only. Idempotent via a `findById()` precheck on the deterministic
 * row ID, plus an app-version marker in app config.
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
use OCA\Doriath\AppInfo\Application;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretDelegation;
use OCA\Doriath\Db\SecretDelegationMapper;
use OCA\Doriath\Db\SecretMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
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
class SeedDevelopmentSecretDelegations implements IRepairStep {
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
	 * App-config marker storing the app version this seed last ran for.
	 *
	 * The step is registered under <post-migration>, which Nextcloud
	 * executes on every `occ upgrade` / `occ maintenance:repair` — on a
	 * dev instance that is every boot. The marker gates the seed to one
	 * run per installed app version.
	 *
	 * @var string
	 */
	private const SEED_VERSION_KEY = 'dev_seed_secret_delegations_version';

	/**
	 * Constructor.
	 *
	 * @param SecretMapper $secretMapper The secret mapper
	 * @param SecretDelegationMapper $delegationMapper The delegation mapper
	 * @param EncryptionSuiteMapper $suiteMapper The suite mapper
	 * @param IConfig $config The config
	 * @param IAppConfig $appConfig The app config
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private SecretMapper $secretMapper,
		private SecretDelegationMapper $delegationMapper,
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
		return 'Seed Doriath development secret delegations (debug only)';
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
			$output->info('Doriath: no dev EncryptionSuite, skipping delegation seed');
			return;
		}

		$secrets = $this->secretMapper->findByOwner('user', self::DEV_USER_ID);
		if ($secrets === []) {
			$output->info('Doriath: no dev secrets, skipping delegation seed');
			return;
		}

		$first = $secrets[0];
		$seeded = $this->seedDelegation(
			id: $this->deterministicId(seed: 'delegation_temp_01'),
			source: $first,
			delegate: self::DEV_DELEGATE,
		);

		$this->appConfig->setValueString(Application::APP_ID, self::SEED_VERSION_KEY, $appVersion);
		$output->info('Doriath: seeded ' . $seeded . ' development secret delegation');
		$this->logger->info('Doriath dev seed: created ' . $seeded . ' SecretDelegation row');
	}//end run()

	/**
	 * Produce a deterministic UUIDv5 from a seed string.
	 *
	 * @param string $seed The seed
	 *
	 * @return string
	 */
	private function deterministicId(string $seed): string {
		return Uuid::uuid5(Uuid::NAMESPACE_OID, 'doriath:secret-delegation:' . $seed)->toString();
	}//end deterministicId()

	/**
	 * Insert one temporary SecretDelegation row.
	 *
	 * @param string $id The deterministic ID
	 * @param Secret $source The source secret
	 * @param string $delegate The placeholder delegate user ID
	 *
	 * @return int Number of rows seeded (0 or 1).
	 *
	 * @spec exclude idempotency fix — dev-only seed guard, no spec scenario
	 */
	private function seedDelegation(string $id, Secret $source, string $delegate): int {
		// Idempotency: the ID is a deterministic UUIDv5, so a pre-existing
		// row means this seed already ran — skip quietly instead of
		// hitting the primary-key constraint.
		try {
			$this->delegationMapper->findById($id);
			$this->logger->debug('Doriath dev seed: secret delegation ' . $id . ' already exists, skipping');
			return 0;
		} catch (DoesNotExistException) {
			// Not seeded yet — insert below.
		}

		$row = new SecretDelegation();
		$row->setId($id);
		$row->setSecretId($source->getId());
		$row->setOriginalOwnerId(self::DEV_USER_ID);
		$row->setDelegatedTo($delegate);
		$row->setDelegatedAt(new DateTime());
		$row->setInitiatedBy(self::DEV_USER_ID);
		$row->setIsPermanent(false);

		$this->delegationMapper->insert($row);

		return 1;
	}//end seedDelegation()
}//end class
