// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.sync

import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.contentOrNull
import nl.conduction.keepiq.shared.api.KeepiqApi
import nl.conduction.keepiq.shared.api.KeepiqApiException
import nl.conduction.keepiq.shared.api.Manifest
import nl.conduction.keepiq.shared.store.VaultSnapshot
import nl.conduction.keepiq.shared.store.VaultStore

/** Why a sync ran (design D5). */
enum class SyncTrigger {
    /** The app opened. Always a full sync. */
    START,

    /** The app came back to the foreground. Cheap check first. */
    FOREGROUND,

    /** A write went through. Always a full sync. */
    AFTER_WRITE,

    /** The 15-minute timer while the app is open. Cheap check first. */
    TIMER,

    /** The user asked for it. Always a full sync. */
    MANUAL,
}

/** Why the vault was locked by a sync. */
enum class LockReason { SUITE_CHANGED, MASTER_PASSWORD_CHANGED, UNLOCK_BLOCKED }

/** The suite the vault was unlocked with. */
data class UnlockedSession(val suiteId: String, val unlockKeyEpoch: Long?)

sealed class SyncOutcome {
    /** The cheap check found nothing new; the stored copy is current. */
    data class Fresh(val syncedAtMillis: Long) : SyncOutcome()

    /** A full sync replaced the stored copy. */
    data class Synced(val syncedAtMillis: Long, val secrets: Int) : SyncOutcome()

    /**
     * Offline caching is off for the organisation. Nothing is kept on disk;
     * the vault is only in this answer, for the current unlocked session.
     */
    data class OnlineOnly(
        val secrets: List<JsonObject>,
        val folders: List<JsonObject>,
        val types: List<JsonObject>,
    ) : SyncOutcome()

    /** The sync locked the vault and emptied the store. */
    data class Locked(val reason: LockReason) : SyncOutcome()
}

/** What the app does when a sync locks the vault. */
interface SyncListener {
    /** Lock now and ask for the master password. */
    fun lock(reason: LockReason)

    /**
     * Delete the biometric and PIN wraps of the unlock key: they wrap a key
     * that no longer opens the envelope (design D4).
     */
    fun deleteUnlockWraps()
}

/**
 * Sync without a change feed, as browser-extension/src/background/vault-sync.js
 * does it: the offline manifest on start, after a write and on request; on
 * foreground and on the timer a cheap check of the newest `updatedAt` and
 * the total first, and the manifest only when either changed or the copy is
 * older than 15 minutes.
 *
 * Every full sync compares the suite id and its `unlockKeyEpoch` with the
 * suite the vault was unlocked with. A new suite, a new epoch (a
 * master-password change elsewhere) or an `unlockBlocked` signal empties the
 * store and locks; an epoch change also deletes the unlock wraps.
 */
class VaultSync(
    private val api: KeepiqApi,
    private val store: VaultStore,
    private val listener: SyncListener,
    private val clock: () -> Long,
) {
    suspend fun sync(trigger: SyncTrigger, session: UnlockedSession): SyncOutcome {
        val cached = store.state()
        val force = trigger == SyncTrigger.START || trigger == SyncTrigger.AFTER_WRITE || trigger == SyncTrigger.MANUAL
        if (!force && cached != null) {
            val latest = api.latestSecret()
            val fresh = clock() - cached.syncedAtMillis < SYNC_INTERVAL_MILLIS
            if (fresh && latest.top == cached.checkTop && latest.total == cached.checkTotal) {
                val now = clock()
                store.touch(now)
                return SyncOutcome.Fresh(now)
            }
        }

        val manifest = try {
            api.offlineManifest()
        } catch (e: KeepiqApiException) {
            // Offline caching off: 403 (OfflineController), 428 (design D5),
            // 404 without an active suite. The extension treats all three alike.
            if (e.status != 403 && e.status != 428 && e.status != 404) throw e
            null
        }

        if (manifest == null) {
            store.clear()
            val suite = api.activeSuite()
            checkSuite(suite?.id, suite?.unlockKeyEpoch, suite?.unlockBlocked, session)?.let { return it }
            return SyncOutcome.OnlineOnly(api.listSecrets(), api.listFolders(), api.listTypes())
        }

        checkSuite(manifest.suite?.id, manifest.suite?.unlockKeyEpoch, manifest.unlockBlocked, session)?.let { return it }

        val now = clock()
        store.replaceAll(snapshotOf(manifest), now)
        return SyncOutcome.Synced(now, manifest.secrets.size)
    }

    private fun checkSuite(suiteId: String?, epoch: Long?, unlockBlocked: String?, session: UnlockedSession): SyncOutcome? {
        if (unlockBlocked != null) return lock(LockReason.UNLOCK_BLOCKED, deleteWraps = false)
        if (suiteId != null && suiteId != session.suiteId) return lock(LockReason.SUITE_CHANGED, deleteWraps = true)
        if (epoch != null && session.unlockKeyEpoch != null && epoch != session.unlockKeyEpoch) {
            return lock(LockReason.MASTER_PASSWORD_CHANGED, deleteWraps = true)
        }
        return null
    }

    private fun lock(reason: LockReason, deleteWraps: Boolean): SyncOutcome {
        store.clear()
        if (deleteWraps) listener.deleteUnlockWraps()
        listener.lock(reason)
        return SyncOutcome.Locked(reason)
    }

    private fun snapshotOf(manifest: Manifest): VaultSnapshot {
        // As vault-sync.js: the newest updatedAt by string order, and the count.
        fun updatedAt(secret: JsonObject) = (secret["updatedAt"] as? JsonPrimitive)?.contentOrNull
        val newest = manifest.secrets.mapNotNull { updatedAt(it) }.maxOrNull()
        return VaultSnapshot(
            secrets = manifest.secrets,
            folders = manifest.folders,
            types = manifest.types,
            suiteId = manifest.suite?.id,
            unlockKeyEpoch = manifest.suite?.unlockKeyEpoch,
            checkTop = newest,
            checkTotal = manifest.secrets.size.toLong(),
        )
    }

    companion object {
        /** The extension's SYNC_INTERVAL_MINUTES. */
        const val SYNC_INTERVAL_MILLIS = 15L * 60_000L
    }
}
