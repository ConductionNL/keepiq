<?php

/**
 * Keepiq Check Root Certificate Expiry Background Job
 *
 * Daily check: notify admins when the root certificate is approaching expiry.
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

use Exception;
use OCA\Keepiq\Db\CACertificateMapper;
use OCA\Keepiq\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Daily check: notify admins when the root certificate is approaching expiry.
 * Sends notifications at 90, 30, and 7 days before expiry.
 */
class CheckRootCertificateExpiry extends TimedJob {
	private const NOTIFICATION_THRESHOLDS = [90, 30, 7];

	/**
	 * App-config key holding "<root expiry timestamp>:<threshold>" of the
	 * last announcement.
	 */
	private const ANNOUNCED_KEY = 'ca_root_expiry_announced';

	/**
	 * Constructor for CheckRootCertificateExpiry.
	 *
	 * @param ITimeFactory $time The time factory
	 * @param CACertificateMapper $caCertMapper The CA certificate mapper
	 * @param LoggerInterface $logger The logger interface
	 * @param IGroupManager $groupManager The group manager (admin recipients)
	 * @param NotificationService $notificationService The notification dispatcher
	 * @param IAppConfig $appConfig Remembers which threshold was announced
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private CACertificateMapper $caCertMapper,
		private LoggerInterface $logger,
		private IGroupManager $groupManager,
		private NotificationService $notificationService,
		private IAppConfig $appConfig,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 86400);
	}//end __construct()

	/**
	 * Run the background job to check root certificate expiry.
	 *
	 * @param mixed $argument The job argument
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is mandated by
	 *   OCP\BackgroundJob\TimedJob::run(); this job carries no cron payload.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
	 */
	protected function run($argument): void {
		try {
			$root = $this->caCertMapper->findRoot();
		} catch (Exception) {
			return;
		}

		$expiresAt = $root->getExpiresAt();
		if ($expiresAt === null) {
			return;
		}

		// Days from NOW until expiry, positive while the root is valid. The
		// former expiry->diff(now) ran backwards, so no threshold ever matched
		// and no admin heard about an expiring root (keepiq#741).
		$now = $this->time->getDateTime();
		$daysUntilExpiry = (int)floor(($expiresAt->getTimestamp() - $now->getTimestamp()) / 86400);

		$threshold = $this->thresholdFor(daysUntilExpiry: $daysUntilExpiry);
		if ($threshold === null) {
			return;
		}

		$this->logger->warning(
			"Keepiq: Root certificate expires in {$daysUntilExpiry} days (threshold: {$threshold})"
		);

		// Announce each threshold of each root once, not every day: the key
		// names the root's expiry, so a renewed root starts over.
		$announced = $expiresAt->getTimestamp().':'.$threshold;
		if ($this->appConfig->getValueString('keepiq', self::ANNOUNCED_KEY, '') === $announced) {
			return;
		}

		$this->notifyAdmins(daysUntilExpiry: $daysUntilExpiry, threshold: $threshold);
		$this->appConfig->setValueString('keepiq', self::ANNOUNCED_KEY, $announced);
	}//end run()

	/**
	 * The threshold a day count falls in: (30, 90] is 90, (7, 30] is 30 and
	 * (0, 7] is 7. Null outside them, and for an expired root.
	 *
	 * @param int $daysUntilExpiry Days until the root expires
	 *
	 * @return int|null
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
	 */
	private function thresholdFor(int $daysUntilExpiry): ?int {
		$lowerBound = 0;
		foreach (array_reverse(self::NOTIFICATION_THRESHOLDS) as $threshold) {
			if ($daysUntilExpiry <= $threshold && $daysUntilExpiry > $lowerBound) {
				return $threshold;
			}

			$lowerBound = $threshold;
		}

		return null;
	}//end thresholdFor()

	/**
	 * Notify every admin that the root is expiring.
	 *
	 * @param int $daysUntilExpiry Days until the root expires
	 * @param int $threshold The threshold the day count falls in
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
	 */
	private function notifyAdmins(int $daysUntilExpiry, int $threshold): void {
		$adminGroup = $this->groupManager->get('admin');
		if ($adminGroup === null) {
			return;
		}

		foreach ($adminGroup->getUsers() as $admin) {
			try {
				$this->notificationService->notify(
					subject: 'ca_root_expiring',
					recipientId: $admin->getUID(),
					params: ['days_left' => $daysUntilExpiry, 'threshold' => $threshold],
					objectType: 'ca_root',
					objectId: 'threshold-'.$threshold,
				);
			} catch (Throwable $exception) {
				$this->logger->warning(
					'Keepiq: root certificate expiry notification failed: '.$exception::class
				);
			}
		}
	}//end notifyAdmins()
}//end class
