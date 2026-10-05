// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.vault

import kotlinx.coroutines.CancellationException
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.longOrNull
import nl.conduction.keepiq.shared.api.KeepiqApi
import nl.conduction.keepiq.shared.api.KeepiqApiException
import nl.conduction.keepiq.shared.store.VaultStore
import nl.conduction.keepiq.shared.sync.LockReason
import nl.conduction.keepiq.shared.sync.SyncOutcome
import nl.conduction.keepiq.shared.sync.SyncTrigger
import nl.conduction.keepiq.shared.sync.VaultSync

/** What the vault screens show: rows without values, and where they came from. */
data class VaultState(
    val rows: List<VaultRow>,
    val folders: List<VaultFolder>,
    val types: List<SecretType>,
    /** When the stored copy was last synced, or null for none. */
    val syncedAtMillis: Long?,
    /** The server could not be reached: reading from the store, edits refused. */
    val offline: Boolean,
    /** The organisation turned offline caching off: nothing is kept on the device. */
    val onlineOnly: Boolean,
    /** Offline with no stored copy, because caching is off or there is no store: the vault needs a connection. */
    val needsConnection: Boolean = false,
    /** The sync locked the vault; the app asks for the master password again. */
    val locked: LockReason? = null,
    /** A read the server refused, for the list to show. */
    val problem: WriteProblem? = null,
) {
    val index: List<IndexEntry> get() = VaultIndex.build(rows, types, folders)

    fun type(typeId: String?): SecretType? = types.firstOrNull { it.id == typeId }

    fun typeNamed(name: String): SecretType? = types.firstOrNull { it.name == name }

    companion object {
        val EMPTY = VaultState(emptyList(), emptyList(), emptyList(), null, offline = false, onlineOnly = false)
    }
}

/** An opened item, or why it could not be opened. */
sealed class OpenResult {
    data class Opened(val item: DecryptedItem) : OpenResult()

    data class Failed(val problem: WriteProblem) : OpenResult()

    /** The item is gone from the server and from the store. */
    data object Missing : OpenResult()
}

/** What deleting a folder needs (browser-extension/src/lib/folder-rules.js deleteKind). */
enum class FolderDeleteKind { EMPTY, ITEMS, SUBFOLDERS }

/**
 * The vault of one unlocked account: reading through the offline store and
 * sync (tasks 1.6 and 1.7), opening items fresh from the server, and every
 * write the vault screens make, through the same endpoints as the web app.
 *
 * [store] and [sync] are null where there is no offline store (iOS until
 * task 1.6.1): the vault is then read from the server only, and needs a
 * connection. While [VaultState.offline] is set every write is refused
 * before it is sent, with the message that edits need a connection.
 */
