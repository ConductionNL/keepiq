<?php

/**
 * Keepiq - The vault a caller may remove a request's placeholder from
 *
 * What a caller of SecretPlaceholderCleaner states it is entitled to delete from:
 * a user's vault, an application's vault, or, with neither stated, the system
 * expiring a lapsed request, for which the Secret's own vault is used.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use OCA\Keepiq\Db\Secret;

/**
 * A caller's owner expectation for a placeholder it may remove.
 */
final class PlaceholderOwnerClaim {
	/**
	 * Constructor for PlaceholderOwnerClaim.
	 *
	 * @param string|null $ownerType 'user', 'application', or null for the system
	 * @param string|null $ownerId   The owner the caller may delete from, or null
	 *
	 * @return void
	 *
	 * @spec exclude Value object wiring only — no domain logic.
	 */
	public function __construct(
		public readonly ?string $ownerType,
		public readonly ?string $ownerId,
	) {
	}//end __construct()

	/**
	 * Whether the claim covers the Secret's vault: the system claims any vault,
	 * anyone else only the vault of the owner it named.
	 *
	 * @param Secret $secret  The placeholder
	 * @param string $ownerId Its owner, taken from the Secret itself
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-optional-expiry
	 */
	public function covers(Secret $secret, string $ownerId): bool {
		if ($this->ownerType === null) {
			return true;
		}

		return $secret->getOwnerType() === $this->ownerType && $ownerId === $this->ownerId;
	}//end covers()
}//end class
