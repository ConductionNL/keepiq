// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared

import io.ktor.client.HttpClient
import io.ktor.client.engine.HttpClientEngine
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.longOrNull
import nl.conduction.keepiq.shared.account.AccountLimitException
import nl.conduction.keepiq.shared.account.AccountStore
import nl.conduction.keepiq.shared.account.SecureStorage
import nl.conduction.keepiq.shared.api.Account
import nl.conduction.keepiq.shared.api.InsecureServerException
import nl.conduction.keepiq.shared.api.KeepiqApi
import nl.conduction.keepiq.shared.api.KeepiqApiException
import nl.conduction.keepiq.shared.api.Suite
import nl.conduction.keepiq.shared.api.platformHttpEngine
import nl.conduction.keepiq.shared.pairing.LoginFlowCredentials
import nl.conduction.keepiq.shared.pairing.LoginFlowStart
import nl.conduction.keepiq.shared.pairing.LoginFlowV2
import nl.conduction.keepiq.shared.pairing.ServerAddress
import nl.conduction.keepiq.shared.unlock.PinUnlock
import nl.conduction.keepiq.shared.unlock.StaleUnlockKeyException
import nl.conduction.keepiq.shared.unlock.UnlockedVault
import nl.conduction.keepiq.shared.unlock.VaultUnlock
import kotlin.time.Clock
import kotlin.time.ExperimentalTime

/** Pairing failed. The message says what to do, in the extension's words (router.js pairingProblem). */
class PairingException(message: String, val status: Int = 0) : Exception(message)

/** What the unlock screen can offer for an account. */
sealed class UnlockGate {
    /** The master password, and the biometric or PIN unlock when set up. */
    data class Ready(val suite: Suite, val offline: Boolean) : UnlockGate()

    /** The server withholds the key: say why, offer no unlock field. */
    data class Blocked(val code: String, val message: String) : UnlockGate()
}

/**
 * The shared core as the two apps use it (design D3 and D4): pairing with
 * Login Flow v2 or an app password, unpairing, and unlocking. Both apps
 * keep one instance. Every call that can fail is marked @Throws, so Swift
 * sees an error instead of a crash.
 *
 * [clientName] is what the Nextcloud device list shows for the app password,
 * such as "Keepiq for Android": Nextcloud names a Login Flow v2 app password
 * after the User-Agent that started the flow.
 *
 * [onWipe] runs on unpair, before the account is forgotten. The app deletes
 * what only it holds there: the biometric wrap, the offline store and the
 * autofill index.
 */
