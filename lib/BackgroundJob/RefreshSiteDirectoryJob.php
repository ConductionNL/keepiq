<?php

/**
 * Keepiq RefreshSiteDirectoryJob
 *
 * Daily refresh of the site directory copy the password health report matches
 * logins against (health-site-security-checks D2). Does nothing while the
 * admin switch `site_directory_enabled` is off.
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-11-17-health-site-security-checks/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\BackgroundJob;

use OCA\Keepiq\Kernel\AppIdentity;
use OCA\Keepiq\Service\SiteDirectoryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refreshes the site directory once a day.
 *
 * @spec openspec/changes/archive/2026-11-17-health-site-security-checks/tasks.md#task-2.2
 */
class RefreshSiteDirectoryJob extends TimedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory         $time    The time factory
	 * @param SiteDirectoryService $service Fetches and stores the copy
	 * @param LoggerInterface      $logger  Logs an unexpected failure
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private SiteDirectoryService $service,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 86400);
	}//end __construct()

	/**
	 * Run the refresh (fail-soft: the last copy is kept).
	 *
	 * @param mixed $argument Unused job argument
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is mandated by
	 *   OCP\BackgroundJob\TimedJob::run(); this job carries no cron payload.
	 *
	 * @spec openspec/changes/archive/2026-11-17-health-site-security-checks/tasks.md#task-2.2
	 */
	protected function run($argument): void {
		try {
			$this->service->refresh();
		} catch (Throwable $exception) {
			$this->logger->warning(
				'Keepiq: site directory refresh failed: ' . $exception->getMessage(),
				['app' => AppIdentity::APP_ID]
			);
		}
	}//end run()
}//end class
