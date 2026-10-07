// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.passkey

import android.app.Activity
import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.Bundle
import android.util.Log
import androidx.activity.compose.setContent
import androidx.annotation.RequiresApi
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.mutableStateOf
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.credentials.CreatePasswordRequest
import androidx.credentials.CreatePasswordResponse
import androidx.credentials.CreatePublicKeyCredentialRequest
import androidx.credentials.CreatePublicKeyCredentialResponse
import androidx.credentials.GetCredentialResponse
import androidx.credentials.GetPasswordOption
import androidx.credentials.GetPublicKeyCredentialOption
import androidx.credentials.PasswordCredential
import androidx.credentials.PublicKeyCredential
import androidx.credentials.exceptions.CreateCredentialException
import androidx.credentials.exceptions.CreateCredentialUnknownException
import androidx.credentials.exceptions.GetCredentialException
import androidx.credentials.exceptions.GetCredentialUnknownException
import androidx.credentials.exceptions.NoCredentialException
import androidx.credentials.exceptions.domerrors.InvalidStateError
import androidx.credentials.exceptions.domerrors.NotAllowedError
import androidx.credentials.exceptions.domerrors.NotSupportedError
import androidx.credentials.exceptions.publickeycredential.CreatePublicKeyCredentialDomException
import androidx.credentials.provider.BeginGetCredentialResponse
import androidx.credentials.provider.PendingIntentHandler
import androidx.fragment.app.FragmentActivity
import androidx.lifecycle.lifecycleScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import nl.conduction.keepiq.android.KeepiqApp
import nl.conduction.keepiq.android.R
import nl.conduction.keepiq.android.Screen
import nl.conduction.keepiq.android.autofill.AutofillCore
import nl.conduction.keepiq.android.ui.ScreenColumn
import nl.conduction.keepiq.android.ui.UnlockScreen
import nl.conduction.keepiq.shared.autofill.AutofillChoices
import nl.conduction.keepiq.shared.autofill.AutofillIndexHub
import nl.conduction.keepiq.shared.autofill.AutofillSaver
import nl.conduction.keepiq.shared.autofill.SaveResult
import nl.conduction.keepiq.shared.autofill.SiteMatch
import nl.conduction.keepiq.shared.crypto.ClientData
import nl.conduction.keepiq.shared.crypto.KeepiqCryptoException
import nl.conduction.keepiq.shared.crypto.WebAuthn
import nl.conduction.keepiq.shared.passkey.ExcludedCredentialException
import nl.conduction.keepiq.shared.passkey.Passkeys
import nl.conduction.keepiq.shared.passkey.WebAuthnJson
import nl.conduction.keepiq.shared.sync.SyncTrigger
import nl.conduction.keepiq.shared.vault.VaultKeys
import nl.conduction.keepiq.shared.vault.VaultRepository

/**
 * What happens after a pick in Android's Credential Manager sheet (task
 * 5.1): the app's own unlock screen when the vault is locked, then
 *
 * - [MODE_UNLOCK]: the entries for the request, now with names;
 * - [MODE_PASSKEY]: the assertion with the picked passkey, its counter
 *   written back as the extension does;
 * - [MODE_PASSWORD]: the picked login;
 * - [MODE_CREATE]: a new passkey or password saved in the vault.
 *
 * The caller and its rpId are checked again here from what the system
 * hands over, so nothing rests on the earlier Begin call.
 */
