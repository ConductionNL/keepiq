// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

@file:OptIn(ExperimentalMaterial3Api::class)

package nl.conduction.keepiq.android.ui

import android.content.Intent
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.ListItem
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.SegmentedButton
import androidx.compose.material3.SegmentedButtonDefaults
import androidx.compose.material3.SingleChoiceSegmentedButtonRow
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import nl.conduction.keepiq.android.R
import nl.conduction.keepiq.android.vault.VaultSession
import nl.conduction.keepiq.shared.send.CreatedSend
import nl.conduction.keepiq.shared.send.IsoTime
import nl.conduction.keepiq.shared.send.OpenSendClient
import nl.conduction.keepiq.shared.send.OpenSendResult
import nl.conduction.keepiq.shared.send.SendExpiry
import nl.conduction.keepiq.shared.send.SendForm
import nl.conduction.keepiq.shared.send.SendLink
import nl.conduction.keepiq.shared.send.SendPayloadType
import nl.conduction.keepiq.shared.send.SendResult
import nl.conduction.keepiq.shared.send.SendSummary
import nl.conduction.keepiq.shared.vault.WriteProblem
import java.text.DateFormat
import java.util.Date

/** The account's sends (task 3.6): metadata only, with delete, and a field to open a Send link. */
@Composable
fun SendListScreen(session: VaultSession, modifier: Modifier, onNew: () -> Unit) {
    var sends by remember(session) { mutableStateOf<List<SendSummary>?>(null) }
    var problem by remember { mutableStateOf<WriteProblem?>(null) }
    var deleting by remember { mutableStateOf<SendSummary?>(null) }
    var opening by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    fun load() {
        scope.launch {
            when (val r = io { session.sends.list() }) {
                is SendResult.Done -> { sends = r.value; problem = null }
                is SendResult.Problem -> problem = r.write
            }
        }
    }
    LaunchedEffect(session) { load() }

    Box(modifier.fillMaxSize()) {
        Column(Modifier.fillMaxSize()) {
            TextButton(onClick = { opening = true }, modifier = Modifier.padding(horizontal = 8.dp)) {
                Text(stringResource(R.string.open_send_paste))
            }
            problem?.let { Text(writeProblemText(it), color = MaterialTheme.colorScheme.error, modifier = Modifier.padding(16.dp)) }
            val list = sends
            when {
                list == null && problem == null -> Message(stringResource(R.string.vault_loading))
                list != null && list.isEmpty() -> Message(stringResource(R.string.send_empty))
                list != null -> LazyColumn {
                    items(list, key = { it.id }) { send ->
                        SendRow(send) { deleting = send }
                        HorizontalDivider()
                    }
                }
            }
        }
        FloatingActionButton(onClick = onNew, modifier = Modifier.align(Alignment.BottomEnd).padding(16.dp)) {
            Icon(Icons.Filled.Add, contentDescription = stringResource(R.string.cd_new_send))
        }
    }

    deleting?.let { send ->
        AlertDialog(
            onDismissRequest = { deleting = null },
            text = { Text(stringResource(R.string.send_delete_confirm)) },
            confirmButton = {
                TextButton(onClick = {
                    deleting = null
                    scope.launch {
                        when (val r = io { session.sends.delete(send.id) }) {
                            is SendResult.Done -> load()
                            is SendResult.Problem -> problem = r.write
                        }
                    }
                }) { Text(stringResource(R.string.action_delete)) }
            },
            dismissButton = { TextButton(onClick = { deleting = null }) { Text(stringResource(R.string.action_cancel)) } },
        )
    }
    if (opening) {
        AlertDialog(
            onDismissRequest = { opening = false },
            text = { OpenSendScreen(initialLink = "", modifier = Modifier) },
            confirmButton = { TextButton(onClick = { opening = false }) { Text(stringResource(R.string.action_close)) } },
        )
    }
}

@Composable
private fun SendRow(send: SendSummary, onDelete: () -> Unit) {
    val kind = stringResource(if (send.payloadType == "credential") R.string.send_credential else R.string.send_text)
    val created = IsoTime.parseMillis(send.createdAt)?.let { DateFormat.getDateTimeInstance(DateFormat.MEDIUM, DateFormat.SHORT).format(Date(it)) }
    val minutes = SendForm.minutesLeft(IsoTime.parseMillis(send.expiresAt), System.currentTimeMillis())
    val expiry = when {
        minutes == null -> null
        minutes <= 0 -> stringResource(R.string.send_expired)
        minutes < 60 -> stringResource(R.string.send_expires_minutes, minutes.toInt())
        minutes < 48 * 60 -> stringResource(R.string.send_expires_hours, ((minutes + 30) / 60).toInt())
        else -> stringResource(R.string.send_expires_days, ((minutes + 720) / 1440).toInt())
    }
    val details = listOfNotNull(
        expiry,
        stringResource(R.string.send_views, send.viewCount.toInt(), send.maxViews.toInt()),
        if (send.hasPassword) stringResource(R.string.send_password_badge) else null,
    ).joinToString(" · ")
    ListItem(
        headlineContent = { Text(if (created != null) stringResource(R.string.send_row, kind, created) else kind) },
        supportingContent = { Text(details) },
        trailingContent = { TextButton(onClick = onDelete) { Text(stringResource(R.string.action_delete)) } },
    )
}

