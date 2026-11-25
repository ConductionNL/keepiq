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

use OCA\Keepiq\Service\PartnerRootRenewalOffers;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Hourly: remind the administrators of offered partner root renewals 7, 5, 3 and 1 day before they end, and withdraw
 * the ones whose 30 days ended. Hourly, so a run late by cron drift cannot skip the 1-day reminder.
 *
 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-offered-renewal-expires-after-30-days-with-reminders
 */
class PartnerRootRenewalRemindersJob extends HourlyJob {
	/**
	 * Constructor for PartnerRootRenewalRemindersJob.
	 *
	 * @param ITimeFactory $time The time factory
	 * @param PartnerRootRenewalOffers $offers Reminds and withdraws
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		ITimeFactory $time,
		private PartnerRootRenewalOffers $offers,
		LoggerInterface $logger,
	) {
		parent::__construct(time: $time, logger: $logger, failure: 'Keepiq: partner root renewal reminders failed: ');
	}//end __construct()

	/**
	 * Remind and withdraw what is due.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#scenario-no-one-accepts-in-time
	 */
	protected function runHourly(): void {
		$this->offers->remindAndExpire(now: $this->time->getDateTime());
	}//end runHourly()
}//end class