@RequiresApi(Build.VERSION_CODES.UPSIDE_DOWN_CAKE)
class CredentialProviderActivity : FragmentActivity() {
    private val app: KeepiqApp get() = application as KeepiqApp
    private val core: AutofillCore get() = app.autofill
    private val unlocking = mutableStateOf(true)
    private var started = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setResult(Activity.RESULT_CANCELED)
        val accountId = core.accountId() ?: return finish()
        val state = app.state
        if (state.gate.value == null) state.refreshGate(accountId)
        setContent {
            MaterialTheme(colorScheme = if (isSystemInDarkTheme()) darkColorScheme() else lightColorScheme()) {
                Surface(modifier = Modifier.fillMaxSize()) {
                    if (unlocking.value) {
                        UnlockScreen(state, this, accountId)
                    } else {
                        ScreenColumn {
                            Text(stringResource(R.string.passkey_working), style = MaterialTheme.typography.headlineSmall, modifier = Modifier.semantics { heading() })
                            CircularProgressIndicator(modifier = Modifier.testTag("passkeyBusy"))
                        }
                    }
                }
            }
        }
        lifecycleScope.launch {
            state.screen.collect { screen ->
                if (!started && (screen is Screen.Unlocked || screen is Screen.Settings)) {
                    started = true
                    unlocking.value = false
                    val result = withContext(Dispatchers.IO) { answer(accountId) }
                    if (result != null) setResult(Activity.RESULT_OK, result)
                    finish()
                }
            }
        }
    }

    /** The result intent for the system, or null to cancel. */
    private suspend fun answer(accountId: String): Intent? {
        val keys = core.keys(accountId) ?: return null
        val mode = intent.getStringExtra(EXTRA_MODE)
        return try {
            when (mode) {
                MODE_UNLOCK -> unlocked(accountId, keys)
                MODE_PASSKEY -> passkey(accountId, keys)
                MODE_PASSWORD -> password(accountId, keys)
                MODE_CREATE -> create(accountId, keys)
                else -> null
            }
        } catch (e: Exception) {
            Log.w(TAG, "$mode failed: ${e.javaClass.simpleName}")
            Intent().also { out ->
                if (mode == MODE_CREATE) {
                    PendingIntentHandler.setCreateCredentialException(out, createError(e))
                } else {
                    PendingIntentHandler.setGetCredentialException(out, GetCredentialUnknownException(e.javaClass.simpleName))
                }
            }
        }
    }

    private suspend fun unlocked(accountId: String, keys: VaultKeys): Intent? {
        val request = PendingIntentHandler.retrieveBeginGetCredentialRequest(intent) ?: return null
        val caller = request.callingAppInfo?.let { CredentialCaller.of(this, it) } ?: return null
        refreshIndex(accountId, keys, always = false)
        val list = CredentialEntries(this, app).entries(request, caller, core.indexOf(accountId), keys)
        return Intent().also { PendingIntentHandler.setBeginGetCredentialResponse(it, BeginGetCredentialResponse(credentialEntries = list)) }
    }

    private suspend fun passkey(accountId: String, keys: VaultKeys): Intent {
        val request = PendingIntentHandler.retrieveProviderGetCredentialRequest(intent) ?: return getError(GetCredentialUnknownException("no request"))
        val caller = CredentialCaller.of(this, request.callingAppInfo) ?: return getError(NoCredentialException())
        val option = request.credentialOptions.filterIsInstance<GetPublicKeyCredentialOption>().firstOrNull() ?: return getError(NoCredentialException())
        val get = WebAuthnJson.get(option.requestJson) ?: return getError(NoCredentialException())
        val rpId = get.rpId?.takeIf { it.isNotEmpty() } ?: (caller as? CredentialCaller.Browser)?.let { SiteMatch.hostOf(it.origin) } ?: ""
        if (!caller.mayUse(rpId, core.identities)) return getError(NoCredentialException())
        refreshIndex(accountId, keys, always = false)
        val itemId = intent.getStringExtra(EXTRA_ITEM)
        val choice = Passkeys.candidates(core.indexOf(accountId), rpId, keys, get.allowCredentialIds).firstOrNull { it.itemId == itemId }
            ?: return getError(NoCredentialException())
        val clientData = option.clientDataHash?.let { ClientData.hashed(it) } ?: ClientData.build(ClientData.GET, get.challenge, caller.origin)
        val api = core.api(accountId) ?: return getError(GetCredentialUnknownException("no account"))
        val assertion = Passkeys.sign(api, keys, choice, clientData, rpId)
        // The index holds the item as it was: read it again, so the next sign starts from the new counter.
        if (assertion.counter > 0) refreshIndex(accountId, keys, always = true)
        return Intent().also {
            PendingIntentHandler.setGetCredentialResponse(it, GetCredentialResponse(PublicKeyCredential(WebAuthnJson.assertionResponse(assertion))))
        }
    }

    private suspend fun password(accountId: String, keys: VaultKeys): Intent {
        val request = PendingIntentHandler.retrieveProviderGetCredentialRequest(intent) ?: return getError(GetCredentialUnknownException("no request"))
        if (request.credentialOptions.none { it is GetPasswordOption }) return getError(NoCredentialException())
        val caller = CredentialCaller.of(this, request.callingAppInfo) ?: return getError(NoCredentialException())
        refreshIndex(accountId, keys, always = false)
        val itemId = intent.getStringExtra(EXTRA_ITEM)
        val choice = AutofillChoices.logins(core.indexOf(accountId), caller.passwordTarget(core.identities), keys).firstOrNull { it.id == itemId }
            ?: return getError(NoCredentialException())
        return Intent().also { PendingIntentHandler.setGetCredentialResponse(it, GetCredentialResponse(PasswordCredential(choice.login, choice.password))) }
    }

    private suspend fun create(accountId: String, keys: VaultKeys): Intent {
        val request = PendingIntentHandler.retrieveProviderCreateCredentialRequest(intent)
            ?: return createErrorIntent(CreateCredentialUnknownException("no request"))
        val caller = CredentialCaller.of(this, request.callingAppInfo)
            ?: return createErrorIntent(CreatePublicKeyCredentialDomException(NotAllowedError(), "caller not verified"))
        val api = core.api(accountId) ?: return createErrorIntent(CreateCredentialUnknownException("no account"))
        refreshIndex(accountId, keys, always = false)
        val index = core.indexOf(accountId)
        return when (val call = request.callingRequest) {
            is CreatePublicKeyCredentialRequest -> {
                val create = WebAuthnJson.creation(call.requestJson)
                    ?: return createErrorIntent(CreatePublicKeyCredentialDomException(NotSupportedError(), "request not readable"))
                if (!caller.mayUse(create.rpId, core.identities)) {
                    return createErrorIntent(CreatePublicKeyCredentialDomException(NotAllowedError(), "rpId not verified for this caller"))
                }
                val clientData = call.clientDataHash?.let { ClientData.hashed(it) }
                    ?: ClientData.build(ClientData.CREATE, WebAuthnJson.challenge(call.requestJson) ?: ByteArray(0), caller.origin)
                val registration = Passkeys.create(api, keys, index, create, clientData, System.currentTimeMillis())
                refreshIndex(accountId, keys, always = true)
                Intent().also {
                    PendingIntentHandler.setCreateCredentialResponse(it, CreatePublicKeyCredentialResponse(WebAuthnJson.registrationResponse(registration)))
                }
            }
            is CreatePasswordRequest -> {
                val label = core.identities.label(caller.identity.packageName)
                val saved = AutofillSaver(api, keys).save(caller.passwordTarget(core.identities), index, call.id, call.password, label, null)
                if (saved == SaveResult.REFUSED) return createErrorIntent(CreateCredentialUnknownException("refused"))
                refreshIndex(accountId, keys, always = true)
                Intent().also { PendingIntentHandler.setCreateCredentialResponse(it, CreatePasswordResponse()) }
            }
            else -> createErrorIntent(CreateCredentialUnknownException("unsupported type"))
        }
    }

    /**
     * Reads the vault again so the index has what the server has. With
     * [always] false only when no sync built the index in this process yet.
     */
    private suspend fun refreshIndex(accountId: String, keys: VaultKeys, always: Boolean) {
        if (!always && core.indexOf(accountId).entries.isNotEmpty()) return
        val api = core.api(accountId) ?: return
        runCatching {
            VaultRepository(api, keys, null, null, { System.currentTimeMillis() }, { AutofillIndexHub.refreshed(accountId, it, keys) })
                .refresh(SyncTrigger.MANUAL)
        }
    }

    private fun getError(e: GetCredentialException): Intent = Intent().also { PendingIntentHandler.setGetCredentialException(it, e) }

    private fun createErrorIntent(e: CreateCredentialException): Intent = Intent().also { PendingIntentHandler.setCreateCredentialException(it, e) }

    private fun createError(e: Exception): CreateCredentialException = when {
        e is ExcludedCredentialException -> CreatePublicKeyCredentialDomException(InvalidStateError(), e.message)
        e is KeepiqCryptoException && e.message == WebAuthn.UNSUPPORTED_ALGORITHM -> CreatePublicKeyCredentialDomException(NotSupportedError(), e.message)
        else -> CreateCredentialUnknownException(e.javaClass.simpleName)
    }

    companion object {
        private const val TAG = "KeepiqPasskeys"
        private const val EXTRA_MODE = "nl.conduction.keepiq.passkey.MODE"
        private const val EXTRA_ITEM = "nl.conduction.keepiq.passkey.ITEM"
        const val MODE_UNLOCK = "unlock"
        const val MODE_PASSKEY = "passkey"
        const val MODE_PASSWORD = "password"
        const val MODE_CREATE = "create"

        fun intent(context: Context, mode: String, itemId: String?): Intent =
            Intent(context, CredentialProviderActivity::class.java)
                .putExtra(EXTRA_MODE, mode)
                .apply { if (itemId != null) putExtra(EXTRA_ITEM, itemId) }
    }
}
