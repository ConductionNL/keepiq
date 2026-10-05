// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.passkey

import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.graphics.drawable.Icon
import android.os.Build
import androidx.annotation.RequiresApi
import androidx.credentials.provider.AuthenticationAction
import androidx.credentials.provider.BeginCreateCredentialRequest
import androidx.credentials.provider.BeginCreateCredentialResponse
import androidx.credentials.provider.BeginCreatePasswordCredentialRequest
import androidx.credentials.provider.BeginCreatePublicKeyCredentialRequest
import androidx.credentials.provider.BeginGetCredentialRequest
import androidx.credentials.provider.BeginGetCredentialResponse
import androidx.credentials.provider.BeginGetPasswordOption
import androidx.credentials.provider.BeginGetPublicKeyCredentialOption
import androidx.credentials.provider.CreateEntry
import androidx.credentials.provider.CredentialEntry
import androidx.credentials.provider.PasswordCredentialEntry
import androidx.credentials.provider.PublicKeyCredentialEntry
import nl.conduction.keepiq.android.KeepiqApp
import nl.conduction.keepiq.android.R
import nl.conduction.keepiq.android.autofill.AutofillCore
import nl.conduction.keepiq.shared.autofill.AutofillIndex
import nl.conduction.keepiq.shared.autofill.SiteMatch
import nl.conduction.keepiq.shared.passkey.Passkeys
import nl.conduction.keepiq.shared.passkey.WebAuthnJson
import nl.conduction.keepiq.shared.vault.VaultKeys

/**
 * What Keepiq offers in Android's Credential Manager sheet (task 5.1),
 * built for the service's Begin calls and again for the unlock screen.
 *
 * - Unlocked: one entry per matching passkey and password, by user name.
 *   A pick opens [CredentialProviderActivity], which signs or fills.
 * - Locked: one "Unlock Keepiq" action, and no names, when the index on
 *   the phone has something for the request or nothing at all yet.
 * - A passkey request whose rpId the caller may not use, or that asks for
 *   another algorithm, gets nothing, so the system offers another provider.
 *
 * Network: the rpId of an app is checked with Digital Asset Links. Call
 * these off the main thread.
 */
@RequiresApi(Build.VERSION_CODES.UPSIDE_DOWN_CAKE)
class CredentialEntries(private val context: Context, private val app: KeepiqApp) {
    private val core: AutofillCore get() = app.autofill

    fun beginGet(request: BeginGetCredentialRequest): BeginGetCredentialResponse {
        val caller = request.callingAppInfo?.let { CredentialCaller.of(context, it) } ?: return EMPTY_GET
        val accountId = core.accountId() ?: return EMPTY_GET
        val keys = core.keys(accountId)
        val index = core.indexOf(accountId)
        if (keys == null) {
            val known = index.entries.isEmpty() || request.beginGetCredentialOptions.any { option -> mayMatch(option, caller, index) }
            return if (known) BeginGetCredentialResponse(authenticationActions = listOf(unlockAction())) else EMPTY_GET
        }
        return BeginGetCredentialResponse(credentialEntries = entries(request, caller, index, keys))
    }

    /** The entries for an unlocked vault. */
    fun entries(request: BeginGetCredentialRequest, caller: CredentialCaller, index: AutofillIndex, keys: VaultKeys): List<CredentialEntry> {
        val out = mutableListOf<CredentialEntry>()
        for (option in request.beginGetCredentialOptions) {
            when (option) {
                is BeginGetPublicKeyCredentialOption -> {
                    val get = WebAuthnJson.get(option.requestJson) ?: continue
                    val rpId = rpIdOf(get.rpId, caller) ?: continue
                    if (!caller.mayUse(rpId, core.identities)) continue
                    for (choice in Passkeys.candidates(index, rpId, keys, get.allowCredentialIds)) {
                        val intent = CredentialProviderActivity.intent(context, CredentialProviderActivity.MODE_PASSKEY, choice.itemId)
                        out += PublicKeyCredentialEntry.Builder(context, choice.label, pending(intent), option)
                            .setDisplayName(choice.name)
                            .setIcon(icon())
                            .build()
                    }
                }
                is BeginGetPasswordOption -> {
                    val target = caller.passwordTarget(core.identities)
                    for (entry in index.candidates(target)) {
                        val user = runCatching { keys.decryptField(entry.login) }.getOrNull() ?: continue
                        val intent = CredentialProviderActivity.intent(context, CredentialProviderActivity.MODE_PASSWORD, entry.id)
                        out += PasswordCredentialEntry.Builder(context, user.ifEmpty { entry.name }, pending(intent), option)
                            .setDisplayName(entry.name)
                            .setIcon(icon())
                            .build()
                    }
                }
                else -> Unit
            }
        }
        return out
    }

