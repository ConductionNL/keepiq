<?php

/**
 * Keepiq Retry Root Renewal Notices Job
 *
 * Sends the root renewal notices to partners that RootRenewedListener queued, and retries a failed one with the
 * backoff of RootRenewalNoticeDelivery (partner-root-renewal-announcement D2). Every minute; a run touches only the
 * partners whose wait is over. A run that throws is logged by Nextcloud's job runner, and the notices it did not
 * send are still due on the next run.
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
use OCA\Keepiq\Service\RootRenewalNoticeDelivery;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Sends and retries root renewal notices to partners.
 *
 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-a-renewed-root-is-announced-to-partners
 */
class RetryRootRenewalNoticesJob extends TimedJob {
	/**
	 * Constructor for RetryRootRenewalNoticesJob.
	 *
	 * @param ITimeFactory $time The time factory
	 * @param RootRenewalNoticeDelivery $notices Sends and retries root renewal notices to partners
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(ITimeFactory $time, private RootRenewalNoticeDelivery $notices) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 60);
	}//end __construct()

	/**
	 * Send the notices that are due.
	 *
	 * @param mixed $argument Unused job argument
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Signature fixed by TimedJob.
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-a-renewed-root-is-announced-to-partners
	 */
	protected function run($argument): void {
		$this->notices->retryDue(now: new DateTime());
	}//end run()
}//end class
