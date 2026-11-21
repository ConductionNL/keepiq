<?php

/**
 * Keepiq Re-encrypted Fields
 *
 * The value ciphertexts of one secret or version, re-encrypted under the new suite.
 *
 * @category Controller
 * @package  OCA\Keepiq\Controller
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

namespace OCA\Keepiq\Controller;

/**
 * The value ciphertexts a secret or version commit carries.
 *
 * @spec openspec/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
 */
final class ReEncryptedFields {
	/**
	 * Constructor for ReEncryptedFields.
	 *
	 * @param string|null $key The re-encrypted key ciphertext
	 * @param string|null $login The re-encrypted login ciphertext
	 * @param string|null $additionalFields The re-encrypted additional-fields ciphertext
	 * @param string|null $totp The re-encrypted TOTP ciphertext
	 * @param string|null $passkeys The re-encrypted passkeys ciphertext
	 *
	 * @return void
	 */
	public function __construct(
		public readonly ?string $key,
		public readonly ?string $login,
		public readonly ?string $additionalFields,
		public readonly ?string $totp,
		public readonly ?string $passkeys,
	) {
	}//end __construct()
}//end class
