// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.vault

import android.content.Context
import nl.conduction.keepiq.shared.api.Account
import nl.conduction.keepiq.shared.autofill.AutofillIndexHub
import nl.conduction.keepiq.shared.api.KeepiqApi
import nl.conduction.keepiq.shared.send.SendService
import nl.conduction.keepiq.shared.store.UnlockKeySealer
import nl.conduction.keepiq.shared.store.VaultStore
import nl.conduction.keepiq.shared.store.openEncryptedDriver
import nl.conduction.keepiq.shared.sync.SyncListener
import nl.conduction.keepiq.shared.sync.VaultSync
import nl.conduction.keepiq.shared.unlock.UnlockedVault
import nl.conduction.keepiq.shared.vault.VaultKeys
import nl.conduction.keepiq.shared.vault.VaultRepository

/**
 * One unlocked account, as the vault, Send and generator screens use it.
 * The unlock flow (task group 2) builds it from the paired [account], the
 * [keys] it opened, and the account's store and sync ([store] and [sync]
 * are null when the organisation keeps no offline copy).
 */
class VaultSession(
    val account: Account,
    val api: KeepiqApi,
    val keys: VaultKeys,
    private val store: VaultStore?,
    sync: VaultSync?,
) {
    val repository = VaultRepository(
        api, keys, store, sync,
        clock = { System.currentTimeMillis() },
        onRefreshed = { AutofillIndexHub.refreshed(account.id, it, keys) },
    )
    val sends = SendService(api)

    /** "alice on cloud.example.nl", for the account switcher. */
    val label: String get() = "${account.loginName} · ${account.server.removePrefix("https://").trimEnd('/')}"

    /**
     * On lock: the private key and the store's sealing key leave memory and
     * the store closes. Nothing decrypts with this session again.
     */
    fun close() {
        keys.forget()
        runCatching { store?.close() }
    }

    companion object {
        /**
         * The session of a vault the unlock flow just opened: the SQLCipher
         * store of the account, sealed with the unlock key (design D5), and
         * its sync. [listener] hears when a sync finds the keys changed.
         */
        fun open(context: Context, api: KeepiqApi, account: Account, vault: UnlockedVault, listener: SyncListener): VaultSession {
            val unlockKey = vault.unlockKey()
            val sealer = try {
                UnlockKeySealer(unlockKey)
            } finally {
                unlockKey.fill(0)
            }
            val store = VaultStore(openEncryptedDriver(context, account.id), sealer)
            val sync = VaultSync(api, store, listener, clock = { System.currentTimeMillis() })
            return VaultSession(account, api, vault.keys(), store, sync)
        }
    }
}
