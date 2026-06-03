<?php

/**
 * Doriath Decrypt Service
 *
 * Stateless decryption service for RSA-OAEP and AES-256-GCM operations.
 *
 * @category Service
 * @package  OCA\Doriath\Service
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

namespace OCA\Doriath\Service;

use OCA\Doriath\Exception\DecryptionException;

/**
 * Stateless decryption service. No database access, no entity awareness.
 *
 * Implements:
 * - RSA-OAEP-SHA256 chunk parsing (D5): read chunk count, decrypt each 512-byte block
 * - AES-256-GCM envelope parsing (D4): parse version/salt/IV/ciphertext/tag
 *
 * The encrypted blobs are base64-encoded.
 */
class DecryptService
{
    private const ENVELOPE_VERSION  = 1;
    private const RSA_BLOCK_SIZE    = 512;
    private const AES_SALT_LENGTH   = 16;
    private const AES_IV_LENGTH     = 12;
    private const AES_TAG_LENGTH    = 16;
    private const PBKDF2_ITERATIONS = 600000;

    /**
     * Decrypt RSA-OAEP-SHA256 chunked ciphertext with a private key.
     *
     * @param string $ciphertext    Base64-encoded chunked ciphertext
     * @param string $privateKeyPem PEM-encoded private key (decrypted)
     *
     * @return string Decrypted plaintext
     *
     * @throws DecryptionException
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-3
     */
    public function rsaDecrypt(string $ciphertext, string $privateKeyPem): string
    {
        $raw = base64_decode($ciphertext, true);
        if ($raw === false || strlen($raw) < 4) {
            throw new DecryptionException(message:'Invalid RSA ciphertext format');
        }

        $privateKey = openssl_pkey_get_private(private_key: $privateKeyPem);
        if ($privateKey === false) {
            throw new DecryptionException(message:'Invalid private key PEM');
        }

        $chunkCount     = unpack('N', substr($raw, 0, 4))[1];
        $expectedLength = 4 + ($chunkCount * self::RSA_BLOCK_SIZE);
        if (strlen($raw) !== $expectedLength) {
            throw new DecryptionException(
                message: "RSA ciphertext length mismatch: expected {$expectedLength}, got ".strlen($raw)
            );
        }

        $plaintext = '';
        for ($i = 0; $i < $chunkCount; $i++) {
            $block     = substr($raw, 4 + ($i * self::RSA_BLOCK_SIZE), self::RSA_BLOCK_SIZE);
            $decrypted = '';
            $success   = openssl_private_decrypt(
                data: $block,
                decrypted_data: $decrypted,
                private_key: $privateKey,
                padding: OPENSSL_PKCS1_OAEP_PADDING
            );

            // @codeCoverageIgnoreStart
            if ($success === false) {
                throw new DecryptionException(message:'RSA decryption failed for chunk '.$i.': '.openssl_error_string());
            }

            // @codeCoverageIgnoreEnd
            $plaintext .= $decrypted;
        }

        return $plaintext;
    }//end rsaDecrypt()

    /**
     * Decrypt AES-256-GCM envelope with a raw 32-byte key.
     *
     * @param string $envelope Base64-encoded envelope
     * @param string $key      Raw 32-byte AES key
     *
     * @return string Decrypted plaintext
     *
     * @throws DecryptionException
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-3
     */
    public function aesDecrypt(string $envelope, string $key): string
    {
        $raw = base64_decode($envelope, true);
        if ($raw === false) {
            throw new DecryptionException(message:'Invalid base64 envelope');
        }

        $minLength = 4 + self::AES_SALT_LENGTH + self::AES_IV_LENGTH + self::AES_TAG_LENGTH;
        if (strlen($raw) < $minLength) {
            throw new DecryptionException(message:'Envelope too short');
        }

        $version = unpack('N', substr($raw, 0, 4))[1];
        if ($version !== self::ENVELOPE_VERSION) {
            throw new DecryptionException(message:"Unsupported envelope version: {$version}");
        }

        $offset  = 4 + self::AES_SALT_LENGTH;
        $ivector = substr($raw, $offset, self::AES_IV_LENGTH);
        $offset += self::AES_IV_LENGTH;

        $ciphertext = substr($raw, $offset, -self::AES_TAG_LENGTH);
        $tag        = substr($raw, -self::AES_TAG_LENGTH);

        $plaintext = openssl_decrypt(
            data: $ciphertext,
            cipher_algo: 'aes-256-gcm',
            passphrase: $key,
            options: OPENSSL_RAW_DATA,
            iv: $ivector,
            tag: $tag
        );

        if ($plaintext === false) {
            throw new DecryptionException(message:'AES-256-GCM decryption failed (authentication tag mismatch)');
        }

        return $plaintext;
    }//end aesDecrypt()

    /**
     * Decrypt a PEM private key from an AES envelope using a password.
     *
     * @param string $blob     Base64-encoded envelope containing the encrypted private key
     * @param string $password The master password (or passphrase)
     *
     * @return string PEM-encoded private key
     *
     * @throws DecryptionException
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-3
     */
    public function decryptPrivateKey(string $blob, string $password): string
    {
        $raw = base64_decode($blob, true);
        if ($raw === false) {
            throw new DecryptionException(message:'Invalid base64 envelope');
        }

        $minLength = 4 + self::AES_SALT_LENGTH + self::AES_IV_LENGTH + self::AES_TAG_LENGTH;
        if (strlen($raw) < $minLength) {
            throw new DecryptionException(message:'Envelope too short');
        }

        $version = unpack('N', substr($raw, 0, 4))[1];
        if ($version !== self::ENVELOPE_VERSION) {
            throw new DecryptionException(message:"Unsupported envelope version: {$version}");
        }

        $salt = substr($raw, 4, self::AES_SALT_LENGTH);
        $key  = $this->deriveKey(password: $password, salt: $salt);

        $offset  = 4 + self::AES_SALT_LENGTH;
        $ivector = substr($raw, $offset, self::AES_IV_LENGTH);
        $offset += self::AES_IV_LENGTH;

        $ciphertext = substr($raw, $offset, -self::AES_TAG_LENGTH);
        $tag        = substr($raw, -self::AES_TAG_LENGTH);

        $plaintext = openssl_decrypt(
            data: $ciphertext,
            cipher_algo: 'aes-256-gcm',
            passphrase: $key,
            options: OPENSSL_RAW_DATA,
            iv: $ivector,
            tag: $tag
        );

        if ($plaintext === false) {
            throw new DecryptionException(message:'Private key decryption failed (wrong password or corrupted blob)');
        }

        return $plaintext;
    }//end decryptPrivateKey()

    /**
     * Derive a 256-bit AES key from a password using PBKDF2-SHA256.
     *
     * @param string $password The password
     * @param string $salt     16-byte salt
     *
     * @return string Raw 32-byte key
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-3
     */
    public function deriveKey(string $password, string $salt): string
    {
        return hash_pbkdf2(
            'sha256',
            $password,
            $salt,
            self::PBKDF2_ITERATIONS,
            32,
            true
        );
    }//end deriveKey()
}//end class
