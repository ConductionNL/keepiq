<?php

/**
 * Doriath Seed Development Secret Requests Repair Step
 *
 * Creates example SecretRequest rows for the dev user's secrets so the
 * frontend can render the request list/fill flow without a real recipient
 * generating tokens. Debug-only, idempotent.
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
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\SecretRequest;
use OCA\Doriath\Db\SecretRequestMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Seed example SecretRequest rows for the development vault.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Seeds couple the SecretRequest
 *  mapper, dev Secret + Suite lookup, IConfig, and the logger by design.
 *
 * @spec openspec/changes/implement-secret-requests/tasks.md#task-1.2
 */
class SeedDevelopmentSecretRequests implements IRepairStep {
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
	private const SEED_VERSION_KEY = 'dev_seed_secret_requests_version';

	/**
	 * Constructor.
	 *
	 * @param SecretRequestMapper $requestMapper The request mapper
	 * @param SecretMapper $secretMapper The secret mapper
	 * @param EncryptionSuiteMapper $suiteMapper The suite mapper
	 * @param IConfig $config The config
	 * @param IAppConfig $appConfig The app config
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private SecretRequestMapper $requestMapper,
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
		return 'Seed Doriath development secret requests (debug only)';
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
			$output->info('Doriath: no dev EncryptionSuite, skipping secret-request seed');
			return;
		}

		$secrets = $this->secretMapper->findByOwner('user', self::DEV_USER_ID);
		if ($secrets === []) {
			$output->info('Doriath: no dev secrets, skipping secret-request seed');
			return;
		}

		$first = $secrets[0];
		$suiteId = $suite->getId();
		$seeded = 0;
		$now = new DateTime();

		// Pending new request — no Secret values yet.
		$seeded += $this->seedRequest(
			id: $this->deterministicId(seed: 'dev_req_pending_01'),
			secret: $first,
			suiteId: $suiteId,
			status: SecretRequest::STATUS_PENDING,
			isReRequest: false,
			expiresAt: (clone $now)->add(new DateInterval('P7D')),
		);

		// Re-request against an existing secret (if we have two).
		if (count($secrets) >= 2) {
			$seeded += $this->seedRequest(
				id: $this->deterministicId(seed: 'dev_req_rerequest_01'),
				secret: $secrets[1],
				suiteId: $suiteId,
				status: SecretRequest::STATUS_PENDING,
				isReRequest: true,
				expiresAt: (clone $now)->add(new DateInterval('P3D')),
			);
		}

		// Expired pending request — should render the "expired" view on the public fill page.
		if (count($secrets) >= 3) {
			$seeded += $this->seedRequest(
				id: $this->deterministicId(seed: 'dev_req_expired_01'),
				secret: $secrets[2],
				suiteId: $suiteId,
				status: SecretRequest::STATUS_PENDING,
				isReRequest: false,
				expiresAt: (clone $now)->sub(new DateInterval('P1D')),
			);
		}

		$this->appConfig->setValueString(Application::APP_ID, self::SEED_VERSION_KEY, $appVersion);
		$output->info('Doriath: seeded ' . $seeded . ' development secret requests');
		$this->logger->info('Doriath dev seed: created ' . $seeded . ' secret requests');
	}//end run()

	/**
	 * Produce a deterministic UUIDv5 from a seed string.
	 *
	 * @param string $seed The seed
	 *
	 * @return string
	 */
	private function deterministicId(string $seed): string {
		return Uuid::uuid5(Uuid::NAMESPACE_OID, 'doriath:secret-request:' . $seed)->toString();
	}//end deterministicId()

	/**
	 * Insert one SecretRequest row.
	 *
	 * @param string $id The deterministic ID
	 * @param Secret $secret The target secret
	 * @param string $suiteId The encryption suite ID
	 * @param string $status The lifecycle status
	 * @param bool $isReRequest Whether this is a re-request
	 * @param DateTime $expiresAt The expiry timestamp
	 *
	 * @return int Number of rows seeded (0 or 1).
	 *
	 * @spec exclude idempotency fix — dev-only seed guard, no spec scenario
	 */
	private function seedRequest(
		string $id,
		Secret $secret,
		string $suiteId,
		string $status,
		bool $isReRequest,
		DateTime $expiresAt,
	): int {
		// Idempotency: the ID is a deterministic UUIDv5, so a pre-existing
		// row means this seed already ran — skip quietly instead of
		// hitting the primary-key constraint.
		try {
			$this->requestMapper->findById($id);
			$this->logger->debug('Doriath dev seed: secret request ' . $id . ' already exists, skipping');
			return 0;
		} catch (DoesNotExistException) {
			// Not seeded yet — insert below.
		}

		$request = new SecretRequest();
		$request->setId($id);
		$request->setSecretId($secret->getId());
		$request->setToken(bin2hex(random_bytes(16)));
		$request->setStatus($status);
		$request->setEncryptionSuiteId($suiteId);
		$request->setIsReRequest($isReRequest);
		$request->setCreatedBy(self::DEV_USER_ID);
		$requestedFields = json_encode(['key', 'login']);
		if ($requestedFields === false) {
			$requestedFields = '[]';
		}

		$request->setRequestedFields($requestedFields);
		$request->setCreatedAt(new DateTime());
		$request->setExpiresAt($expiresAt);

		$this->requestMapper->insert($request);

		return 1;
	}//end seedRequest()
}//end class
