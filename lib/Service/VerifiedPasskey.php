<?php

/**
 * Keepiq Verified Passkey
 *
 * The credential data a verified enrolment ceremony proved: read from the
 * attested authenticator data, never from the client's own claims.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

/**
 * The verified credential id, COSE public key, authenticator model and
 * signature counter of an enrolled passkey.
 *
 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkey-enrollment-requires-an-unlocked-vault
 */
final class VerifiedPasskey {
	/**
	 * Constructor for VerifiedPasskey.
	 *
	 * @param string $credentialId The base64url credential id
	 * @param string $publicKey The base64url COSE public key
	 * @param string $aaguid The authenticator model id (RFC 4122)
	 * @param int $counter The signature counter after the proof assertion
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $credentialId,
		public readonly string $publicKey,
		public readonly string $aaguid,
		public readonly int $counter,
	) {
	}//end __construct()
}//end class
