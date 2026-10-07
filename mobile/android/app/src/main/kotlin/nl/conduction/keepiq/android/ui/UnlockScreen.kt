// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.ui

import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.fragment.app.FragmentActivity
import nl.conduction.keepiq.android.AppState
import nl.conduction.keepiq.shared.UnlockGate
import nl.conduction.keepiq.shared.account.AccountStore

/**
 * Unlock (tasks 2.2 and 2.3): the master password, or the PIN or the
 * biometric prompt when the user turned them on. When the server reports
 * `unlockBlocked`, the screen says why and shows no unlock field.
 */
@Composable
fun UnlockScreen(state: AppState, activity: FragmentActivity, accountId: String) {
    val gate by state.gate.collectAsState()
    val busy by state.busy.collectAsState()
    val message by state.message.collectAsState()
    val account = state.client.accounts.account(accountId)
    val accounts = state.client.accounts.accounts()
    var password by remember(accountId) { mutableStateOf("") }
    var pin by remember(accountId) { mutableStateOf("") }
    var usePassword by remember(accountId) { mutableStateOf(false) }
    val pinSet = state.client.pins.has(accountId)
    val biometricOn = state.biometric.isEnabled(accountId) && state.biometric.canUse()

    LaunchedEffect(accountId) { if (gate == null) state.refreshGate(accountId) }
    LaunchedEffect(accountId, gate is UnlockGate.Ready, biometricOn) {
        if (gate is UnlockGate.Ready && biometricOn) state.unlockWithBiometric(activity, accountId)
    }

    ScreenColumn {
        Text("Unlock Keepiq", style = MaterialTheme.typography.headlineSmall, modifier = Modifier.semantics { heading() })
        if (account != null) Text("${account.loginName} on ${account.server.removePrefix("https://")}", modifier = Modifier.testTag("account"))

        when (val g = gate) {
            null -> CircularProgressIndicator()
            is UnlockGate.Blocked -> {
                Text(g.message, modifier = Modifier.testTag("blocked"))
                OutlinedButton(onClick = { state.refreshGate(accountId) }, enabled = !busy) { Text("Check again") }
            }
            is UnlockGate.Ready -> {
                if (g.offline) Text("You are offline. Keepiq unlocks with what this phone stored at the last unlock.")
                if (biometricOn) {
                    OutlinedButton(
                        onClick = { state.unlockWithBiometric(activity, accountId) },
                        enabled = !busy,
                        modifier = Modifier.fillMaxWidth().testTag("biometric"),
                    ) { Text("Unlock with fingerprint or face") }
                }
                if (pinSet && !usePassword) {
                    OutlinedTextField(
                        value = pin,
                        onValueChange = { pin = it },
                        label = { Text("PIN") },
                        singleLine = true,
                        visualTransformation = PasswordVisualTransformation(),
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword, imeAction = ImeAction.Done),
                        modifier = Modifier.fillMaxWidth().testTag("pin"),
                    )
                    Button(
                        onClick = { state.unlockWithPin(accountId, pin); pin = "" },
                        enabled = !busy && pin.isNotEmpty(),
                        modifier = Modifier.fillMaxWidth().testTag("unlockPin"),
                    ) { Text("Unlock with PIN") }
                    TextButton(onClick = { usePassword = true }) { Text("Use your master password") }
                } else {
                    OutlinedTextField(
                        value = password,
                        onValueChange = { password = it },
                        label = { Text("Master password") },
                        singleLine = true,
                        visualTransformation = PasswordVisualTransformation(),
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password, imeAction = ImeAction.Done),
                        modifier = Modifier.fillMaxWidth().testTag("masterPassword"),
                    )
                    Button(
                        onClick = { state.unlockWithMasterPassword(accountId, password); password = "" },
                        enabled = !busy && password.isNotEmpty(),
                        modifier = Modifier.fillMaxWidth().testTag("unlock"),
                    ) { Text("Unlock") }
                }
            }
        }
        if (busy) CircularProgressIndicator(modifier = Modifier.testTag("busy"))
        Problem(message)

        HorizontalDivider()
        for (other in accounts.filter { it.id != accountId }) {
            TextButton(onClick = { state.switchAccount(other.id) }) {
                Text("Switch to ${other.loginName} on ${other.server.removePrefix("https://")}")
            }
        }
        if (accounts.size < AccountStore.MAX_ACCOUNTS) {
            TextButton(onClick = { state.addAccount() }, modifier = Modifier.testTag("addAccount")) { Text("Connect another account") }
        }
    }
}
