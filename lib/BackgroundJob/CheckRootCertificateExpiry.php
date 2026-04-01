<?php

declare(strict_types=1);

namespace OCA\Doriath\BackgroundJob;

use OCA\Doriath\Db\CACertificateMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily check: notify admins when the root certificate is approaching expiry.
 * Sends notifications at 90, 30, and 7 days before expiry.
 */
class CheckRootCertificateExpiry extends TimedJob
{
    private const NOTIFICATION_THRESHOLDS = [90, 30, 7];

    public function __construct(
        ITimeFactory $time,
        private CACertificateMapper $caCertMapper,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(86400);
    }//end __construct()

    protected function run($argument): void
    {
        try {
            $root = $this->caCertMapper->findRoot();
        } catch (\Exception) {
            return;
        }

        $expiresAt = $root->getExpiresAt();
        if ($expiresAt === null) {
            return;
        }

        $daysUntilExpiry = (int) $expiresAt->diff(new \DateTime())->format('%r%a');

        foreach (self::NOTIFICATION_THRESHOLDS as $threshold) {
            if ($daysUntilExpiry <= $threshold && $daysUntilExpiry > ($threshold === 90 ? 30 : ($threshold === 30 ? 7 : 0))) {
                $this->logger->warning(
                    "Doriath: Root certificate expires in {$daysUntilExpiry} days (threshold: {$threshold})"
                );
                break;
            }
        }
    }//end run()
}//end class
