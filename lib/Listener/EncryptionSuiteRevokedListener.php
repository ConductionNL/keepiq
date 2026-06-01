<?php

/**
 * Doriath EncryptionSuiteRevokedListener
 *
 * On EncryptionSuite revocation, cascade-deletes the owner's secret shares and
 * makes their temporary delegations permanent (ownership transfer).
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

use OCA\Doriath\Event\EncryptionSuiteRevokedEvent;
use OCA\Doriath\Service\DelegationService;
use OCA\Doriath\Service\ShareService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Handles EncryptionSuiteRevokedEvent for share cascade and delegation transfer.
 *
 * @implements IEventListener<Event>
 */
class EncryptionSuiteRevokedListener implements IEventListener
{
    /**
     * Constructor for EncryptionSuiteRevokedListener.
     *
     * @param ShareService      $shareService      The share service
     * @param DelegationService $delegationService The delegation service
     *
     * @return void
     */
    public function __construct(
        private ShareService $shareService,
        private DelegationService $delegationService,
    ) {
    }//end __construct()

    /**
     * Handle the suite-revoked event.
     *
     * @param Event $event The event to handle
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if ($event instanceof EncryptionSuiteRevokedEvent === false) {
            return;
        }

        if ($event->getOwnerType() !== 'user') {
            return;
        }

        $ownerId = $event->getOwnerId();

        // 1. Cascade-delete all shares targeting this user (their copies are
        //    now undecryptable). Original secrets of other owners are untouched.
        $this->shareService->deleteAllSharesForTargetUser($ownerId);

        // 2. Make temporary delegations this user granted permanent, and drop
        //    their now-inaccessible original copies.
        $this->delegationService->makePermanent($ownerId);
    }//end handle()
}//end class