    fun beginCreate(request: BeginCreateCredentialRequest): BeginCreateCredentialResponse {
        val caller = request.callingAppInfo?.let { CredentialCaller.of(context, it) } ?: return EMPTY_CREATE
        val accountId = core.accountId() ?: return EMPTY_CREATE
        val account = app.state.client.accounts.account(accountId)
            ?.let { "${it.loginName} · ${it.server.removePrefix("https://").trimEnd('/')}" } ?: return EMPTY_CREATE
        when (request) {
            is BeginCreatePublicKeyCredentialRequest -> {
                val create = WebAuthnJson.creation(request.requestJson) ?: return EMPTY_CREATE
                if (!nl.conduction.keepiq.shared.crypto.WebAuthn.supports(create.algorithms)) return EMPTY_CREATE
                if (!caller.mayUse(create.rpId, core.identities)) return EMPTY_CREATE
            }
            is BeginCreatePasswordCredentialRequest -> Unit
            else -> return EMPTY_CREATE
        }
        val intent = CredentialProviderActivity.intent(context, CredentialProviderActivity.MODE_CREATE, null)
        val entry = CreateEntry.Builder(account, pending(intent))
            .setDescription(context.getString(R.string.passkey_create_description))
            .setIcon(icon())
            .build()
        return BeginCreateCredentialResponse(createEntries = listOf(entry))
    }

    /**
     * The rpId of a get request: the one it names, else the host of a
     * browser's origin. An app must name one.
     */
    private fun rpIdOf(named: String?, caller: CredentialCaller): String? =
        named?.takeIf { it.isNotEmpty() } ?: (caller as? CredentialCaller.Browser)?.let { SiteMatch.hostOf(it.origin) }?.takeIf { it.isNotEmpty() }

    /** Whether the locked index holds something for [option], without decrypting or asking the network. */
    private fun mayMatch(option: androidx.credentials.provider.BeginGetCredentialOption, caller: CredentialCaller, index: AutofillIndex): Boolean = when (option) {
        is BeginGetPublicKeyCredentialOption -> {
            val rpId = rpIdOf(WebAuthnJson.get(option.requestJson)?.rpId, caller)
            rpId != null && index.entries.any { it.isPasskey && SiteMatch.registrableDomain(it.url ?: "") == SiteMatch.registrableDomain(rpId) }
        }
        is BeginGetPasswordOption -> index.candidates(caller.passwordTarget(core.identities)).isNotEmpty()
        else -> false
    }

    private fun unlockAction(): AuthenticationAction {
        val intent = CredentialProviderActivity.intent(context, CredentialProviderActivity.MODE_UNLOCK, null)
        return AuthenticationAction(context.getString(R.string.autofill_unlock), pending(intent))
    }

    private fun pending(intent: Intent): PendingIntent = PendingIntent.getActivity(
        context,
        nextRequestCode(),
        intent,
        PendingIntent.FLAG_MUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
    )

    private fun icon(): Icon = Icon.createWithResource(context, R.drawable.ic_autofill_key)

    companion object {
        private val EMPTY_GET = BeginGetCredentialResponse()
        private val EMPTY_CREATE = BeginCreateCredentialResponse()
        private var requestCode = 0

        @Synchronized
        private fun nextRequestCode(): Int = ++requestCode
    }
}
