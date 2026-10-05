// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.autofill

import android.app.PendingIntent
import android.content.Intent
import android.os.Build
import android.os.CancellationSignal
import android.service.autofill.AutofillService
import android.service.autofill.FillCallback
import android.service.autofill.FillRequest
import android.service.autofill.FillResponse
import android.service.autofill.SaveCallback
import android.service.autofill.SaveInfo
import android.service.autofill.SaveRequest
import android.util.Log
import android.widget.Toast
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import nl.conduction.keepiq.android.KeepiqApp
import nl.conduction.keepiq.android.R
import nl.conduction.keepiq.shared.autofill.AutofillChoices
import nl.conduction.keepiq.shared.autofill.AutofillSaver
import nl.conduction.keepiq.shared.autofill.AutofillTarget
import nl.conduction.keepiq.shared.autofill.SaveResult

/**
 * Keepiq as the Android autofill service (mobile-system-autofill, tasks
 * 4.1 to 4.3):
 *
 * - finds user name, password and one-time-code fields ([FormParser]);
 * - matches the website a trusted browser reports, or the app by package
 *   and signing certificate plus its Digital Asset Links websites;
 * - unlocked: one entry per matched login, or per current code; locked:
 *   one "Unlock Keepiq" entry without account names, which opens
 *   [AutofillUnlockActivity] and fills after the unlock;
 * - offers to save what the user typed, unless the site or app is on the
 *   never-save list, with "Never" adding it there.
 */
class KeepiqAutofillService : AutofillService() {
    private val core: AutofillCore get() = (application as KeepiqApp).autofill

