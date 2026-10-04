// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.ui

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.ListItem
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import nl.conduction.keepiq.android.R
import nl.conduction.keepiq.android.vault.VaultSession
import nl.conduction.keepiq.shared.crypto.Totp
import nl.conduction.keepiq.shared.vault.Composite
import nl.conduction.keepiq.shared.vault.DecryptedItem
import nl.conduction.keepiq.shared.vault.FormKind
import nl.conduction.keepiq.shared.vault.OpenResult
import nl.conduction.keepiq.shared.vault.VaultIndex
import nl.conduction.keepiq.shared.vault.WriteProblem
import nl.conduction.keepiq.shared.vault.WriteResult

private const val MASK = "••••••••"

/**
 * One item (task 3.1): secret values hidden until revealed, copy through
 * the sensitive clipboard, the TOTP code with its seconds left. A use-only
 * copy shows no value and offers no reveal or copy of it.
 */
@Composable
fun ItemDetailScreen(
    session: VaultSession,
    id: String,
    modifier: Modifier,
    onCopy: (String) -> Unit,
    onEdit: () -> Unit,
    onGone: () -> Unit,
    onSendLogin: (String, String) -> Unit,
) {
    var result by remember(id) { mutableStateOf<OpenResult?>(null) }
    var action by remember { mutableStateOf<DetailAction?>(null) }
    var problem by remember { mutableStateOf<WriteProblem?>(null) }
    val scope = rememberCoroutineScope()
    LaunchedEffect(id) { result = io { session.repository.open(id) } }

    when (val r = result) {
        null -> Message(stringResource(R.string.vault_loading))
        OpenResult.Missing -> Message(stringResource(R.string.detail_missing))
        is OpenResult.Failed -> Message(writeProblemText(r.problem))
        is OpenResult.Opened -> ItemDetail(
            item = r.item,
            folderPath = VaultIndex.folderPath(session.repository.state.folders, r.item.row.folderId)
                ?: stringResource(R.string.vault_no_folder),
            offline = session.repository.state.offline,
            problem = problem,
            modifier = modifier,
            onCopy = onCopy,
            onEdit = onEdit,
            onMove = { action = DetailAction.Move },
            onTrash = { action = DetailAction.Trash },
            onSendLogin = { onSendLogin(r.item.login, r.item.secret) },
        )
    }

    val item = (result as? OpenResult.Opened)?.item ?: return
    fun write(block: suspend () -> WriteResult, after: () -> Unit) {
        action = null
        scope.launch {
            when (val w = io { block() }) {
                is WriteResult.Saved -> after()
                is WriteResult.Refused -> problem = w.problem
            }
        }
    }
    when (action) {
        DetailAction.Trash -> AlertDialog(
            onDismissRequest = { action = null },
            text = { Text(stringResource(R.string.detail_trash_confirm, item.row.name)) },
            confirmButton = {
                TextButton(onClick = { write({ session.repository.trash(item.row.id) }, onGone) }) {
                    Text(stringResource(R.string.action_trash))
                }
            },
            dismissButton = { TextButton(onClick = { action = null }) { Text(stringResource(R.string.action_cancel)) } },
        )
        DetailAction.Move -> {
            val folders = VaultIndex.folderTree(session.repository.state.folders)
            AlertDialog(
                onDismissRequest = { action = null },
                title = { Text(stringResource(R.string.move_title)) },
                text = {
                    Column(Modifier.verticalScroll(rememberScrollState())) {
                        val choices = listOf<Pair<String?, String>>(null to stringResource(R.string.vault_no_folder)) +
                            folders.map { it.id to "  ".repeat(it.depth) + it.name }
                        for ((folderId, label) in choices) {
                            ListItem(
                                headlineContent = { Text(label) },
                                leadingContent = { RadioButton(selected = folderId == item.row.folderId, onClick = null) },
                                modifier = Modifier
                                    .fillMaxWidth()
                                    .semantics { contentDescription = label.trim() }
                                    .clickable {
                                        write({ session.repository.move(item.row.id, folderId) }) {
                                            scope.launch { result = io { session.repository.open(id) } }
                                        }
                                    },
                            )
                        }
                    }
                },
                confirmButton = { TextButton(onClick = { action = null }) { Text(stringResource(R.string.action_cancel)) } },
            )
        }
        null -> Unit
    }
}

