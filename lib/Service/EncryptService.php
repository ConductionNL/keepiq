<?php

/**
 * Keepiq Encrypt Service
 *
 * Stateless encryption service for RSA-OAEP and AES-256-GCM operations.
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

use InvalidArgumentException;
use OCA\Keepiq\Kernel\Contracts\EncryptServiceInterface;
use RuntimeException;

/**
 * Stateless encryption service. No database access, no entity awareness.
 *
 * Implements:
 * - RSA-OAEP-SHA256 with chunking (D5): [4-byte chunk count][512-byte blocks...]
 * - AES-256-GCM with envelope (D4): [4-byte version][16-byte salt][12-byte IV][ciphertext][16-byte tag]
 *
 * The envelope is base64-encoded for database storage.
 */
class EncryptService implements EncryptServiceInterface {
	private const ENVELOPE_VERSION = 1;
	private const RSA_CHUNK_SIZE = 446;
	private const RSA_BLOCK_SIZE = 512;
	private const AES_SALT_LENGTH = 16;
	private const AES_IV_LENGTH = 12;
	private const AES_TAG_LENGTH = 16;

	/**
	 * Encrypt plaintext with an RSA public key using OAEP-SHA256 with chunking.
	 *
	 * @param string $plaintext The data to encrypt
	 * @param string $publicKeyPem PEM-encoded public key or certificate
	 *
	 * @return string Base64-encoded ciphertext: [4-byte chunk count][512-byte blocks...]
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-3
	 */
	public function rsaEncrypt(string $plaintext, string $publicKeyPem): string {
		$publicKey = openssl_pkey_get_public(public_key: $publicKeyPem);
		if ($publicKey === false) {
			throw new InvalidArgumentException('Invalid public key PEM');
		}

		$chunks = str_split($plaintext, self::RSA_CHUNK_SIZE);
		// @codeCoverageIgnoreStart
		if (empty($chunks) === true) {
			$chunks = [''];
		}

		// @codeCoverageIgnoreEnd
		$chunkCount = count($chunks);
		$result = pack('N', $chunkCount);

		foreach ($chunks as $chunk) {
			// EME-OAEP-SHA256 pad, then raw RSA. PHP's
			// openssl_public_encrypt(OPENSSL_PKCS1_OAEP_PADDING) hard-codes the
			// OAEP/MGF1 hash to SHA-1, which is INCOMPATIBLE with the browser's
			// WebCrypto RSA-OAEP keys (imported with hash: 'SHA-256'). Padding
			// here ourselves keeps the PHP and JS ciphertext formats aligned.
			$padded = $this->oaepSha256Pad(message: $chunk, blockSize: self::RSA_BLOCK_SIZE);
			$encrypted = '';
			$success = openssl_public_encrypt(
				data: $padded,
				encrypted_data: $encrypted,
				public_key: $publicKey,
				padding: OPENSSL_NO_PADDING
			);

			// @codeCoverageIgnoreStart
			if ($success === false) {
				throw new RuntimeException('RSA encryption failed: ' . openssl_error_string());
			}

			if (strlen($encrypted) !== self::RSA_BLOCK_SIZE) {
				throw new RuntimeException(
					'Unexpected RSA block size: ' . strlen($encrypted) . ' (expected ' . self::RSA_BLOCK_SIZE . ')'
				);
			}

			// @codeCoverageIgnoreEnd
			$result .= $encrypted;
		}//end foreach

		return base64_encode($result);
	}//end rsaEncrypt()