    override fun onFillRequest(request: FillRequest, cancellationSignal: CancellationSignal, callback: FillCallback) {
        val structure = request.fillContexts.lastOrNull()?.structure ?: return callback.onSuccess(null)
        val form = FormParser.parse(structure)
        if (form.packageName == packageName || form.detected.isEmpty) return callback.onSuccess(null)
        val inline = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) request.inlineSuggestionsRequest else null
        val job = core.scope.launch {
            val response = try {
                respond(form, Datasets(this@KeepiqAutofillService, inline))
            } catch (e: Exception) {
                Log.w(TAG, "fill request failed", e)
                null
            }
            withContext(Dispatchers.Main) {
                runCatching { callback.onSuccess(response) }
            }
        }
        cancellationSignal.setOnCancelListener { job.cancel() }
    }

    private fun respond(form: ParsedForm, datasets: Datasets): FillResponse? {
        val accountId = core.accountId() ?: return null
        val target = core.identities.target(form) ?: return null
        val index = core.indexOf(accountId)
        val loginIds = listOfNotNull(form.username?.id) + form.passwords.map { it.id }
        val code = form.oneTimeCode
        val keys = core.keys(accountId)
        val builder = FillResponse.Builder()
        var any = false

        if (keys == null) {
            // Locked: one entry, no names, only when something matches.
            val hasLogin = loginIds.isNotEmpty() && index.candidates(target).isNotEmpty()
            val hasCode = code != null && index.candidates(target, totp = true).isNotEmpty()
            if (hasLogin || hasCode) {
                val ids = (if (hasLogin) loginIds else emptyList()) + listOfNotNull(code?.id?.takeIf { hasCode })
                datasets.locked(ids, AutofillUnlockActivity.fillIntent(this))?.let {
                    builder.addDataset(it)
                    any = true
                }
            }
        } else {
            if (loginIds.isNotEmpty()) {
                for (choice in AutofillChoices.logins(index, target, keys)) {
                    datasets.filled(choice.login.ifEmpty { choice.name }, choice.name, loginValues(form, choice.login, choice.password))
                        ?.let { builder.addDataset(it); any = true }
                }
            }
            if (code != null) {
                for (choice in AutofillChoices.codes(index, target, keys, System.currentTimeMillis())) {
                    datasets.filled(getString(R.string.autofill_code_from, choice.name), null, listOf(code.id to choice.code))
                        ?.let { builder.addDataset(it); any = true }
                }
            }
        }

        val saveInfo = saveInfo(form, target, accountId)
        if (saveInfo != null) {
            builder.setSaveInfo(saveInfo)
            any = true
        }
        return if (any) builder.build() else null
    }

    /** The save offer: for a form with a password field, unless the site or app is on the never-save list. */
    private fun saveInfo(form: ParsedForm, target: AutofillTarget, accountId: String): SaveInfo? {
        val passwords = form.passwords.map { it.id }
        if (passwords.isEmpty() || core.neverSave.contains(target.saveKey)) return null
        if (core.indexOf(accountId).blocksSave(target)) return null
        val type = SaveInfo.SAVE_DATA_TYPE_PASSWORD or (if (form.username != null) SaveInfo.SAVE_DATA_TYPE_USERNAME else 0)
        val builder = SaveInfo.Builder(type, passwords.toTypedArray())
            .apply { form.username?.let { setOptionalIds(arrayOf(it.id)) } }
            .setFlags(SaveInfo.FLAG_SAVE_ON_ALL_VIEWS_INVISIBLE)
        // The "Never" button exists from Android 11. Before that the offer
        // has the system's own "Not now", and the list is kept in Keepiq's
        // autofill settings only.
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            val never = PendingIntent.getBroadcast(
                this, target.saveKey.hashCode(),
                Intent(this, NeverSaveReceiver::class.java).putExtra(NeverSaveReceiver.EXTRA_SITE, target.saveKey),
                PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
            )
            builder.setNegativeAction(SaveInfo.NEGATIVE_BUTTON_STYLE_NEVER, never.intentSender)
        }
        return builder.build()
    }

    override fun onSaveRequest(request: SaveRequest, callback: SaveCallback) {
        val structure = request.fillContexts.lastOrNull()?.structure ?: return callback.onSuccess()
        val form = FormParser.parse(structure)
        val password = form.typedPassword
        val accountId = core.accountId()
        if (password.isNullOrEmpty() || accountId == null) return callback.onSuccess()
        val login = form.username?.text ?: ""
        val keys = core.keys(accountId)
        if (keys == null) {
            // Locked: hold the login in memory and ask for the unlock first.
            core.scope.launch {
                val target = core.identities.target(form)
                withContext(Dispatchers.Main) {
                    if (target == null || core.neverSave.contains(target.saveKey)) return@withContext callback.onSuccess()
                    val token = core.hold(
                        PendingSave(accountId, target, login, password, core.identities.label(form.packageName), form.webScheme, System.currentTimeMillis() + AutofillCore.PENDING_MILLIS),
                    )
                    callback.onSuccess(AutofillUnlockActivity.saveIntent(this@KeepiqAutofillService, token))
                }
            }
            return
        }
        callback.onSuccess()
        core.scope.launch {
            val result = try {
                val target = core.identities.target(form) ?: return@launch
                if (core.neverSave.contains(target.saveKey)) return@launch
                val api = core.api(accountId) ?: return@launch
                AutofillSaver(api, keys).save(target, core.indexOf(accountId), login, password, core.identities.label(form.packageName), form.webScheme)
            } catch (e: Exception) {
                Log.w(TAG, "save failed", e)
                null
            }
            withContext(Dispatchers.Main) { toast(this@KeepiqAutofillService, result) }
        }
    }

    companion object {
        private const val TAG = "KeepiqAutofill"

        /** What saving did, in words. */
        fun toast(context: android.content.Context, result: SaveResult?) {
            val text = when (result) {
                SaveResult.SAVED -> R.string.autofill_saved
                SaveResult.UPDATED -> R.string.autofill_updated
                SaveResult.UNCHANGED, SaveResult.REFUSED -> return
                null -> R.string.autofill_save_failed
            }
            Toast.makeText(context, text, Toast.LENGTH_SHORT).show()
        }

        /** The login and password values for the fields a form has. */
        fun loginValues(form: ParsedForm, login: String, password: String): List<Pair<android.view.autofill.AutofillId, String>> =
            listOfNotNull(form.username?.id?.takeIf { login.isNotEmpty() }?.let { it to login }) + form.passwords.map { it.id to password }
    }
}
