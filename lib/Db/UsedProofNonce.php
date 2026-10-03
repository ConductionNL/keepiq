<?php

/**
 * Keepiq Used Proof Nonce Entity
 *
 * One consumed vault-key-proof challenge. Only a hash of the challenge is
 * stored, never the challenge, the proof or the signature.
 *
 * @category Db
 * @package  OCA\Keepiq\Db
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

namespace OCA\Keepiq\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A consumed vault-key-proof challenge.
 *
 * @method string getNonceHash()
 * @method void setNonceHash(string $nonceHash)
 * @method int getExpiresAt()
 * @method void setExpiresAt(int $expiresAt)
 */
class UsedProofNonce extends Entity {
	/**
	 * SHA-256 of the challenge, hex.
	 *
	 * @var string
	 */
	protected string $nonceHash = '';

	/**
	 * When the challenge expires, as a Unix timestamp. After that the
	 * challenge is refused on its own, so the row can be swept.
	 *
	 * @var integer
	 */
	protected int $expiresAt = 0;

	/**
	 * Register the column types.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/harden-vault-key-material-guards/specs/vault-key-proof/spec.md#requirement-challenges-are-stateless-and-expiring
	 */
	public function __construct() {
		$this->addType(fieldName: 'nonceHash', type: 'string');
		$this->addType(fieldName: 'expiresAt', type: 'integer');
	}//end __construct()
}//end class
