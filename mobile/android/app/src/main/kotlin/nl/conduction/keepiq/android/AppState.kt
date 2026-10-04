// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android

import androidx.fragment.app.FragmentActivity
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.MainScope
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import nl.conduction.keepiq.android.security.BiometricCancelledException
import nl.conduction.keepiq.android.security.BiometricUnlock
import nl.conduction.keepiq.shared.KeepiqClient
import nl.conduction.keepiq.shared.UnlockGate
import nl.conduction.keepiq.shared.account.AccountSettings
import nl.conduction.keepiq.shared.account.IdlePolicy
import nl.conduction.keepiq.shared.account.IdleTimer
import nl.conduction.keepiq.shared.pairing.LoginFlowStart
import nl.conduction.keepiq.shared.pairing.LoginFlowStoppedException
import nl.conduction.keepiq.shared.unlock.StaleUnlockKeyException
import nl.conduction.keepiq.shared.unlock.UnlockedVault

/** Where the app is. */
sealed interface Screen {
    /** No account, or the user adds one. */
    data object Pair : Screen

    /** A paired account, locked. */
    data class Unlock(val accountId: String) : Screen

    /** The vault is open. */
    data class Unlocked(val vault: UnlockedVault) : Screen

    /** Unlock options, auto-lock and unpair for the open vault. */
    data class Settings(val vault: UnlockedVault) : Screen
}

/** The browser sign-in. */
sealed interface LoginState {
    data object Idle : LoginState

    /** The login page is open in the browser; the app polls. */
    data class Waiting(val start: LoginFlowStart) : LoginState
}

/**
 * The app's state and the actions the screens call (tasks 2.1 to 2.6). One
 * instance per process, in [KeepiqApp].
 */
class AppState(val client: KeepiqClient, val biometric: BiometricUnlock, private val clock: () -> Long = System::currentTimeMillis) {
    private val scope = MainScope()
    private var loginJob: Job? = null
    private var idleJob: Job? = null

    private val _screen = MutableStateFlow(initialScreen())
    val screen: StateFlow<Screen> = _screen.asStateFlow()

    private val _login = MutableStateFlow<LoginState>(LoginState.Idle)
    val login: StateFlow<LoginState> = _login.asStateFlow()

    private val _busy = MutableStateFlow(false)
    val busy: StateFlow<Boolean> = _busy.asStateFlow()

    private val _message = MutableStateFlow<String?>(null)

    /** The last problem, in words for the user; the screen shows it. */
    val message: StateFlow<String?> = _message.asStateFlow()

    /** The unlock screen's offer for the account, read from the server. */
    private val _gate = MutableStateFlow<UnlockGate?>(null)
    val gate: StateFlow<UnlockGate?> = _gate.asStateFlow()

    /** The organisation's idle maximum, read at unlock. */
    private val _maxIdle = MutableStateFlow<Int?>(null)
    val maxIdle: StateFlow<Int?> = _maxIdle.asStateFlow()

    private val idle = IdleTimer(clock, IdlePolicy.DEFAULT_MINUTES)

    init {
        client.onWipe = { accountId -> biometric.disable(accountId) }
    }

    private fun initialScreen(): Screen = client.accounts.activeId()?.let { Screen.Unlock(it) } ?: Screen.Pair

    fun clearMessage() {
        _message.value = null
    }

    fun reportProblem(text: String) {
        _message.value = text
    }

    // Pairing (tasks 2.1 and 2.4)

    /** Starts Login Flow v2 and hands the login page to [openBrowser]. */
    fun startLogin(server: String, openBrowser: (String) -> Unit) = action {
        val start = client.startLogin(server)
        _login.value = LoginState.Waiting(start)
        openBrowser(start.loginUrl)
        loginJob = scope.launch {
            try {
                val account = client.finishLogin(start)
                _login.value = LoginState.Idle
                showUnlock(account.id)
            } catch (e: CancellationException) {
                throw e
            } catch (e: LoginFlowStoppedException) {
                _login.value = LoginState.Idle
                if (e.timedOut) _message.value = e.message
            } catch (e: Exception) {
                _login.value = LoginState.Idle
                _message.value = e.message
            }
        }
    }

    /**
     * The user came back from the browser without the flow finishing: one
     * last poll, then the address form again with nothing stored (spec:
     * "The user abandons the login").
     */
    fun browserClosed() {
        val waiting = _login.value as? LoginState.Waiting ?: return
        loginJob?.cancel()
        client.cancelLogin()
        action {
            val account = client.finishLoginNow(waiting.start)
            _login.value = LoginState.Idle
            if (account != null) showUnlock(account.id)
        }
    }

    fun cancelLogin() {
        loginJob?.cancel()
        client.cancelLogin()
        _login.value = LoginState.Idle
    }

    fun pairManually(server: String, loginName: String, appPassword: String) = action {
        val account = client.pairManually(server, loginName, appPassword)
        showUnlock(account.id)
    }

    fun addAccount() {
        _message.value = null
        _screen.value = Screen.Pair
    }

    fun switchAccount(accountId: String) {
        lock()
        client.accounts.setActive(accountId)
        showUnlock(accountId)
    }

    // Unlock (tasks 2.2 and 2.3)

    private fun showUnlock(accountId: String) {
        _gate.value = null
        _message.value = null
        _screen.value = Screen.Unlock(accountId)
        refreshGate(accountId)
    }

