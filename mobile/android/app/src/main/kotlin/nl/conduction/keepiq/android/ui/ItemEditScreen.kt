// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

@file:OptIn(ExperimentalMaterial3Api::class)

package nl.conduction.keepiq.android.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.MenuAnchorType
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
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
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import nl.conduction.keepiq.android.R
import nl.conduction.keepiq.android.vault.VaultSession
import nl.conduction.keepiq.shared.generator.GeneratorPolicy
import nl.conduction.keepiq.shared.generator.GeneratorSettings
import nl.conduction.keepiq.shared.vault.Composite
import nl.conduction.keepiq.shared.vault.DecryptedItem
import nl.conduction.keepiq.shared.vault.DraftProblem
import nl.conduction.keepiq.shared.vault.FormKind
import nl.conduction.keepiq.shared.vault.ItemCodec
import nl.conduction.keepiq.shared.vault.ItemDraft
import nl.conduction.keepiq.shared.vault.OpenResult
import nl.conduction.keepiq.shared.vault.SecretType
import nl.conduction.keepiq.shared.vault.VaultIndex
import nl.conduction.keepiq.shared.vault.WriteProblem
import nl.conduction.keepiq.shared.vault.WriteResult

/**
 * Create or edit an item (task 3.2). The type's fields come from
 * `/api/v1/secret-types`; the values are encrypted on the device to the
 * suite's key before they are sent. A refusal is shown and the form keeps
 * what the user typed; nothing is shown as saved unless the server took it.
 */
