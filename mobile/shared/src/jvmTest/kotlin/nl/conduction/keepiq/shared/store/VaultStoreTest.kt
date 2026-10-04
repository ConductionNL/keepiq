// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.store

import app.cash.sqldelight.driver.jdbc.sqlite.JdbcSqliteDriver
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import nl.conduction.keepiq.shared.crypto.KeepiqCryptoException
import nl.conduction.keepiq.shared.crypto.Primitives
import nl.conduction.keepiq.shared.store.db.KeepiqDatabase
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
    fun aSecondSyncReplacesTheFirst() {
        val store = open()
        store.replaceAll(snapshot, 1_000L)
        store.replaceAll(snapshot.copy(secrets = snapshot.secrets.take(1), checkTotal = 1), 2_000L)
        assertEquals(listOf("s1"), store.secrets().map { it.id })
        store.touch(3_000L)
        assertEquals(3_000L, store.state()!!.syncedAtMillis)
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
