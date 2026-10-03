<?php

/**
 * Keepiq Vault Key Proof Exemption
 *
 * A request-time waiver for a #[VaultKeyProofRequired] route. The attribute
 * names an implementation in `exemption`; VaultKeyProofMiddleware asks it
 * before it verifies, and skips the proof only when it answers true. Used where
 * a proof is needed for the risky shape of an operation but not for its
 * ordinary shape (keepiq#818: a share to a new recipient versus a known one).
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

use OCP\IRequest;

/**
 * Decides whether one request may skip its vault-key proof.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-sharing-with-a-new-party-requires-a-verified-key-proof
 */
interface VaultKeyProofExemption {
	/**
	 * Whether this request may go ahead without a proof. Must answer false
	 * whenever it is unsure: a wrong true is a missing guard.
	 *
	 * @param IRequest $request The incoming request
	 * @param string   $userId  The acting user
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-sharing-with-a-new-party-requires-a-verified-key-proof
	 */
	public function exempts(IRequest $request, string $userId): bool;
}//end interface
