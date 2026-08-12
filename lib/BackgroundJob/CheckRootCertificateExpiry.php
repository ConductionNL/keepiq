<?php

/**
 * Doriath Check Root Certificate Expiry Background Job
 *
 * Daily check: notify admins when the root certificate is approaching expiry.
 *
 * @category BackgroundJob
 * @package  OCA\Doriath\BackgroundJob
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

namespace OCA\Doriath\BackgroundJob;

use DateTime;
use Exception;
use OCA\Doriath\Db\CACertificateMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily check: notify admins when the root certificate is approaching expiry.
 * Sends notifications at 90, 30, and 7 days before expiry.
 */
class CheckRootCertificateExpiry extends TimedJob {
	private const NOTIFICATION_THRESHOLDS = [90, 30, 7];

	/**
	 * Constructor for CheckRootCertificateExpiry.
	 *
	 * @param ITimeFactory $time The time factory
	 * @param CACertificateMapper $caCertMapper The CA certificate mapper
	 * @param LoggerInterface $logger The logger interface
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private CACertificateMapper $caCertMapper,
		private LoggerInterface $logger,
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

		$daysUntilExpiry = (int)$expiresAt->diff(new DateTime())->format('%r%a');

		foreach (self::NOTIFICATION_THRESHOLDS as $threshold) {
			$lowerBound = 0;
			if ($threshold === 90) {
				$lowerBound = 30;
			} elseif ($threshold === 30) {
				$lowerBound = 7;
			}

			if ($daysUntilExpiry <= $threshold && $daysUntilExpiry > $lowerBound) {
				$this->logger->warning(
					"Doriath: Root certificate expires in {$daysUntilExpiry} days (threshold: {$threshold})"
				);
				break;
			}
		}
	}//end run()
}//end class
