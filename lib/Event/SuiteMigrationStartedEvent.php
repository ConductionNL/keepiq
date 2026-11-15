<?php

/**
 * Keepiq SuiteMigrationStartedEvent
 *
 * Dispatched when compromise recovery for an EncryptionSuite begins.
 * Listeners typically lock dependent resources (SecretRequests, etc.).
 *
 * @category Event
 * @package  OCA\Keepiq\Event
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

namespace OCA\Keepiq\Event;

/**
 * Fired when compromise recovery for an EncryptionSuite begins.
 */
class SuiteMigrationStartedEvent extends SuiteMigrationEvent {
}//end class
