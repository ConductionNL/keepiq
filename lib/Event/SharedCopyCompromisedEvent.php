<?php

/**
 * Doriath SharedCopyCompromisedEvent
 *
 * Dispatched when a shared secret copy is flagged possibly_compromised_at
 * during an EncryptionSuite compromise migration, so the original owner can be
 * notified to replace the secret value.
 *
 * This event is dispatched by the suite-migration flow owned by the
 * implement-secrets / encryption-suites changes once a shared copy is migrated.
 * The listener here is the sharing-side consumer.
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
 * Event signalling that a shared copy was flagged as possibly compromised.
 */
class SharedCopyCompromisedEvent extends Event
{
    /**
     * Constructor for SharedCopyCompromisedEvent.
     *
     * @param string $sourceSecretId The original (source) secret ID
     * @param string $copySecretId   The flagged copy ID
     * @param string $targetUserId   The recipient whose suite was compromised
     *
     * @return void
     */
    public function __construct(
        private string $sourceSecretId,
        private string $copySecretId,
        private string $targetUserId,
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * Get the original (source) secret ID.
     *
     * @return string
     */
    public function getSourceSecretId(): string
    {
        return $this->sourceSecretId;
    }//end getSourceSecretId()

    /**
     * Get the flagged copy ID.
     *
     * @return string
     */
    public function getCopySecretId(): string
    {
        return $this->copySecretId;
    }//end getCopySecretId()

    /**
     * Get the recipient whose suite was compromised.
     *
     * @return string
     */
    public function getTargetUserId(): string
    {
        return $this->targetUserId;
    }//end getTargetUserId()
}//end class
