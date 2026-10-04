// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

@file:OptIn(ExperimentalMaterial3Api::class)

package nl.conduction.keepiq.android.ui

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.List
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.ListItem
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import nl.conduction.keepiq.android.R
import nl.conduction.keepiq.android.vault.VaultSession
import nl.conduction.keepiq.shared.sync.LockReason
import nl.conduction.keepiq.shared.sync.SyncTrigger
import nl.conduction.keepiq.shared.sync.VaultSync
import nl.conduction.keepiq.shared.vault.IndexEntry
import nl.conduction.keepiq.shared.vault.ListState
import nl.conduction.keepiq.shared.vault.VaultIndex
import nl.conduction.keepiq.shared.vault.VaultState

/**
 * The vault list (task 3.1): one folder's subfolders and items, or, while
 * searching, every match. Names and addresses only: nothing is decrypted
 * here. Search runs on the device.
 */
@Composable
fun VaultListScreen(
    session: VaultSession,
    folderId: String?,
    modifier: Modifier,
    onOpenFolder: (String) -> Unit,
    onOpenItem: (String) -> Unit,
    onAddItem: () -> Unit,
    onFolderGone: () -> Unit,
) {
    val repository = session.repository
    var state by remember(session) { mutableStateOf<VaultState?>(repository.state.takeIf { it != VaultState.EMPTY }) }
    var query by remember { mutableStateOf("") }
    var folderMenu by remember { mutableStateOf(false) }
    var folderDialog by remember { mutableStateOf<FolderDialog?>(null) }
    val scope = rememberCoroutineScope()

    fun sync(trigger: SyncTrigger) {
        scope.launch { state = io { repository.refresh(trigger) } }
    }

    LaunchedEffect(session) {
        if (state == null) state = io { repository.refresh(SyncTrigger.START) }
        while (true) {
            delay(VaultSync.SYNC_INTERVAL_MILLIS)
            state = io { repository.refresh(SyncTrigger.TIMER) }
        }
    }
    val lifecycle = LocalLifecycleOwner.current.lifecycle
    DisposableEffect(lifecycle, session) {
        var started = false
        val observer = LifecycleEventObserver { _, event ->
            if (event == Lifecycle.Event.ON_START) {
                if (started) sync(SyncTrigger.FOREGROUND)
                started = true
            }
        }
        lifecycle.addObserver(observer)
        onDispose { lifecycle.removeObserver(observer) }
    }

    val current = state
    Box(modifier.fillMaxSize()) {
        Column(Modifier.fillMaxSize()) {
            Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(horizontal = 16.dp)) {
                OutlinedTextField(
                    value = query,
                    onValueChange = { query = it },
                    label = { Text(stringResource(R.string.search_label)) },
                    placeholder = { Text(stringResource(R.string.search_hint)) },
                    singleLine = true,
                    modifier = Modifier.weight(1f),
                )
                IconButton(onClick = { sync(SyncTrigger.MANUAL) }) {
                    Icon(Icons.Filled.Refresh, contentDescription = stringResource(R.string.action_refresh))
                }
                IconButton(onClick = { folderMenu = true }) {
                    Icon(Icons.Filled.MoreVert, contentDescription = stringResource(R.string.cd_folder_actions))
                }
                DropdownMenu(expanded = folderMenu, onDismissRequest = { folderMenu = false }) {
                    DropdownMenuItem(text = { Text(stringResource(R.string.folder_new)) }, onClick = {
                        folderMenu = false
                        folderDialog = FolderDialog.Create(folderId)
                    })
                    if (folderId != null) {
                        val name = current?.folders?.firstOrNull { it.id == folderId }?.name ?: ""
                        DropdownMenuItem(text = { Text(stringResource(R.string.folder_rename)) }, onClick = {
                            folderMenu = false
                            folderDialog = FolderDialog.Rename(folderId, name)
                        })
                        DropdownMenuItem(text = { Text(stringResource(R.string.folder_delete)) }, onClick = {
                            folderMenu = false
                            folderDialog = FolderDialog.Delete(folderId, name)
                        })
                    }
                }
            }
            if (current != null) SyncNote(current)
            when {
                current == null -> Message(stringResource(R.string.vault_loading))
                current.locked != null -> Message(stringResource(lockText(current.locked!!)))
                current.needsConnection -> Message(stringResource(R.string.vault_needs_connection))
                else -> VaultEntries(current, folderId, query, onOpenFolder, onOpenItem)
            }
        }
        if (current != null && !current.offline && current.locked == null) {
            FloatingActionButton(
                onClick = onAddItem,
                modifier = Modifier.align(Alignment.BottomEnd).padding(16.dp),
            ) { Icon(Icons.Filled.Add, contentDescription = stringResource(R.string.cd_add_item)) }
        }
    }

    folderDialog?.let { dialog ->
        FolderDialogs(
            dialog = dialog,
            session = session,
            onDone = { changed, gone ->
                folderDialog = null
                if (changed) state = repository.state
                if (gone) onFolderGone()
            },
        )
    }
}