    /** Reads what the unlock screen may offer: the master password, or the reason it is blocked. */
    fun refreshGate(accountId: String) = action {
        _gate.value = client.unlockGate(accountId)
    }

    fun unlockWithMasterPassword(accountId: String, password: String) = withReadySuite(accountId) { suite ->
        opened(client.unlockWithMasterPassword(accountId, suite, password))
    }

    fun unlockWithPin(accountId: String, pin: String) = withReadySuite(accountId) { suite ->
        opened(client.unlockWithPin(accountId, suite, pin))
    }

    fun unlockWithBiometric(activity: FragmentActivity, accountId: String) = withReadySuite(accountId) { suite ->
        try {
            opened(client.unlockWithKey(accountId, suite, biometric.unlockKey(activity, accountId)))
        } catch (e: BiometricCancelledException) {
            // The master password field is right there.
        } catch (e: StaleUnlockKeyException) {
            biometric.disable(accountId)
            throw e
        }
    }

    private fun withReadySuite(accountId: String, block: suspend (nl.conduction.keepiq.shared.api.Suite) -> Unit) = action {
        val gate = _gate.value ?: client.unlockGate(accountId).also { _gate.value = it }
        when (gate) {
            is UnlockGate.Blocked -> _message.value = gate.message
            is UnlockGate.Ready -> block(gate.suite)
        }
    }

    private suspend fun opened(vault: UnlockedVault) {
        _message.value = null
        _screen.value = Screen.Unlocked(vault)
        _maxIdle.value = client.maxIdleMinutes(vault.accountId)
        applyIdle(vault.accountId)
        idle.touch()
        startIdleWatch()
    }

    // Unlock options (task 2.3)

    fun enableBiometric(activity: FragmentActivity, vault: UnlockedVault) = action {
        try {
            biometric.enable(activity, vault.accountId, vault.unlockKey())
            client.accounts.updateSettings(vault.accountId, client.accounts.settings(vault.accountId).copy(biometric = true))
        } catch (e: BiometricCancelledException) {
            // Nothing changed.
        }
    }

    fun disableBiometric(vault: UnlockedVault) {
        biometric.disable(vault.accountId)
        client.accounts.updateSettings(vault.accountId, client.accounts.settings(vault.accountId).copy(biometric = false))
    }

    fun setPin(vault: UnlockedVault, pin: String, onDone: () -> Unit) = action {
        client.setPin(vault, pin)
        onDone()
    }

    fun removePin(vault: UnlockedVault) {
        client.pins.remove(vault.accountId)
    }

    // Locking (task 2.5)

    fun setIdleMinutes(vault: UnlockedVault, minutes: Int) {
        client.accounts.updateSettings(vault.accountId, client.accounts.settings(vault.accountId).copy(idleMinutes = minutes))
        applyIdle(vault.accountId)
    }

    fun effectiveIdleMinutes(accountId: String): Int =
        IdlePolicy.effectiveMinutes(client.accounts.settings(accountId).idleMinutes, _maxIdle.value)

    private fun applyIdle(accountId: String) {
        idle.minutes = effectiveIdleMinutes(accountId)
    }

    /** Every touch of the screen restarts the idle time (MainActivity.onUserInteraction). */
    fun touch() = idle.touch()

    fun onForeground() {
        if (idle.expired()) lock()
        if (_screen.value is Screen.Unlocked || _screen.value is Screen.Settings) startIdleWatch()
    }

    fun onBackground() {
        idleJob?.cancel()
    }

    private fun startIdleWatch() {
        idleJob?.cancel()
        idleJob = scope.launch {
            while (true) {
                if (idle.expired()) {
                    lock()
                    return@launch
                }
                delay(minOf(idle.remainingMillis(), 10_000L).coerceAtLeast(500L))
            }
        }
    }

    /** Locks now: forgets the vault and shows the unlock screen. */
    fun lock() {
        val vault = when (val s = _screen.value) {
            is Screen.Unlocked -> s.vault
            is Screen.Settings -> s.vault
            else -> return
        }
        idleJob?.cancel()
        vault.lock()
        _gate.value = null
        _screen.value = Screen.Unlock(vault.accountId)
        refreshGate(vault.accountId)
    }

    fun openSettings(vault: UnlockedVault) {
        _screen.value = Screen.Settings(vault)
    }

    fun closeSettings(vault: UnlockedVault) {
        _screen.value = Screen.Unlocked(vault)
    }

    fun settings(accountId: String): AccountSettings = client.accounts.settings(accountId)

    // Unpair (task 2.6)

    fun unpair(accountId: String) = action {
        (_screen.value as? Screen.Unlocked)?.vault?.lock()
        (_screen.value as? Screen.Settings)?.vault?.lock()
        idleJob?.cancel()
        val revoked = client.unpair(accountId)
        _gate.value = null
        _screen.value = initialScreen()
        _message.value = if (revoked) {
            "Disconnected. The app password was deleted in Nextcloud."
        } else {
            "Disconnected on this phone. Nextcloud could not be reached, so delete the app password there under Security."
        }
    }

    /** Runs [block] with the busy flag set, and turns a failure into the message the screen shows. */
    private fun action(block: suspend () -> Unit) {
        scope.launch {
            _busy.value = true
            _message.value = null
            try {
                block()
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                _message.value = e.message ?: e.toString()
            } finally {
                _busy.value = false
            }
        }
    }
}
