// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.store

import app.cash.sqldelight.db.SqlDriver
import kotlin.concurrent.Volatile
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.contentOrNull
import nl.conduction.keepiq.shared.crypto.Encoding
import nl.conduction.keepiq.shared.crypto.KeepiqCryptoException
import nl.conduction.keepiq.shared.crypto.SendCrypto
import nl.conduction.keepiq.shared.store.db.KeepiqDatabase
import nl.conduction.keepiq.shared.vault.VaultLockedException

/**
 * Seals the plaintext metadata the server sends (names, URLs, folder names)
 * before it is written to disk, and opens it again. [UnlockKeySealer] is the
 * one the app uses; the interface exists so the store logic can be tested on
 * its own.
 */
interface MetadataSealer {
    fun seal(plaintext: String): String
    fun open(sealed: String): String

    /** Drops the key from memory, on lock. */
    fun forget() {}
}

/**
 * AES-256-GCM under the 32-byte unlock key, stored as base64(IV + ciphertext
 * and tag), the same blob shape as a Send (src/send/sendCrypto.js aesEncrypt).
 * The unlock key lives only while the vault is unlocked, so the names on disk
 * are unreadable without the master password (offline-readonly-cache).
 */
class UnlockKeySealer(unlockKey: ByteArray) : MetadataSealer {
    private val key = unlockKey.copyOf()

    init {
        if (key.size != 32) throw KeepiqCryptoException("The unlock key must be 32 bytes")
    }

    override fun seal(plaintext: String): String = SendCrypto.aesEncrypt(key, Encoding.utf8(plaintext))

    override fun open(sealed: String): String = Encoding.fromUtf8(SendCrypto.aesDecrypt(key, sealed))

    override fun forget() = key.fill(0)
}

/** One item as the store gives it back: ciphertext untouched, names opened. */
data class StoredSecret(
    val id: String,
    val name: String,
    val url: String?,
    val typeId: String?,
    val folderId: String?,
    val key: String?,
    val login: String?,
    val additionalFields: String?,
    val encryptionSuiteId: String?,
    val updatedAt: String?,
    /** A use-only copy: fill only, never reveal or copy (use-only-shares). */
    val useOnly: Boolean = false,
    /** The user may read this item but not change it. */
    val readOnly: Boolean = false,
    /** The item's key cannot be used here; [blockedReason] says why. */
    val blocked: Boolean = false,
    val blockedReason: String? = null,
)

data class StoredFolder(val id: String, val name: String, val parentId: String?)

/** What the last full sync recorded. */
data class SyncState(
    val suiteId: String?,
    val unlockKeyEpoch: Long?,
    val syncedAtMillis: Long,
    val checkTop: String?,
    val checkTotal: Long,
)

/** A full vault as the server sent it, before it is stored. */
data class VaultSnapshot(
    val secrets: List<JsonObject>,
    val folders: List<JsonObject>,
    val types: List<JsonObject>,
    val suiteId: String?,
    val unlockKeyEpoch: Long?,
    val checkTop: String?,
    val checkTotal: Long,
)

/**
 * The offline store of one account (design D5, mobile-shared-core
 * "Encrypted offline store"). The [driver] is SQLCipher on Android
 * ([openEncryptedDriver] in androidMain) with a random key held under a
 * device-bound Keystore key. Item ciphertext is stored as the server sent
 * it; names, URLs and folder names are sealed with [sealer].
 *
 * Every write replaces the whole vault in one transaction, so a reader never
 * sees half a vault. [clear] empties it on unpair, on a suite change, on a
 * master-password change and when offline caching is turned off.
 */
class VaultStore(private val driver: SqlDriver, private val sealer: MetadataSealer) {
    private val db = KeepiqDatabase(driver)

    @Volatile
    private var closed = false

    /**
     * On lock: forgets the sealing key and closes the database. The store is
     * reopened at the next unlock. A read or write still under way, or one
     * started later, throws [VaultLockedException].
     */
    fun close() {
        closed = true
        sealer.forget()
        driver.close()
    }

    /** Runs [block] on an open store; a store closed under it reads as locked. */
    private inline fun <T> open(block: () -> T): T {
        if (closed) throw VaultLockedException()
        return try {
            block()
        } catch (e: IllegalStateException) {
            if (closed) throw VaultLockedException()
            println("Keepiq store failed: $e")
            throw e
        } catch (e: Exception) {
            println("Keepiq store failed: $e")
            throw e
        }
    }

