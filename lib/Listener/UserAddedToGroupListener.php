<?php

/**
 * Doriath UserAddedToGroupListener
 *
 * Handles the Nextcloud UserAddedEvent to maintain group shares when members join.
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
 * Handles UserAddedEvent to trigger group share notifications for new members.
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
    public function __construct(
        private GroupShareService $groupShareService,
    ) {
    }//end __construct()

    /**
     * Handle the UserAddedEvent.
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

        $userId  = $event->getUser()->getUID();
        $groupId = $event->getGroup()->getGID();

        $this->groupShareService->handleNewGroupMember(
            userId: $userId,
            groupId: $groupId
        );
    }//end handle()
}//end class
