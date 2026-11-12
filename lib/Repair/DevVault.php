<?php

/**
 * Keepiq Dev Vault
 *
 * The development user's active suite and secrets a seed builds on.
 *
 * @category Repair
 * @package  OCA\Keepiq\Repair
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

namespace OCA\Keepiq\Repair;

use OCA\Keepiq\Db\Secret;

/**
 * The development user's active suite id and their secrets, in list order.
 *
 * @spec exclude dev-only seed support, no spec scenario
 */
final class DevVault {
	/**
	 * Constructor for DevVault.
	 *
	 * @param string $suiteId The dev user's active suite id
	 * @param Secret[] $secrets The dev user's secrets, never empty
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $suiteId,
		public readonly array $secrets,
	) {
	}//end __construct()
}//end class
