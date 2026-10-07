// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.autofill

import android.content.Context
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import nl.conduction.keepiq.android.AppState
import nl.conduction.keepiq.shared.account.SecureStorage
import nl.conduction.keepiq.shared.api.KeepiqApi
import nl.conduction.keepiq.shared.autofill.AutofillIndex
import nl.conduction.keepiq.shared.autofill.AutofillIndexHub
import nl.conduction.keepiq.shared.autofill.AutofillTarget
import nl.conduction.keepiq.shared.autofill.NeverSaveList
import nl.conduction.keepiq.shared.crypto.RsaFields
import nl.conduction.keepiq.shared.crypto.RsaPrivateKey
import nl.conduction.keepiq.shared.unlock.UnlockedVault
import nl.conduction.keepiq.shared.vault.VaultKeys
import java.security.SecureRandom
import java.util.concurrent.ConcurrentHashMap

/** A login typed into a form while the vault was locked, held until the user unlocks or for five minutes. */
class PendingSave(
    val accountId: String,
    val target: AutofillTarget,
    val login: String,
    val password: String,
    val appLabel: String?,
    val webScheme: String?,
    val expiresAt: Long,
) {
    override fun toString(): String = "PendingSave(target=$target)"
}

/**
 * What the autofill service and its unlock screen share in the app process
 * (design D4: on Android the services run in the app process, so they see
 * the app's unlocked vault): the index, app identities, the never-save list
 * and logins waiting for an unlock.
 */
class AutofillCore(context: Context, storage: SecureStorage, private val state: () -> AppState) {
    val index = AndroidAutofillIndex(context)
    val identities = AppIdentities(context, storage)
    val neverSave = NeverSaveList(storage)
    val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val pending = ConcurrentHashMap<String, PendingSave>()
    private var keysFor: Pair<UnlockedVault, VaultKeys>? = null

    init {
        AutofillIndexHub.sink = index
    }

    /** The active account, which autofill serves. */
    fun accountId(): String? = state().client.accounts.activeId()

    fun indexOf(accountId: String): AutofillIndex = index.index(accountId)

    /** The keys of the open vault of [accountId], or null while it is locked. */
    @Synchronized
    fun keys(accountId: String): VaultKeys? {
        val vault = state().openVault()?.takeIf { it.accountId == accountId } ?: return null
        keysFor?.let { (v, k) -> if (v === vault) return k }
        // The vault's own keys, forgotten when it locks; without a certificate, read only.
        val keys = try {
            if (vault.certificate != null) vault.keys() else DecryptOnlyKeys(RsaPrivateKey.fromPem(vault.privateKeyPem), vault.suiteId, vault.unlockKeyEpoch)
        } catch (e: Exception) {
            return null
        }
        keysFor = vault to keys
        return keys
    }

    fun api(accountId: String): KeepiqApi? = state().client.accounts.account(accountId)?.let { state().client.api(it) }

    fun hold(save: PendingSave): String {
        val now = System.currentTimeMillis()
        pending.entries.removeIf { it.value.expiresAt < now }
        val token = ByteArray(16).also { SecureRandom().nextBytes(it) }.joinToString("") { "%02x".format(it) }
        pending[token] = save
        return token
    }

    /** The held login for [token], once: it is forgotten as it is taken. */
    fun take(token: String?): PendingSave? =
        token?.let { pending.remove(it) }?.takeIf { it.expiresAt >= System.currentTimeMillis() }

    /** Unpair: the account's index goes with it. */
    fun forget(accountId: String) {
        AutofillIndexHub.clear(accountId)
    }

    /** Without a certificate the vault can still be read; saving needs one. */
    private class DecryptOnlyKeys(private val key: RsaPrivateKey, override val suiteId: String, override val unlockKeyEpoch: Long?) : VaultKeys {
        override fun decryptField(ciphertext: String?): String = if (ciphertext.isNullOrEmpty()) "" else RsaFields.decrypt(ciphertext, key)

        override fun encryptField(plaintext: String): String = throw IllegalStateException("This vault has no certificate to encrypt to")
    }

    companion object {
        const val PENDING_MILLIS = 5 * 60_000L
    }
}
