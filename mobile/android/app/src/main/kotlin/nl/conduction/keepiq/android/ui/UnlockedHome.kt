// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.ui

import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import nl.conduction.keepiq.android.AppState
import nl.conduction.keepiq.shared.unlock.UnlockedVault

/**
 * What an open vault shows until the vault screens of task group 3 land:
 * proof of the unlock, the lock and the settings. The vault list replaces
 * the middle of this screen.
 */
@Composable
fun UnlockedHome(state: AppState, vault: UnlockedVault) {
    val account = state.client.accounts.account(vault.accountId)
    ScreenColumn {
        Text("Your vault is open", style = MaterialTheme.typography.headlineSmall, modifier = Modifier.semantics { heading() }.testTag("unlocked"))
        if (account != null) Text("${account.loginName} on ${account.server.removePrefix("https://")}")
        Text("Keepiq locks after ${state.effectiveIdleMinutes(vault.accountId)} minutes without use, and when the screen turns off.")
        Button(onClick = { state.lock() }, modifier = Modifier.fillMaxWidth().testTag("lock")) { Text("Lock now") }
        OutlinedButton(onClick = { state.openSettings(vault) }, modifier = Modifier.fillMaxWidth().testTag("settings")) {
            Text("Unlock and account settings")
        }
    }
}
