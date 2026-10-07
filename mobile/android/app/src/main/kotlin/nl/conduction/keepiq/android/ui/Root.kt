// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import androidx.fragment.app.FragmentActivity
import nl.conduction.keepiq.android.AppState
import nl.conduction.keepiq.android.Screen

/** The app: one screen at a time, chosen by [AppState.screen]. */
@Composable
fun KeepiqRoot(state: AppState, activity: FragmentActivity, openBrowser: (String) -> Unit) {
    val screen by state.screen.collectAsState()
    MaterialTheme(colorScheme = if (isSystemInDarkTheme()) darkColorScheme() else lightColorScheme()) {
        Surface(modifier = Modifier.fillMaxSize()) {
            when (val s = screen) {
                Screen.Pair -> PairScreen(state, openBrowser)
                is Screen.Unlock -> UnlockScreen(state, activity, s.accountId)
                is Screen.Unlocked -> VaultApp(
                    session = s.session,
                    accounts = state.client.accounts.accounts(),
                    onSwitchAccount = { state.switchAccount(it.id) },
                    onAddAccount = { state.addAccount() },
                    onLock = { state.lock() },
                    onSettings = { state.openSettings(s.vault) },
                    onLocked = { reason -> state.lock(reason) },
                )
                is Screen.Settings -> SettingsScreen(state, activity, s.vault)
            }
        }
    }
}

/** A scrolling column with the app's margins, clear of the system bars and the keyboard. */
@Composable
internal fun ScreenColumn(content: @Composable ColumnScope.() -> Unit) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .safeDrawingPadding()
            .imePadding()
            .verticalScroll(rememberScrollState())
            .padding(horizontal = 24.dp, vertical = 32.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp),
        content = content,
    )
}

/** The last problem, read out by TalkBack when it appears. */
@Composable
internal fun Problem(message: String?) {
    if (message == null) return
    Text(
        message,
        color = MaterialTheme.colorScheme.error,
        modifier = Modifier
            .fillMaxWidth()
            .testTag("message")
            .semantics { liveRegion = LiveRegionMode.Polite },
    )
}
