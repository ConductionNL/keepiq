<?php

/**
 * Keepiq Crypto Primitives
 *
 * The two primitives encryption and decryption both build on: the MGF1 mask
 * of RSA-OAEP and the PBKDF2 key derivation of the password envelopes.
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

/**
 * Stateless primitives shared by EncryptService and DecryptService, which
 * build their own instance.
 *
 * @spec openspec/specs/encryption-suites/spec.md#requirement-suite-creation-on-first-login
 */
final class CryptoPrimitives {
	private const PBKDF2_ITERATIONS = 600000;

	/**
	 * MGF1 mask generation function (RFC 8017 §B.2.1) using SHA-256.
	 *
	 * @param string $seed The seed from which the mask is generated
	 * @param int $length The intended length in bytes of the mask
	 *
	 * @return string The generated mask of $length bytes
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-suite-creation-on-first-login
	 */
	public function mgf1Sha256(string $seed, int $length): string {
		$output = '';
		$counter = 0;

		while (strlen($output) < $length) {
			$output .= hash('sha256', $seed . pack('N', $counter), true);
			$counter++;
		}

		return substr($output, 0, $length);
	}//end mgf1Sha256()

	/**
	 * Derive a 256-bit AES key from a password using PBKDF2-SHA256.
	 *
	 * @param string $password The password
	 * @param string $salt 16-byte salt
	 *
	 * @return string Raw 32-byte key
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-suite-creation-on-first-login
	 */
	public function pbkdf2Sha256(string $password, string $salt): string {
		return hash_pbkdf2(
			'sha256',
			$password,
			$salt,
			self::PBKDF2_ITERATIONS,
			32,
			true
		);
	}//end pbkdf2Sha256()
}//end class
