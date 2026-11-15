<?php

/**
 * An account deletion event named like the real one but carrying no actor, so AuditListener anonymises nobody.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Support\VaultEventShapes
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Support\VaultEventShapes;

use OCP\EventDispatcher\Event;


/**
 * Same short name as the real AccountDataDeletedEvent; no accessors at all.
 */
final class AccountDataDeletedEvent extends Event {
}//end class
