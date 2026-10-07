// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.account

import kotlinx.serialization.Serializable
import kotlinx.serialization.builtins.ListSerializer
import kotlinx.serialization.json.Json
import nl.conduction.keepiq.shared.api.Account
import nl.conduction.keepiq.shared.crypto.Encoding
import nl.conduction.keepiq.shared.crypto.Primitives

/**
 * Small values the app keeps across restarts, bound to the device (design
 * D4): the paired accounts with their app passwords, and the PIN wraps.
 *
 * - Android: SharedPreferences whose values are sealed with an AES key in the
 *   AndroidKeyStore that needs no user authentication.
 * - iOS: Keychain items, `kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly`.
 *
 * Neither leaves the device in a backup.
 */
interface SecureStorage {
    fun read(key: String): String?

    fun write(key: String, value: String)

    fun delete(key: String)
}

/** A [SecureStorage] in memory, for tests and previews. */
class InMemorySecureStorage : SecureStorage {
    private val values = LinkedHashMap<String, String>()

    override fun read(key: String): String? = values[key]

    override fun write(key: String, value: String) {
        values[key] = value
    }

    override fun delete(key: String) {
        values.remove(key)
    }

    /** The keys held, for tests that check a wipe. */
    fun keys(): Set<String> = values.keys.toSet()
}

/** A sixth account, or the same account twice. */
class AccountLimitException(message: String) : Exception(message)

/** The user's choices for one account. */
@Serializable
data class AccountSettings(val idleMinutes: Int = IdlePolicy.DEFAULT_MINUTES, val biometric: Boolean = false)

@Serializable
private data class StoredAccount(
    val id: String,
    val server: String,
    val loginName: String,
    val appPassword: String,
    val serverVersion: String? = null,
    val settings: AccountSettings = AccountSettings(),
)

/**
 * The paired accounts, at most [MAX_ACCOUNTS] (design D3, the extension's
 * limit), in pairing order, with the active one.
 */
class AccountStore(private val storage: SecureStorage) {
    private val json = Json { ignoreUnknownKeys = true }
    private val serializer = ListSerializer(StoredAccount.serializer())

    fun accounts(): List<Account> = load().map { it.toAccount() }

    fun account(id: String): Account? = load().firstOrNull { it.id == id }?.toAccount()

    fun activeId(): String? = storage.read(ACTIVE_KEY)?.takeIf { id -> load().any { it.id == id } }
        ?: load().firstOrNull()?.id

    fun setActive(id: String) {
        require(load().any { it.id == id }) { "unknown account" }
        storage.write(ACTIVE_KEY, id)
    }

    fun settings(id: String): AccountSettings = load().firstOrNull { it.id == id }?.settings ?: AccountSettings()

    fun serverVersion(id: String): String? = load().firstOrNull { it.id == id }?.serverVersion

    fun updateSettings(id: String, settings: AccountSettings) {
        require(settings.idleMinutes in IdlePolicy.CHOICES) { "idle minutes must be one of ${IdlePolicy.CHOICES}" }
        save(load().map { if (it.id == id) it.copy(settings = settings) else it })
    }

    /** Adds an account and makes it active. Refuses a sixth one and a second copy of the same login. */
    @Throws(AccountLimitException::class)
    fun add(server: String, loginName: String, appPassword: String, serverVersion: String?): Account {
        val list = load()
        if (list.any { it.server == server && it.loginName == loginName }) {
            throw AccountLimitException("This account is already connected.")
        }
        if (list.size >= MAX_ACCOUNTS) {
            throw AccountLimitException("You can connect up to $MAX_ACCOUNTS accounts. Disconnect one first.")
        }
        val stored = StoredAccount(Encoding.hex(Primitives.randomBytes(16)), server, loginName, appPassword, serverVersion)
        save(list + stored)
        storage.write(ACTIVE_KEY, stored.id)
        return stored.toAccount()
    }

    /** Removes an account. The caller wipes its other device state first (see [nl.conduction.keepiq.shared.KeepiqClient.unpair]). */
    fun remove(id: String) {
        val left = load().filterNot { it.id == id }
        save(left)
        if (storage.read(ACTIVE_KEY) == id) {
            left.firstOrNull()?.let { storage.write(ACTIVE_KEY, it.id) } ?: storage.delete(ACTIVE_KEY)
        }
    }

    private fun load(): List<StoredAccount> {
        val text = storage.read(ACCOUNTS_KEY) ?: return emptyList()
        return runCatching { json.decodeFromString(serializer, text) }.getOrDefault(emptyList())
    }

    private fun save(list: List<StoredAccount>) {
        if (list.isEmpty()) storage.delete(ACCOUNTS_KEY) else storage.write(ACCOUNTS_KEY, json.encodeToString(serializer, list))
    }

    private fun StoredAccount.toAccount() = Account(id, server, loginName, appPassword)

    companion object {
        const val MAX_ACCOUNTS = 5
        private const val ACCOUNTS_KEY = "accounts"
        private const val ACTIVE_KEY = "accounts.active"
    }
}
