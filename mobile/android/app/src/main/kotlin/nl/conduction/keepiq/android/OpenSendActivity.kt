// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android

import android.content.Intent
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import nl.conduction.keepiq.android.ui.OpenSendScreen
import nl.conduction.keepiq.android.vault.AndroidClipboard
import nl.conduction.keepiq.android.vault.HandlerScheduler
import nl.conduction.keepiq.android.vault.VaultPreferences
import nl.conduction.keepiq.shared.vault.SensitiveClipboard

/**
 * Opens a Keepiq Send link in the app (task 3.6): a tapped link the user
 * chose to open with Keepiq, or a link shared to it. Needs no paired
 * account and no unlock: a Send is opened with the key in its link or its
 * password, as the public page does. The public page keeps working for
 * anyone without the app.
 */
class OpenSendActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val link = when (intent?.action) {
            Intent.ACTION_VIEW -> intent.dataString
            Intent.ACTION_SEND -> intent.getStringExtra(Intent.EXTRA_TEXT)?.let { text ->
                Regex("https://\\S+").find(text)?.value
            }
            else -> null
        } ?: ""
        setContent {
            MaterialTheme {
                Surface(Modifier.fillMaxSize()) {
                    val prefs = remember { VaultPreferences(this) }
                    val clipboard = remember {
                        SensitiveClipboard(AndroidClipboard(this), HandlerScheduler()) { prefs.clipboardClearSeconds }
                    }
                    OpenSendScreen(
                        initialLink = link,
                        modifier = Modifier.safeDrawingPadding().padding(8.dp),
                        onCopy = { clipboard.copy(it) },
                    )
                }
            }
        }
    }
}