private enum class DetailAction { Move, Trash }

@Composable
internal fun ItemDetail(
    item: DecryptedItem,
    folderPath: String,
    offline: Boolean,
    problem: WriteProblem?,
    modifier: Modifier,
    onCopy: (String) -> Unit,
    onEdit: () -> Unit,
    onMove: () -> Unit,
    onTrash: () -> Unit,
    onSendLogin: () -> Unit,
) {
    val row = item.row
    Column(modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(row.name, style = MaterialTheme.typography.headlineSmall, modifier = Modifier.semantics { heading() })
        Text("${item.type?.label ?: item.typeName} · $folderPath", style = MaterialTheme.typography.bodyMedium)
        if (item.fromCache) Note(stringResource(R.string.detail_from_cache))
        if (row.useOnly) Note(stringResource(R.string.detail_use_only))
        if (row.readOnly && !row.useOnly) Note(stringResource(R.string.detail_read_only))
        problem?.let { Text(writeProblemText(it), color = MaterialTheme.colorScheme.error) }
        if (row.blocked) {
            Note(row.blockedReason ?: stringResource(R.string.detail_blocked))
            return@Column
        }

        val passwordLabel = stringResource(if (item.kind == FormKind.LOGIN) R.string.detail_password else R.string.detail_value)
        when (item.kind) {
            FormKind.LOGIN, FormKind.GENERIC -> {
                if (item.login.isNotEmpty()) FieldRow(stringResource(R.string.detail_username), item.login, onCopy = onCopy)
                if (!row.useOnly) FieldRow(passwordLabel, item.secret, masked = true, onCopy = onCopy)
            }
            FormKind.TOTP -> TotpRow(item, onCopy)
            FormKind.NOTE -> if (!row.useOnly) FieldRow(stringResource(R.string.detail_notes), item.secret, onCopy = onCopy)
            FormKind.CARD, FormKind.IDENTITY -> if (!row.useOnly) {
                val data = item.composite
                if (data == null) {
                    Text(stringResource(R.string.detail_fields_error), color = MaterialTheme.colorScheme.error)
                } else {
                    if (item.kind == FormKind.CARD) {
                        Composite.last4(data["number"] ?: "").takeIf { it.isNotEmpty() }?.let {
                            Text(stringResource(R.string.detail_card_ending, it))
                        }
                    }
                    for (field in Composite.fieldsOf(item.kind)) {
                        val value = data[field] ?: ""
                        if (value.isNotEmpty()) FieldRow(compositeLabel(field), value, masked = field in Composite.MASKED, onCopy = onCopy)
                    }
                }
            }
            FormKind.PASSKEY -> item.passkey?.let { pk ->
                FieldRow(stringResource(R.string.detail_passkey_site), pk.rpName?.let { "$it (${pk.rpId})" } ?: pk.rpId, copy = false, onCopy = onCopy)
                pk.userName?.let { FieldRow(stringResource(R.string.detail_passkey_account), it, copy = false, onCopy = onCopy) }
                pk.createdAt?.let { FieldRow(stringResource(R.string.detail_passkey_created), it, copy = false, onCopy = onCopy) }
                Text(stringResource(R.string.detail_passkey_note), style = MaterialTheme.typography.bodySmall)
            }
        }
        row.url?.takeIf { it.isNotEmpty() }?.let { FieldRow(stringResource(R.string.detail_address), it, onCopy = onCopy) }
        if (!row.useOnly) {
            for ((field, value) in item.typedValues) {
                if (value.isNotEmpty()) FieldRow(field.label, value, masked = field.hidden, onCopy = onCopy)
            }
            if (item.kind != FormKind.NOTE && item.notes.isNotEmpty()) FieldRow(stringResource(R.string.detail_notes), item.notes, onCopy = onCopy)
            if (item.additionalFieldsError) Text(stringResource(R.string.detail_fields_error), color = MaterialTheme.colorScheme.error)
            if (item.extraFields.isNotEmpty()) {
                Text(stringResource(R.string.detail_extra_fields), style = MaterialTheme.typography.titleSmall, modifier = Modifier.semantics { heading() })
                for ((name, value) in item.extraFields) FieldRow(name, value, onCopy = onCopy)
            }
        }

        HorizontalDivider()
        if (!row.useOnly) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedButton(onClick = onEdit, enabled = !offline) { Text(stringResource(R.string.action_edit)) }
                OutlinedButton(onClick = onMove, enabled = !offline) { Text(stringResource(R.string.action_move)) }
            }
            if (item.kind == FormKind.LOGIN) {
                OutlinedButton(onClick = onSendLogin, enabled = !offline) { Text(stringResource(R.string.cd_new_send)) }
            }
        }
        OutlinedButton(onClick = onTrash, enabled = !offline) { Text(stringResource(R.string.action_trash)) }
        if (offline) Text(stringResource(R.string.write_offline), style = MaterialTheme.typography.bodySmall)
    }
}

