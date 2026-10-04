// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.crypto

/**
 * The private-key envelope and the unlock key.
 *
 * Envelope: base64(uint32 big-endian version 1, 16-byte salt, 12-byte IV,
 * AES-256-GCM ciphertext with its 16-byte tag), no AAD. Any other version is
 * refused before anything is decrypted. Source: src/crypto/envelope.js.
 *
 * Unlock key: PBKDF2-HMAC-SHA256, 600,000 iterations, over the UTF-8 master
 * password and the envelope's salt, 32 bytes. Source: src/crypto/aes.js.
 */
object PrivateKeyEnvelope {
    const val VERSION = 1L
    const val SALT_LENGTH = 16
    const val IV_LENGTH = 12
    const val TAG_LENGTH = 16
    const val PBKDF2_ITERATIONS = 600_000
    const val KEY_LENGTH = 32
    private const val HEADER_LENGTH = 4 + SALT_LENGTH + IV_LENGTH

    /** The parts of an envelope. [ciphertextWithTag] is the GCM ciphertext followed by its tag. */
    class Parts(val salt: ByteArray, val iv: ByteArray, val ciphertextWithTag: ByteArray)

    /** Splits an envelope; refuses a short one or a version other than 1. */
    fun decode(envelope: String): Parts {
        val raw = Encoding.fromBase64(envelope)
        if (raw.size < HEADER_LENGTH + TAG_LENGTH) throw KeepiqCryptoException("Envelope too short")
        val version = Encoding.readUint32BigEndian(raw, 0)
        if (version != VERSION) throw UnsupportedEnvelopeVersionException(version)
        return Parts(
            salt = raw.copyOfRange(4, 4 + SALT_LENGTH),
            iv = raw.copyOfRange(4 + SALT_LENGTH, HEADER_LENGTH),
            ciphertextWithTag = raw.copyOfRange(HEADER_LENGTH, raw.size),
        )
    }

    /** Joins the parts into the base64 envelope (encodeEnvelope). */
    fun encode(salt: ByteArray, iv: ByteArray, ciphertextWithTag: ByteArray): String =
        Encoding.toBase64(Encoding.uint32BigEndian(VERSION) + salt + iv + ciphertextWithTag)

    /** deriveUnlockKeyRaw: the 32-byte key that opens the envelope. */
    fun deriveUnlockKey(masterPassword: String, salt: ByteArray): ByteArray =
        Primitives.pbkdf2Sha256(Encoding.utf8(masterPassword), salt, PBKDF2_ITERATIONS, KEY_LENGTH)

    /** decryptPrivateKey: opens the envelope with the master password, returns the PKCS#8 PEM. */
    fun open(envelope: String, masterPassword: String): String {
        val parts = decode(envelope)
        return openParts(parts, deriveUnlockKey(masterPassword, parts.salt))
    }

    /** decryptPrivateKeyWithRawKey: opens the envelope with an unlock key held from earlier. */
    fun openWithUnlockKey(envelope: String, unlockKey: ByteArray): String = openParts(decode(envelope), unlockKey)

    /** encryptPrivateKey: seals a PEM under the master password with a fresh salt and IV. */
    fun seal(privateKeyPem: String, masterPassword: String): String {
        val salt = Primitives.randomBytes(SALT_LENGTH)
        val iv = Primitives.randomBytes(IV_LENGTH)
        val key = deriveUnlockKey(masterPassword, salt)
        return encode(salt, iv, Primitives.aesGcmEncrypt(key, iv, Encoding.utf8(privateKeyPem)))
    }

    private fun openParts(parts: Parts, key: ByteArray): String {
        if (key.size != KEY_LENGTH) throw KeepiqCryptoException("Unlock key must be $KEY_LENGTH bytes")
        return Encoding.fromUtf8(Primitives.aesGcmDecrypt(key, parts.iv, parts.ciphertextWithTag))
    }
}
