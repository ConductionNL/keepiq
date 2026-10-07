// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.store

import app.cash.sqldelight.db.QueryResult
import app.cash.sqldelight.db.SqlDriver
import app.cash.sqldelight.driver.native.NativeSqliteDriver
import co.touchlab.sqliter.DatabaseConfiguration
import co.touchlab.sqliter.DatabaseFileContext
import kotlinx.cinterop.BetaInteropApi
import kotlinx.cinterop.ExperimentalForeignApi
import kotlinx.cinterop.UnsafeNumber
import nl.conduction.keepiq.shared.account.SecureStorage
import nl.conduction.keepiq.shared.crypto.Encoding
import nl.conduction.keepiq.shared.crypto.Primitives
import nl.conduction.keepiq.shared.store.db.KeepiqDatabase
import platform.Foundation.NSApplicationSupportDirectory
import platform.Foundation.NSFileManager
import platform.Foundation.NSNumber
import platform.Foundation.NSSearchPathForDirectoriesInDomains
import platform.Foundation.NSURL
import platform.Foundation.NSURLIsExcludedFromBackupKey
import platform.Foundation.NSUserDomainMask

/** SQLCipher is not in the binary: the store refuses to write a plaintext database. */
class StoreNotEncryptedException : IllegalStateException(
    "Keepiq cannot encrypt its offline copy on this phone, so it keeps none. The vault works online only.",
)

/**
 * The offline store on iOS (design D5, task 1.6.1), as openEncryptedDriver
 * in androidMain does it on Android.
 *
 * The database is SQLCipher (Community Edition, built from source by
 * shared/build.gradle.kts and bundled into this framework). Its key is 32
 * random bytes per account, kept as a [SecureStorage] item: on iOS that is
 * KeychainStorage, a Keychain item with
 * kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly and no biometric access
 * control. It never leaves the phone, so a copied database file cannot be
 * opened elsewhere. Names, URLs and folder names are sealed again by
 * [VaultStore] under the unlock key.
 */
object IosEncryptedStore {
    /** The folder the stores live in: Application Support, excluded from backups. */
    @OptIn(UnsafeNumber::class, ExperimentalForeignApi::class, BetaInteropApi::class)
    fun defaultDirectory(): String {
        val base = NSSearchPathForDirectoriesInDomains(NSApplicationSupportDirectory, NSUserDomainMask, true).first() as String
        val directory = "$base/keepiq-stores"
        val files = NSFileManager.defaultManager
        if (!files.fileExistsAtPath(directory)) files.createDirectoryAtPath(directory, true, null, null)
        NSURL.fileURLWithPath(directory).setResourceValue(NSNumber(bool = true), NSURLIsExcludedFromBackupKey, null)
        return directory
    }

    /**
     * Opens the store of [accountId] in [directory]. A file without its key
     * (the Keychain was reset) or one the key does not open is deleted and
     * created again: it is a copy of the server, not the only one. Throws
     * [StoreNotEncryptedException] when SQLCipher is missing from the binary.
     */
    fun open(storage: SecureStorage, accountId: String, directory: String): SqlDriver {
        val stored = storage.read(keyName(accountId))
        if (stored == null) DatabaseFileContext.deleteDatabase(databaseName(accountId), directory)
        val key = stored?.let { Encoding.fromBase64(it) } ?: newKey(storage, accountId)
        return try {
            openWith(key, accountId, directory)
        } catch (e: StoreNotEncryptedException) {
            // Plain SQLite already wrote the empty schema: leave no file behind.
            delete(storage, accountId, directory)
            throw e
        } catch (e: Exception) {
            println("Keepiq store could not be opened, starting a new one: $e")
            delete(storage, accountId, directory)
            openWith(newKey(storage, accountId), accountId, directory)
        } finally {
            key.fill(0)
        }
    }

    /** Deletes the store of an account and its key: unpair. */
    fun delete(storage: SecureStorage, accountId: String, directory: String) {
        DatabaseFileContext.deleteDatabase(databaseName(accountId), directory)
        storage.delete(keyName(accountId))
    }

    /** The path of the database file, for the tests that read it raw. */
    fun path(accountId: String, directory: String): String =
        DatabaseFileContext.databasePath(databaseName(accountId), directory)

    /**
     * The SQLCipher version the connection reports, or null for plain
     * SQLite. Plain SQLite ignores PRAGMA key and would write an open file.
     */
    fun cipherVersion(driver: SqlDriver): String? =
        driver.executeQuery(null, "PRAGMA cipher_version", { cursor ->
            QueryResult.Value(if (cursor.next().value) cursor.getString(0) else null)
        }, 0).value

    private fun openWith(key: ByteArray, accountId: String, directory: String): SqlDriver {
        // A raw 256-bit key as SQLCipher's blob literal: no key derivation on
        // every open, the key is random already.
        val rawKey = "x'" + Encoding.hex(key) + "'"
        val driver = NativeSqliteDriver(
            schema = KeepiqDatabase.Schema,
            name = databaseName(accountId),
            onConfiguration = { config ->
                config.copy(
                    extendedConfig = config.extendedConfig.copy(basePath = directory),
                    encryptionConfig = DatabaseConfiguration.Encryption(key = rawKey),
                )
            },
        )
        try {
            if (cipherVersion(driver).isNullOrEmpty()) throw StoreNotEncryptedException()
            // Reads the schema: fails here, not later, when the key does not open the file.
            driver.executeQuery(null, "SELECT count(*) FROM sqlite_master", { cursor -> QueryResult.Value(cursor.next().value) }, 0)
        } catch (e: Exception) {
            runCatching { driver.close() }
            throw e
        }
        return driver
    }

    private fun newKey(storage: SecureStorage, accountId: String): ByteArray {
        val key = Primitives.randomBytes(32)
        storage.write(keyName(accountId), Encoding.toBase64(key))
        return key
    }

    private fun keyName(accountId: String) = "store-key/$accountId"

    private fun databaseName(accountId: String) = "keepiq-vault-${Encoding.hex(Encoding.utf8(accountId))}.db"
}
