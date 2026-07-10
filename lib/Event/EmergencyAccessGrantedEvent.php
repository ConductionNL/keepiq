<?php

/**
 * Doriath EmergencyAccessGrantedEvent
 *
 * Dispatched when an emergency-access request's wait period elapses without
 * owner rejection and the request auto-grants. Carries only non-sensitive
 * identifiers — never key material. Consumed by the add-secret-audit-trail
 * pipeline and the owner/contact notification path.
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
 * Fired when an emergency-access request auto-grants.
 */
class EmergencyAccessGrantedEvent extends Event
{
    /**
     * Constructor for EmergencyAccessGrantedEvent.
     *
     * @param string $requestId The emergency-request record ID
     * @param string $ownerId   The vault owner's Nextcloud user ID
     * @param string $contactId The granted contact's Nextcloud user ID
     * @param string $level     The access level granted (view|takeover)
     *
     * @return void
     */
    public function __construct(
        private string $requestId,
        private string $ownerId,
        private string $contactId,
        private string $level,
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
     * Get the granted contact's Nextcloud user ID.
     *
     * @return string
     */
    public function getContactId(): string
    {
        return $this->contactId;
    }//end getContactId()

    /**
     * Get the access level granted (view|takeover).
     *
     * @return string
     */
    public function getLevel(): string
    {
        return $this->level;
    }//end getLevel()
}//end class
