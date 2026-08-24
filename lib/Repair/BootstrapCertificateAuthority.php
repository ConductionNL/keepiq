<?php

/**
 * Keepiq Bootstrap Certificate Authority Repair Step
 *
 * Bootstraps the private CA (root + intermediate) on first install.
 *
 * @category Repair
 * @package  OCA\Keepiq\Repair
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

namespace OCA\Keepiq\Repair;

use OCA\Keepiq\Service\CertificateAuthorityService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Bootstraps the private CA (root + intermediate) on first install.
 * Idempotent — skips if CA already exists.
 */
class BootstrapCertificateAuthority implements IRepairStep {
	/**
	 * Constructor for BootstrapCertificateAuthority.
	 *
	 * @param CertificateAuthorityService $caService The CA service
	 * @param LoggerInterface $logger The logger interface
	 *
	 * @return void
	 */
	public function __construct(
		private CertificateAuthorityService $caService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Bootstrap Keepiq Certificate Authority';
	}//end getName()

	/**
	 * Run the repair step to bootstrap the Certificate Authority.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
	 */
	public function run(IOutput $output): void {
		$output->info('Bootstrapping Keepiq Certificate Authority...');

		try {
			$this->caService->bootstrap();
			$output->info('Certificate Authority bootstrapped successfully');
		} catch (Throwable $e) {
			$output->warning('CA bootstrap failed: ' . $e->getMessage());
			$this->logger->error(
				'Keepiq CA bootstrap failed',
				[
					'exception' => $e->getMessage(),
				]
			);
		}
	}//end run()
}//end class
