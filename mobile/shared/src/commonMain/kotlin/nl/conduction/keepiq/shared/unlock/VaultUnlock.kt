// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.unlock

import nl.conduction.keepiq.shared.api.Suite
import nl.conduction.keepiq.shared.crypto.Encoding
import nl.conduction.keepiq.shared.crypto.KeepiqCryptoException
import nl.conduction.keepiq.shared.crypto.PrivateKeyEnvelope
import nl.conduction.keepiq.shared.vault.RsaVaultKeys
import nl.conduction.keepiq.shared.vault.VaultKeys
import nl.conduction.keepiq.shared.vault.VaultLockedException

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
 * (design D4). [lock] overwrites the unlock key and the private key bytes of
 * the [keys] handed out, and drops the PEM text, so nothing decrypts after
 * it. The PEM is an immutable string: lock drops the last reference to it,
 * which is as far as the JVM and Kotlin/Native let a string be erased.
 */
class UnlockedVault(
    val accountId: String,
    val suiteId: String,
    val unlockKeyEpoch: Long?,
    val certificate: String?,
    privateKeyPem: String,
    unlockKey: ByteArray,
) {
    private val key = unlockKey.copyOf()
    private var pem: String? = privateKeyPem
    private var vaultKeys: RsaVaultKeys? = null

    /** True once [lock] ran. */
    var isLocked: Boolean = false
        private set

    /** The opened private key (PKCS#8 PEM). Throws once locked. */
    val privateKeyPem: String get() = pem ?: throw VaultLockedException()

    /** A copy of the 32-byte unlock key, to wrap it for biometric or PIN unlock. */
    @Throws(VaultLockedException::class)
    fun unlockKey(): ByteArray {
        if (isLocked) throw VaultLockedException()
        return key.copyOf()
    }

    /** The unlock key in base64, for the Swift side, which keeps it in the Keychain. */
    @Throws(VaultLockedException::class)
    fun unlockKeyBase64(): String = Encoding.toBase64(unlockKey())

    /**
     * The keys the vault, Send and generator screens encrypt and decrypt
     * with, built once from the opened key and the suite's certificate.
     */
    @Throws(VaultLockedException::class, NoVaultException::class)
    fun keys(): VaultKeys {
        if (isLocked) throw VaultLockedException()
        vaultKeys?.let { return it }
        val cert = certificate ?: throw NoVaultException()
        return RsaVaultKeys.fromPem(privateKeyPem, cert, suiteId, unlockKeyEpoch).also { vaultKeys = it }
    }

    fun lock() {
        key.fill(0)
        vaultKeys?.forget()
        vaultKeys = null
        pem = null
        isLocked = true
    }

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
