<?php

declare(strict_types=1);

namespace OCA\Doriath\Repair;

use OCA\Doriath\Service\CertificateAuthorityService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Bootstraps the private CA (root + intermediate) on first install.
 * Idempotent — skips if CA already exists.
 */
class BootstrapCertificateAuthority implements IRepairStep
{
    public function __construct(
        private CertificateAuthorityService $caService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    public function getName(): string
    {
        return 'Bootstrap Doriath Certificate Authority';
    }//end getName()

    public function run(IOutput $output): void
    {
        $output->info('Bootstrapping Doriath Certificate Authority...');

        try {
            $this->caService->bootstrap();
            $output->info('Certificate Authority bootstrapped successfully');
        } catch (\Throwable $e) {
            $output->warning('CA bootstrap failed: ' . $e->getMessage());
            $this->logger->error('Doriath CA bootstrap failed', [
                'exception' => $e->getMessage(),
            ]);
        }
    }//end run()
}//end class
