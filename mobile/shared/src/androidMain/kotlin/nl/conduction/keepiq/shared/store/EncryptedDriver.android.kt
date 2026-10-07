// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.store

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import app.cash.sqldelight.db.SqlDriver
import app.cash.sqldelight.driver.android.AndroidSqliteDriver
import net.zetetic.database.sqlcipher.SupportOpenHelperFactory
import nl.conduction.keepiq.shared.crypto.Encoding
import nl.conduction.keepiq.shared.store.db.KeepiqDatabase
import java.security.KeyStore
import java.security.SecureRandom
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/**
 * Opens the SQLCipher store of one account. Its key is 32 random bytes,
 * wrapped with an AndroidKeyStore AES key that never leaves the device and
 * needs no user authentication (design D5). The wrapped key sits in app
 * preferences that are never backed up, so a copied database file cannot be opened
 * elsewhere.
 *
 * Runs only on Android: the jvm tests cover the store logic over plain
 * SQLite (VaultStoreTest), and CI compiles this.
 */
fun openEncryptedDriver(context: Context, accountId: String): SqlDriver {
    System.loadLibrary("sqlcipher")
    val key = DeviceBoundDatabaseKey(context).keyFor(accountId)
    return AndroidSqliteDriver(
        schema = KeepiqDatabase.Schema,
        context = context,
        name = databaseName(accountId),
        factory = SupportOpenHelperFactory(key),
    )
}

/** Deletes the store of an account, on unpair. */
fun deleteEncryptedStore(context: Context, accountId: String) {
    context.deleteDatabase(databaseName(accountId))
    DeviceBoundDatabaseKey(context).forget(accountId)
}

private fun databaseName(accountId: String) = "keepiq-vault-${Encoding.hex(Encoding.utf8(accountId))}.db"

private class DeviceBoundDatabaseKey(context: Context) {
    // The app sets android:allowBackup="false", so these never leave the device.
    private val prefs = context.getSharedPreferences("keepiq-store-keys", Context.MODE_PRIVATE)

    fun keyFor(accountId: String): ByteArray {
        val stored = prefs.getString(accountId, null)
        if (stored != null) return unwrap(Encoding.fromBase64(stored))
        val key = ByteArray(32).also { SecureRandom().nextBytes(it) }
        prefs.edit().putString(accountId, Encoding.toBase64(wrap(key))).apply()
        return key
    }

    fun forget(accountId: String) {
        prefs.edit().remove(accountId).apply()
    }

    private fun wrap(key: ByteArray): ByteArray {
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.ENCRYPT_MODE, keystoreKey())
        return cipher.iv + cipher.doFinal(key)
    }

    private fun unwrap(blob: ByteArray): ByteArray {
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.DECRYPT_MODE, keystoreKey(), GCMParameterSpec(128, blob, 0, 12))
        return cipher.doFinal(blob, 12, blob.size - 12)
    }

    private fun keystoreKey(): SecretKey {
        val keyStore = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        (keyStore.getKey(ALIAS, null) as? SecretKey)?.let { return it }
        val generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore")
        generator.init(
            KeyGenParameterSpec.Builder(ALIAS, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setKeySize(256)
                .build(),
        )
        return generator.generateKey()
    }

    private companion object {
        const val ALIAS = "keepiq-store-key"
    }
}
