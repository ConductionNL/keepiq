<?php

/**
 * Doriath Renew Intermediate Certificate Background Job
 *
 * Daily check: renew the intermediate certificate if within 30 days of expiry.
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
use OCA\Doriath\Service\CertificateAuthorityService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily check: renew the intermediate certificate if within 30 days of expiry.
 */
class RenewIntermediateCertificate extends TimedJob {
	/**
	 * Constructor for RenewIntermediateCertificate.
	 *
	 * @param ITimeFactory $time The time factory
	 * @param CACertificateMapper $caCertMapper The CA certificate mapper
	 * @param CertificateAuthorityService $caService The CA service
	 * @param LoggerInterface $logger The logger interface
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private CACertificateMapper $caCertMapper,
		private CertificateAuthorityService $caService,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 86400);
	}//end __construct()

	/**
	 * Run the background job to renew intermediate certificate.
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
			$intermediate = $this->caCertMapper->findActiveIntermediate();
		} catch (Exception) {
			$this->logger->warning('Doriath: No active intermediate found, skipping renewal check');
			return;
		}

		$expiresAt = $intermediate->getExpiresAt();
		if ($expiresAt === null) {
			return;
		}

		$daysUntilExpiry = (int)$expiresAt->diff(new DateTime())->format('%r%a');
		if ($daysUntilExpiry > 30) {
			return;
		}

		$this->logger->info("Doriath: Intermediate certificate expires in {$daysUntilExpiry} days, auto-renewing");

		try {
			$count = $this->caService->renewIntermediate(forced: false);
			$this->logger->info("Doriath: Intermediate auto-renewed, {$count} suites re-signed");
		} catch (Exception $e) {
			$this->logger->error(
				'Doriath: Intermediate auto-renewal failed',
				[
					'exception' => $e->getMessage(),
				]
			);
		}
	}//end run()
}//end class