class VaultRepository(
    private val api: KeepiqApi,
    private val keys: VaultKeys,
    private val store: VaultStore?,
    private val sync: VaultSync?,
    private val clock: () -> Long,
    /**
     * Sees every state [refresh] returns. The sessions pass
     * [nl.conduction.keepiq.shared.autofill.AutofillIndexHub.refreshed], so
     * the autofill index follows each sync (task 4.6).
     */
    private val onRefreshed: (VaultState) -> Unit = {},
) {
    /** The last state [refresh] produced. */
    var state: VaultState = VaultState.EMPTY
        private set

    /** Syncs for [trigger] and returns what the list shows. */
    suspend fun refresh(trigger: SyncTrigger): VaultState {
        state = try {
            if (sync != null && store != null) syncThroughStore(trigger) else readOnline()
        } catch (e: CancellationException) {
            throw e
        } catch (e: KeepiqApiException) {
            if (e.status == 0) offlineState() else fromStore(offline = false).copy(problem = WriteProblem.from(e))
        } catch (e: Exception) {
            offlineState()
        }
        try {
            onRefreshed(state)
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            // The index is rebuilt at the next sync; the list still shows the vault.
        }
        return state
    }

    private suspend fun syncThroughStore(trigger: SyncTrigger): VaultState =
        when (val outcome = sync!!.sync(trigger, keys.syncSession())) {
            is SyncOutcome.Fresh, is SyncOutcome.Synced -> fromStore(offline = false)
            is SyncOutcome.OnlineOnly -> fromJson(outcome.secrets, outcome.folders, outcome.types)
            is SyncOutcome.Locked -> VaultState.EMPTY.copy(locked = outcome.reason)
        }

    /** Without a store: the manifest, or the lists when offline caching is off, held in memory only. */
    private suspend fun readOnline(): VaultState {
        val manifest = try {
            api.offlineManifest()
        } catch (e: KeepiqApiException) {
            if (e.status != 403 && e.status != 428 && e.status != 404) throw e
            null
        }
        if (manifest == null) {
            val suite = api.activeSuite()
            lockReason(suite?.id, suite?.unlockKeyEpoch, suite?.unlockBlocked)?.let { return VaultState.EMPTY.copy(locked = it) }
            return fromJson(api.listSecrets(), api.listFolders(), api.listTypes())
        }
        lockReason(manifest.suite?.id, manifest.suite?.unlockKeyEpoch, manifest.unlockBlocked)?.let {
            return VaultState.EMPTY.copy(locked = it)
        }
        return fromJson(manifest.secrets, manifest.folders, manifest.types).copy(onlineOnly = false, syncedAtMillis = clock())
    }

    /** VaultSync.checkSuite, for the path without a store. */
    private fun lockReason(suiteId: String?, epoch: Long?, unlockBlocked: String?): LockReason? = when {
        unlockBlocked != null -> LockReason.UNLOCK_BLOCKED
        suiteId != null && suiteId != keys.suiteId -> LockReason.SUITE_CHANGED
        epoch != null && keys.unlockKeyEpoch != null && epoch != keys.unlockKeyEpoch -> LockReason.MASTER_PASSWORD_CHANGED
        else -> null
    }

    private fun fromStore(offline: Boolean): VaultState {
        val s = store ?: return VaultState.EMPTY.copy(offline = offline, needsConnection = offline)
        val synced = s.state()
        if (synced == null && offline) {
            // Nothing stored: caching is off for the organisation, or there was never a sync.
            return VaultState.EMPTY.copy(offline = true, onlineOnly = state.onlineOnly, needsConnection = true)
        }
        return VaultState(
            rows = s.secrets().map { VaultRow.from(it) },
            folders = s.folders().map { VaultFolder.from(it) },
            types = s.types().mapNotNull { runCatching { SecretType.from(kotlinx.serialization.json.Json.parseToJsonElement(it) as JsonObject) }.getOrNull() },
            syncedAtMillis = synced?.syncedAtMillis,
            offline = offline,
            onlineOnly = false,
        )
    }

    private fun fromJson(secrets: List<JsonObject>, folders: List<JsonObject>, types: List<JsonObject>) = VaultState(
        rows = secrets.mapNotNull { VaultRow.from(it) },
        folders = folders.mapNotNull { VaultFolder.from(it) },
        types = types.mapNotNull { SecretType.from(it) },
        syncedAtMillis = null,
        offline = false,
        onlineOnly = true,
    )

    private fun offlineState(): VaultState = if (state.onlineOnly || store == null) {
        VaultState.EMPTY.copy(offline = true, onlineOnly = state.onlineOnly, needsConnection = true)
    } else {
        fromStore(offline = true)
    }

    /**
     * Opens one item, fetched fresh so a stale row never seeds an edit
     * (the extension's vault-item). Offline, the stored row is read instead.
     */
    suspend fun open(id: String): OpenResult {
        val types = state.types
        val (row, fromCache) = try {
            if (state.offline) throw OfflineSignal()
            val fresh = api.getSecret(id)?.let { VaultRow.from(it) }
            (fresh ?: return OpenResult.Missing) to false
        } catch (e: CancellationException) {
            throw e
        } catch (e: KeepiqApiException) {
            if (e.status == 404) return OpenResult.Missing
            if (e.status != 0 && e.status < 500) return OpenResult.Failed(WriteProblem.from(e))
            (cachedRow(id) ?: return OpenResult.Failed(WriteProblem.from(e))) to true
        } catch (e: Exception) {
            (cachedRow(id) ?: return OpenResult.Failed(WriteProblem(WriteProblemKind.UNREACHABLE))) to true
        }
        return try {
            OpenResult.Opened(ItemCodec.open(row, types.firstOrNull { it.id == row.typeId }, keys, fromCache))
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            OpenResult.Failed(WriteProblem(WriteProblemKind.FAILED, e.message))
        }
    }

    private fun cachedRow(id: String): VaultRow? =
        store?.secret(id)?.let { VaultRow.from(it) } ?: state.rows.firstOrNull { it.id == id }

    /** Creates an item of [type] from [draft]. */
    suspend fun create(draft: ItemDraft, type: SecretType?): WriteResult = write {
        if (draft.kind == FormKind.PASSKEY) return@write WriteResult.Refused(WriteProblem(WriteProblemKind.REFUSED))
        val body = ItemCodec.createBody(ItemCodec.parts(draft, type), type?.id ?: draft.typeId, keys)
        val saved = api.createSecret(body)
        WriteResult.Saved(saved?.let { it["id"] as? JsonPrimitive }?.contentOrNull)
    }

    /**
     * Updates [item] with only the parts that changed. A passkey's key is
     * never rewritten: only its name, address, folder and notes change.
     */
    suspend fun update(item: DecryptedItem, draft: ItemDraft): WriteResult = write {
        if (item.row.useOnly || item.row.blocked) return@write WriteResult.Refused(WriteProblem(WriteProblemKind.REFUSED))
        val before = ItemCodec.parts(ItemCodec.draft(item, item.type), item.type)
        val after = ItemCodec.parts(draft, item.type)
        val changed = ItemCodec.changed(before, after).let { if (item.kind == FormKind.PASSKEY) it - "key" - "login" else it }
        if (changed.isEmpty()) return@write WriteResult.Saved(item.row.id)
        api.updateSecret(item.row.id, ItemCodec.updateBody(after, changed, keys))
        WriteResult.Saved(item.row.id)
    }

    /** Moves an item to another folder: only its folder changes (the extension's vault-move). */
    suspend fun move(id: String, folderId: String?): WriteResult = write {
        api.updateSecret(id, JsonObject(mapOf("folderId" to (folderId?.let { JsonPrimitive(it) } ?: kotlinx.serialization.json.JsonNull))))
        WriteResult.Saved(id)
    }

    /** Moves an item to the trash; the web app can restore it. */
    suspend fun trash(id: String): WriteResult = write {
        api.trashSecret(id)
        WriteResult.Saved(id)
    }

    suspend fun createFolder(name: String, parentId: String?): WriteResult = write {
        val folder = api.createFolder(name.trim(), parentId)
        WriteResult.Saved(folder?.let { it["id"] as? JsonPrimitive }?.contentOrNull)
    }

    suspend fun renameFolder(id: String, name: String): WriteResult = write {
        api.renameFolder(id, name.trim())
        WriteResult.Saved(id)
    }

    /** What a folder holds, to choose how to delete it. */
    suspend fun folderDeleteKind(id: String): FolderDeleteKind? = try {
        val children = api.folderChildren(id)
        val subfolders = (children?.get("subfolders") as? kotlinx.serialization.json.JsonArray)?.size ?: 0
        val items = (children?.get("directSecretCount") as? JsonPrimitive)?.longOrNull ?: 0L
        when {
            subfolders > 0 -> FolderDeleteKind.SUBFOLDERS
            items > 0 -> FolderDeleteKind.ITEMS
            else -> FolderDeleteKind.EMPTY
        }
    } catch (e: CancellationException) {
        throw e
    } catch (e: Exception) {
        null
    }

    /**
     * Deletes a folder. An empty folder goes plainly; one that holds items
     * moves them out first (`cascade=move`) or deletes them with it. A folder
     * with subfolders is deleted in the web app, which plans each subfolder.
     */
    suspend fun deleteFolder(id: String, kind: FolderDeleteKind, deleteItems: Boolean): WriteResult = write {
        when (kind) {
            FolderDeleteKind.EMPTY -> api.deleteFolder(id)
            FolderDeleteKind.ITEMS -> api.deleteFolder(id, if (deleteItems) "delete" else "move")
            FolderDeleteKind.SUBFOLDERS -> return@write WriteResult.Refused(WriteProblem(WriteProblemKind.REFUSED))
        }
        WriteResult.Saved(id)
    }

    /** Records a fill of a use-only copy for its owner (sharing-use-only-and-expiring-shares 3.3). */
    suspend fun reportUseOnlyFill(id: String): Boolean = try {
        api.reportUseOnlyFill(id)
        true
    } catch (e: CancellationException) {
        throw e
    } catch (e: Exception) {
        false
    }

    /**
     * Runs one write. Offline it is refused before anything is sent. After
     * a write that went through, the vault syncs (the after-write trigger).
     */
    private suspend fun write(block: suspend () -> WriteResult): WriteResult {
        if (state.offline) return WriteResult.Refused(WriteProblem(WriteProblemKind.OFFLINE))
        val result = try {
            block()
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            val problem = WriteProblem.from(e)
            WriteResult.Refused(if (problem.kind == WriteProblemKind.UNREACHABLE) WriteProblem(WriteProblemKind.OFFLINE) else problem)
        }
        if (result is WriteResult.Saved) refresh(SyncTrigger.AFTER_WRITE)
        return result
    }

    private class OfflineSignal : Exception()
}
