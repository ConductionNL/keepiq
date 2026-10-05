// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.autofill

import android.app.Activity
import android.app.PendingIntent
import android.app.assist.AssistStructure
import android.content.Context
import android.content.Intent
import android.content.IntentSender
import android.os.Build
import android.os.Bundle
import android.service.autofill.Dataset
import android.view.autofill.AutofillManager
import androidx.activity.compose.setContent
import androidx.compose.foundation.clickable
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ListItem
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.fragment.app.FragmentActivity
import androidx.lifecycle.lifecycleScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import nl.conduction.keepiq.android.KeepiqApp
import nl.conduction.keepiq.android.R
import nl.conduction.keepiq.android.Screen
import nl.conduction.keepiq.android.ui.ScreenColumn
import nl.conduction.keepiq.android.ui.UnlockScreen
import nl.conduction.keepiq.shared.autofill.AutofillChoices
import nl.conduction.keepiq.shared.autofill.AutofillIndexHub
import nl.conduction.keepiq.shared.autofill.AutofillSaver
import nl.conduction.keepiq.shared.autofill.AutofillTarget
import nl.conduction.keepiq.shared.autofill.LoginChoice
import nl.conduction.keepiq.shared.sync.SyncTrigger
import nl.conduction.keepiq.shared.vault.VaultKeys
import nl.conduction.keepiq.shared.vault.VaultRepository

/**
 * The "Unlock Keepiq" entry (tasks 4.1 to 4.3): the app's own unlock
 * screen, then the fill. With one matching login it returns that login's
 * dataset, which the system fills at once; with several the user picks one
 * here. In save mode it saves the login that waited for the unlock.
 */
class AutofillUnlockActivity : FragmentActivity() {
    private val app: KeepiqApp get() = application as KeepiqApp
    private val core: AutofillCore get() = app.autofill
    private val phase = mutableStateOf<Phase>(Phase.Unlock)
    private var started = false

    private sealed interface Phase {
        data object Unlock : Phase
        data object Working : Phase
        data class Choose(val choices: List<LoginChoice>, val form: ParsedForm) : Phase
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setResult(Activity.RESULT_CANCELED)
        val accountId = core.accountId() ?: return finish()
        val state = app.state
        if (state.gate.value == null) state.refreshGate(accountId)
        setContent {
            MaterialTheme(colorScheme = if (isSystemInDarkTheme()) darkColorScheme() else lightColorScheme()) {
                Surface(modifier = Modifier.fillMaxSize()) {
                    when (val p = phase.value) {
                        Phase.Unlock -> UnlockScreen(state, this, accountId)
                        Phase.Working -> ScreenColumn { CircularProgressIndicator(modifier = Modifier.testTag("autofillBusy")) }
                        is Phase.Choose -> Choices(p)
                    }
                }
            }
        }
        lifecycleScope.launch {
            state.screen.collect { screen ->
                if (!started && (screen is Screen.Unlocked || screen is Screen.Settings)) {
                    started = true
                    phase.value = Phase.Working
                    unlocked(accountId)
                }
            }
        }
    }

    private suspend fun unlocked(accountId: String) {
        val keys = core.keys(accountId) ?: return finish()
        withContext(Dispatchers.IO) { ensureIndex(accountId, keys) }
        if (intent.getStringExtra(EXTRA_MODE) == MODE_SAVE) {
            save(keys)
        } else {
            fill(accountId, keys)
        }
    }

    /** No sync has built the index yet in this process: read the vault once, which builds it. */
    private suspend fun ensureIndex(accountId: String, keys: VaultKeys) {
        if (core.indexOf(accountId).entries.isNotEmpty()) return
        val api = core.api(accountId) ?: return
        runCatching {
            VaultRepository(api, keys, null, null, { System.currentTimeMillis() }, { AutofillIndexHub.refreshed(accountId, it, keys) })
                .refresh(SyncTrigger.MANUAL)
        }
    }