@Composable
fun ItemEditScreen(
    session: VaultSession,
    id: String?,
    folderId: String?,
    policy: GeneratorPolicy?,
    generatorSettings: GeneratorSettings,
    modifier: Modifier,
    onSaved: (String?) -> Unit,
    onCancel: () -> Unit,
) {
    val repository = session.repository
    val types = repository.state.types.filter { it.name != "passkey" }
    var item by remember(id) { mutableStateOf<DecryptedItem?>(null) }
    var loaded by remember(id) { mutableStateOf(id == null) }
    var type by remember(id) { mutableStateOf<SecretType?>(types.firstOrNull { it.name == "login" } ?: types.firstOrNull()) }
    var draft by remember(id) { mutableStateOf(ItemCodec.draft(null, type).copy(folderId = folderId)) }
    var problem by remember { mutableStateOf<WriteProblem?>(null) }
    var showErrors by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var generateFor by remember { mutableStateOf<((String) -> Unit)?>(null) }
    val scope = rememberCoroutineScope()

    LaunchedEffect(id) {
        if (id != null) {
            val opened = io { repository.open(id) }
            if (opened is OpenResult.Opened) {
                item = opened.item
                type = opened.item.type
                draft = ItemCodec.draft(opened.item, opened.item.type)
            } else if (opened is OpenResult.Failed) {
                problem = opened.problem
            }
            loaded = true
        }
    }
    if (!loaded) {
        Message(stringResource(R.string.vault_loading))
        return
    }
    val errors = ItemCodec.validate(draft, type)
    fun error(key: String): DraftProblem? = errors[key].takeIf { showErrors }

    Column(
        modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        Text(stringResource(if (id == null) R.string.edit_new_title else R.string.edit_title), style = MaterialTheme.typography.headlineSmall)
        if (id == null && types.isNotEmpty()) {
            Picker(
                label = stringResource(R.string.edit_type),
                selected = type?.label ?: type?.name ?: "",
                options = types.map { (it.label ?: it.name) to it },
                onPick = { picked ->
                    type = picked
                    val kept = draft
                    draft = ItemCodec.draft(null, picked).copy(name = kept.name, url = kept.url, folderId = kept.folderId, notes = kept.notes)
                },
            )
        }
        if (draft.kind == FormKind.PASSKEY) Text(stringResource(R.string.edit_passkey_note), style = MaterialTheme.typography.bodySmall)
        TextInput(stringResource(R.string.edit_name), draft.name, error("name")) { draft = draft.copy(name = it) }
        TextInput(stringResource(R.string.edit_url), draft.url, error("url"), keyboard = KeyboardType.Uri) { draft = draft.copy(url = it) }
        val folders = VaultIndex.folderTree(repository.state.folders)
        Picker(
            label = stringResource(R.string.detail_folder),
            selected = VaultIndex.folderPath(repository.state.folders, draft.folderId) ?: stringResource(R.string.vault_no_folder),
            options = listOf<Pair<String, String?>>(stringResource(R.string.vault_no_folder) to null) +
                folders.map { ("  ".repeat(it.depth) + it.name) to it.id },
            onPick = { draft = draft.copy(folderId = it) },
        )
        when (draft.kind) {
            FormKind.LOGIN, FormKind.GENERIC -> {
                TextInput(stringResource(R.string.detail_username), draft.login, error("login")) { draft = draft.copy(login = it) }
                SecretInput(
                    label = stringResource(if (draft.kind == FormKind.LOGIN) R.string.detail_password else R.string.detail_value),
                    value = draft.secret,
                    problem = error("secret"),
                    onGenerate = { generateFor = { v -> draft = draft.copy(secret = v) } },
                ) { draft = draft.copy(secret = it) }
            }
            FormKind.TOTP -> TextInput(stringResource(R.string.edit_totp_secret), draft.secret, error("secret")) { draft = draft.copy(secret = it) }
            FormKind.CARD, FormKind.IDENTITY -> for (field in Composite.fieldsOf(draft.kind)) {
                val value = draft.composite[field] ?: ""
                val label = compositeFieldLabel(field)
                if (field in Composite.MASKED) {
                    SecretInput(label, value, null, onGenerate = null) { draft = draft.copy(composite = draft.composite + (field to it)) }
                } else {
                    TextInput(label, value, null) { draft = draft.copy(composite = draft.composite + (field to it)) }
                }
            }
            FormKind.NOTE, FormKind.PASSKEY -> Unit
        }
        for (field in type?.fields ?: emptyList()) {
            val value = draft.typed[field.key] ?: ""
            val label = if (field.required) "${field.label} (${stringResource(R.string.edit_required)})" else field.label
            val update: (String) -> Unit = { draft = draft.copy(typed = draft.typed + (field.key to it)) }
            if (field.hidden) {
                SecretInput(label, value, error("typed-${field.key}"), onGenerate = { generateFor = update }, onChange = update)
            } else {
                val keyboard = when (field.kind) {
                    "url" -> KeyboardType.Uri
                    "email" -> KeyboardType.Email
                    else -> KeyboardType.Text
                }
                TextInput(label, value, error("typed-${field.key}"), keyboard = keyboard, onChange = update)
            }
        }
        TextInput(stringResource(R.string.detail_notes), draft.notes, null, singleLine = false) { draft = draft.copy(notes = it) }
        if (draft.kind != FormKind.PASSKEY) ExtraFields(draft, ::error) { draft = it }

        problem?.let { Text(writeProblemText(it), color = MaterialTheme.colorScheme.error) }
        error("fields")?.let { Text(draftProblemText(it), color = MaterialTheme.colorScheme.error) }
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Button(
                enabled = !busy && !repository.state.offline,
                onClick = {
                    showErrors = true
                    if (errors.isNotEmpty()) return@Button
                    busy = true
                    problem = null
                    scope.launch {
                        val current = item
                        val result = io { if (current == null) repository.create(draft, type) else repository.update(current, draft) }
                        busy = false
                        when (result) {
                            is WriteResult.Saved -> onSaved(result.id)
                            is WriteResult.Refused -> problem = result.problem
                        }
                    }
                },
            ) { Text(stringResource(R.string.action_save)) }
            OutlinedButton(onClick = onCancel) { Text(stringResource(R.string.action_cancel)) }
        }
        if (repository.state.offline) Text(stringResource(R.string.write_offline), style = MaterialTheme.typography.bodySmall)
    }

    generateFor?.let { apply ->
        var value by remember { mutableStateOf("") }
        var settings by remember { mutableStateOf(generatorSettings) }
        AlertDialog(
            onDismissRequest = { generateFor = null },
            text = {
                GeneratorScreen(policy = policy, settings = settings, onSettings = { settings = it }, onCopy = null, onValue = { value = it }, modifier = Modifier)
            },
            confirmButton = {
                TextButton(enabled = value.isNotEmpty(), onClick = {
                    apply(value)
                    generateFor = null
                }) { Text(stringResource(R.string.action_use)) }
            },
            dismissButton = { TextButton(onClick = { generateFor = null }) { Text(stringResource(R.string.action_cancel)) } },
        )
    }
}

