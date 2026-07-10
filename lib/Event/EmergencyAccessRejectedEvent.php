<?php

/**
 * Doriath EmergencyAccessRejectedEvent
 *
 * Dispatched when the owner rejects a pending emergency-access request
 * during the wait period. Carries only non-sensitive identifiers — never
 * key material. Consumed by the add-secret-audit-trail pipeline.
 *
 * @category Event
 * @package  OCA\Doriath\Event
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

namespace OCA\Doriath\Event;

use OCP\EventDispatcher\Event;

/**
 * Fired when the owner rejects an emergency-access request.
 */
class EmergencyAccessRejectedEvent extends Event
{
    /**
     * Constructor for EmergencyAccessRejectedEvent.
     *
     * @param string $requestId The emergency-request record ID
     * @param string $ownerId   The vault owner's Nextcloud user ID
     * @param string $contactId The rejected contact's Nextcloud user ID
     *
     * @return void
     */
    public function __construct(
        private string $requestId,
        private string $ownerId,
        private string $contactId,
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * Get the emergency-request record ID.
     *
     * @return string
     */
    public function getRequestId(): string
    {
        return $this->requestId;
    }//end getRequestId()

    /**
     * Get the vault owner's Nextcloud user ID.
     *
     * @return string
     */
    public function getOwnerId(): string
    {
        return $this->ownerId;
    }//end getOwnerId()

    /**
     * Get the rejected contact's Nextcloud user ID.
     *
     * @return string
     */
    public function getContactId(): string
    {
        return $this->contactId;
    }//end getContactId()
}//end class