    private suspend fun fill(accountId: String, keys: VaultKeys) {
        val structure = assistStructure() ?: return finish()
        val form = FormParser.parse(structure)
        val (target, logins, codes) = withContext(Dispatchers.IO) {
            val target = core.identities.target(form)
            val index = core.indexOf(accountId)
            Triple(
                target,
                target?.let { AutofillChoices.logins(index, it, keys) } ?: emptyList(),
                target?.let { AutofillChoices.codes(index, it, keys, System.currentTimeMillis()) } ?: emptyList(),
            )
        }
        if (target == null) return finish()
        val datasets = Datasets(this, null)
        val hasLoginFields = form.username != null || form.passwords.isNotEmpty()
        when {
            hasLoginFields && logins.size == 1 -> deliver(dataset(datasets, form, logins[0]))
            hasLoginFields && logins.size > 1 -> phase.value = Phase.Choose(logins, form)
            form.oneTimeCode != null && codes.isNotEmpty() ->
                deliver(datasets.filled(getString(R.string.autofill_code_from, codes[0].name), null, listOf(form.oneTimeCode!!.id to codes[0].code)))
            else -> {
                android.widget.Toast.makeText(this, R.string.autofill_nothing, android.widget.Toast.LENGTH_SHORT).show()
                finish()
            }
        }
    }

    private fun dataset(datasets: Datasets, form: ParsedForm, choice: LoginChoice): Dataset? =
        datasets.filled(choice.login.ifEmpty { choice.name }, choice.name, KeepiqAutofillService.loginValues(form, choice.login, choice.password))

    private fun deliver(dataset: Dataset?) {
        if (dataset != null) setResult(Activity.RESULT_OK, Intent().putExtra(AutofillManager.EXTRA_AUTHENTICATION_RESULT, dataset))
        finish()
    }

    private suspend fun save(keys: VaultKeys) {
        val pending = core.take(intent.getStringExtra(EXTRA_TOKEN)) ?: return finish()
        val result = withContext(Dispatchers.IO) {
            runCatching {
                val api = core.api(pending.accountId) ?: return@runCatching null
                AutofillSaver(api, keys).save(pending.target, core.indexOf(pending.accountId), pending.login, pending.password, pending.appLabel, pending.webScheme)
            }.getOrNull()
        }
        KeepiqAutofillService.toast(this, result)
        finish()
    }

    @Suppress("DEPRECATION")
    private fun assistStructure(): AssistStructure? = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
        intent.getParcelableExtra(AutofillManager.EXTRA_ASSIST_STRUCTURE, AssistStructure::class.java)
    } else {
        intent.getParcelableExtra(AutofillManager.EXTRA_ASSIST_STRUCTURE)
    }

    @Composable
    private fun Choices(p: Phase.Choose) {
        ScreenColumn {
            Text(stringResource(R.string.autofill_choose), style = MaterialTheme.typography.headlineSmall, modifier = Modifier.semantics { heading() })
            val datasets = Datasets(this@AutofillUnlockActivity, null)
            for (choice in p.choices) {
                ListItem(
                    headlineContent = { Text(choice.login.ifEmpty { choice.name }) },
                    supportingContent = { Text(choice.name) },
                    modifier = Modifier.testTag("autofillChoice").clickable { deliver(dataset(datasets, p.form, choice)) },
                )
            }
        }
    }

    companion object {
        private const val EXTRA_MODE = "nl.conduction.keepiq.autofill.MODE"
        private const val EXTRA_TOKEN = "nl.conduction.keepiq.autofill.TOKEN"
        private const val MODE_FILL = "fill"
        private const val MODE_SAVE = "save"
        private var requestCode = 0

        /** The locked entry's authentication: the system adds the form's structure to the intent. */
        fun fillIntent(context: Context): IntentSender {
            val intent = Intent(context, AutofillUnlockActivity::class.java).putExtra(EXTRA_MODE, MODE_FILL)
            return PendingIntent.getActivity(
                context, synchronized(this) { ++requestCode }, intent,
                PendingIntent.FLAG_CANCEL_CURRENT or PendingIntent.FLAG_MUTABLE,
            ).intentSender
        }

        /** The save offer while locked: unlock, then save the held login. */
        fun saveIntent(context: Context, token: String): IntentSender {
            val intent = Intent(context, AutofillUnlockActivity::class.java).putExtra(EXTRA_MODE, MODE_SAVE).putExtra(EXTRA_TOKEN, token)
            return PendingIntent.getActivity(
                context, synchronized(this) { ++requestCode }, intent,
                PendingIntent.FLAG_CANCEL_CURRENT or PendingIntent.FLAG_IMMUTABLE,
            ).intentSender
        }
    }
}