@Composable
private fun ExtraFields(draft: ItemDraft, error: (String) -> DraftProblem?, onChange: (ItemDraft) -> Unit) {
    Text(stringResource(R.string.detail_extra_fields), style = MaterialTheme.typography.titleSmall)
    draft.fields.forEachIndexed { i, (name, value) ->
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                TextInput(stringResource(R.string.edit_field_name), name, error("field-$i")) { n ->
                    onChange(draft.copy(fields = draft.fields.toMutableList().also { it[i] = n to value }))
                }
                TextInput(stringResource(R.string.edit_field_value), value, null) { v ->
                    onChange(draft.copy(fields = draft.fields.toMutableList().also { it[i] = name to v }))
                }
            }
            IconButton(onClick = { onChange(draft.copy(fields = draft.fields.filterIndexed { j, _ -> j != i })) }) {
                Icon(Icons.Filled.Delete, contentDescription = stringResource(R.string.cd_remove_field, name))
            }
        }
    }
    TextButton(onClick = { onChange(draft.copy(fields = draft.fields + ("" to ""))) }) { Text(stringResource(R.string.edit_add_field)) }
}

@Composable
private fun compositeFieldLabel(field: String): String = stringResource(
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
fun TextInput(
    label: String,
    value: String,
    problem: DraftProblem?,
    keyboard: KeyboardType = KeyboardType.Text,
    singleLine: Boolean = true,
    onChange: (String) -> Unit,
) {
    OutlinedTextField(
        value = value,
        onValueChange = onChange,
        label = { Text(label) },
        singleLine = singleLine,
        isError = problem != null,
        supportingText = problem?.let { { Text(draftProblemText(it)) } },
        keyboardOptions = KeyboardOptions(keyboardType = keyboard),
        modifier = Modifier.fillMaxWidth(),
    )
}

/** A hidden input with Show and, where it fits, Generate. The keyboard is told not to learn it. */
@Composable
private fun SecretInput(label: String, value: String, problem: DraftProblem?, onGenerate: (() -> Unit)?, onChange: (String) -> Unit) {
    var shown by remember { mutableStateOf(false) }
    Column {
        OutlinedTextField(
            value = value,
            onValueChange = onChange,
            label = { Text(label) },
            singleLine = true,
            isError = problem != null,
            supportingText = problem?.let { { Text(draftProblemText(it)) } },
            visualTransformation = if (shown) VisualTransformation.None else PasswordVisualTransformation(),
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password, autoCorrectEnabled = false),
            modifier = Modifier.fillMaxWidth(),
        )
        Row {
            TextButton(onClick = { shown = !shown }) {
                Text(stringResource(if (shown) R.string.cd_hide_field else R.string.cd_show_field, label))
            }
            if (onGenerate != null) TextButton(onClick = onGenerate) { Text(stringResource(R.string.action_generate)) }
        }
    }
}

/** A read-only field that opens a menu of [options]. */
@Composable
fun <T> Picker(label: String, selected: String, options: List<Pair<String, T>>, onPick: (T) -> Unit) {
    var open by remember { mutableStateOf(false) }
    ExposedDropdownMenuBox(expanded = open, onExpandedChange = { open = it }) {
        OutlinedTextField(
            value = selected,
            onValueChange = {},
            readOnly = true,
            label = { Text(label) },
            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = open) },
            modifier = Modifier.fillMaxWidth().menuAnchor(MenuAnchorType.PrimaryNotEditable),
        )
        ExposedDropdownMenu(expanded = open, onDismissRequest = { open = false }) {
            for ((text, value) in options) {
                DropdownMenuItem(text = { Text(text) }, onClick = {
                    open = false
                    onPick(value)
                })
            }
        }
    }
}