    fun replaceAll(snapshot: VaultSnapshot, nowMillis: Long) = open {
        db.transaction {
            clearRows()
            snapshot.secrets.forEachIndexed { index, s ->
                db.vaultQueries.insertSecret(
                    nl.conduction.keepiq.shared.store.db.Secret(
                        id = s.text("id") ?: return@forEachIndexed,
                        name_sealed = sealer.seal(s.text("name") ?: ""),
                        url_sealed = s.text("url")?.let { sealer.seal(it) },
                        type_id = s.text("typeId"),
                        folder_id = s.text("folderId"),
                        key_ciphertext = s.text("key"),
                        login_ciphertext = s.text("login"),
                        additional_fields_ciphertext = s.text("additionalFields"),
                        encryption_suite_id = s.text("encryptionSuiteId"),
                        updated_at = s.text("updatedAt"),
                        position = index.toLong(),
                        use_only = if (s.flag("useOnly")) 1L else 0L,
                        read_only = if (s.flag("readOnly")) 1L else 0L,
                        blocked = if (s.flag("blocked")) 1L else 0L,
                        blocked_reason = s.text("blockedReason"),
                    ),
                )
            }
            for (f in snapshot.folders) {
                db.vaultQueries.insertFolder(
                    nl.conduction.keepiq.shared.store.db.Folder(
                        id = f.text("id") ?: continue,
                        name_sealed = sealer.seal(f.text("name") ?: ""),
                        parent_id = f.text("parentId"),
                    ),
                )
            }
            for (t in snapshot.types) {
                db.vaultQueries.insertType(
                    nl.conduction.keepiq.shared.store.db.Secret_type(id = t.text("id") ?: continue, json = t.toString()),
                )
            }
            db.vaultQueries.putState(
                snapshot.suiteId,
                snapshot.unlockKeyEpoch,
                nowMillis,
                snapshot.checkTop,
                snapshot.checkTotal,
            )
        }
    }

    /** Records a cheap check that found nothing new. */
    fun touch(nowMillis: Long) = open { db.vaultQueries.touchState(nowMillis) }

    fun state(): SyncState? = open {
        db.vaultQueries.state().executeAsOneOrNull()?.let {
            SyncState(it.suite_id, it.unlock_key_epoch, it.synced_at_millis, it.check_top, it.check_total)
        }
    }

    fun secrets(): List<StoredSecret> = open { db.vaultQueries.secrets().executeAsList().map { it.open() } }

    fun secret(id: String): StoredSecret? = open { db.vaultQueries.secretById(id).executeAsOneOrNull()?.open() }

    fun folders(): List<StoredFolder> = open {
        db.vaultQueries.folders().executeAsList().map { StoredFolder(it.id, sealer.open(it.name_sealed), it.parent_id) }
    }

    fun types(): List<String> = open { db.vaultQueries.types().executeAsList().map { it.json } }

    /** Empties the store: unpair, suite change, master-password change, offline caching off. */
    fun clear() = open { db.transaction { clearRows() } }

    private fun clearRows() {
        // Deleted rows are overwritten with zeros, not left in free pages. Set
        // inside the transaction: a driver may hand out a fresh connection
        // per call outside one, and the pragma is per connection. A query,
        // not an execute: the pragma answers with a row, and Android's
        // SQLite refuses a statement that returns rows from execute.
        driver.executeQuery(null, "PRAGMA secure_delete = ON", { cursor -> cursor.next() }, 0)
        db.vaultQueries.deleteSecrets()
        db.vaultQueries.deleteFolders()
        db.vaultQueries.deleteTypes()
        db.vaultQueries.deleteState()
    }

    private fun nl.conduction.keepiq.shared.store.db.Secret.open() = StoredSecret(
        id = id,
        name = sealer.open(name_sealed),
        url = url_sealed?.let { sealer.open(it) },
        typeId = type_id,
        folderId = folder_id,
        key = key_ciphertext,
        login = login_ciphertext,
        additionalFields = additional_fields_ciphertext,
        encryptionSuiteId = encryption_suite_id,
        updatedAt = updated_at,
        useOnly = use_only != 0L,
        readOnly = read_only != 0L,
        blocked = blocked != 0L,
        blockedReason = blocked_reason,
    )

    private fun JsonObject.text(name: String): String? = (this[name] as? JsonPrimitive)?.contentOrNull

    private fun JsonObject.flag(name: String): Boolean = (this[name] as? JsonPrimitive)?.contentOrNull == "true"
}
