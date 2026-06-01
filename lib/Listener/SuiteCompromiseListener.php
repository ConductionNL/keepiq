<?php

/**
 * Doriath SuiteCompromiseListener
 *
 * Notifies the original owner of a shared secret when a recipient's copy is
 * flagged possibly_compromised_at during a compromise migration, advising them
 * to replace the secret value.
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

use OCA\Doriath\Event\SharedCopyCompromisedEvent;
use OCA\Doriath\Service\NotificationService;
use OCA\Doriath\Service\SecretOwnershipResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Handles SharedCopyCompromisedEvent for owner compromise notification.
 *
 * @implements IEventListener<Event>
 */
class SuiteCompromiseListener implements IEventListener
{
    /**
     * Constructor for SuiteCompromiseListener.
     *
     * @param NotificationService     $notificationService The notification service
     * @param SecretOwnershipResolver $ownership           The ownership resolver
     *
     * @return void
     */
    public function __construct(
        private NotificationService $notificationService,
        private SecretOwnershipResolver $ownership,
    ) {
    }//end __construct()

    /**
     * Handle the shared-copy-compromised event.
     *
     * @param Event $event The event to handle
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if ($event instanceof SharedCopyCompromisedEvent === false) {
            return;
        }

        $ownerId = $this->ownership->getOwnerId($event->getSourceSecretId());
        if ($ownerId === null) {
            return;
        }

        $this->notificationService->notify(
            'secret_compromised',
            $ownerId,
            [
                'targetUserId'   => $event->getTargetUserId(),
                'sourceSecretId' => $event->getSourceSecretId(),
            ],
            $event->getSourceSecretId()
        );
    }//end handle()
}//end class
