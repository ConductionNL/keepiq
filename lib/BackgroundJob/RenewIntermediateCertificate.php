<?php

declare(strict_types=1);

namespace OCA\Doriath\BackgroundJob;

use OCA\Doriath\Db\CACertificateMapper;
use OCA\Doriath\Service\CertificateAuthorityService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Daily check: renew the intermediate certificate if within 30 days of expiry.
 */
class RenewIntermediateCertificate extends TimedJob
{
    public function __construct(
        ITimeFactory $time,
        private CACertificateMapper $caCertMapper,
        private CertificateAuthorityService $caService,
        private INotificationManager $notificationManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(86400);
    }//end __construct()

    protected function run($argument): void
    {
        try {
            $intermediate = $this->caCertMapper->findActiveIntermediate();
        } catch (\Exception) {
            $this->logger->warning('Doriath: No active intermediate found, skipping renewal check');
            return;
        }

        $expiresAt = $intermediate->getExpiresAt();
        if ($expiresAt === null) {
            return;
        }

        $daysUntilExpiry = (int) $expiresAt->diff(new \DateTime())->format('%r%a');
        if ($daysUntilExpiry > 30) {
            return;
        }

        $this->logger->info("Doriath: Intermediate certificate expires in {$daysUntilExpiry} days, auto-renewing");

        try {
            $count = $this->caService->renewIntermediate(forced: false);
            $this->logger->info("Doriath: Intermediate auto-renewed, {$count} suites re-signed");
        } catch (\Exception $e) {
            $this->logger->error('Doriath: Intermediate auto-renewal failed', [
                'exception' => $e->getMessage(),
            ]);
        }
    }//end run()
}//end class
