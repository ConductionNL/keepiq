<?php

/**
 * Keepiq Known Share Recipient Exemption
 *
 * Waives the vault-key proof on a direct share when the caller already holds a
 * direct share with the same recipient (keepiq#818). A session alone can then
 * keep sharing with people the user already shares with, but cannot add a new
 * party who would receive this secret and every later value of it.
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

use OCA\Keepiq\Db\ShareTargetMapper;
use OCP\IRequest;
use Throwable;

/**
 * A share to a recipient the caller already shares with directly needs no proof.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-sharing-with-a-new-party-requires-a-verified-key-proof
 */
class KnownShareRecipientExemption implements VaultKeyProofExemption {
	/**
	 * Constructor.
	 *
	 * @param ShareTargetMapper $shareTargets The share-target mapper
	 *
	 * @return void
	 */
	public function __construct(
		private ShareTargetMapper $shareTargets,
	) {
	}//end __construct()

	/**
	 * Exempt when the request's `targetUserId` already receives a direct share
	 * from the caller. A missing recipient, or a failing lookup, is not exempt.
	 *
	 * @param IRequest $request The incoming request
	 * @param string   $userId  The acting user
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-sharing-with-a-new-party-requires-a-verified-key-proof
	 */
	public function exempts(IRequest $request, string $userId): bool {
		$targetUserId = (string)$request->getParam('targetUserId', '');
		if ($targetUserId === '' || $targetUserId === $userId) {
			return false;
		}

		try {
			return $this->shareTargets->hasDirectShareBetween(createdBy: $userId, targetUserId: $targetUserId);
		} catch (Throwable) {
			return false;
		}
	}//end exempts()
}//end class
