// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.unlock

import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json
import nl.conduction.keepiq.shared.account.SecureStorage
import nl.conduction.keepiq.shared.crypto.Encoding
import nl.conduction.keepiq.shared.crypto.KeepiqCryptoException
import nl.conduction.keepiq.shared.crypto.Primitives
import nl.conduction.keepiq.shared.crypto.SendCrypto
import nl.conduction.keepiq.shared.crypto.argon2idAvailable

/** A wrong PIN, or the PIN was wiped. [triesLeft] is 0 when it was wiped. */
class WrongPinException(val triesLeft: Int) : Exception(
    when (triesLeft) {
        0 -> "Too many wrong PINs. Unlock with your master password."
        1 -> "Wrong PIN. 1 try left."
        else -> "Wrong PIN. $triesLeft tries left."
    },
)

@Serializable
private data class PinRecord(val salt: String, val wrapped: String, val attempts: Int = 0)

/**
 * Unlock with a PIN (design D4), the scheme of
 * browser-extension/src/lib/pin-unlock.js: the 32-byte unlock key is wrapped
 * with AES-256-GCM under Argon2id(PIN) with a fresh 16-byte salt (the Send
 * parameters: 64 MiB, 3 passes, 1 lane). After [MAX_ATTEMPTS] wrong PINs the
 * wrap is deleted.
 *
 * Unlike the extension, the wrap survives a restart. It stays bound to the
 * device because [SecureStorage] seals it under a Keystore or Keychain key.
 */
class PinUnlock(private val storage: SecureStorage) {
    private val json = Json { ignoreUnknownKeys = true }

    /** Whether PIN unlock works on this platform (Argon2id is available). */
    val available: Boolean get() = argon2idAvailable

    fun has(accountId: String): Boolean = record(accountId) != null

    /** Wraps [unlockKey] under [pin]. Throws [IllegalArgumentException] with the reason for a PIN that is too short or long. */
    @Throws(Exception::class)
    fun set(accountId: String, unlockKey: ByteArray, pin: String) {
        problem(pin)?.let { throw IllegalArgumentException(it) }
        if (unlockKey.size != 32) throw KeepiqCryptoException("The unlock key must be 32 bytes")
        val salt = Primitives.randomBytes(SendCrypto.ARGON2_SALT_LENGTH)
        val wrapped = SendCrypto.aesEncrypt(SendCrypto.deriveKek(pin, salt), unlockKey)
        storage.write(key(accountId), json.encodeToString(PinRecord.serializer(), PinRecord(Encoding.toBase64(salt), wrapped)))
    }

    /** The unlock key, or [WrongPinException]. The fifth wrong PIN deletes the wrap. */
    @Throws(Exception::class)
    fun open(accountId: String, pin: String): ByteArray {
        val record = record(accountId) ?: throw WrongPinException(0)
        val kek = SendCrypto.deriveKek(pin, Encoding.fromBase64(record.salt))
        return try {
            SendCrypto.aesDecrypt(kek, record.wrapped).also {
                if (record.attempts != 0) storage.write(key(accountId), json.encodeToString(PinRecord.serializer(), record.copy(attempts = 0)))
            }
        } catch (e: KeepiqCryptoException) {
            val attempts = record.attempts + 1
            if (attempts >= MAX_ATTEMPTS) {
                remove(accountId)
                throw WrongPinException(0)
            }
            storage.write(key(accountId), json.encodeToString(PinRecord.serializer(), record.copy(attempts = attempts)))
            throw WrongPinException(MAX_ATTEMPTS - attempts)
        }
    }

    fun remove(accountId: String) = storage.delete(key(accountId))

    private fun record(accountId: String): PinRecord? =
        storage.read(key(accountId))?.let { runCatching { json.decodeFromString(PinRecord.serializer(), it) }.getOrNull() }

    private fun key(accountId: String) = "pin:$accountId"

    companion object {
        const val MAX_ATTEMPTS = 5
        const val MIN_LENGTH = 6
        const val MAX_LENGTH = 64

        /** What is wrong with a PIN, or null (pin-unlock.js pinProblem). */
        fun problem(pin: String): String? = when {
            pin.length < MIN_LENGTH -> "A PIN has at least $MIN_LENGTH characters."
            pin.length > MAX_LENGTH -> "A PIN has at most $MAX_LENGTH characters."
            else -> null
        }
    }
}
