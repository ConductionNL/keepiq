// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.ui

import android.content.Intent
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.selection.selectableGroup
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.fragment.app.FragmentActivity
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import nl.conduction.keepiq.android.AppState
import nl.conduction.keepiq.android.R
import nl.conduction.keepiq.android.autofill.AutofillSettingsActivity
import nl.conduction.keepiq.shared.account.IdlePolicy
import nl.conduction.keepiq.shared.unlock.PinUnlock
import nl.conduction.keepiq.shared.unlock.UnlockedVault

/**
 * Autofill from inside the app: whether Keepiq is the autofill service, a
 * button to choose it, and the way to the never-save list. Without this the
 * autofill settings are only reachable from Android's own settings. The
 * status is read again whenever the screen comes back from Android's picker.
 */
@Composable
fun AutofillEntry() {
    val context = LocalContext.current
    val lifecycle = LocalLifecycleOwner.current.lifecycle
    var enabled by remember { mutableStateOf(AutofillSettingsActivity.isKeepiq(context)) }
    DisposableEffect(lifecycle) {
        val observer = LifecycleEventObserver { _, event ->
            if (event == Lifecycle.Event.ON_RESUME) enabled = AutofillSettingsActivity.isKeepiq(context)
        }
        lifecycle.addObserver(observer)
        onDispose { lifecycle.removeObserver(observer) }
    }
    AutofillEntry(
        enabled = enabled,
        onChoose = { AutofillSettingsActivity.chooseKeepiq(context) },
        onNeverList = { context.startActivity(Intent(context, AutofillSettingsActivity::class.java)) },
    )
}

/** The autofill entry of the settings screen, without the system calls. */
@Composable
fun AutofillEntry(enabled: Boolean, onChoose: () -> Unit, onNeverList: () -> Unit) {
    Text(stringResource(R.string.autofill_settings_title), style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
    Text(stringResource(if (enabled) R.string.autofill_status_on else R.string.autofill_status_off), modifier = Modifier.testTag("settingsAutofillStatus"))
    if (!enabled) {
        Button(onClick = onChoose, modifier = Modifier.fillMaxWidth().testTag("settingsAutofillChoose")) {
            Text(stringResource(R.string.autofill_choose_service))
        }
    }
    OutlinedButton(onClick = onNeverList, modifier = Modifier.fillMaxWidth().testTag("settingsAutofillNever")) {
        Text(stringResource(R.string.autofill_open_never_list))
    }
}

/** Unlock options (2.3), auto-lock (2.5), autofill (group 4) and disconnect (2.6) for the open vault. */
@Composable
fun SettingsScreen(state: AppState, activity: FragmentActivity, vault: UnlockedVault) {
    val busy by state.busy.collectAsState()
    val message by state.message.collectAsState()
    val maxIdle by state.maxIdle.collectAsState()
    // Re-read after every change; the store is the truth.
    var revision by remember { mutableIntStateOf(0) }
    val settings = remember(revision, busy) { state.settings(vault.accountId) }
    val biometricOn = remember(revision, busy) { state.biometric.isEnabled(vault.accountId) }
    val pinSet = remember(revision, busy) { state.client.pins.has(vault.accountId) }
    var newPin by remember { mutableStateOf("") }
    var confirmUnpair by remember { mutableStateOf(false) }
    val account = state.client.accounts.account(vault.accountId)

    ScreenColumn {
        Text("Unlock and account", style = MaterialTheme.typography.headlineSmall, modifier = Modifier.semantics { heading() })

        Text("Unlock", style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
        Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.fillMaxWidth()) {
            Text("Fingerprint or face", modifier = Modifier.weight(1f))
            Switch(
                checked = biometricOn,
                enabled = !busy && state.biometric.canUse(),
                onCheckedChange = { on ->
                    if (on) state.enableBiometric(activity, vault) else state.disableBiometric(vault)
                    revision++
                },
                modifier = Modifier.testTag("biometricSwitch"),
            )
        }
        if (!state.biometric.canUse()) {
            Text("Set up a fingerprint or face unlock in the phone settings to use it here.", style = MaterialTheme.typography.bodySmall)
        }

        if (!state.client.pins.available) {
            Text("PIN unlock is not available on this phone.")
        } else if (pinSet) {
            Text("A PIN is set. Five wrong PINs delete it.")
            OutlinedButton(onClick = { state.removePin(vault); revision++ }, modifier = Modifier.testTag("removePin")) { Text("Remove the PIN") }
        } else {
            OutlinedTextField(
                value = newPin,
                onValueChange = { newPin = it },
                label = { Text("New PIN, 6 to 64 characters") },
                singleLine = true,
                visualTransformation = PasswordVisualTransformation(),
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
                supportingText = { PinUnlock.problem(newPin)?.takeIf { newPin.isNotEmpty() }?.let { Text(it) } },
                modifier = Modifier.fillMaxWidth().testTag("newPin"),
            )
            Button(
                onClick = { state.setPin(vault, newPin) { newPin = ""; revision++ } },
                enabled = !busy && PinUnlock.problem(newPin) == null,
                modifier = Modifier.testTag("setPin"),
            ) { Text("Set PIN") }
        }

        HorizontalDivider()
        Text("Lock after", style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
        if (maxIdle != null) Text("Your organisation allows at most $maxIdle minutes.", style = MaterialTheme.typography.bodySmall)
        Column(Modifier.selectableGroup()) {
            for (minutes in IdlePolicy.offeredChoices(maxIdle)) {
                Row(
                    verticalAlignment = Alignment.CenterVertically,
                    // 48 dp: the radio row is the touch target (the accessibility audit, task 3.1).
                    modifier = Modifier
                        .fillMaxWidth()
                        .heightIn(min = 48.dp)
                        .selectable(
                            selected = settings.idleMinutes == minutes,
                            role = Role.RadioButton,
                            onClick = { state.setIdleMinutes(vault, minutes); revision++ },
                        )
                        .testTag("idle$minutes"),
                ) {
                    RadioButton(selected = settings.idleMinutes == minutes, onClick = null)
                    Text(if (minutes == 1) "1 minute" else "$minutes minutes")
                }
            }
        }

        HorizontalDivider()
        AutofillEntry()

        HorizontalDivider()
        Text("Account", style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
        if (account != null) Text("${account.loginName} on ${account.server.removePrefix("https://")}")
        OutlinedButton(onClick = { confirmUnpair = true }, enabled = !busy, modifier = Modifier.fillMaxWidth().testTag("unpair")) {
            Text("Disconnect this account")
        }
        Problem(message)
        TextButton(onClick = { state.closeSettings(vault) }, modifier = Modifier.testTag("back")) { Text("Back") }
    }

    if (confirmUnpair) {
        AlertDialog(
            onDismissRequest = { confirmUnpair = false },
            title = { Text("Disconnect this account?") },
            text = {
                Text("Keepiq deletes its app password in Nextcloud and removes everything it stored for this account on this phone.")
            },
            confirmButton = {
                TextButton(onClick = { confirmUnpair = false; state.unpair(vault.accountId) }, modifier = Modifier.testTag("confirmUnpair")) {
                    Text("Disconnect")
                }
            },
            dismissButton = { TextButton(onClick = { confirmUnpair = false }) { Text("Cancel") } },
        )
    }
}
