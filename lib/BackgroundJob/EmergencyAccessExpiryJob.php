<?php

/**
 * Doriath Emergency Access Expiry Background Job
 *
 * Hourly check: auto-grant every emergency-access request whose configured
 * wait period has elapsed without an owner rejection. Delegates the actual
 * state transition + event dispatch to EmergencyAccessService so the logic
 * stays unit-testable independently of the scheduler.
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

use OCA\Doriath\Service\EmergencyAccessService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hourly auto-grant of expired emergency-access requests.
 */
class EmergencyAccessExpiryJob extends TimedJob
{
    /**
     * Constructor for EmergencyAccessExpiryJob.
     *
     * @param ITimeFactory           $time    The time factory
     * @param EmergencyAccessService $service The emergency-access service
     * @param LoggerInterface        $logger  The logger interface
     *
     * @return void
     */
    public function __construct(
        ITimeFactory $time,
        private EmergencyAccessService $service,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: 3600);
    }//end __construct()

    /**
     * Run the auto-grant sweep.
     *
     * @param mixed $argument The job argument
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/changes/emergency-access/tasks.md#task-3.1
     */
    protected function run($argument): void
    {
        try {
            $granted = $this->service->processExpiredRequests();
        } catch (Throwable $e) {
            $this->logger->error('Doriath: emergency-access expiry sweep failed: '.$e->getMessage());
            return;
        }

        if ($granted > 0) {
            $this->logger->info("Doriath: auto-granted {$granted} expired emergency-access request(s)");
        }
    }//end run()
}//end class