@OptIn(ExperimentalTime::class)
class KeepiqClient(
    private val storage: SecureStorage,
    val clientName: String,
    engine: HttpClientEngine = platformHttpEngine(),
    private val clock: () -> Long = { Clock.System.now().toEpochMilliseconds() },
) {
    private val http: HttpClient = KeepiqApi.httpClient(engine)
    private val loginFlow = LoginFlowV2(http, clientName, clock)
    private var loginCancelled = false
    private var loginStartedAt = 0L
    private val json = Json { ignoreUnknownKeys = true }

    val accounts: AccountStore = AccountStore(storage)
    val pins: PinUnlock = PinUnlock(storage)

    /** Called with the account id on unpair, before the account is removed. */
    var onWipe: (String) -> Unit = {}

    /** A cleaned server address, or a [nl.conduction.keepiq.shared.pairing.ServerAddressException] with the reason. */
    @Throws(Exception::class)
    fun normalizeServer(input: String): String = ServerAddress.normalize(input)

    /** Step 1 and 2 of Login Flow v2: the page to open in the system browser. */
    @Throws(Exception::class)
    suspend fun startLogin(serverInput: String): LoginFlowStart {
        checkRoom()
        loginCancelled = false
        val start = try {
            loginFlow.start(serverInput)
        } catch (e: KeepiqApiException) {
            throw PairingException(e.message ?: "Cannot reach this server.", e.status)
        } catch (e: nl.conduction.keepiq.shared.pairing.ServerAddressException) {
            throw e
        } catch (e: Exception) {
            if (e is kotlinx.coroutines.CancellationException) throw e
            throw PairingException("Cannot reach this server. Check the address and your connection.")
        }
        loginStartedAt = clock()
        return start
    }

    /** Step 3 to 5: waits for the grant (at most 20 minutes), then pairs. */
    @Throws(Exception::class)
    suspend fun finishLogin(start: LoginFlowStart): Account {
        val credentials: LoginFlowCredentials = loginFlow.await(start, loginStartedAt) { loginCancelled }
        try {
            return pairWith(credentials.server, credentials.loginName, credentials.appPassword)
        } catch (e: Exception) {
            // Nothing is stored, so the app password Login Flow made is
            // deleted again rather than left behind in the device list.
            runCatching { api(Account("", credentials.server, credentials.loginName, credentials.appPassword)).revokeAppPassword() }
            throw e
        }
    }

    /**
     * One last poll, when the user closed the browser: pairs when access was
     * granted just before, and answers null when it was not, so the app can
     * show the address form again with nothing stored.
     */
    @Throws(Exception::class)
    suspend fun finishLoginNow(start: LoginFlowStart): Account? {
        val credentials = try {
            loginFlow.poll(start)
        } catch (e: Exception) {
            if (e is kotlinx.coroutines.CancellationException) throw e
            null
        } ?: return null
        try {
            return pairWith(credentials.server, credentials.loginName, credentials.appPassword)
        } catch (e: Exception) {
            runCatching { api(Account("", credentials.server, credentials.loginName, credentials.appPassword)).revokeAppPassword() }
            throw e
        }
    }

    /** Stops [finishLogin] at its next poll. */
    fun cancelLogin() {
        loginCancelled = true
    }

    /** The fallback: pair with an app password the user made in Nextcloud. */
    @Throws(Exception::class)
    suspend fun pairManually(serverInput: String, loginName: String, appPassword: String): Account {
        val server = ServerAddress.normalize(serverInput)
        if (loginName.isBlank() || appPassword.isBlank()) throw PairingException("Enter your user name and the app password.")
        return pairWith(server, loginName.trim(), appPassword.trim())
    }

    private suspend fun pairWith(server: String, loginName: String, appPassword: String): Account {
        checkRoom()
        val response = try {
            api(Account("", server, loginName, appPassword)).pair()
        } catch (e: InsecureServerException) {
            throw PairingException(e.message ?: "")
        } catch (e: KeepiqApiException) {
            throw PairingException(pairingProblem(e.status), e.status)
        } catch (e: Exception) {
            if (e is kotlinx.coroutines.CancellationException) throw e
            throw PairingException(pairingProblem(0))
        }
        if (!response.ok) throw PairingException("This server did not confirm the pairing.")
        if (response.apiVersion !in SUPPORTED_API_VERSIONS) {
            throw PairingException(
                "This server runs a Keepiq version this app does not support (API version ${response.apiVersion ?: "unknown"}). " +
                    "Ask your administrator to update Keepiq.",
            )
        }
        return try {
            accounts.add(server, loginName, appPassword, response.serverVersion)
        } catch (e: AccountLimitException) {
            throw PairingException(e.message ?: "")
        }
    }

    private fun checkRoom() {
        if (accounts.accounts().size >= AccountStore.MAX_ACCOUNTS) {
            throw PairingException("You can connect up to ${AccountStore.MAX_ACCOUNTS} accounts. Disconnect one first.")
        }
    }

    /**
     * Unpairs (design D3): tells Keepiq, deletes the app password in
     * Nextcloud, then wipes everything the device holds for the account,
     * also when the server cannot be reached. True when Nextcloud deleted
     * the app password.
     */
    @Throws(Exception::class)
    suspend fun unpair(accountId: String): Boolean {
        val account = accounts.account(accountId) ?: return false
        val api = api(account)
        runCatching { api.unpair() }
        val revoked = try {
            api.revokeAppPassword()
        } catch (e: Exception) {
            if (e is kotlinx.coroutines.CancellationException) throw e
            false
        }
        wipe(accountId)
        return revoked
    }

    /** Deletes every trace of an account on the device. */
    fun wipe(accountId: String) {
        runCatching { onWipe(accountId) }
        pins.remove(accountId)
        storage.delete(suiteKey(accountId))
        accounts.remove(accountId)
    }

    /**
     * What the unlock screen may offer. Reads the active suite from the
     * server, and falls back to the copy from the last unlock when the
     * server cannot be reached, so an offline phone still unlocks.
     */
    @Throws(Exception::class)
    suspend fun unlockGate(accountId: String): UnlockGate {
        val account = requireAccount(accountId)
        val suite = try {
            api(account).activeSuite()?.also { rememberSuite(accountId, it) }
        } catch (e: KeepiqApiException) {
            if (e.status == 0) cachedSuite(accountId)?.let { return gateOf(it, offline = true) }
            throw e
        } catch (e: Exception) {
            if (e is kotlinx.coroutines.CancellationException) throw e
            cachedSuite(accountId)?.let { return gateOf(it, offline = true) }
            throw PairingException("Cannot reach the server, and this phone has not unlocked this vault before.")
        }
        suite ?: throw nl.conduction.keepiq.shared.unlock.NoVaultException()
        return gateOf(suite, offline = false)
    }

    private fun gateOf(suite: Suite, offline: Boolean): UnlockGate = suite.unlockBlocked
        ?.let { UnlockGate.Blocked(it, nl.conduction.keepiq.shared.unlock.blockedMessage(it)) }
        ?: UnlockGate.Ready(suite, offline)

    /** Opens the vault with the master password. PBKDF2 runs off the main thread. */
    @Throws(Exception::class)
    suspend fun unlockWithMasterPassword(accountId: String, suite: Suite, masterPassword: String): UnlockedVault =
        withContext(Dispatchers.Default) { VaultUnlock.withMasterPassword(accountId, suite, masterPassword) }

    /**
     * Opens the vault with an unlock key from the biometric wrap. A key that
     * no longer opens the envelope deletes the PIN wrap too, and the app
     * deletes its biometric wrap on [StaleUnlockKeyException].
     */
    @Throws(Exception::class)
    suspend fun unlockWithKey(accountId: String, suite: Suite, unlockKey: ByteArray): UnlockedVault =
        withContext(Dispatchers.Default) {
            try {
                VaultUnlock.withUnlockKey(accountId, suite, unlockKey)
            } catch (e: StaleUnlockKeyException) {
                pins.remove(accountId)
                throw e
            }
        }

    /** The Swift side reads the biometric wrap as base64. */
    @Throws(Exception::class)
    suspend fun unlockWithKeyBase64(accountId: String, suite: Suite, unlockKey: String): UnlockedVault =
        unlockWithKey(accountId, suite, nl.conduction.keepiq.shared.crypto.Encoding.fromBase64(unlockKey))

    /** Opens the vault with the PIN. Argon2id runs off the main thread. */
    @Throws(Exception::class)
    suspend fun unlockWithPin(accountId: String, suite: Suite, pin: String): UnlockedVault {
        val key = withContext(Dispatchers.Default) { pins.open(accountId, pin) }
        return unlockWithKey(accountId, suite, key)
    }

    /** Sets a PIN for an unlocked vault. */
    @Throws(Exception::class)
    suspend fun setPin(vault: UnlockedVault, pin: String) {
        withContext(Dispatchers.Default) { pins.set(vault.accountId, vault.unlockKey(), pin) }
    }

    /** The organisation's idle maximum, or null when it cannot be read. */
    suspend fun maxIdleMinutes(accountId: String): Int? {
        val account = accounts.account(accountId) ?: return null
        return try {
            api(account).maxIdleMinutes()
        } catch (e: Exception) {
            if (e is kotlinx.coroutines.CancellationException) throw e
            null
        }
    }

    /** The API for an account, for the vault screens. */
    @Throws(Exception::class)
    fun api(account: Account): KeepiqApi = KeepiqApi(http, account)

    private fun requireAccount(accountId: String): Account =
        accounts.account(accountId) ?: throw PairingException("This account is no longer connected.")

    private fun suiteKey(accountId: String) = "suite:$accountId"

    private fun rememberSuite(accountId: String, suite: Suite) {
        if (suite.unlockBlocked != null || suite.privateKey == null) {
            storage.delete(suiteKey(accountId))
            return
        }
        val obj = buildJsonObject {
            put("id", JsonPrimitive(suite.id))
            suite.unlockKeyEpoch?.let { put("unlockKeyEpoch", JsonPrimitive(it)) }
            suite.certificate?.let { put("certificate", JsonPrimitive(it)) }
            put("privateKey", JsonPrimitive(suite.privateKey))
        }
        storage.write(suiteKey(accountId), obj.toString())
    }

    private fun cachedSuite(accountId: String): Suite? {
        val text = storage.read(suiteKey(accountId)) ?: return null
        val obj = runCatching { json.parseToJsonElement(text) as? JsonObject }.getOrNull() ?: return null
        return Suite(
            id = (obj["id"] as? JsonPrimitive)?.contentOrNull ?: return null,
            unlockKeyEpoch = (obj["unlockKeyEpoch"] as? JsonPrimitive)?.longOrNull,
            certificate = (obj["certificate"] as? JsonPrimitive)?.contentOrNull,
            privateKey = (obj["privateKey"] as? JsonPrimitive)?.contentOrNull,
            unlockBlocked = null,
        )
    }

    companion object {
        /** The pair response's `apiVersion` values this app speaks. */
        val SUPPORTED_API_VERSIONS: Set<Int> = setOf(1)

        /** What went wrong when pairing (browser-extension/src/background/router.js pairingProblem). */
        fun pairingProblem(status: Int): String = when {
            status == 0 -> "Cannot reach this server. Check the address and your connection."
            status == 401 -> "Nextcloud did not accept this user name and app password."
            status == 403 -> "This Nextcloud account may not use Keepiq."
            status == 404 -> "Keepiq is not installed on this server, or the address is wrong."
            status >= 500 -> "The server could not answer. Try again later."
            else -> "Connecting failed ($status)."
        }
    }
}

/**
 * For Swift, which sees no default arguments: a client with the platform
 * HTTP engine and the system clock.
 */
fun newKeepiqClient(storage: SecureStorage, clientName: String): KeepiqClient = KeepiqClient(storage, clientName)

/** The account id under a name Objective-C does not reserve, for Swift. */
val Account.accountId: String get() = id