@Composable
private fun SyncNote(state: VaultState) {
    val text = when {
        state.offline && state.syncedAtMillis != null -> stringResource(R.string.vault_offline, relativeTime(state.syncedAtMillis!!))
        state.offline -> stringResource(R.string.vault_offline_never)
        state.onlineOnly -> stringResource(R.string.vault_online_only)
        state.syncedAtMillis != null -> stringResource(R.string.vault_synced, relativeTime(state.syncedAtMillis!!))
        else -> return
    }
    val colors = if (state.offline) {
        CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.secondaryContainer)
    } else {
        CardDefaults.cardColors()
    }
    Card(colors = colors, modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 8.dp)) {
        Text(text, style = MaterialTheme.typography.bodyMedium, modifier = Modifier.padding(12.dp))
    }
    state.problem?.let {
        Text(writeProblemText(it), color = MaterialTheme.colorScheme.error, modifier = Modifier.padding(horizontal = 16.dp))
    }
}

private fun lockText(reason: LockReason): Int = when (reason) {
    LockReason.SUITE_CHANGED -> R.string.vault_locked_suite
    LockReason.MASTER_PASSWORD_CHANGED -> R.string.vault_locked_password
    LockReason.UNLOCK_BLOCKED -> R.string.vault_locked_two_factor
}

@Composable
private fun VaultEntries(
    state: VaultState,
    folderId: String?,
    query: String,
    onOpenFolder: (String) -> Unit,
    onOpenItem: (String) -> Unit,
) {
    val index = state.index
    val searching = query.isNotBlank()
    val folderIds = state.folders.map { it.id }.toSet()
    val items = when {
        searching -> VaultIndex.filter(index, query)
        folderId != null -> VaultIndex.filter(index, folderId = folderId)
        // The top level also holds items whose folder is gone, so none is ever out of reach.
        else -> index.filter { it.folderId == null || it.folderId !in folderIds }
    }
    val folders = if (searching) emptyList() else VaultIndex.subfolders(state.folders, folderId)
    val listState = VaultIndex.listState(index, if (searching) items else index)
    when {
        listState == ListState.EMPTY -> Message(stringResource(R.string.vault_empty))
        listState == ListState.ALL_BLOCKED && !searching -> Message(stringResource(R.string.vault_all_blocked))
        searching && items.isEmpty() -> Message(stringResource(R.string.vault_no_match))
        !searching && items.isEmpty() && folders.isEmpty() -> Message(stringResource(R.string.vault_folder_empty))
        else -> LazyColumn(Modifier.fillMaxSize()) {
            items(folders, key = { "f-" + it.id }) { folder ->
                val description = stringResource(R.string.cd_open_folder, folder.name)
                ListItem(
                    headlineContent = { Text(folder.name) },
                    leadingContent = { Icon(Icons.Filled.List, contentDescription = null) },
                    modifier = Modifier
                        .semantics { contentDescription = description }
                        .clickable { onOpenFolder(folder.id) },
                )
                HorizontalDivider()
            }
            items(items, key = { "s-" + it.id }) { entry ->
                EntryRow(entry, showFolder = searching, onOpenItem)
                HorizontalDivider()
            }
        }
    }
}

@Composable
private fun EntryRow(entry: IndexEntry, showFolder: Boolean, onOpenItem: (String) -> Unit) {
    val badges = buildList {
        if (entry.useOnly) add(stringResource(R.string.badge_use_only))
        if (entry.readOnly && !entry.useOnly) add(stringResource(R.string.badge_read_only))
        if (entry.blocked) add(stringResource(R.string.badge_blocked))
    }
    val host = entry.url.removePrefix("https://").removePrefix("http://").substringBefore('/')
    val supporting = listOfNotNull(
        host.ifEmpty { null },
        entry.folderName.takeIf { showFolder && it.isNotEmpty() },
        badges.joinToString(", ").ifEmpty { null },
    ).joinToString(" · ")
    ListItem(
        headlineContent = { Text(entry.name) },
        supportingContent = if (supporting.isNotEmpty()) ({ Text(supporting) }) else null,
        modifier = Modifier.clickable(onClickLabel = stringResource(R.string.cd_open_item, entry.name)) { onOpenItem(entry.id) },
    )
}

@Composable
fun Message(text: String) {
    Text(text, style = MaterialTheme.typography.bodyLarge, modifier = Modifier.padding(24.dp))
}
