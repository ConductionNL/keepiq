// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.store

import app.cash.sqldelight.driver.jdbc.sqlite.JdbcSqliteDriver
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import nl.conduction.keepiq.shared.crypto.KeepiqCryptoException
import nl.conduction.keepiq.shared.crypto.Primitives
import nl.conduction.keepiq.shared.store.db.KeepiqDatabase
import nl.conduction.keepiq.shared.vault.VaultLockedException
import nl.conduction.keepiq.shared.vault.VaultRow
import java.io.File
import java.util.Properties
import kotlin.test.AfterTest
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFailsWith
import kotlin.test.assertFalse
import kotlin.test.assertNull
import kotlin.test.assertTrue

/**
 * The store logic (task 1.6) on the jvm target, over plain SQLite through
 * the JDBC driver. SQLCipher itself (the encrypted file and its
 * device-bound key) only runs on Android; see openEncryptedDriver.
 */
class VaultStoreTest {
    private val file: File = File.createTempFile("keepiq-store", ".db")
    private val unlockKey = Primitives.randomBytes(32)

    private fun open(key: ByteArray = unlockKey): VaultStore {
        // Creates the schema when the file is new (user_version 0), as the app does.
        val driver = JdbcSqliteDriver("jdbc:sqlite:${file.absolutePath}", Properties(), KeepiqDatabase.Schema)
        return VaultStore(driver, UnlockKeySealer(key))
    }

    @AfterTest
    fun cleanUp() {
        file.delete()
    }

    private val snapshot = VaultSnapshot(
        secrets = listOf(
            obj("""{"id":"s1","name":"Huisbank Zuidas","url":"https://mijn.huisbank.example","typeId":1,"folderId":"f1","key":"UlNBLWNodW5r","login":"TE9HSU4=","additionalFields":null,"encryptionSuiteId":"suite-9","updatedAt":"2026-10-02T09:00:00+00:00"}"""),
            obj("""{"id":"s2","name":"Gemeentelijk portaal","url":null,"typeId":2,"folderId":null,"key":"S0VZ","login":null,"additionalFields":"QURE","encryptionSuiteId":"suite-9","updatedAt":"2026-10-03T09:00:00+00:00"}"""),
        ),
        folders = listOf(obj("""{"id":"f1","name":"Privé financiën","parentId":null}""")),
        types = listOf(obj("""{"id":1,"name":"login"}""")),
        suiteId = "suite-9",
        unlockKeyEpoch = 2,
        checkTop = "2026-10-03T09:00:00+00:00",
        checkTotal = 2,
    )

    @Test
    fun aSyncedVaultReadsBackWithCiphertextUntouched() {
        val store = open()
        store.replaceAll(snapshot, 1_000L)
        val s1 = store.secret("s1")!!
        assertEquals("Huisbank Zuidas", s1.name)
        assertEquals("https://mijn.huisbank.example", s1.url)
        assertEquals("UlNBLWNodW5r", s1.key)
        assertEquals("TE9HSU4=", s1.login)
        assertEquals("1", s1.typeId)
        assertEquals(listOf("s1", "s2"), store.secrets().map { it.id })
        assertNull(store.secret("s2")!!.url)
        assertEquals("Privé financiën", store.folders().single().name)
        assertEquals(SyncState("suite-9", 2, 1_000L, "2026-10-03T09:00:00+00:00", 2), store.state())
    }

    @Test
    fun theDatabaseFileHoldsNoPlaintextNameUrlOrFolder() {
        open().replaceAll(snapshot, 1_000L)
        val bytes = file.readBytes()
        for (plain in listOf("Huisbank Zuidas", "mijn.huisbank.example", "Gemeentelijk portaal", "Privé financiën")) {
            assertFalse(contains(bytes, plain.encodeToByteArray()), "\"$plain\" is readable in the file")
        }
        // A control: the server's ciphertext is stored as it came.
        assertTrue(contains(bytes, "UlNBLWNodW5r".encodeToByteArray()))
    }

    @Test
    fun anotherUnlockKeyCannotReadTheNames() {
        open().replaceAll(snapshot, 1_000L)
        assertFailsWith<KeepiqCryptoException> { open(Primitives.randomBytes(32)).secrets() }
    }

    @Test
    fun clearEmptiesEverythingAndLeavesNothingInTheFile() {
        val store = open()
        store.replaceAll(snapshot, 1_000L)
        store.clear()
        assertTrue(store.secrets().isEmpty())
        assertTrue(store.folders().isEmpty())
        assertNull(store.state())
        assertFalse(contains(file.readBytes(), "UlNBLWNodW5r".encodeToByteArray()), "secure_delete left the ciphertext behind")
    }

