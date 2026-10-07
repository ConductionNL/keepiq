// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.ui

import androidx.compose.foundation.layout.Column
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.MaterialTheme
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
import androidx.compose.ui.res.stringResource
import kotlinx.coroutines.launch
import nl.conduction.keepiq.android.R
import nl.conduction.keepiq.android.vault.VaultSession
import nl.conduction.keepiq.shared.vault.FolderDeleteKind
import nl.conduction.keepiq.shared.vault.WriteProblem
import nl.conduction.keepiq.shared.vault.WriteResult

sealed interface FolderDialog {
    data class Create(val parentId: String?) : FolderDialog
    data class Rename(val id: String, val name: String) : FolderDialog
    data class Delete(val id: String, val name: String) : FolderDialog
}

/** folderNameProblem (browser-extension/src/lib/folder-rules.js). */
private fun folderNameProblem(name: String): Int? {
    val clean = name.trim()
    return when {
        clean.isEmpty() -> R.string.folder_name_missing
        clean.contains('/') -> R.string.folder_name_slash
        clean.length > 255 -> R.string.problem_too_long
        else -> null
    }
}

/** Create, rename and delete a folder (task 3.2). [onDone] says whether the vault changed and whether the folder is gone. */
@Composable
fun FolderDialogs(dialog: FolderDialog, session: VaultSession, onDone: (changed: Boolean, gone: Boolean) -> Unit) {
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    var problem by remember { mutableStateOf<WriteProblem?>(null) }

    fun run(gone: Boolean, block: suspend () -> WriteResult) {
        busy = true
        scope.launch {
            when (val result = io { block() }) {
                is WriteResult.Saved -> onDone(true, gone)
                is WriteResult.Refused -> problem = result.problem
            }
            busy = false
        }
    }

    when (dialog) {
        is FolderDialog.Create, is FolderDialog.Rename -> {
            var name by remember { mutableStateOf((dialog as? FolderDialog.Rename)?.name ?: "") }
            val nameProblem = folderNameProblem(name)
            AlertDialog(
                onDismissRequest = { onDone(false, false) },
                title = { Text(stringResource(if (dialog is FolderDialog.Create) R.string.folder_new else R.string.folder_rename)) },
                text = {
                    Column {
                        OutlinedTextField(
                            value = name,
                            onValueChange = { name = it },
                            label = { Text(stringResource(R.string.folder_name)) },
                            singleLine = true,
                            isError = nameProblem != null && name.isNotEmpty(),
                            supportingText = nameProblem?.takeIf { name.isNotEmpty() }?.let { { Text(stringResource(it)) } },
                        )
                        problem?.let { Text(writeProblemText(it), color = MaterialTheme.colorScheme.error) }
                    }
                },
                confirmButton = {
                    TextButton(enabled = !busy && nameProblem == null, onClick = {
                        run(gone = false) {
                            when (dialog) {
                                is FolderDialog.Create -> session.repository.createFolder(name, dialog.parentId)
                                is FolderDialog.Rename -> session.repository.renameFolder(dialog.id, name)
                                else -> error("unreachable")
                            }
                        }
                    }) { Text(stringResource(R.string.action_save)) }
                },
                dismissButton = { TextButton(onClick = { onDone(false, false) }) { Text(stringResource(R.string.action_cancel)) } },
            )
        }
        is FolderDialog.Delete -> {
            var kind by remember { mutableStateOf<FolderDeleteKind?>(null) }
            var loaded by remember { mutableStateOf(false) }
            LaunchedEffect(dialog.id) {
                kind = io { session.repository.folderDeleteKind(dialog.id) }
                loaded = true
            }
            AlertDialog(
                onDismissRequest = { onDone(false, false) },
                title = { Text(stringResource(R.string.folder_delete)) },
                text = {
                    Column {
                        when {
                            !loaded -> Text(stringResource(R.string.vault_loading))
                            kind == null -> Text(stringResource(R.string.write_unreachable))
                            kind == FolderDeleteKind.EMPTY -> Text(stringResource(R.string.folder_delete_empty, dialog.name))
                            kind == FolderDeleteKind.ITEMS -> Text(stringResource(R.string.folder_delete_items, dialog.name))
                            else -> Text(stringResource(R.string.folder_delete_subfolders))
                        }
                        problem?.let { Text(writeProblemText(it), color = MaterialTheme.colorScheme.error) }
                        if (kind == FolderDeleteKind.ITEMS) {
                            TextButton(enabled = !busy, onClick = {
                                run(gone = true) { session.repository.deleteFolder(dialog.id, FolderDeleteKind.ITEMS, deleteItems = true) }
                            }) { Text(stringResource(R.string.folder_delete_with_items)) }
                        }
                    }
                },
                confirmButton = {
                    when (kind) {
                        FolderDeleteKind.EMPTY -> TextButton(enabled = !busy, onClick = {
                            run(gone = true) { session.repository.deleteFolder(dialog.id, FolderDeleteKind.EMPTY, deleteItems = false) }
                        }) { Text(stringResource(R.string.action_delete)) }
                        FolderDeleteKind.ITEMS -> TextButton(enabled = !busy, onClick = {
                            run(gone = true) { session.repository.deleteFolder(dialog.id, FolderDeleteKind.ITEMS, deleteItems = false) }
                        }) { Text(stringResource(R.string.folder_delete_move)) }
                        else -> TextButton(onClick = { onDone(false, false) }) { Text(stringResource(R.string.action_close)) }
                    }
                },
                dismissButton = { TextButton(onClick = { onDone(false, false) }) { Text(stringResource(R.string.action_cancel)) } },
            )
        }
    }
}
