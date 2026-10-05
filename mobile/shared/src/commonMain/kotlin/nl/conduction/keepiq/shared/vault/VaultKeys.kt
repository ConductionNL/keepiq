// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.vault

import nl.conduction.keepiq.shared.crypto.RsaFields
import nl.conduction.keepiq.shared.crypto.RsaPrivateKey
import nl.conduction.keepiq.shared.crypto.RsaPublicKey
import nl.conduction.keepiq.shared.sync.UnlockedSession

/**
 * The key material of an unlocked vault, as the vault, Send and generator
 * screens need it. The unlock flow (task group 2) produces it; these screens
 * only consume it, so they never see the master password or the envelope.
 *
 * Fields are encrypted to the active suite's public key, the key the vault
 * was unlocked with, as the web app (src/store/modules/secret.js, the
 * session certificate) and the extension (browser-extension/src/lib/vault.js
 * encryptField) do. There is no per-item key (design D2).
 */
interface VaultKeys {
    /** The active suite the vault was unlocked with. */
    val suiteId: String

    /** The suite's `unlockKeyEpoch` at unlock, or null when the server sends none. */
    val unlockKeyEpoch: Long?

    /** Decrypts one field. An absent or empty ciphertext reads as "", as in vault.js decryptField. */
    fun decryptField(ciphertext: String?): String

    /** Encrypts one field to the suite's public key (src/crypto/rsa.js rsaEncrypt). */
    fun encryptField(plaintext: String): String

    /** Drops the private key from memory, on lock. Every later call throws [VaultLockedException]. */
    fun forget() {}
}

/** The vault was locked: its key is gone from memory. */
class VaultLockedException : IllegalStateException("The vault is locked.")

/** [VaultKeys] over the decrypted private key and the suite's certificate, held in memory only. */
class RsaVaultKeys(
    private val privateKey: RsaPrivateKey,
    private val publicKey: RsaPublicKey,
    override val suiteId: String,
    override val unlockKeyEpoch: Long?,
) : VaultKeys {
    override fun decryptField(ciphertext: String?): String {
        if (privateKey.forgotten) throw VaultLockedException()
        return if (ciphertext.isNullOrEmpty()) "" else RsaFields.decrypt(ciphertext, privateKey)
    }

    override fun encryptField(plaintext: String): String {
        if (privateKey.forgotten) throw VaultLockedException()
        return RsaFields.encrypt(plaintext, publicKey)
    }

    override fun forget() = privateKey.forget()

    companion object {
        /**
         * From the opened envelope (PKCS#8 PEM) and the suite's certificate or
         * SPKI PEM, as browser-extension/src/lib/vault.js hold() keeps them.
         */
        fun fromPem(privateKeyPem: String, certificatePem: String, suiteId: String, unlockKeyEpoch: Long?): RsaVaultKeys =
            RsaVaultKeys(RsaPrivateKey.fromPem(privateKeyPem), RsaPublicKey.fromPem(certificatePem), suiteId, unlockKeyEpoch)
    }
}

/** The session [nl.conduction.keepiq.shared.sync.VaultSync] compares a manifest with. */
fun VaultKeys.syncSession(): UnlockedSession = UnlockedSession(suiteId, unlockKeyEpoch)
