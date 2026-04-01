<?php

/**
 * Doriath Encrypt Service
 *
 * Stateless encryption service for RSA-OAEP and AES-256-GCM operations.
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

use InvalidArgumentException;
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
class EncryptService
{
    private const ENVELOPE_VERSION  = 1;
    private const RSA_CHUNK_SIZE    = 446;
    private const RSA_BLOCK_SIZE    = 512;
    private const AES_SALT_LENGTH   = 16;
    private const AES_IV_LENGTH     = 12;
    private const AES_TAG_LENGTH    = 16;
    private const PBKDF2_ITERATIONS = 600000;

    /**
     * Encrypt plaintext with an RSA public key using OAEP-SHA256 with chunking.
     *
     * @param string $plaintext    The data to encrypt
     * @param string $publicKeyPem PEM-encoded public key or certificate
     *
     * @return string Base64-encoded ciphertext: [4-byte chunk count][512-byte blocks...]
     */
    public function rsaEncrypt(string $plaintext, string $publicKeyPem): string
    {
        $publicKey = openssl_pkey_get_public($publicKeyPem);
        if ($publicKey === false) {
            throw new InvalidArgumentException('Invalid public key PEM');
        }

        $chunks = str_split($plaintext, self::RSA_CHUNK_SIZE);
        if (empty($chunks) === true) {
            $chunks = [''];
        }

        $chunkCount = count($chunks);
        $result     = pack('N', $chunkCount);

        foreach ($chunks as $chunk) {
            $encrypted = '';
            $success   = openssl_public_encrypt(
                $chunk,
                $encrypted,
                $publicKey,
                OPENSSL_PKCS1_OAEP_PADDING
            );

            if ($success === false) {
                throw new RuntimeException('RSA encryption failed: '.openssl_error_string());
            }

            if (strlen($encrypted) !== self::RSA_BLOCK_SIZE) {
                throw new RuntimeException(
                    'Unexpected RSA block size: '.strlen($encrypted).' (expected '.self::RSA_BLOCK_SIZE.')'
                );
            }

            $result .= $encrypted;
        }//end foreach

        return base64_encode($result);
    }//end rsaEncrypt()

    /**
     * Encrypt plaintext with AES-256-GCM using a raw 32-byte key.
     *
     * @param string $plaintext The data to encrypt
     * @param string $key       Raw 32-byte AES key
     *
     * @return string Base64-encoded envelope
     */
    public function aesEncrypt(string $plaintext, string $key): string
    {
        $ivector = random_bytes(self::AES_IV_LENGTH);
        $tag     = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $ivector,
            $tag,
            '',
            self::AES_TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new RuntimeException('AES-256-GCM encryption failed: '.openssl_error_string());
        }

        // Envelope: version(4) + salt(16) + IV(12) + ciphertext + tag(16)
        // Salt is empty here — use encryptPrivateKey() for PBKDF2-derived keys.
        $envelope = pack('N', self::ENVELOPE_VERSION)
            .str_repeat("\0", self::AES_SALT_LENGTH)
            .$ivector
            .$ciphertext
            .$tag;

        return base64_encode($envelope);
    }//end aesEncrypt()

    /**
     * Encrypt a PEM private key with an AES key derived from a password via PBKDF2-SHA256.
     *
     * @param string $pem      PEM-encoded private key
     * @param string $password The master password (or passphrase)
     *
     * @return string Base64-encoded envelope with salt embedded
     */
    public function encryptPrivateKey(string $pem, string $password): string
    {
        $salt = random_bytes(self::AES_SALT_LENGTH);
        $key  = $this->deriveKey(password: $password, salt: $salt);

        $ivector = random_bytes(self::AES_IV_LENGTH);
        $tag     = '';

        $ciphertext = openssl_encrypt(
            $pem,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $ivector,
            $tag,
            '',
            self::AES_TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new RuntimeException('AES-256-GCM encryption failed: '.openssl_error_string());
        }

        $envelope = pack('N', self::ENVELOPE_VERSION)
            .$salt
            .$ivector
            .$ciphertext
            .$tag;

        return base64_encode($envelope);
    }//end encryptPrivateKey()

    /**
     * Derive a 256-bit AES key from a password using PBKDF2-SHA256.
     *
     * @param string $password The password
     * @param string $salt     16-byte salt
     *
     * @return string Raw 32-byte key
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
