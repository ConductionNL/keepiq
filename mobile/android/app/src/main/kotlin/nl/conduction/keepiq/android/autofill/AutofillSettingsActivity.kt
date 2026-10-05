// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.autofill

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.provider.Settings
import android.view.autofill.AutofillManager
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.material3.Button
import androidx.compose.material3.ListItem
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import nl.conduction.keepiq.android.KeepiqApp
import nl.conduction.keepiq.android.R
import nl.conduction.keepiq.android.ui.ScreenColumn

/**
 * Keepiq's autofill settings, which Android links from its autofill
 * service settings: whether Keepiq fills, the button to choose it, the
 * never-save list with a way to take a site off it, and turning autofill
 * off, which deletes the index on the phone.
 */
class AutofillSettingsActivity : ComponentActivity() {
    private val core: AutofillCore get() = (application as KeepiqApp).autofill
    private var enabled by mutableStateOf(false)
    private var never by mutableStateOf(emptyList<String>())

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent {
            MaterialTheme(colorScheme = if (isSystemInDarkTheme()) darkColorScheme() else lightColorScheme()) {
                Surface(modifier = Modifier.fillMaxSize()) {
                    ScreenColumn {
                        Text(stringResource(R.string.autofill_settings_title), style = MaterialTheme.typography.headlineSmall, modifier = Modifier.semantics { heading() })
                        Text(stringResource(if (enabled) R.string.autofill_status_on else R.string.autofill_status_off), modifier = Modifier.testTag("autofillStatus"))
                        if (enabled) {
                            OutlinedButton(onClick = ::turnOff, modifier = Modifier.fillMaxWidth().testTag("autofillOff")) { Text(stringResource(R.string.autofill_turn_off)) }
                        } else {
                            Button(onClick = ::chooseService, modifier = Modifier.fillMaxWidth().testTag("autofillChoose")) { Text(stringResource(R.string.autofill_choose_service)) }
                        }
                        Text(stringResource(R.string.autofill_never_title), style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
                        if (never.isEmpty()) Text(stringResource(R.string.autofill_never_empty))
                        for (site in never) {
                            ListItem(
                                headlineContent = { Text(site) },
                                trailingContent = {
                                    TextButton(onClick = { never = core.neverSave.set(site, false) }, modifier = Modifier.testTag("neverRemove")) {
                                        Text(stringResource(R.string.autofill_never_remove, site))
                                    }
                                },
                            )
                        }
                    }
                }
            }
        }
    }

    override fun onResume() {
        super.onResume()
        enabled = core.index.isAutofillService()
        never = core.neverSave.sites()
        core.index.clearFilesIfNotTheService()
    }

    private fun chooseService() {
        startActivity(Intent(Settings.ACTION_REQUEST_SET_AUTOFILL_SERVICE).setData(Uri.parse("package:$packageName")))
    }

    private fun turnOff() {
        getSystemService(AutofillManager::class.java)?.disableAutofillServices()
        core.index.clearAll()
        enabled = core.index.isAutofillService()
    }
}
