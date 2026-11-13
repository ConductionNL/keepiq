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

use OCA\Keepiq\Db\RotationFlagMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Kernel\AppIdentity;
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
		$scan = new ExpiryScan(thresholds: $this->reminderThresholds(), now: $this->time->getDateTime());
		$offset = 0;
		do {
			$page = $this->secretMapper->findAllUserOwnedPaged(limit: self::PAGE_SIZE, offset: $offset);
			$pageCount = count($page);
			foreach ($page as $secret) {
				try {
					$this->scanOne(secret: $secret, scan: $scan);
				} catch (Throwable $exception) {
					// The class, not the message: a message is unbounded and can
					// carry whatever the failing call put in it (keepiq#728).
					$this->logger->warning(
						'Keepiq: expiry scan failed for secret '.$secret->getId().': '.$exception::class,
						['app' => AppIdentity::APP_ID]
					);
				}
			}

			$offset += self::PAGE_SIZE;
		} while ($pageCount === self::PAGE_SIZE);
	}//end run()

	/**
	 * The instance-wide reminder days, or [30,7,1] when none are stored.
	 *
	 * @return array<int,mixed>
	 */
	private function reminderThresholds(): array {
		$thresholds = json_decode(
			$this->appConfig->getValueString(AppIdentity::APP_ID, 'expiry_reminder_days', '[30,7,1]'),
			true
		);
		if (is_array($thresholds) === false || $thresholds === []) {
			return [30, 7, 1];
		}

		return $thresholds;
	}//end reminderThresholds()

	/**
	 * Scan a single secret: threshold reminder or overdue flag.
	 *
	 * @param Secret     $secret The secret row
	 * @param ExpiryScan $scan   The reminder days and the scan instant
	 *
	 * @return void
	 */
	private function scanOne(Secret $secret, ExpiryScan $scan): void {
		$expiry = $this->rotationService->resolveEffectiveExpiry(secret: $secret);
		if ($expiry === null) {
			return;
		}

		$daysLeft = $scan->daysUntil(expiry: $expiry);
		if ($daysLeft < 0) {
			$this->flagOverdue(secret: $secret);
			return;
		}

		// Approaching: the daily cadence means daysLeft passes each integer
		// exactly once, so an exact threshold match is naturally deduped. A
		// policy that sets its own reminder days replaces the instance-wide
		// thresholds for the secrets it scopes (keepiq#746).
		$thresholds = ($this->rotationService->reminderDaysFor(secret: $secret) ?? $scan->thresholds);
		if (in_array($daysLeft, array_map('intval', $thresholds), true) === true) {
			$this->remindExpiring(secret: $secret, daysLeft: $daysLeft);
		}
	}//end scanOne()

	/**
	 * Raise the policy_expiry flag on an overdue secret and notify its owner,
	 * ONLY when the flag was not already open (one notification per episode).
	 *
	 * @param Secret $secret The overdue secret
	 *
	 * @return void
	 */
	private function flagOverdue(Secret $secret): void {
		if ($this->flagOpen(secret: $secret) === true) {
			return;
		}

		$this->rotationService->flag(secretId: $secret->getId(), reason: 'policy_expiry');
		$this->notificationService->notify(
			subject: 'secret_rotation_due',
			recipientId: $secret->getOwnerId(),
			params: $this->secretParams(secret: $secret),
			objectType: 'secret',
			objectId: $secret->getId(),
		);
	}//end flagOverdue()

	/**
	 * Whether the secret's rotation flag is open.
	 *
	 * @param Secret $secret The secret
	 *
	 * @return bool False when it has no flag yet
	 */
	private function flagOpen(Secret $secret): bool {
		try {
			return $this->flagMapper->findBySecret(secretId: $secret->getId())->getStatus() === 'open';
		} catch (DoesNotExistException) {
			// No flag yet.
			return false;
		}
	}//end flagOpen()

	/**
	 * Remind the owner of a secret that reached a reminder day.
	 *
	 * @param Secret $secret   The secret
	 * @param int    $daysLeft Whole days until it expires
	 *
	 * @return void
	 */
	private function remindExpiring(Secret $secret, int $daysLeft): void {
		$this->notificationService->notify(
			subject: 'secret_expiring',
			recipientId: $secret->getOwnerId(),
			params: $this->secretParams(secret: $secret) + ['days_left' => $daysLeft],
			objectType: 'secret',
			objectId: $secret->getId(),
		);
	}//end remindExpiring()

	/**
	 * The notification parameters naming a secret.
	 *
	 * @param Secret $secret The secret
	 *
	 * @return array{secret_name:string,secret_id:string}
	 */
	private function secretParams(Secret $secret): array {
		return [
			'secret_name' => $secret->getName(),
			'secret_id' => $secret->getId(),
		];
	}//end secretParams()
}//end class
