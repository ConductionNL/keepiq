// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.security

import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyPermanentlyInvalidatedException
import android.security.keystore.KeyProperties
import android.security.keystore.StrongBoxUnavailableException
import android.util.Base64
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricManager.Authenticators.BIOMETRIC_STRONG
import androidx.biometric.BiometricPrompt
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity
import java.security.KeyStore
import java.security.UnrecoverableKeyException
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException
import kotlinx.coroutines.suspendCancellableCoroutine

/** Biometric unlock cannot be used now; the message says why. */
class BiometricUnavailableException(message: String) : Exception(message)

/** A new fingerprint or face was enrolled, so the wrap is gone (design D4). */
class BiometricInvalidatedException : Exception(
    "A fingerprint or face was added to this phone. Unlock with your master password, then turn biometric unlock on again.",
)

/** The user chose the master password in the prompt, or cancelled it. */
class BiometricCancelledException : Exception("Biometric unlock cancelled.")

/**
 * Biometric unlock on Android (design D4): the 32-byte unlock key is
 * encrypted with an AES key in the AndroidKeyStore that
 *
 * - needs a strong biometric for every use (`setUserAuthenticationRequired`),
 *   opened through [BiometricPrompt] with a [BiometricPrompt.CryptoObject];
 * - is invalidated when a biometric is enrolled
 *   (`setInvalidatedByBiometricEnrollment`);
 * - lives in StrongBox when the phone has it.
 *
 * The encrypted unlock key is kept in [KeystoreStorage], so it is sealed
 * twice and never readable without the biometric.
 */
class BiometricUnlock(private val context: Context, private val storage: KeystoreStorage) {
    fun canUse(): Boolean = BiometricManager.from(context).canAuthenticate(BIOMETRIC_STRONG) == BiometricManager.BIOMETRIC_SUCCESS

    fun isEnabled(accountId: String): Boolean = storage.read(blobKey(accountId)) != null

    /** Wraps [unlockKey] after a biometric prompt. */
    suspend fun enable(activity: FragmentActivity, accountId: String, unlockKey: ByteArray) {
        if (!canUse()) throw BiometricUnavailableException("Set up a fingerprint or face unlock in the phone settings first.")
        disable(accountId)
        val cipher = Cipher.getInstance(TRANSFORMATION)
        cipher.init(Cipher.ENCRYPT_MODE, createKey(accountId))
        val authenticated = prompt(activity, cipher, "Turn on biometric unlock")
        val sealed = authenticated.iv + authenticated.doFinal(unlockKey)
        storage.write(blobKey(accountId), Base64.encodeToString(sealed, Base64.NO_WRAP))
    }

    /** The unlock key, after a biometric prompt. */
    suspend fun unlockKey(activity: FragmentActivity, accountId: String): ByteArray {
        val sealed = storage.read(blobKey(accountId))?.let { Base64.decode(it, Base64.NO_WRAP) }
            ?: throw BiometricUnavailableException("Biometric unlock is off for this account.")
        val cipher = Cipher.getInstance(TRANSFORMATION)
        try {
            val key = keyStore().getKey(alias(accountId), null) as? SecretKey ?: throw KeyPermanentlyInvalidatedException()
            cipher.init(Cipher.DECRYPT_MODE, key, GCMParameterSpec(128, sealed, 0, IV_LENGTH))
        } catch (e: KeyPermanentlyInvalidatedException) {
            disable(accountId)
            throw BiometricInvalidatedException()
        } catch (e: UnrecoverableKeyException) {
            disable(accountId)
            throw BiometricInvalidatedException()
        }
        val authenticated = prompt(activity, cipher, "Unlock Keepiq")
        return authenticated.doFinal(sealed, IV_LENGTH, sealed.size - IV_LENGTH)
    }

    /** Deletes the wrap and its key, on unpair, on an epoch change and when the user turns it off. */
    fun disable(accountId: String) {
        storage.delete(blobKey(accountId))
        runCatching { keyStore().deleteEntry(alias(accountId)) }
    }

    private suspend fun prompt(activity: FragmentActivity, cipher: Cipher, title: String): Cipher =
        suspendCancellableCoroutine { continuation ->
            val prompt = BiometricPrompt(
                activity,
                ContextCompat.getMainExecutor(activity),
                object : BiometricPrompt.AuthenticationCallback() {
                    override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult) {
                        val c = result.cryptoObject?.cipher
                        if (c == null) {
                            continuation.resumeWithException(BiometricUnavailableException("The biometric prompt returned no key."))
                        } else {
                            continuation.resume(c)
                        }
                    }

                    override fun onAuthenticationError(errorCode: Int, errString: CharSequence) {
                        if (!continuation.isActive) return
                        val cancelled = errorCode == BiometricPrompt.ERROR_NEGATIVE_BUTTON ||
                            errorCode == BiometricPrompt.ERROR_USER_CANCELED ||
                            errorCode == BiometricPrompt.ERROR_CANCELED
                        continuation.resumeWithException(
                            if (cancelled) BiometricCancelledException() else BiometricUnavailableException(errString.toString()),
                        )
                    }
                },
            )
            val info = BiometricPrompt.PromptInfo.Builder()
                .setTitle(title)
                .setNegativeButtonText("Use master password")
                .setAllowedAuthenticators(BIOMETRIC_STRONG)
                .build()
            prompt.authenticate(info, BiometricPrompt.CryptoObject(cipher))
            continuation.invokeOnCancellation { prompt.cancelAuthentication() }
        }

    private fun createKey(accountId: String): SecretKey {
        fun spec(strongBox: Boolean) = KeyGenParameterSpec.Builder(
            alias(accountId),
            KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT,
        )
            .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
            .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
            .setKeySize(256)
            .setUserAuthenticationRequired(true)
            .setInvalidatedByBiometricEnrollment(true)
            .apply {
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
                    setUserAuthenticationParameters(0, KeyProperties.AUTH_BIOMETRIC_STRONG)
                }
                if (strongBox) setIsStrongBoxBacked(true)
            }
            .build()

        val generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, KeystoreStorage.ANDROID_KEYSTORE)
        val hasStrongBox = context.packageManager.hasSystemFeature(PackageManager.FEATURE_STRONGBOX_KEYSTORE)
        if (hasStrongBox) {
            try {
                generator.init(spec(strongBox = true))
                return generator.generateKey()
            } catch (e: StrongBoxUnavailableException) {
                // Fall through to the TEE.
            }
        }
        generator.init(spec(strongBox = false))
        return generator.generateKey()
    }

    private fun keyStore(): KeyStore = KeyStore.getInstance(KeystoreStorage.ANDROID_KEYSTORE).apply { load(null) }

    private fun alias(accountId: String) = "keepiq.biometric.$accountId"

    private fun blobKey(accountId: String) = "biometric:$accountId"

    companion object {
        private const val TRANSFORMATION = "AES/GCM/NoPadding"
        private const val IV_LENGTH = 12
    }
}
