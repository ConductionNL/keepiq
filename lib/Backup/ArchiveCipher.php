<?php

/**
 * Keepiq Backup Archive Cipher
 *
 * Optional encryption of a backup archive to an administrator-held public key
 * (admin-scheduled-vault-backups D2). The archive is streamed through
 * AES-256-GCM in 1 MiB segments, each with its own random nonce and tag,
 * under a random content key; the content key is wrapped with RSA-OAEP
 * (SHA-256, MGF1-SHA-256) under the recipient public key. The private key
 * never touches the server; verify and restore take it from a file.
 *
 * File layout: the magic line, a 4-byte big-endian header length, a JSON
 * header (format, wrapped key, segment size), then segments of
 * [4-byte length][12-byte nonce][ciphertext][16-byte tag]. Each segment's
 * additional data is its 8-byte index plus a final flag, so segments cannot
 * be reordered, dropped or cut off without the tag failing.
 *
 * @category Backup
 * @package  OCA\Keepiq\Backup
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

namespace OCA\Keepiq\Backup;

use InvalidArgumentException;
use phpseclib4\Crypt\PublicKeyLoader;
use phpseclib4\Crypt\RSA;
use RuntimeException;
use Throwable;

/**
 * Encrypts and decrypts backup archives.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) phpseclib's key loader is static by design.
 */
class ArchiveCipher {
	/**
	 * The first bytes of an encrypted archive.
	 */
	public const MAGIC = "KEEPIQ-BACKUP-ENC-1\n";

	/**
	 * Plaintext bytes per segment.
	 */
	private const SEGMENT = 1048576;

	/**
	 * Whether a file is an encrypted archive.
	 *
	 * @param string $path The file
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#1.3
	 */
	public function isEncrypted(string $path): bool {
		$handle = fopen($path, 'rb');
		if ($handle === false) {
			return false;
		}

		$head = (string)fread($handle, strlen(self::MAGIC));
		fclose($handle);

		return $head === self::MAGIC;
	}//end isEncrypted()

	/**
	 * Whether a PEM is a usable RSA public key or certificate.
	 *
	 * @param string $pem The PEM
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#2.1
	 */
	public function isValidPublicKey(string $pem): bool {
		try {
			$this->publicKey(pem: $pem);
			return true;
		} catch (Throwable) {
			return false;
		}
	}//end isValidPublicKey()

	/**
	 * Encrypt a plaintext archive file to a public key.
	 *
	 * @param string $plainPath The plaintext archive
	 * @param string $outPath Where to write the encrypted archive
	 * @param string $publicPem The recipient public key or certificate
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the key is unusable
	 * @throws RuntimeException When a file cannot be read or written
	 *
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#1.3
	 */
	public function encryptFile(string $plainPath, string $outPath, string $publicPem): void {
		$contentKey = random_bytes(32);
		$wrapped = $this->publicKey(pem: $publicPem)->encrypt($contentKey);
		$header = (string)json_encode(
			[
				'format' => 'keepiq-vault-backup-enc-v1',
				'cipher' => 'AES-256-GCM',
				'keyWrap' => 'RSA-OAEP-SHA256',
				'segmentSize' => self::SEGMENT,
				'wrappedKey' => base64_encode((string)$wrapped),
			]
		);

		$source = $this->open(path: $plainPath, mode: 'rb');
		$sink = $this->open(path: $outPath, mode: 'wb');
		fwrite($sink, self::MAGIC . pack('N', strlen($header)) . $header);

		$index = 0;
		$chunk = (string)fread($source, self::SEGMENT);
		do {
			$next = (string)fread($source, self::SEGMENT);
			$final = ($next === '');
			$nonce = random_bytes(12);
			$tag = '';
			$cipher = openssl_encrypt(
				$chunk,
				'aes-256-gcm',
				$contentKey,
				OPENSSL_RAW_DATA,
				$nonce,
				$tag,
				$this->aad(index: $index, final: $final)
			);
			if ($cipher === false) {
				throw new RuntimeException('Archive encryption failed');
			}

			fwrite($sink, pack('N', strlen($cipher)) . $nonce . $cipher . $tag);
			$chunk = $next;
			$index++;
		} while ($final === false);

		fclose($source);
		fclose($sink);
	}//end encryptFile()

	/**
	 * Decrypt an encrypted archive with the private key.
	 *
	 * @param string $encPath The encrypted archive
	 * @param string $outPath Where to write the plaintext archive
	 * @param string $privatePem The recipient private key
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the key is wrong or the archive was changed
	 *
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#1.3
	 */
	public function decryptFile(string $encPath, string $outPath, string $privatePem): void {
		$source = $this->open(path: $encPath, mode: 'rb');
		try {
			if ((string)fread($source, strlen(self::MAGIC)) !== self::MAGIC) {
				throw new InvalidArgumentException('Not an encrypted Keepiq backup');
			}

			$header = json_decode((string)fread($source, (int)(unpack('N', (string)fread($source, 4))[1] ?? 0)), true);
			$contentKey = $this->unwrapKey(header: (array)$header, privatePem: $privatePem);
			$this->decryptSegments(source: $source, outPath: $outPath, contentKey: $contentKey);
		} finally {
			fclose($source);
		}
	}//end decryptFile()

