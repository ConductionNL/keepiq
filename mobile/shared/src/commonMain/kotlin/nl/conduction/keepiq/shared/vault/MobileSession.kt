// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.vault

import nl.conduction.keepiq.shared.api.Account
import nl.conduction.keepiq.shared.api.KeepiqApi
import nl.conduction.keepiq.shared.api.platformHttpEngine
import nl.conduction.keepiq.shared.send.SendService
import nl.conduction.keepiq.shared.store.VaultStore
import nl.conduction.keepiq.shared.sync.VaultSync
import kotlin.time.Clock
import kotlin.time.ExperimentalTime

/**
 * One unlocked account as the vault, Send and generator screens use it,
 * built in common code so the iOS app gets it without Ktor or clock types.
 */
class MobileSession(
    val account: Account,
    val api: KeepiqApi,
    val keys: VaultKeys,
    store: VaultStore?,
    sync: VaultSync?,
) {
    val repository = VaultRepository(api, keys, store, sync, clock = ::nowMillis)
    val sends = SendService(api)

    /** "alice · cloud.example.nl", for the account switcher. */
    val label: String get() = labelOf(account)

    companion object {
        /** Without an offline store (iOS until task 1.6.1): the vault is read from the server. */
        fun online(account: Account, keys: VaultKeys): MobileSession =
            MobileSession(account, KeepiqApi(KeepiqApi.httpClient(platformHttpEngine()), account), keys, null, null)

        fun labelOf(account: Account): String =
            "${account.loginName} · ${account.server.removePrefix("https://").trimEnd('/')}"

        @OptIn(ExperimentalTime::class)
        fun nowMillis(): Long = Clock.System.now().toEpochMilliseconds()
    }
}
