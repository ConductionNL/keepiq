// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

@file:OptIn(ExperimentalMaterial3Api::class)

package nl.conduction.keepiq.android.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.selection.selectableGroup
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.AccountCircle
import androidx.compose.material.icons.filled.Build
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.Send
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.ListItem
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import nl.conduction.keepiq.android.R
import nl.conduction.keepiq.android.vault.AndroidClipboard
import nl.conduction.keepiq.android.vault.HandlerScheduler
import nl.conduction.keepiq.android.vault.VaultPreferences
import nl.conduction.keepiq.android.vault.VaultSession
import nl.conduction.keepiq.shared.api.Account
import nl.conduction.keepiq.shared.generator.GeneratorPolicy
import nl.conduction.keepiq.shared.generator.GeneratorSettings
import nl.conduction.keepiq.shared.vault.ClearDelay
import nl.conduction.keepiq.shared.vault.SensitiveClipboard

/** Where the user is inside a tab. */
sealed interface Route {
    data class Folder(val id: String?) : Route
    data class Detail(val id: String) : Route
    data class Edit(val id: String?) : Route
    data object Sends : Route
    data class NewSend(val text: String = "", val username: String = "", val password: String = "") : Route
    data object Generator : Route
}

enum class Tab { VAULT, GENERATOR, SEND }

/**
 * The vault, Send and generator screens of one unlocked account (task
 * group 3). The unlock flow hands in the [session]; [accounts] and the
 * switch and add callbacks drive the account switcher.
 */
@Composable
fun VaultApp(
    session: VaultSession,
    accounts: List<Account>,
    onSwitchAccount: (Account) -> Unit,
    onAddAccount: (() -> Unit)?,
) {
    val context = LocalContext.current
    val prefs = remember { VaultPreferences(context) }
    val clipboard = remember {
        SensitiveClipboard(AndroidClipboard(context), HandlerScheduler(), ClearDelay { prefs.clipboardClearSeconds })
    }
    val snackbar = remember { SnackbarHostState() }
    val scope = rememberCoroutineScope()
    var tab by remember(session) { mutableStateOf(Tab.VAULT) }
    var stacks by remember(session) {
        mutableStateOf(
            mapOf(
                Tab.VAULT to listOf<Route>(Route.Folder(null)),
                Tab.GENERATOR to listOf<Route>(Route.Generator),
                Tab.SEND to listOf<Route>(Route.Sends),
            ),
        )
    }
    var showAccounts by remember { mutableStateOf(false) }
    var policy by remember(session) { mutableStateOf<GeneratorPolicy?>(null) }
    var generatorSettings by remember(session) { mutableStateOf(GeneratorSettings()) }

    LaunchedEffect(session) {
        policy = io { GeneratorPolicy.fetch(session.api) }
    }

    val stack = stacks.getValue(tab)
    fun push(route: Route) {
        stacks = stacks + (tab to stack + route)
    }
    fun pop() {
        if (stack.size > 1) stacks = stacks + (tab to stack.dropLast(1))
    }
    fun replaceTop(route: Route) {
        stacks = stacks + (tab to stack.dropLast(1) + route)
    }
    BackHandler(enabled = stack.size > 1) { pop() }

    val copiedText = stringResource(R.string.copied)
    val copiedKept = stringResource(R.string.copied_kept)
    val copy: (String) -> Unit = { value ->
        val seconds = clipboard.write(value)
        scope.launch { snackbar.showSnackbar(if (seconds > 0) copiedText.format(seconds) else copiedKept) }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(stringResource(titleOf(tab))) },
                navigationIcon = {
                    if (stack.size > 1) {
                        TextButton(onClick = { pop() }) { Text(stringResource(R.string.action_back)) }
                    }
                },
                actions = {
                    IconButton(onClick = { showAccounts = true }) {
                        Icon(Icons.Filled.AccountCircle, contentDescription = stringResource(R.string.cd_switch_account, session.label))
                    }
                },
            )
        },
        bottomBar = {
            NavigationBar {
                NavigationBarItem(
                    selected = tab == Tab.VAULT,
                    onClick = { tab = Tab.VAULT },
                    icon = { Icon(Icons.Filled.Home, contentDescription = null) },
                    label = { Text(stringResource(R.string.tab_vault)) },
                )
                NavigationBarItem(
                    selected = tab == Tab.GENERATOR,
                    onClick = { tab = Tab.GENERATOR },
                    icon = { Icon(Icons.Filled.Build, contentDescription = null) },
                    label = { Text(stringResource(R.string.tab_generator)) },
                )
                NavigationBarItem(
                    selected = tab == Tab.SEND,
                    onClick = { tab = Tab.SEND },
                    icon = { Icon(Icons.Filled.Send, contentDescription = null) },
                    label = { Text(stringResource(R.string.tab_send)) },
                )
            }
        },
        snackbarHost = { SnackbarHost(snackbar) },
    ) { padding ->
        val modifier = Modifier.padding(padding)
        when (val route = stack.last()) {
            is Route.Folder -> VaultListScreen(
                session = session,
                folderId = route.id,
                modifier = modifier,
                onOpenFolder = { push(Route.Folder(it)) },
                onOpenItem = { push(Route.Detail(it)) },
                onAddItem = { push(Route.Edit(null)) },
                onFolderGone = { pop() },
            )
            is Route.Detail -> ItemDetailScreen(
                session = session,
                id = route.id,
                modifier = modifier,
                onCopy = copy,
                onEdit = { push(Route.Edit(route.id)) },
                onGone = { pop() },
                onSendLogin = { username, password ->
                    tab = Tab.SEND
                    stacks = stacks + (Tab.SEND to listOf(Route.Sends, Route.NewSend(username = username, password = password)))
                },
            )
            is Route.Edit -> ItemEditScreen(
                session = session,
                id = route.id,
                folderId = stack.filterIsInstance<Route.Folder>().lastOrNull()?.id,
                policy = policy,
                generatorSettings = generatorSettings,
                modifier = modifier,
                onSaved = { savedId ->
                    if (route.id == null && savedId != null) replaceTop(Route.Detail(savedId)) else pop()
                },
                onCancel = { pop() },
            )
            Route.Generator -> GeneratorScreen(
                policy = policy,
                settings = generatorSettings,
                onSettings = { generatorSettings = it },
                onCopy = copy,
                modifier = modifier,
            )
            Route.Sends -> SendListScreen(
                session = session,
                modifier = modifier,
                onNew = { push(Route.NewSend()) },
                onCopy = copy,
            )
            is Route.NewSend -> NewSendScreen(
                session = session,
                initial = route,
                modifier = modifier,
                onCopy = copy,
                onDone = { pop() },
            )
        }
    }

    if (showAccounts) {
        AccountsDialog(
            session = session,
            accounts = accounts,
            prefs = prefs,
            onSwitch = {
                showAccounts = false
                onSwitchAccount(it)
            },
            onAdd = onAddAccount?.let { add -> { showAccounts = false; add() } },
            onDismiss = { showAccounts = false },
        )
    }
}

