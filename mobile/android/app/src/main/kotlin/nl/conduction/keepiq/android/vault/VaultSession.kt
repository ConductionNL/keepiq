// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.vault

import nl.conduction.keepiq.shared.api.Account
import nl.conduction.keepiq.shared.autofill.AutofillIndexHub
import nl.conduction.keepiq.shared.api.KeepiqApi
import nl.conduction.keepiq.shared.send.SendService
import nl.conduction.keepiq.shared.store.VaultStore
import nl.conduction.keepiq.shared.sync.VaultSync
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
    store: VaultStore?,
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
}
