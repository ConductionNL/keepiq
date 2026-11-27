<?php

/**
 * Keepiq RootRenewedEvent
 *
 * Dispatched once a renewed Keepiq root is committed, so the partners that pinned the previous root can be told
 * (partner-root-renewal-announcement D2).
 *
 * @category Event
 * @package  OCA\Keepiq\Event
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Event;

use OCP\EventDispatcher\Event;

/**
 * Fired after this instance's renewed root is stored.
 *
 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-a-renewed-root-is-announced-to-partners
 */
class RootRenewedEvent extends Event {
}//end class
