// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.store

import kotlinx.cinterop.ByteVar
import kotlinx.cinterop.ExperimentalForeignApi
import kotlinx.cinterop.UnsafeNumber
import kotlinx.cinterop.readBytes
import kotlinx.cinterop.reinterpret
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import nl.conduction.keepiq.shared.account.InMemorySecureStorage
import nl.conduction.keepiq.shared.crypto.Primitives
import platform.Foundation.NSFileManager
import platform.Foundation.NSTemporaryDirectory
import platform.Foundation.NSUUID
import kotlin.test.AfterTest
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertNotEquals
import kotlin.test.assertNotNull
import kotlin.test.assertNull
import kotlin.test.assertTrue

/**
 * The SQLCipher store on the iOS simulator (task 1.6.1): the file on disk
 * is encrypted with the device key, the key lives in [SecureStorage], and
 * unpair deletes both. VaultStoreTest covers the store logic on the jvm.
 */
@OptIn(ExperimentalForeignApi::class, UnsafeNumber::class)
class IosEncryptedStoreTest {
    private val directory = NSTemporaryDirectory() + "keepiq-store-test-" + NSUUID().UUIDString
    private val storage = InMemorySecureStorage()
    private val accountId = "admin@https://cloud.example.nl"
    private val unlockKey = Primitives.randomBytes(32)

    init {
        NSFileManager.defaultManager.createDirectoryAtPath(directory, true, null, null)
    }

    @AfterTest
    fun cleanUp() {
        NSFileManager.defaultManager.removeItemAtPath(directory, null)
    }

    private fun open(): VaultStore = VaultStore(IosEncryptedStore.open(storage, accountId, directory), UnlockKeySealer(unlockKey))

    private val snapshot = VaultSnapshot(
        secrets = listOf(
            obj("""{"id":"s1","name":"Huisbank Zuidas","url":"https://mijn.huisbank.example","typeId":1,"folderId":"f1","key":"UlNBLWNodW5r","login":"TE9HSU4=","additionalFields":null,"encryptionSuiteId":"suite-9","updatedAt":"2026-10-02T09:00:00+00:00"}"""),
        ),
        folders = listOf(obj("""{"id":"f1","name":"Privé financiën","parentId":null}""")),
        types = listOf(obj("""{"id":1,"name":"login"}""")),
        suiteId = "suite-9",
        unlockKeyEpoch = 2,
        checkTop = "2026-10-02T09:00:00+00:00",
        checkTotal = 1,
    )

    @Test
    fun theStoreIsSqlCipher() {
        val driver = IosEncryptedStore.open(storage, accountId, directory)
        try {
            val version = IosEncryptedStore.cipherVersion(driver)
            assertNotNull(version, "plain SQLite answered: SQLCipher is not linked")
            println("SQLCipher $version")
        } finally {
            driver.close()
        }
    }

    @Test
    fun aSyncedVaultReadsBackAfterReopening() {
        open().apply { replaceAll(snapshot, 1_000L) }.close()
        val store = open()
        assertEquals("Huisbank Zuidas", store.secret("s1")!!.name)
        assertEquals("UlNBLWNodW5r", store.secret("s1")!!.key)
        assertEquals("Privé financiën", store.folders().single().name)
        assertEquals(1_000L, store.state()!!.syncedAtMillis)
        store.close()
    }

    @Test
    fun theFileOnDiskHoldsNothingReadable() {
        open().apply { replaceAll(snapshot, 1_000L) }.close()
        val bytes = fileBytes()
        assertTrue(bytes.size > 1024, "the database file is missing")
        // Plain SQLite starts every file with this header; SQLCipher encrypts it too.
        assertFalse(contains(bytes, "SQLite format 3".encodeToByteArray()), "the file is a plain SQLite database")
        // The server's ciphertext is stored as it came, so finding it would mean the page is not encrypted.
        for (plain in listOf("UlNBLWNodW5r", "TE9HSU4=", "suite-9", "Huisbank Zuidas", "mijn.huisbank.example", "Privé financiën")) {
            assertFalse(contains(bytes, plain.encodeToByteArray()), "\"$plain\" is readable in the file")
        }
    }

    @Test
    fun theKeyIsRandomPerAccountAndKeptInSecureStorage() {
        IosEncryptedStore.open(storage, accountId, directory).close()
        IosEncryptedStore.open(storage, "other@https://cloud.example.nl", directory).close()
        val first = storage.read("store-key/$accountId")
        val second = storage.read("store-key/other@https://cloud.example.nl")
        assertNotNull(first)
        assertNotNull(second)
        assertNotEquals(first, second)
    }

    @Test
    fun aFileWhoseKeyIsGoneStartsEmpty() {
        open().apply { replaceAll(snapshot, 1_000L) }.close()
        // The Keychain was reset: the old file cannot be opened, so a new one starts.
        storage.delete("store-key/$accountId")
        val store = open()
        assertNull(store.state())
        assertTrue(store.secrets().isEmpty())
        store.close()
    }

    @Test
    fun unpairDeletesTheFileAndTheKey() {
        open().apply { replaceAll(snapshot, 1_000L) }.close()
        IosEncryptedStore.delete(storage, accountId, directory)
        assertNull(storage.read("store-key/$accountId"))
        assertFalse(NSFileManager.defaultManager.fileExistsAtPath(IosEncryptedStore.path(accountId, directory)))
    }

    @Test
    fun clearEmptiesTheStore() {
        val store = open()
        store.replaceAll(snapshot, 1_000L)
        store.clear()
        assertTrue(store.secrets().isEmpty())
        assertNull(store.state())
        store.close()
    }

    private fun obj(json: String) = Json.parseToJsonElement(json).jsonObject

    private fun fileBytes(): ByteArray {
        val path = IosEncryptedStore.path(accountId, directory)
        return listOf(path, "$path-wal").fold(ByteArray(0)) { all, file ->
            val data = NSFileManager.defaultManager.contentsAtPath(file) ?: return@fold all
            val bytes = data.bytes?.reinterpret<ByteVar>()?.readBytes(data.length.toInt()) ?: ByteArray(0)
            all + bytes
        }
    }

    private fun contains(haystack: ByteArray, needle: ByteArray): Boolean {
        outer@ for (i in 0..haystack.size - needle.size) {
            for (j in needle.indices) if (haystack[i + j] != needle[j]) continue@outer
            return true
        }
        return false
    }
}
