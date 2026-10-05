// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.autofill

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.view.autofill.AutofillManager
import nl.conduction.keepiq.android.passkey.PasskeyProvider
import nl.conduction.keepiq.shared.autofill.AutofillIndex
import nl.conduction.keepiq.shared.autofill.AutofillIndexSink
import nl.conduction.keepiq.shared.vault.VaultKeys
import java.io.File
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/**
 * The autofill index on Android (design D5, task 4.6): one file per
 * account in the no-backup directory, sealed with AES-256-GCM under an
 * AndroidKeyStore key that needs no user authentication. The service reads
 * it while the vault is locked, to tell that a form has a match; it holds
 * names, addresses and the item ciphertext the server sent, never a
 * plaintext secret.
 *
 * Kept in memory only when the organisation keeps no offline copy, and when
 * Keepiq is neither the autofill service nor the passkey provider: then
 * nothing is written to disk.
 */
class AndroidAutofillIndex(private val context: Context) : AutofillIndexSink {
    private val memory = HashMap<String, AutofillIndex>()

    @Synchronized
    override fun replace(accountId: String, index: AutofillIndex, persist: Boolean, keys: VaultKeys) {
        memory[accountId] = index
        if (persist && keepsFiles()) {
            write(accountId, index)
        } else {
            fileOf(accountId).delete()
        }
    }

    @Synchronized
    override fun clear(accountId: String) {
        memory.remove(accountId)
        fileOf(accountId).delete()
    }

    /** The index of an account: memory first, else the sealed file. */
    @Synchronized
    fun index(accountId: String): AutofillIndex {
        memory[accountId]?.let { return it }
        val file = fileOf(accountId)
        if (!file.isFile) return AutofillIndex.EMPTY
        val index = try {
            AutofillIndex.fromJson(String(open(file.readBytes()), Charsets.UTF_8))
        } catch (e: Exception) {
            // Sealed under a key that is gone: worthless, rebuilt at the next sync.
            file.delete()
            AutofillIndex.EMPTY
        }
        memory[accountId] = index
        return index
    }

    /** The user turned autofill off, or chose another service: no index on disk (mobile-system-autofill). */
    @Synchronized
    fun clearFilesIfNotTheService() {
        if (keepsFiles()) return
        dir().listFiles()?.forEach { it.delete() }
    }

    @Synchronized
    fun clearAll() {
        memory.clear()
        dir().listFiles()?.forEach { it.delete() }
    }

    /**
     * The index is on disk while Keepiq fills: as the autofill service, or
     * as the passkey and password provider on Android 14 and later (task
     * 5.1), which reads it while the vault is locked.
     */
    private fun keepsFiles(): Boolean = isAutofillService() || PasskeyProvider.isEnabled(context)

    fun isAutofillService(): Boolean = runCatching {
        context.getSystemService(AutofillManager::class.java)?.hasEnabledAutofillServices() == true
    }.getOrDefault(false)

    private fun write(accountId: String, index: AutofillIndex) {
        val file = fileOf(accountId)
        val tmp = File(file.path + ".tmp")
        tmp.writeBytes(seal(index.toJson().toByteArray(Charsets.UTF_8)))
        tmp.renameTo(file)
    }

    private fun dir(): File = File(context.noBackupFilesDir, "autofill").apply { mkdirs() }

    private fun fileOf(accountId: String) = File(dir(), "index-" + accountId.toByteArray(Charsets.UTF_8).joinToString("") { "%02x".format(it) } + ".bin")

    private fun seal(plain: ByteArray): ByteArray {
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.ENCRYPT_MODE, key())
        return cipher.iv + cipher.doFinal(plain)
    }

    private fun open(blob: ByteArray): ByteArray {
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.DECRYPT_MODE, key(), GCMParameterSpec(128, blob, 0, 12))
        return cipher.doFinal(blob, 12, blob.size - 12)
    }

    private fun key(): SecretKey {
        val store = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        (store.getKey(ALIAS, null) as? SecretKey)?.let { return it }
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
        const val ALIAS = "keepiq-autofill-index"
    }
}