	/**
	 * Unwrap the content key with the administrator's private key.
	 *
	 * @param array<string,mixed> $header The archive header
	 * @param string $privatePem The private key
	 *
	 * @return string The 32-byte content key
	 *
	 * @throws InvalidArgumentException When the key does not open the archive
	 */
	private function unwrapKey(array $header, string $privatePem): string {
		try {
			$private = PublicKeyLoader::load($privatePem);
			$wrapped = base64_decode((string)($header['wrappedKey'] ?? ''), true);
			$contentKey = null;
			if ($private instanceof RSA\PrivateKey && $wrapped !== false) {
				$contentKey = $private->withPadding(RSA::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256')
					->decrypt($wrapped);
			}
		} catch (Throwable) {
			$contentKey = null;
		}

		if (is_string($contentKey) === false || strlen($contentKey) !== 32) {
			throw new InvalidArgumentException('The key does not open this backup');
		}

		return $contentKey;
	}//end unwrapKey()

	/**
	 * Decrypt every segment into the output file, refusing any change,
	 * reorder or cut.
	 *
	 * @param resource $source The archive, positioned after the header
	 * @param string $outPath Where to write the plaintext
	 * @param string $contentKey The content key
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a segment fails or the last one is missing
	 */
	private function decryptSegments($source, string $outPath, string $contentKey): void {
		$sink = $this->open(path: $outPath, mode: 'wb');
		$index = 0;
		$sawFinal = false;
		try {
			while (($lengthBytes = (string)fread($source, 4)) !== '') {
				$length = (int)(unpack('N', $lengthBytes)[1] ?? 0);
				$nonce = (string)fread($source, 12);
				$cipher = '';
				if ($length > 0) {
					$cipher = (string)fread($source, $length);
				}

				$tag = (string)fread($source, 16);
				$final = $this->atEnd(handle: $source);
				$plain = openssl_decrypt($cipher, 'aes-256-gcm', $contentKey, OPENSSL_RAW_DATA, $nonce, $tag, $this->aad(index: $index, final: $final));
				if ($plain === false) {
					throw new InvalidArgumentException('The backup was changed or cut off');
				}

				fwrite($sink, $plain);
				$sawFinal = $final;
				$index++;
			}
		} finally {
			fclose($sink);
		}

		if ($sawFinal === false) {
			throw new InvalidArgumentException('The backup was changed or cut off');
		}
	}//end decryptSegments()

	/**
	 * The additional data that binds a segment to its place.
	 *
	 * @param int $index The segment index
	 * @param bool $final Whether it is the last segment
	 *
	 * @return string
	 */
	private function aad(int $index, bool $final): string {
		$flag = "\x00";
		if ($final === true) {
			$flag = "\x01";
		}

		return pack('J', $index) . $flag;
	}//end aad()

	/**
	 * Whether a stream has no more bytes, without consuming any.
	 *
	 * @param resource $handle The stream
	 *
	 * @return bool
	 */
	private function atEnd($handle): bool {
		$position = ftell($handle);
		$probe = fread($handle, 1);
		if ($probe === false || $probe === '') {
			return true;
		}

		fseek($handle, (int)$position);

		return false;
	}//end atEnd()

	/**
	 * Load a public key from a PEM key or certificate, OAEP-SHA256 ready.
	 *
	 * @param string $pem The PEM
	 *
	 * @return RSA\PublicKey
	 *
	 * @throws InvalidArgumentException When it is not an RSA public key
	 */
	private function publicKey(string $pem): RSA\PublicKey {
		$resource = openssl_pkey_get_public($pem);
		if ($resource === false) {
			throw new InvalidArgumentException('Not a public key or certificate');
		}

		$details = openssl_pkey_get_details($resource);
		$key = PublicKeyLoader::load((string)($details['key'] ?? ''));
		if (($key instanceof RSA\PublicKey) === false) {
			throw new InvalidArgumentException('Only RSA keys are supported');
		}

		return $key->withPadding(RSA::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256');
	}//end publicKey()

	/**
	 * Open a file or fail loudly.
	 *
	 * @param string $path The file
	 * @param string $mode The fopen mode
	 *
	 * @return resource
	 *
	 * @throws RuntimeException When it cannot be opened
	 */
	private function open(string $path, string $mode) {
		$handle = fopen($path, $mode);
		if ($handle === false) {
			throw new RuntimeException('Cannot open ' . $path);
		}

		return $handle;
	}//end open()
}//end class