	/**
	 * Apply EME-OAEP padding (RFC 8017 §7.1.1) with SHA-256 as both the label
	 * hash and the MGF1 hash, producing a block ready for raw RSA encryption.
	 *
	 * This mirrors WebCrypto's RSA-OAEP with SHA-256, so a value encrypted here
	 * can be decrypted in the browser by a key imported with hash: 'SHA-256'.
	 *
	 * @param string $message The chunk to pad (<= blockSize - 2*hLen - 2 bytes)
	 * @param int $blockSize The RSA modulus size in bytes (k)
	 *
	 * @return string The padded encoded message (EM) of length $blockSize
	 *
	 * @throws RuntimeException When the message is too long for the block
	 */
	private function oaepSha256Pad(string $message, int $blockSize): string {
		// SHA-256 output length.
		$hLen = 32;
		$mLen = strlen($message);
		$maxLen = ($blockSize - (2 * $hLen) - 2);

		// @codeCoverageIgnoreStart
		if ($mLen > $maxLen) {
			throw new RuntimeException('OAEP message too long: ' . $mLen . ' > ' . $maxLen);
		}

		// @codeCoverageIgnoreEnd
		// lHash = SHA-256("") — empty label, matching WebCrypto's default.
		$lHash = hash('sha256', '', true);

		// PS is (k - mLen - 2*hLen - 2) zero bytes; DB = lHash || PS || 0x01 || M.
		$psLen = ($blockSize - $mLen - (2 * $hLen) - 2);
		$dataBlock = $lHash . str_repeat("\0", $psLen) . "\x01" . $message;

		$seed = random_bytes($hLen);
		$dbMask = (new CryptoPrimitives())->mgf1Sha256(seed: $seed, length: ($blockSize - $hLen - 1));
		$maskedDb = $dataBlock ^ $dbMask;

		$seedMask = (new CryptoPrimitives())->mgf1Sha256(seed: $maskedDb, length: $hLen);
		$maskedSeed = $seed ^ $seedMask;

		// EM = 0x00 || maskedSeed || maskedDB.
		return "\0" . $maskedSeed . $maskedDb;
	}//end oaepSha256Pad()

	/**
	 * Encrypt plaintext with AES-256-GCM using a raw 32-byte key.
	 *
	 * @param string $plaintext The data to encrypt
	 * @param string $key Raw 32-byte AES key
	 *
	 * @return string Base64-encoded envelope
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-3
	 */
	public function aesEncrypt(string $plaintext, string $key): string {
		// Salt is empty here — use encryptPrivateKey() for PBKDF2-derived keys.
		return $this->envelopeOf(
			salt: str_repeat("\0", self::AES_SALT_LENGTH),
			sealed: $this->gcmSeal(plaintext: $plaintext, key: $key)
		);
	}//end aesEncrypt()

	/**
	 * Encrypt a PEM private key with an AES key derived from a password via PBKDF2-SHA256.
	 *
	 * @param string $pem PEM-encoded private key
	 * @param string $password The master password (or passphrase)
	 *
	 * @return string Base64-encoded envelope with salt embedded
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-3
	 */
	public function encryptPrivateKey(string $pem, string $password): string {
		$salt = random_bytes(self::AES_SALT_LENGTH);
		$key = $this->deriveKey(password: $password, salt: $salt);

		return $this->envelopeOf(salt: $salt, sealed: $this->gcmSeal(plaintext: $pem, key: $key));
	}//end encryptPrivateKey()

	/**
	 * AES-256-GCM encryption under a fresh IV.
	 *
	 * @param string $plaintext The data to encrypt
	 * @param string $key Raw 32-byte AES key
	 *
	 * @return array<string,string> The IV, the ciphertext and the tag
	 */
	private function gcmSeal(string $plaintext, string $key): array {
		$ivector = random_bytes(self::AES_IV_LENGTH);
		$tag = '';

		$ciphertext = openssl_encrypt(
			data: $plaintext,
			cipher_algo: 'aes-256-gcm',
			passphrase: $key,
			options: OPENSSL_RAW_DATA,
			iv: $ivector,
			tag: $tag,
			aad: '',
			tag_length: self::AES_TAG_LENGTH
		);

		// @codeCoverageIgnoreStart
		if ($ciphertext === false) {
			throw new RuntimeException('AES-256-GCM encryption failed: ' . openssl_error_string());
		}

		// @codeCoverageIgnoreEnd
		return ['iv' => $ivector, 'ciphertext' => $ciphertext, 'tag' => $tag];
	}//end gcmSeal()

	/**
	 * The base64 envelope: version(4) + salt(16) + IV(12) + ciphertext + tag(16).
	 *
	 * @param string $salt The salt, or 16 zero bytes for a raw key
	 * @param array<string,string> $sealed The IV, the ciphertext and the tag
	 *
	 * @return string Base64-encoded envelope
	 */
	private function envelopeOf(string $salt, array $sealed): string {
		$envelope = pack('N', self::ENVELOPE_VERSION)
			. $salt
			. $sealed['iv']
			. $sealed['ciphertext']
			. $sealed['tag'];

		return base64_encode($envelope);
	}//end envelopeOf()

	/**
	 * Derive a 256-bit AES key from a password using PBKDF2-SHA256.
	 *
	 * @param string $password The password
	 * @param string $salt 16-byte salt
	 *
	 * @return string Raw 32-byte key
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-3
	 */
	public function deriveKey(string $password, string $salt): string {
		return (new CryptoPrimitives())->pbkdf2Sha256(password: $password, salt: $salt);
	}//end deriveKey()
}//end class