    @Test
    fun aClosedStoreReadsAsLocked() {
        val store = open()
        store.replaceAll(snapshot, 1_000L)
        store.close()
        assertFailsWith<VaultLockedException> { store.secrets() }
        assertFailsWith<VaultLockedException> { store.state() }
        assertFailsWith<VaultLockedException> { store.replaceAll(snapshot, 2_000L) }
    }

    @Test
    fun aSecondSyncReplacesTheFirst() {
        val store = open()
        store.replaceAll(snapshot, 1_000L)
        store.replaceAll(snapshot.copy(secrets = snapshot.secrets.take(1), checkTotal = 1), 2_000L)
        assertEquals(listOf("s1"), store.secrets().map { it.id })
        store.touch(3_000L)
        assertEquals(3_000L, store.state()!!.syncedAtMillis)
    }

    @Test
    fun aLoginsCodeAndPasskeysReadBackAsCiphertext() {
        val store = open()
        store.replaceAll(
            snapshot.copy(
                secrets = listOf(
                    obj("""{"id":"s1","name":"Huisbank","typeId":1,"key":"S0VZ","totp":"VE9UUA==","passkeys":"UEFTUw=="}"""),
                    obj("""{"id":"s2","name":"Portaal","typeId":1,"key":"S0VZ","totp":"","passkeys":null}"""),
                ),
            ),
            1_000L,
        )
        val s1 = store.secret("s1")!!
        assertEquals("VE9UUA==", s1.totp)
        assertEquals("UEFTUw==", s1.passkeys)
        assertEquals("", store.secret("s2")!!.totp)
        assertNull(store.secret("s2")!!.passkeys)
        val row = VaultRow.from(s1)
        assertEquals("VE9UUA==", row.totp)
        assertEquals("UEFTUw==", row.passkeys)
    }

    @Test
    fun aStoreFromBeforeTheFieldsMigratesAndSyncsInFull() {
        // The schema as it was before totp and passkeys (version 1).
        JdbcSqliteDriver("jdbc:sqlite:${file.absolutePath}").use { old ->
            listOf(
                "CREATE TABLE secret (id TEXT NOT NULL PRIMARY KEY, name_sealed TEXT NOT NULL, url_sealed TEXT, type_id TEXT, folder_id TEXT, " +
                    "key_ciphertext TEXT, login_ciphertext TEXT, additional_fields_ciphertext TEXT, encryption_suite_id TEXT, updated_at TEXT, " +
                    "position INTEGER NOT NULL, use_only INTEGER NOT NULL DEFAULT 0, read_only INTEGER NOT NULL DEFAULT 0, " +
                    "blocked INTEGER NOT NULL DEFAULT 0, blocked_reason TEXT)",
                "CREATE TABLE folder (id TEXT NOT NULL PRIMARY KEY, name_sealed TEXT NOT NULL, parent_id TEXT)",
                "CREATE TABLE secret_type (id TEXT NOT NULL PRIMARY KEY, json TEXT NOT NULL)",
                "CREATE TABLE sync_state (singleton INTEGER NOT NULL PRIMARY KEY CHECK (singleton = 1), suite_id TEXT, unlock_key_epoch INTEGER, " +
                    "synced_at_millis INTEGER NOT NULL, check_top TEXT, check_total INTEGER NOT NULL)",
                "INSERT INTO secret (id, name_sealed, key_ciphertext, position) VALUES ('s1', '${UnlockKeySealer(unlockKey).seal("Huisbank")}', 'S0VZ', 0)",
                "INSERT INTO sync_state VALUES (1, 'suite-9', 2, 1000, '2026-10-03T09:00:00+00:00', 1)",
                "PRAGMA user_version = 1",
            ).forEach { old.execute(null, it, 0) }
        }
        val store = open()
        val s1 = store.secret("s1")!!
        assertEquals("S0VZ", s1.key)
        assertNull(s1.totp)
        assertNull(s1.passkeys)
        assertNull(store.state())
    }

    private fun obj(json: String) = Json.parseToJsonElement(json).jsonObject

    private fun contains(haystack: ByteArray, needle: ByteArray): Boolean {
        outer@ for (i in 0..haystack.size - needle.size) {
            for (j in needle.indices) if (haystack[i + j] != needle[j]) continue@outer
            return true
        }
        return false
    }
}
