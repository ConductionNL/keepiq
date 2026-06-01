<?php

/**
 * Doriath UserRemovedFromGroupListener
 *
 * Auto-revokes group-derived secret shares when a user leaves a group.
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

use OCA\Doriath\Service\GroupShareService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\UserRemovedEvent;

/**
 * Handles UserRemovedEvent for group-share auto-revocation.
 *
 * @implements IEventListener<Event>
 */
class UserRemovedFromGroupListener implements IEventListener
{
    /**
     * Constructor for UserRemovedFromGroupListener.
     *
     * @param GroupShareService $groupShareService The group share service
     *
     * @return void
     */
    public function __construct(private GroupShareService $groupShareService)
    {
    }//end __construct()

    /**
     * Handle the user-removed event.
     *
     * @param Event $event The event to handle
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if ($event instanceof UserRemovedEvent === false) {
            return;
        }

        $this->groupShareService->handleMemberLeave(
            $event->getUser()->getUID(),
            $event->getGroup()->getGID()
        );
    }//end handle()
}//end class