private fun titleOf(tab: Tab): Int = when (tab) {
    Tab.VAULT -> R.string.tab_vault
    Tab.GENERATOR -> R.string.tab_generator
    Tab.SEND -> R.string.tab_send
}

/** The account switcher, with the clipboard delay that applies to every account. */
@Composable
private fun AccountsDialog(
    session: VaultSession,
    accounts: List<Account>,
    prefs: VaultPreferences,
    onSwitch: (Account) -> Unit,
    onAdd: (() -> Unit)?,
    onDismiss: () -> Unit,
) {
    var clearSeconds by remember { mutableStateOf(prefs.clipboardClearSeconds) }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.accounts_title)) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                Column(Modifier.selectableGroup()) {
                    for (account in accounts) {
                        val label = "${account.loginName} · ${account.server.removePrefix("https://").trimEnd('/')}"
                        ListItem(
                            headlineContent = { Text(label) },
                            leadingContent = { RadioButton(selected = account.id == session.account.id, onClick = null) },
                            modifier = Modifier
                                .fillMaxWidth()
                                .selectable(selected = account.id == session.account.id, role = Role.RadioButton) { onSwitch(account) },
                        )
                    }
                }
                if (onAdd != null && accounts.size < 5) {
                    TextButton(onClick = onAdd) { Text(stringResource(R.string.accounts_add)) }
                } else if (onAdd != null) {
                    Text(stringResource(R.string.accounts_limit), style = MaterialTheme.typography.bodySmall)
                }
                Text(
                    stringResource(R.string.settings_clipboard),
                    style = MaterialTheme.typography.titleSmall,
                    modifier = Modifier.padding(top = 16.dp, bottom = 4.dp),
                )
                Column(Modifier.selectableGroup()) {
                    for (seconds in SensitiveClipboard.CLEAR_CHOICES) {
                        val label = when {
                            seconds == 0 -> stringResource(R.string.settings_clipboard_never)
                            seconds >= 120 -> stringResource(R.string.settings_clipboard_minutes, seconds / 60)
                            else -> stringResource(R.string.settings_clipboard_seconds, seconds)
                        }
                        ListItem(
                            headlineContent = { Text(label) },
                            leadingContent = { RadioButton(selected = clearSeconds == seconds, onClick = null) },
                            modifier = Modifier
                                .fillMaxWidth()
                                .semantics { contentDescription = label }
                                .selectable(selected = clearSeconds == seconds, role = Role.RadioButton) {
                                    clearSeconds = seconds
                                    prefs.clipboardClearSeconds = seconds
                                },
                        )
                    }
                }
            }
        },
        confirmButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.action_close)) } },
    )
}
