// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.ui

import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import nl.conduction.keepiq.android.AppState
import nl.conduction.keepiq.android.LoginState

/**
 * Connect an account (task 2.1): the server address, then the server's own
 * login page in the browser. "Use an app password instead" is the fallback
 * for servers whose login page does not open in a browser tab.
 */
@Composable
fun PairScreen(state: AppState, openBrowser: (String) -> Unit) {
    val login by state.login.collectAsState()
    val busy by state.busy.collectAsState()
    val message by state.message.collectAsState()
    var server by rememberSaveable { mutableStateOf("") }
    var manual by rememberSaveable { mutableStateOf(false) }
    var loginName by rememberSaveable { mutableStateOf("") }
    var appPassword by rememberSaveable { mutableStateOf("") }
    val hasAccounts = state.client.accounts.accounts().isNotEmpty()

    ScreenColumn {
        Text("Connect to your Nextcloud", style = MaterialTheme.typography.headlineSmall, modifier = Modifier.semantics { heading() })

        val waiting = login as? LoginState.Waiting
        if (waiting != null) {
            Text("Sign in on the page that opened in your browser, then come back here. Keepiq waits up to 20 minutes.")
            CircularProgressIndicator(modifier = Modifier.testTag("waiting"))
            OutlinedButton(onClick = { openBrowser(waiting.start.loginUrl) }, modifier = Modifier.fillMaxWidth()) {
                Text("Open the sign-in page again")
            }
            TextButton(onClick = { state.cancelLogin() }, modifier = Modifier.testTag("cancelLogin")) { Text("Cancel") }
            Problem(message)
            return@ScreenColumn
        }

        OutlinedTextField(
            value = server,
            onValueChange = { server = it },
            label = { Text("Server address") },
            placeholder = { Text("cloud.example.com") },
            singleLine = true,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri, imeAction = ImeAction.Next),
            modifier = Modifier.fillMaxWidth().testTag("server"),
        )

        if (!manual) {
            Button(
                onClick = { state.startLogin(server, openBrowser) },
                enabled = !busy && server.isNotBlank(),
                modifier = Modifier.fillMaxWidth().testTag("signIn"),
            ) { Text("Sign in with your browser") }
            TextButton(onClick = { manual = true }, modifier = Modifier.testTag("useAppPassword")) {
                Text("Use an app password instead")
            }
        } else {
            Text("Create an app password in Nextcloud under Personal settings, Security, and enter it here.")
            OutlinedTextField(
                value = loginName,
                onValueChange = { loginName = it },
                label = { Text("User name") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth().testTag("loginName"),
            )
            OutlinedTextField(
                value = appPassword,
                onValueChange = { appPassword = it },
                label = { Text("App password") },
                singleLine = true,
                visualTransformation = PasswordVisualTransformation(),
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password, imeAction = ImeAction.Done),
                modifier = Modifier.fillMaxWidth().testTag("appPassword"),
            )
            Button(
                onClick = { state.pairManually(server, loginName, appPassword) },
                enabled = !busy && server.isNotBlank() && loginName.isNotBlank() && appPassword.isNotBlank(),
                modifier = Modifier.fillMaxWidth().testTag("connect"),
            ) { Text("Connect") }
            TextButton(onClick = { manual = false }) { Text("Sign in with your browser instead") }
        }

        if (busy) CircularProgressIndicator()
        Problem(message)

        if (hasAccounts) {
            val active = state.client.accounts.activeId()
            TextButton(onClick = { active?.let { state.switchAccount(it) } }) { Text("Back to your accounts") }
        }
    }
}
