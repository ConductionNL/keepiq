// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android

import android.app.Application
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.os.Build
import androidx.core.content.ContextCompat
import androidx.lifecycle.DefaultLifecycleObserver
import androidx.lifecycle.LifecycleOwner
import androidx.lifecycle.ProcessLifecycleOwner
import nl.conduction.keepiq.android.autofill.AutofillCore
import nl.conduction.keepiq.android.security.BiometricUnlock
import nl.conduction.keepiq.android.security.KeystoreStorage
import nl.conduction.keepiq.android.vault.VaultSession
import nl.conduction.keepiq.shared.KeepiqClient
import nl.conduction.keepiq.shared.store.deleteEncryptedStore

/**
 * Holds the one [AppState] of the process. The vault is unlocked in memory
 * only, so a killed process is a locked vault.
 *
 * Locks (design D4, "Locking"): when the screen turns off, which is how a
 * phone locks, and when the idle time ran out while the app was away.
 */
class KeepiqApp : Application() {
    lateinit var state: AppState
        private set

    /** System autofill (task group 4): the index, app identities and the never-save list. */
    lateinit var autofill: AutofillCore
        private set

    override fun onCreate() {
        super.onCreate()
        val storage = KeystoreStorage(this)
        val client = KeepiqClient(storage, clientName())
        // System autofill first: unpair wipes its index (task 4.6). It reads
        // the state lazily, so it may exist before the state.
        autofill = AutofillCore(this, storage) { state }
        state = AppState(
            client,
            BiometricUnlock(this, storage),
            openSession = { account, vault, listener -> VaultSession.open(this, client.api(account), account, vault, listener) },
            // Unpair removes everything stored for the account: the offline copy and the autofill index.
            onAccountWiped = { accountId ->
                deleteEncryptedStore(this, accountId)
                autofill.forget(accountId)
            },
        )

        val screenOff = object : BroadcastReceiver() {
            override fun onReceive(context: Context, intent: Intent) {
                if (intent.action == Intent.ACTION_SCREEN_OFF) state.lock()
            }
        }
        ContextCompat.registerReceiver(this, screenOff, IntentFilter(Intent.ACTION_SCREEN_OFF), ContextCompat.RECEIVER_NOT_EXPORTED)

        ProcessLifecycleOwner.get().lifecycle.addObserver(
            object : DefaultLifecycleObserver {
                override fun onStart(owner: LifecycleOwner) {
                    state.onForeground()
                    // Autofill turned off in the system settings: no index on disk.
                    autofill.index.clearFilesIfNotTheService()
                }

                override fun onStop(owner: LifecycleOwner) = state.onBackground()
            },
        )
    }

    /**
     * What the Nextcloud device list shows for the app password: Nextcloud
     * names a Login Flow v2 app password after the User-Agent (task 2.4).
     */
    private fun clientName(): String = "Keepiq for Android (${Build.MANUFACTURER} ${Build.MODEL})"
}
