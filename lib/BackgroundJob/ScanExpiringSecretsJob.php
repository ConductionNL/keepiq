<?php

/**
 * Keepiq Scan Expiring Secrets Job
 *
 * Daily scan of all user-owned secrets against their effective expiry
 * (rotation-expiry-policies §4.1): reminder notifications when days-left
 * exactly hits a configured threshold (the daily cadence makes each
 * threshold fire once, no dedupe storage needed), and an auto-raised
 * `policy_expiry` rotation flag + one-time notification once overdue.
 * Server-visible metadata only — no ciphertext is ever touched.
 *
 * @category BackgroundJob
 * @package  OCA\Keepiq\BackgroundJob
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

namespace OCA\Keepiq\BackgroundJob;

use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Db\RotationFlagMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\RotationPolicyService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Daily expiry scan: reminders at thresholds, flags once overdue.
 */
class ScanExpiringSecretsJob extends TimedJob {
	/**
	 * Rows fetched per page during the full-vault scan.
	 *
	 * @var int
	 */
	private const PAGE_SIZE = 500;

	/**
	 * Constructor for ScanExpiringSecretsJob.
	 *
	 * @param ITimeFactory $time The time factory
	 * @param SecretMapper $secretMapper The secret mapper
	 * @param RotationFlagMapper $flagMapper The flag mapper (open-flag check)
	 * @param RotationPolicyService $rotationService The rotation service
	 * @param NotificationService $notificationService The notification dispatcher
	 * @param IAppConfig $appConfig The app config
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private SecretMapper $secretMapper,
		private RotationFlagMapper $flagMapper,
		private RotationPolicyService $rotationService,
		private NotificationService $notificationService,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 86400);
	}//end __construct()

	/**
	 * Run the expiry scan (fail-soft, per-secret isolation).
	 *
	 * @param mixed $argument Unused job argument
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is mandated by
	 *   OCP\BackgroundJob\TimedJob::run(); this job carries no cron payload.
	 *
	 * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md
	 */
	protected function run($argument): void {
		$thresholds = json_decode(
			$this->appConfig->getValueString(Application::APP_ID, 'expiry_reminder_days', '[30,7,1]'),
			true
		);
		if (is_array($thresholds) === false || $thresholds === []) {
			$thresholds = [30, 7, 1];
		}

		$now = $this->time->getDateTime();
		$offset = 0;
		do {
			$page = $this->secretMapper->findAllUserOwnedPaged(limit: self::PAGE_SIZE, offset: $offset);
			$pageCount = count($page);
			foreach ($page as $secret) {
				try {
					$this->scanOne(secret: $secret, thresholds: $thresholds, now: $now);
				} catch (Throwable $exception) {
					$this->logger->warning(
						'Keepiq: expiry scan failed for secret ' . $secret->getId() . ': ' . $exception->getMessage(),
						['app' => Application::APP_ID]
					);
				}
			}

			$offset += self::PAGE_SIZE;
		} while ($pageCount === self::PAGE_SIZE);
	}//end run()

	/**
	 * Scan a single secret: threshold reminder or overdue flag.
	 *
	 * @param Secret $secret The secret row
	 * @param int[] $thresholds Reminder thresholds (days before expiry)
	 * @param \DateTimeInterface $now The scan instant
	 *
	 * @return void
	 */
	private function scanOne(Secret $secret, array $thresholds, \DateTimeInterface $now): void {
		$expiry = $this->rotationService->resolveEffectiveExpiry(secret: $secret);
		if ($expiry === null) {
			return;
		}

		$daysLeft = (int)floor(($expiry->getTimestamp() - $now->getTimestamp()) / 86400);

		if ($daysLeft < 0) {
			// Overdue: raise the policy_expiry flag; notify ONLY when the
			// flag was not already open (one notification per episode).
			$alreadyOpen = false;
			try {
				$alreadyOpen = $this->flagMapper->findBySecret(secretId: $secret->getId())->getStatus() === 'open';
			} catch (DoesNotExistException) {
				// No flag yet.
			}

			if ($alreadyOpen === true) {
				return;
			}

			$this->rotationService->flag(secretId: $secret->getId(), reason: 'policy_expiry');
			$this->notificationService->notify(
				subject: 'secret_rotation_due',
				recipientId: $secret->getOwnerId(),
				params: [
					'secret_name' => $secret->getName(),
					'secret_id' => $secret->getId(),
				],
				objectType: 'secret',
				objectId: $secret->getId(),
			);
			return;
		}//end if

		// Approaching: the daily cadence means daysLeft passes each integer
		// exactly once, so an exact threshold match is naturally deduped.
		if (in_array($daysLeft, array_map('intval', $thresholds), true) === true) {
			$this->notificationService->notify(
				subject: 'secret_expiring',
				recipientId: $secret->getOwnerId(),
				params: [
					'secret_name' => $secret->getName(),
					'secret_id' => $secret->getId(),
					'days_left' => $daysLeft,
				],
				objectType: 'secret',
				objectId: $secret->getId(),
			);
		}
	}//end scanOne()
}//end class
