// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.passkey

import android.os.Build
import android.os.CancellationSignal
import android.os.OutcomeReceiver
import android.util.Log
import androidx.annotation.RequiresApi
import androidx.credentials.exceptions.ClearCredentialException
import androidx.credentials.exceptions.CreateCredentialException
import androidx.credentials.exceptions.CreateCredentialUnknownException
import androidx.credentials.exceptions.GetCredentialException
import androidx.credentials.exceptions.GetCredentialUnknownException
import androidx.credentials.provider.BeginCreateCredentialRequest
import androidx.credentials.provider.BeginCreateCredentialResponse
import androidx.credentials.provider.BeginGetCredentialRequest
import androidx.credentials.provider.BeginGetCredentialResponse
import androidx.credentials.provider.CredentialProviderService
import androidx.credentials.provider.ProviderClearCredentialStateRequest
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.launch
import nl.conduction.keepiq.android.KeepiqApp

/**
 * Keepiq in Android's Credential Manager (task 5.1, Android 14 and later):
 * passkeys and passwords, from androidx.credentials alone, without
 * credentials-play-services-auth. The manifest declares it for every
 * version, but Android starts a credential provider on 14 and later only.
 *
 * The Begin calls run off the main thread (an app's rpId is checked with
 * Digital Asset Links) and answer with entries or an unlock action; the
 * pick runs in [CredentialProviderActivity].
 */
@RequiresApi(Build.VERSION_CODES.UPSIDE_DOWN_CAKE)
class KeepiqCredentialProviderService : CredentialProviderService() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val entries by lazy { CredentialEntries(this, application as KeepiqApp) }

    override fun onBeginGetCredentialRequest(
        request: BeginGetCredentialRequest,
        cancellationSignal: CancellationSignal,
        callback: OutcomeReceiver<BeginGetCredentialResponse, GetCredentialException>,
    ) {
        scope.launch {
            val response = try {
                entries.beginGet(request)
            } catch (e: Exception) {
                Log.w(TAG, "get request failed: ${e.javaClass.simpleName}")
                callback.onError(GetCredentialUnknownException(e.javaClass.simpleName))
                return@launch
            }
            if (!cancellationSignal.isCanceled) callback.onResult(response)
        }
    }

    override fun onBeginCreateCredentialRequest(
        request: BeginCreateCredentialRequest,
        cancellationSignal: CancellationSignal,
        callback: OutcomeReceiver<BeginCreateCredentialResponse, CreateCredentialException>,
    ) {
        scope.launch {
            val response = try {
                entries.beginCreate(request)
            } catch (e: Exception) {
                Log.w(TAG, "create request failed: ${e.javaClass.simpleName}")
                callback.onError(CreateCredentialUnknownException(e.javaClass.simpleName))
                return@launch
            }
            if (!cancellationSignal.isCanceled) callback.onResult(response)
        }
    }

    /** Keepiq keeps no sign-in state for a caller, so there is nothing to clear. */
    override fun onClearCredentialStateRequest(
        request: ProviderClearCredentialStateRequest,
        cancellationSignal: CancellationSignal,
        callback: OutcomeReceiver<Void?, ClearCredentialException>,
    ) {
        callback.onResult(null)
    }

    override fun onDestroy() {
        scope.cancel()
        super.onDestroy()
    }

    private companion object {
        const val TAG = "KeepiqPasskeys"
    }
}
