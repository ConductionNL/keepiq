<?php

/**
 * Doriath EncryptionSuiteRevokedEvent
 *
 * Dispatched after an EncryptionSuite is revoked, so sharing listeners can
 * cascade-delete the owner's shares and finalise delegations without coupling
 * EncryptionSuiteService to the sharing services.
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
 * Event signalling that an EncryptionSuite was revoked.
 */
class EncryptionSuiteRevokedEvent extends Event
{
    /**
     * Constructor for EncryptionSuiteRevokedEvent.
     *
     * @param string $suiteId   The revoked suite ID
     * @param string $ownerType The owner type ('user' or 'application')
     * @param string $ownerId   The owner ID whose suite was revoked
     *
     * @return void
     */
    public function __construct(
        private string $suiteId,
        private string $ownerType,
        private string $ownerId,
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * Get the revoked suite ID.
     *
     * @return string
     */
    public function getSuiteId(): string
    {
        return $this->suiteId;
    }//end getSuiteId()

    /**
     * Get the owner type.
     *
     * @return string
     */
    public function getOwnerType(): string
    {
        return $this->ownerType;
    }//end getOwnerType()

    /**
     * Get the owner ID.
     *
     * @return string
     */
    public function getOwnerId(): string
    {
        return $this->ownerId;
    }//end getOwnerId()
}//end class
