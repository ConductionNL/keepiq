<?php

/**
 * Keepiq Partner Root Renewal Reminders Job
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

use OCA\Keepiq\Service\PartnerRootRenewalService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hourly: remind the administrators of offered partner root renewals 7, 5, 3 and 1 day before they end, and withdraw
 * the ones whose 30 days ended. Hourly, so a run late by cron drift cannot skip the 1-day reminder.
 *
 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-offered-renewal-expires-after-30-days-with-reminders
 */
class PartnerRootRenewalRemindersJob extends TimedJob {
	/**
	 * Constructor for PartnerRootRenewalRemindersJob.
	 *
	 * @param ITimeFactory $time The time factory
	 * @param PartnerRootRenewalService $renewals Reminds and withdraws
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		ITimeFactory $time,
		private PartnerRootRenewalService $renewals,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 3600);
	}//end __construct()

	/**
	 * Remind and withdraw what is due.
	 *
	 * @param mixed $argument Unused job argument
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Signature fixed by TimedJob.
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#scenario-no-one-accepts-in-time
	 */
	protected function run($argument): void {
		try {
			$this->renewals->remindAndExpire(now: $this->time->getDateTime());
		} catch (Throwable $exception) {
			$this->logger->error(
				'Keepiq: partner root renewal reminders failed: ' . $exception->getMessage(),
				['exception' => $exception, 'app' => 'keepiq']
			);
		}
	}//end run()
}//end class
