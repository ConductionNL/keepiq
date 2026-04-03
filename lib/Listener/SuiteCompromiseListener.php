<?php

/**
 * Doriath SuiteCompromiseListener
 *
 * Placeholder listener for encryption suite compromise events.
 * Cascade logic will be wired when the compromise event is dispatched.
 *
 * @category Listener
 * @package  OCA\Doriath\Listener
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

namespace OCA\Doriath\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Placeholder listener for encryption suite compromise events.
 *
 * The actual cascade logic (flagging secrets as possibly compromised, notifying
 * owners and share recipients) will be implemented once the compromise event is
 * dispatched from EncryptionSuiteService.
 *
 * @implements IEventListener<Event>
 */
class SuiteCompromiseListener implements IEventListener
{
    /**
     * Constructor for SuiteCompromiseListener.
     *
     * @param LoggerInterface $logger The logger interface
     *
     * @return void
     */
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle the suite compromise event.
     *
     * @param Event $event The event to handle
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        $this->logger->info(
            'Doriath: SuiteCompromiseListener fired — cascade logic not yet wired'
        );
    }//end handle()
}//end class
