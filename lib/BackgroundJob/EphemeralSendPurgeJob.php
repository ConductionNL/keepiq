<?php

/**
 * Doriath Ephemeral Send Purge Job
 *
 * Hourly deletion of TTL-elapsed and fully-burned ephemeral sends
 * (ephemeral-send §3.1) so ciphertext never outlives its send.
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

use OCA\Doriath\AppInfo\Application;
use OCA\Doriath\Service\EphemeralSendService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hourly purge of dead ephemeral sends.
 */
class EphemeralSendPurgeJob extends TimedJob
{
    /**
     * Constructor for EphemeralSendPurgeJob.
     *
     * @param ITimeFactory         $time    The time factory
     * @param EphemeralSendService $service The send service
     * @param LoggerInterface      $logger  The logger
     *
     * @return void
     */
    public function __construct(
        ITimeFactory $time,
        private EphemeralSendService $service,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: 3600);
    }//end __construct()

    /**
     * Run the purge (fail-soft).
     *
     * @param mixed $argument Unused job argument
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/changes/ephemeral-send/specs/ephemeral-send/spec.md#requirement-purge
     */
    protected function run($argument): void
    {
        try {
            $purged = $this->service->purge();
            if ($purged > 0) {
                $this->logger->info(
                    'Doriath: purged '.$purged.' dead ephemeral sends',
                    ['app' => Application::APP_ID]
                );
            }
        } catch (Throwable $exception) {
            $this->logger->warning(
                'Doriath: ephemeral-send purge failed: '.$exception->getMessage(),
                ['app' => Application::APP_ID]
            );
        }
    }//end run()
}//end class
