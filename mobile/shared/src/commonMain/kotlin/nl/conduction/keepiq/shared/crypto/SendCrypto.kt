// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.crypto

/**
 * Send crypto.
 *
 * Payload: a fresh AES-256-GCM key, stored as base64(12-byte IV + ciphertext
 * and tag). Without a password the raw key rides the link fragment
 * `#k=<base64url key>`. Source: src/send/sendCrypto.js.
 *
 * Password: Argon2id (65,536 KiB, 3 passes, parallelism 1, 32 bytes) over the
 * UTF-8 password and a 16-byte salt derives a key that wraps the raw key with
 * the same IV-prefixed AES-GCM blob. The request body carries `wrappedKey` and
 * `argon2idSalt` (base64). Sources: src/crypto/argon2.js,
 * src/store/modules/ephemeralSend.js createSend.
 */
object SendCrypto {
    const val IV_LENGTH = 12
    const val KEY_LENGTH = 32
    const val ARGON2_MEMORY_KIB = 65_536
    const val ARGON2_ITERATIONS = 3
    const val ARGON2_PARALLELISM = 1
    const val ARGON2_SALT_LENGTH = 16

    /** A sealed payload: [encryptedPayload] goes to the server, [rawKey] never does. */
    class Sealed(val encryptedPayload: String, val rawKey: ByteArray)

    /** The password wrap of a raw key, as the create request carries it. */
    class PasswordWrap(val wrappedKey: String, val argon2idSalt: String)

    /** aesEncrypt: base64(IV + ciphertext and tag) under [key] with a fresh IV. */
    fun aesEncrypt(key: ByteArray, plaintext: ByteArray): String {
        val iv = Primitives.randomBytes(IV_LENGTH)
        return Encoding.toBase64(iv + Primitives.aesGcmEncrypt(key, iv, plaintext))
    }

    /** aesDecrypt: opens an IV-prefixed blob. */
    fun aesDecrypt(key: ByteArray, blob: String): ByteArray {
        val combined = Encoding.fromBase64(blob)
        if (combined.size < IV_LENGTH + 16) throw KeepiqCryptoException("Send blob too short")
        return Primitives.aesGcmDecrypt(
            key,
            combined.copyOfRange(0, IV_LENGTH),
            combined.copyOfRange(IV_LENGTH, combined.size),
        )
    }

    /** sealPayload: encrypts [payload] under a fresh content key. */
    fun sealPayload(payload: String): Sealed {
        val rawKey = Primitives.randomBytes(KEY_LENGTH)
        return Sealed(aesEncrypt(rawKey, Encoding.utf8(payload)), rawKey)
    }

    /** Opens a payload with its raw content key. */
    fun openPayload(encryptedPayload: String, rawKey: ByteArray): String =
        Encoding.fromUtf8(aesDecrypt(rawKey, encryptedPayload))

    /**
     * sendLink: `{publicBase}/send/{token}`, plus `#k=<base64url key>` when the
     * send has no password. [publicBase] is the origin plus `/…/apps/keepiq/public`.
     */
    fun sendLink(publicBase: String, token: String, rawKey: ByteArray?): String {
        val base = "$publicBase/send/${encodeUriComponent(token)}"
        return if (rawKey != null) "$base#k=${Encoding.toBase64Url(rawKey)}" else base
    }

    /** The key-encryption key for a password send. */
    fun deriveKek(password: String, salt: ByteArray): ByteArray = argon2id(
        Encoding.utf8(password),
        salt,
        ARGON2_MEMORY_KIB,
        ARGON2_ITERATIONS,
        ARGON2_PARALLELISM,
        KEY_LENGTH,
    )

    /** Wraps a raw content key under a password, with a fresh salt. */
    fun wrapKey(rawKey: ByteArray, password: String): PasswordWrap {
        val salt = Primitives.randomBytes(ARGON2_SALT_LENGTH)
        return PasswordWrap(aesEncrypt(deriveKek(password, salt), rawKey), Encoding.toBase64(salt))
    }

    /** Unwraps the raw content key of a password send. Throws on a wrong password. */
    fun unwrapKey(wrappedKey: String, argon2idSalt: String, password: String): ByteArray =
        aesDecrypt(deriveKek(password, Encoding.fromBase64(argon2idSalt)), wrappedKey)

    /** JavaScript encodeURIComponent over UTF-8. */
    internal fun encodeUriComponent(value: String): String {
        val unreserved = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_.!~*'()"
        val hexDigits = "0123456789ABCDEF"
        val sb = StringBuilder()
        for (b in Encoding.utf8(value)) {
            val c = b.toInt() and 0xFF
            if (c < 0x80 && unreserved.indexOf(c.toChar()) >= 0) {
                sb.append(c.toChar())
            } else {
                sb.append('%').append(hexDigits[c shr 4]).append(hexDigits[c and 0xF])
            }
        }
        return sb.toString()
    }
}