/**
 * Create a Send (task 3.6): text or a login, a view limit, an expiry and
 * an optional password. The link goes to the system share sheet.
 */
@Composable
fun NewSendScreen(
    session: VaultSession,
    initial: Route.NewSend,
    modifier: Modifier,
    onCopy: (String) -> Unit,
    onDone: () -> Unit,
) {
    val context = LocalContext.current
    var type by remember { mutableStateOf(if (initial.password.isNotEmpty()) SendPayloadType.CREDENTIAL else SendPayloadType.TEXT) }
    var text by remember { mutableStateOf(initial.text) }
    var username by remember { mutableStateOf(initial.username) }
    var password by remember { mutableStateOf(initial.password) }
    var views by remember { mutableStateOf("1") }
    var expiry by remember { mutableStateOf(SendExpiry.SEVEN_DAYS) }
    var hours by remember { mutableStateOf("") }
    var sendPassword by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var problem by remember { mutableStateOf<SendResult.Problem?>(null) }
    var created by remember { mutableStateOf<CreatedSend?>(null) }
    val scope = rememberCoroutineScope()

    Column(modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(stringResource(R.string.send_new_title), style = MaterialTheme.typography.headlineSmall)
        val done = created
        if (done != null) {
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(stringResource(if (done.hasPassword) R.string.send_created_password else R.string.send_created))
                    Text(done.link, style = MaterialTheme.typography.bodySmall)
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Button(onClick = {
                            val share = Intent(Intent.ACTION_SEND).setType("text/plain").putExtra(Intent.EXTRA_TEXT, done.link)
                            context.startActivity(Intent.createChooser(share, null))
                        }) { Text(stringResource(R.string.action_share)) }
                        OutlinedButton(onClick = { onCopy(done.link) }) { Text(stringResource(R.string.send_copy_link)) }
                    }
                    TextButton(onClick = onDone) { Text(stringResource(R.string.action_close)) }
                }
            }
            return@Column
        }
        SingleChoiceSegmentedButtonRow(Modifier.fillMaxWidth()) {
            SegmentedButton(selected = type == SendPayloadType.TEXT, onClick = { type = SendPayloadType.TEXT }, shape = SegmentedButtonDefaults.itemShape(0, 2)) {
                Text(stringResource(R.string.send_kind_text))
            }
            SegmentedButton(selected = type == SendPayloadType.CREDENTIAL, onClick = { type = SendPayloadType.CREDENTIAL }, shape = SegmentedButtonDefaults.itemShape(1, 2)) {
                Text(stringResource(R.string.send_kind_login))
            }
        }
        if (type == SendPayloadType.TEXT) {
            OutlinedTextField(text, { text = it }, label = { Text(stringResource(R.string.send_text_label)) }, minLines = 3, modifier = Modifier.fillMaxWidth())
        } else {
            OutlinedTextField(username, { username = it }, label = { Text(stringResource(R.string.detail_username)) }, singleLine = true, modifier = Modifier.fillMaxWidth())
            OutlinedTextField(
                password, { password = it }, label = { Text(stringResource(R.string.detail_password)) }, singleLine = true,
                visualTransformation = PasswordVisualTransformation(), modifier = Modifier.fillMaxWidth(),
            )
        }
        OutlinedTextField(
            views, { views = it.filter(Char::isDigit).take(3) }, label = { Text(stringResource(R.string.send_views_label)) }, singleLine = true,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.fillMaxWidth(),
        )
        Picker(
            label = stringResource(R.string.send_expiry_label),
            selected = expiryLabel(expiry),
            options = SendExpiry.entries.map { expiryLabel(it) to it },
            onPick = { expiry = it },
        )
        if (expiry == SendExpiry.CUSTOM) {
            OutlinedTextField(
                hours, { hours = it.filter(Char::isDigit).take(4) }, label = { Text(stringResource(R.string.send_hours_label)) }, singleLine = true,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.fillMaxWidth(),
            )
        }
        OutlinedTextField(
            sendPassword, { sendPassword = it }, label = { Text(stringResource(R.string.send_password_label)) }, singleLine = true,
            visualTransformation = PasswordVisualTransformation(), modifier = Modifier.fillMaxWidth(),
        )
        problem?.form?.let { Text(sendProblemText(it), color = MaterialTheme.colorScheme.error) }
        problem?.write?.let { Text(writeProblemText(it), color = MaterialTheme.colorScheme.error) }
        Button(enabled = !busy, onClick = {
            busy = true
            problem = null
            scope.launch {
                val plaintext = if (type == SendPayloadType.CREDENTIAL) SendForm.credentialPayload(username, password) else text
                val result = io {
                    session.sends.create(type, plaintext, views, expiry, hours, sendPassword, passwordAvailable = true)
                }
                busy = false
                when (result) {
                    is SendResult.Done -> created = result.value
                    is SendResult.Problem -> problem = result
                }
            }
        }) { Text(stringResource(R.string.send_create)) }
    }
}

