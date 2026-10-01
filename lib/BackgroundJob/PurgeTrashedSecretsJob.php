<?php

/**
 * Keepiq Purge Trashed Secrets Job
 *
 * Daily purge of secrets that have been in the trash longer than the
 * administrator's retention period (vault-trash-and-archive D4), with the
 * full delete cascade and a system audit event per secret.
 *
 * @category BackgroundJob
 * @package  OCA\Keepiq\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\BackgroundJob;

use DateTime;
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretTrashMapper;
use OCA\Keepiq\Service\SecretService;
use OCA\Keepiq\Service\SecretTrashService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Daily purge of the trash past its retention.
 */
class PurgeTrashedSecretsJob extends TimedJob {
	/**
	 * Rows read per batch.
	 *
	 * @var int
	 */
	private const BATCH = 500;

	/**
	 * Batches per run, so one run stays bounded.
	 *
	 * @var int
	 */
	private const MAX_BATCHES = 20;

	/**
	 * Constructor for PurgeTrashedSecretsJob.
	 *
	 * @param ITimeFactory $time The time factory
	 * @param SecretTrashMapper $trashMapper Reads the trash across users
	 * @param SecretService $secretService The full delete cascade
	 * @param IAppConfig $appConfig The app config (retention)
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		ITimeFactory $time,
		private SecretTrashMapper $trashMapper,
		private SecretService $secretService,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 86400);
	}//end __construct()

	/**
	 * The days a trashed secret is kept, clamped to 1 to 365.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-restoring-and-purging-trashed-secrets
	 */
	public function retentionDays(): int {
		$days = $this->appConfig->getValueInt(
			Application::APP_ID,
			'trash_retention_days',
			SecretTrashService::RETENTION_DEFAULT
		);

		return max(SecretTrashService::RETENTION_MIN, min(SecretTrashService::RETENTION_MAX, $days));
	}//end retentionDays()

	/**
	 * Purge every secret trashed longer ago than the retention, fail-soft
	 * per secret.
	 *
	 * @param DateTime $now The current instant
	 *
	 * @return int The number of secrets purged
	 *
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-restoring-and-purging-trashed-secrets
	 */
	public function purgeExpired(DateTime $now): int {
		$cutoff = (clone $now)->modify('-'.$this->retentionDays().' days');
		$purged = 0;
		for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
			$rows = $this->trashMapper->findTrashedBefore(cutoff: $cutoff, limit: self::BATCH);
			$done = 0;
			foreach ($rows as $secret) {
				$done += $this->purgeOne(secret: $secret);
			}

			$purged += $done;
			if (count($rows) < self::BATCH || $done === 0) {
				break;
			}
		}

		return $purged;
	}//end purgeExpired()

	/**
	 * Run the purge (fail-soft).
	 *
	 * @param mixed $argument Unused job argument
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is mandated by
	 *   OCP\BackgroundJob\TimedJob::run(); this job carries no cron payload.
	 *
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-restoring-and-purging-trashed-secrets
	 */
	protected function run($argument): void {
		try {
			$purged = $this->purgeExpired(now: $this->time->getDateTime());
			if ($purged > 0) {
				$this->logger->info(
					'Keepiq: purged '.$purged.' secrets from the trash',
					['app' => Application::APP_ID]
				);
			}
		} catch (Throwable $exception) {
			$this->logger->warning(
				'Keepiq: trash purge failed: '.$exception->getMessage(),
				['app' => Application::APP_ID]
			);
		}
	}//end run()

	/**
	 * Purge one expired secret; a failure is logged and skipped.
	 *
	 * @param Secret $secret The trashed secret past retention
	 *
	 * @return int 1 when purged, 0 when it failed
	 *
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-restoring-and-purging-trashed-secrets
	 */
	private function purgeOne(Secret $secret): int {
		try {
			$this->secretService->delete($secret->getId(), $secret->getOwnerId(), 'retention');
		} catch (Throwable $e) {
			$this->logger->warning(
				'Keepiq: trash purge failed for secret '.$secret->getId().': '.$e->getMessage(),
				['app' => Application::APP_ID]
			);
			return 0;
		}

		return 1;
	}//end purgeOne()
}//end class
