// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.vault

import nl.conduction.keepiq.shared.account.SecureStorage
import nl.conduction.keepiq.shared.api.Account
import nl.conduction.keepiq.shared.api.KeepiqApi
import nl.conduction.keepiq.shared.api.platformHttpEngine
import nl.conduction.keepiq.shared.store.IosEncryptedStore
import nl.conduction.keepiq.shared.store.UnlockKeySealer
import nl.conduction.keepiq.shared.store.VaultStore
import nl.conduction.keepiq.shared.sync.SyncListener
import nl.conduction.keepiq.shared.sync.VaultSync
import nl.conduction.keepiq.shared.unlock.UnlockedVault

/**
 * The iOS app's sessions with the offline store (task 1.6.1), as
 * VaultSession.open does it on Android.
 */
object IosVaultSession {
    /**
     * The session of a vault the unlock flow just opened: the account's
     * SQLCipher store, sealed with the unlock key, and its sync. [listener]
     * hears when a sync finds the keys changed. When the store cannot be
     * opened the session reads from the server only, as without one.
     */
    @Throws(Exception::class)
    fun open(account: Account, vault: UnlockedVault, storage: SecureStorage, listener: SyncListener): MobileSession {
        val api = KeepiqApi(KeepiqApi.httpClient(platformHttpEngine()), account)
        val keys = vault.keys()
        val store = try {
            openStore(account.id, vault, storage)
        } catch (e: Exception) {
            println("Keepiq keeps no offline copy: $e")
            null
        }
        val sync = store?.let { VaultSync(api, it, listener, clock = MobileSession::nowMillis) }
        return MobileSession(account, api, keys, store, sync)
    }

    /** Deletes the account's store and its key: unpair. */
    fun deleteStore(accountId: String, storage: SecureStorage) {
        IosEncryptedStore.delete(storage, accountId, IosEncryptedStore.defaultDirectory())
    }

    private fun openStore(accountId: String, vault: UnlockedVault, storage: SecureStorage): VaultStore {
        val unlockKey = vault.unlockKey()
        val sealer = try {
            UnlockKeySealer(unlockKey)
        } finally {
            unlockKey.fill(0)
        }
        return VaultStore(IosEncryptedStore.open(storage, accountId, IosEncryptedStore.defaultDirectory()), sealer)
    }
}
