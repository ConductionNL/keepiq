// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.crypto

/**
 * The platform crypto primitives the core builds on (clients-mobile-apps
 * design D2, "Platform primitives"). Every format decision lives in common
 * code; an actual only runs the named primitive on raw bytes.
 *
 * - jvm() and Android: javax.crypto and java.security, Bouncy Castle for Argon2id.
 * - iOS: Security framework and CryptoKit through cryptography-kotlin.
 */
internal expect object Primitives {
    /** Cryptographically secure random bytes. */
    fun randomBytes(size: Int): ByteArray

    /** SHA-256 digest. */
    fun sha256(data: ByteArray): ByteArray

    /** HMAC with SHA-1, SHA-256 or SHA-512. */
    fun hmac(algorithm: HmacAlgorithm, key: ByteArray, data: ByteArray): ByteArray

    /** PBKDF2-HMAC-SHA256 over the password BYTES (the caller UTF-8 encodes). */
    fun pbkdf2Sha256(password: ByteArray, salt: ByteArray, iterations: Int, lengthBytes: Int): ByteArray

    /** AES-256-GCM, 128-bit tag, no AAD. Returns ciphertext followed by the tag. */
    fun aesGcmEncrypt(key: ByteArray, iv: ByteArray, plaintext: ByteArray): ByteArray

    /** AES-256-GCM, 128-bit tag, no AAD. Throws when the tag does not verify. */
    fun aesGcmDecrypt(key: ByteArray, iv: ByteArray, ciphertextAndTag: ByteArray): ByteArray

    /** RSA-OAEP with SHA-256 for the hash and MGF1, empty label. [spki] is DER SubjectPublicKeyInfo. */
    fun rsaOaepSha256Encrypt(spki: ByteArray, plaintext: ByteArray): ByteArray

    /** RSA-OAEP with SHA-256 for the hash and MGF1, empty label. [pkcs8] is DER PrivateKeyInfo. */
    fun rsaOaepSha256Decrypt(pkcs8: ByteArray, block: ByteArray): ByteArray

    /** ECDSA P-256 with SHA-256 over [data]. [pkcs8] is DER PrivateKeyInfo. Returns a DER signature. */
    fun ecdsaP256Sign(pkcs8: ByteArray, data: ByteArray): ByteArray

    /** Verifies a DER ECDSA P-256 / SHA-256 signature. [spki] is DER SubjectPublicKeyInfo. */
    fun ecdsaP256Verify(spki: ByteArray, data: ByteArray, signatureDer: ByteArray): Boolean
}

/** HMAC hash functions TOTP accepts (src/totp/totp.js HASH_BY_ALGORITHM). */
internal enum class HmacAlgorithm { SHA1, SHA256, SHA512 }

/**
 * Argon2id (RFC 9106, version 0x13) over the password BYTES.
 *
 * jvm() and Android use Bouncy Castle. On iOS this is not available yet and
 * throws [UnsupportedOperationException]; see [argon2idAvailable].
 */
internal expect fun argon2id(
    password: ByteArray,
    salt: ByteArray,
    memoryKiB: Int,
    iterations: Int,
    parallelism: Int,
    lengthBytes: Int,
): ByteArray

/** Whether [argon2id] works on this target. */
internal expect val argon2idAvailable: Boolean

/** A crypto operation failed or its input is not in a format the web app writes. */
open class KeepiqCryptoException(message: String, cause: Throwable? = null) : Exception(message, cause)

/** A private-key envelope whose version word is not 1 (src/crypto/envelope.js). */
class UnsupportedEnvelopeVersionException(val version: Long) :
    KeepiqCryptoException("Unsupported envelope version: $version")