@Composable
private fun compositeLabel(field: String): String = stringResource(
    when (field) {
        "number" -> R.string.composite_number
        "expiry" -> R.string.composite_expiry
        "cvv" -> R.string.composite_cvv
        "pin" -> R.string.composite_pin
        "cardholder" -> R.string.composite_cardholder
        "firstName" -> R.string.composite_first_name
        "lastName" -> R.string.composite_last_name
        "address" -> R.string.composite_address
        "phone" -> R.string.composite_phone
        "email" -> R.string.composite_email
        else -> R.string.composite_bsn
    },
)

@Composable
private fun Note(text: String) {
    Card(Modifier.fillMaxWidth()) { Text(text, modifier = Modifier.padding(12.dp)) }
}

/** A labelled value with Show and Copy. Buttons are 48 dp targets and name the field for TalkBack. */
@Composable
fun FieldRow(label: String, value: String, masked: Boolean = false, copy: Boolean = true, onCopy: (String) -> Unit) {
    var shown by remember(value) { mutableStateOf(!masked) }
    Column(Modifier.fillMaxWidth()) {
        Text(label, style = MaterialTheme.typography.labelMedium)
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(
                if (shown) value.ifEmpty { "-" } else MASK,
                fontFamily = if (masked) FontFamily.Monospace else null,
                modifier = Modifier.weight(1f).let { if (!shown) it.clearAndSetSemantics { contentDescription = label } else it },
            )
            if (masked) {
                val cd = stringResource(if (shown) R.string.cd_hide_field else R.string.cd_show_field, label)
                TextButton(onClick = { shown = !shown }, modifier = Modifier.semantics { contentDescription = cd }) {
                    Text(stringResource(if (shown) R.string.action_hide else R.string.action_show))
                }
            }
            if (copy && value.isNotEmpty()) {
                val cd = stringResource(R.string.cd_copy_field, label)
                TextButton(onClick = { onCopy(value) }, modifier = Modifier.semantics { contentDescription = cd }) {
                    Text(stringResource(R.string.action_copy))
                }
            }
        }
    }
}

/** The current code and its seconds left, refreshed every second. */
@Composable
private fun TotpRow(item: DecryptedItem, onCopy: (String) -> Unit) {
    val params = item.totp
    if (params == null) {
        Text(stringResource(R.string.detail_code_invalid), color = MaterialTheme.colorScheme.error)
        return
    }
    var now by remember { mutableLongStateOf(System.currentTimeMillis()) }
    LaunchedEffect(params) {
        while (true) {
            now = System.currentTimeMillis()
            delay(1_000L - now % 1_000L)
        }
    }
    val code = remember(params, now / 1_000L / params.period) { Totp.generate(params, now) }
    val left = Totp.secondsRemaining(params.period, now)
    val label = stringResource(R.string.detail_code)
    val leftText = stringResource(R.string.detail_code_seconds, left)
    Column {
        Text(label, style = MaterialTheme.typography.labelMedium)
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            val half = (code.length + 1) / 2
            Text(
                code.substring(0, half) + " " + code.substring(half),
                style = MaterialTheme.typography.headlineMedium,
                fontFamily = FontFamily.Monospace,
                modifier = Modifier.semantics { liveRegion = LiveRegionMode.Polite },
            )
            CircularProgressIndicator(
                progress = { left.toFloat() / params.period },
                modifier = Modifier.semantics { contentDescription = leftText },
            )
            val copyCd = stringResource(R.string.cd_copy_field, label)
            TextButton(onClick = { onCopy(code) }, modifier = Modifier.semantics { contentDescription = copyCd }) {
                Text(stringResource(R.string.action_copy))
            }
        }
    }
}
