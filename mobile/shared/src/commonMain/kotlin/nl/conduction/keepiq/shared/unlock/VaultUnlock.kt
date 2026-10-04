// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.unlock

import nl.conduction.keepiq.shared.api.Suite
import nl.conduction.keepiq.shared.crypto.Encoding
import nl.conduction.keepiq.shared.crypto.KeepiqCryptoException
import nl.conduction.keepiq.shared.crypto.PrivateKeyEnvelope

/** The server withholds the private key (`unlockBlocked`). [code] is the server's reason, such as `two_factor_required`. */
class UnlockBlockedException(val code: String) : Exception(blockedMessage(code))

/** The master password does not open the envelope. Nothing was unlocked. */
class WrongMasterPasswordException : Exception("This master password is not correct.")

/**
 * A stored unlock key (biometric or PIN) no longer opens the envelope: the
 * master password changed elsewhere. The wraps are deleted.
 */
class StaleUnlockKeyException : Exception("Your master password changed. Unlock with the new master password.")

/** The account has no vault yet: it was never set up in the web app. */
class NoVaultException : Exception("This account has no vault yet. Open Keepiq in the browser once to set it up.")

/**
 * An unlocked vault. The private key and the unlock key live in memory only
 * (design D4); [lock] overwrites the unlock key.
 */
class UnlockedVault(
    val accountId: String,
    val suiteId: String,
    val unlockKeyEpoch: Long?,
    val certificate: String?,
    val privateKeyPem: String,
    unlockKey: ByteArray,
) {
    private val key = unlockKey.copyOf()

    /** A copy of the 32-byte unlock key, to wrap it for biometric or PIN unlock. */
    fun unlockKey(): ByteArray = key.copyOf()

    /** The unlock key in base64, for the Swift side, which keeps it in the Keychain. */
    fun unlockKeyBase64(): String = Encoding.toBase64(key)

    fun lock() = key.fill(0)

    override fun toString(): String = "UnlockedVault(accountId=$accountId, suiteId=$suiteId)"
}

/**
 * Opens the private-key envelope of a suite (design D4, "First unlock"). A
 * suite with `unlockBlocked` is refused before any key is derived, and
 * carries no envelope anyway (admin-vault-policies D3).
 */
object VaultUnlock {
    @Throws(Exception::class)
    fun withMasterPassword(accountId: String, suite: Suite, masterPassword: String): UnlockedVault {
        val envelope = checkSuite(suite)
        val parts = try {
            PrivateKeyEnvelope.decode(envelope)
        } catch (e: KeepiqCryptoException) {
            throw KeepiqCryptoException("The vault on the server is in a format this app cannot open.", e)
        }
        val key = PrivateKeyEnvelope.deriveUnlockKey(masterPassword, parts.salt)
        val pem = try {
            PrivateKeyEnvelope.openWithUnlockKey(envelope, key)
        } catch (e: KeepiqCryptoException) {
            throw WrongMasterPasswordException()
        }
        return UnlockedVault(accountId, suite.id, suite.unlockKeyEpoch, suite.certificate, pem, key)
    }

    @Throws(Exception::class)
    fun withUnlockKey(accountId: String, suite: Suite, unlockKey: ByteArray): UnlockedVault {
        val envelope = checkSuite(suite)
        val pem = try {
            PrivateKeyEnvelope.openWithUnlockKey(envelope, unlockKey)
        } catch (e: KeepiqCryptoException) {
            throw StaleUnlockKeyException()
        }
        return UnlockedVault(accountId, suite.id, suite.unlockKeyEpoch, suite.certificate, pem, unlockKey)
    }

    private fun checkSuite(suite: Suite): String {
        suite.unlockBlocked?.let { throw UnlockBlockedException(it) }
        return suite.privateKey ?: throw NoVaultException()
    }
}

/** The words the unlock screen shows for a blocked unlock. */
fun blockedMessage(code: String): String = when (code) {
    "two_factor_required" ->
        "Your organisation requires two-factor authentication before you can open your vault. " +
            "Set it up in Nextcloud, under Personal settings and then Security, and come back."
    else -> "Your organisation does not allow unlocking your vault right now ($code)."
}
