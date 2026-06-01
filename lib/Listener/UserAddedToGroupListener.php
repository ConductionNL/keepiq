<?php

/**
 * Doriath UserAddedToGroupListener
 *
 * Notifies secret owners when a new user joins a group that has group shares,
 * so they can approve adding the new member.
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
use OCP\Group\Events\UserAddedEvent;

/**
 * Handles UserAddedEvent for group-share new-member notification.
 *
 * @implements IEventListener<Event>
 */
class UserAddedToGroupListener implements IEventListener
{
    /**
     * Constructor for UserAddedToGroupListener.
     *
     * @param GroupShareService $groupShareService The group share service
     *
     * @return void
     */
    public function __construct(private GroupShareService $groupShareService)
    {
    }//end __construct()

    /**
     * Handle the user-added event.
     *
     * @param Event $event The event to handle
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if ($event instanceof UserAddedEvent === false) {
            return;
        }

        $this->groupShareService->handleNewGroupMember(
            $event->getUser()->getUID(),
            $event->getGroup()->getGID()
        );
    }//end handle()
}//end class