@Composable
private fun expiryLabel(expiry: SendExpiry): String = stringResource(
    when (expiry) {
        SendExpiry.HOUR -> R.string.expiry_1h
        SendExpiry.DAY -> R.string.expiry_1d
        SendExpiry.TWO_DAYS -> R.string.expiry_2d
        SendExpiry.THREE_DAYS -> R.string.expiry_3d
        SendExpiry.SEVEN_DAYS -> R.string.expiry_7d
        SendExpiry.THIRTY_DAYS -> R.string.expiry_30d
        SendExpiry.CUSTOM -> R.string.expiry_custom
    },
)

/**
 * Opens a Send link on this phone (task 3.6), decrypting on the device as
 * the public page does. Needs no account: the recipient may have none on
 * that server. Opening uses a view, so the app asks first.
 */
@Composable
fun OpenSendScreen(initialLink: String, modifier: Modifier, onCopy: ((String) -> Unit)? = null) {
    val client = remember { OpenSendClient.platform() }
    var link by remember { mutableStateOf(initialLink) }
    var state by remember { mutableStateOf<OpenSendResult?>(null) }
    var password by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val parsed = SendLink.parse(link)
    LaunchedEffect(parsed) {
        state = null
        if (parsed != null) state = io { client.peek(parsed) }
    }

    Column(modifier.padding(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(stringResource(R.string.open_send_title), style = MaterialTheme.typography.titleLarge)
        val current = state
        if (current !is OpenSendResult.Opened) {
            OutlinedTextField(
                link, { link = it.trim() }, label = { Text(stringResource(R.string.open_send_link)) }, singleLine = true,
                isError = link.isNotEmpty() && parsed == null,
                supportingText = if (link.isNotEmpty() && parsed == null) ({ Text(stringResource(R.string.open_send_invalid)) }) else null,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri), modifier = Modifier.fillMaxWidth(),
            )
        }
        val needsPassword = current is OpenSendResult.NeedsPassword || (current is OpenSendResult.WrongPassword && !current.burned)
        when (current) {
            is OpenSendResult.Ready -> Text(stringResource(R.string.open_send_ready))
            is OpenSendResult.NeedsPassword -> Text(stringResource(R.string.open_send_password))
            is OpenSendResult.WrongPassword -> Text(
                if (current.burned) stringResource(R.string.open_send_burned) else stringResource(R.string.open_send_wrong, current.attemptsLeft.toInt()),
                color = MaterialTheme.colorScheme.error,
            )
            OpenSendResult.Gone -> Text(stringResource(R.string.open_send_gone))
            OpenSendResult.NoKey -> Text(stringResource(R.string.open_send_no_key))
            is OpenSendResult.Failed -> Text(stringResource(R.string.open_send_failed), color = MaterialTheme.colorScheme.error)
            is OpenSendResult.Opened -> {
                Card(Modifier.fillMaxWidth()) { Text(current.payload, modifier = Modifier.padding(12.dp)) }
                if (current.burned) Text(stringResource(R.string.open_send_last_view), style = MaterialTheme.typography.bodySmall)
                if (onCopy != null) OutlinedButton(onClick = { onCopy(current.payload) }) { Text(stringResource(R.string.action_copy)) }
            }
            null -> Unit
        }
        if (needsPassword) {
            OutlinedTextField(
                password, { password = it }, label = { Text(stringResource(R.string.detail_password)) }, singleLine = true,
                visualTransformation = PasswordVisualTransformation(), modifier = Modifier.fillMaxWidth(),
            )
        }
        if (parsed != null && (current is OpenSendResult.Ready || needsPassword)) {
            Button(enabled = !busy && (!needsPassword || password.isNotEmpty()), onClick = {
                busy = true
                scope.launch {
                    state = io { client.open(parsed, password) }
                    busy = false
                }
            }) { Text(stringResource(R.string.action_open)) }
        }
    }
}
