// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.passkey

import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.contentOrNull
import nl.conduction.keepiq.shared.api.KeepiqApi
import nl.conduction.keepiq.shared.autofill.AppLink
import nl.conduction.keepiq.shared.autofill.AutofillEntry
import nl.conduction.keepiq.shared.autofill.AutofillIndex
import nl.conduction.keepiq.shared.autofill.SiteMatch
import nl.conduction.keepiq.shared.crypto.ClientData
import nl.conduction.keepiq.shared.crypto.PasskeyCredential
import nl.conduction.keepiq.shared.crypto.WebAuthn
import nl.conduction.keepiq.shared.send.IsoTime
import nl.conduction.keepiq.shared.vault.ItemCodec
import nl.conduction.keepiq.shared.vault.ItemParts
import nl.conduction.keepiq.shared.vault.LoginPasskeys
import nl.conduction.keepiq.shared.vault.VaultKeys
import nl.conduction.keepiq.shared.vault.VaultRow

/**
 * A stored passkey for the asked relying party, decrypted for one request:
 * a Passkey item, or with [onLogin] one credential of a login's `passkeys`.
 */
class PasskeyChoice(val itemId: String, val name: String, val credential: PasskeyCredential, val onLogin: Boolean = false) {
    /** Tells apart the passkeys of one login: what the platform's choice carries back. */
    val id: String get() = itemId + "|" + credential.credentialId

    /** What the platform shows: the user name, else the display name, else the item name. */
    val label: String get() = credential.userName.ifEmpty { credential.userDisplayName }.ifEmpty { name }

    override fun toString(): String = "PasskeyChoice(id=$itemId)"
}

/** The site already has one of the passkeys the request excludes (WebAuthn InvalidStateError). */
class ExcludedCredentialException : IllegalStateException("A passkey for this account is already in your vault.")

/**
 * Keepiq as a passkey provider on the phones (mobile-passkey-provider), as
 * browser-extension/src/passkey/orchestrator.js does it in the extension:
 *
 * - [candidates]: the stored passkeys for an rpId, on Passkey items and on
 *   logins, matched on the item's plaintext address first and then on the
 *   decrypted record's rpId, which must be equal. An allow list narrows
 *   them to its credential ids.
 * - [sign]: the assertion, and a non-zero counter written back with PUT
 *   (orchestrator.js handleGet); on a login, to that credential of the
 *   login as the server holds it now. A write-back that fails fails the
 *   request, as in the extension.
 * - [create]: a new ES256 passkey saved onto the one matching login, else
 *   onto a new login named rpName or rpId with the rpId as address
 *   (mobile-login-totp-passkeys D12). An excluded credential refuses before
 *   anything is made.
 *
 * The rpId MUST come from the origin the operating system verified; the
 * callers check that before they come here.
 */
object Passkeys {
    fun candidates(index: AutofillIndex, rpId: String, keys: VaultKeys, allowCredentialIds: List<String> = emptyList()): List<PasskeyChoice> {
        val rp = rpId.lowercase()
        val site = SiteMatch.registrableDomain(rp)
        if (site.isEmpty()) return emptyList()
        fun usable(record: PasskeyCredential) =
            record.rpId.lowercase() == rp && (allowCredentialIds.isEmpty() || record.credentialId in allowCredentialIds)
        return index.entries.filter { (it.isPasskey || it.hasPasskeys) && SiteMatch.registrableDomain(it.url ?: "") == site }.flatMap { e ->
            if (e.isPasskey) {
                val record = runCatching { PasskeyCredential.parse(keys.decryptField(e.key)) { "" } }.getOrNull()
                listOfNotNull(record?.takeIf(::usable)?.let { PasskeyChoice(e.id, e.name, it) })
            } else {
                val records = runCatching { LoginPasskeys.credentials(keys.decryptField(e.passkeys)) }.getOrDefault(emptyList())
                records.filter(::usable).map { PasskeyChoice(e.id, e.name, it, onLogin = true) }
            }
        }
    }

    /** Signs for [rpId] with [choice] and writes a non-zero counter back to its item. */
    suspend fun sign(api: KeepiqApi, keys: VaultKeys, choice: PasskeyChoice, clientData: ClientData, rpId: String): WebAuthn.Assertion {
        val assertion = WebAuthn.getAssertion(clientData, rpId, choice.credential)
        if (assertion.counter > 0 && assertion.counter != choice.credential.counter && choice.onLogin) {
            val stored = storedPasskeys(api, keys, choice.itemId)
            api.updateSecret(choice.itemId, ItemCodec.passkeysBody(LoginPasskeys.withCounter(stored, choice.credential.credentialId, assertion.counter), keys))
        } else if (assertion.counter > 0 && assertion.counter != choice.credential.counter) {
            val updated = choice.credential.copy(counter = assertion.counter)
            val parts = ItemParts(choice.name, rpId, null, "", updated.toJson(), null)
            api.updateSecret(choice.itemId, ItemCodec.updateBody(parts, setOf("key"), keys))
        }
        return assertion
    }

    /** A login's `passkeys` as the server holds it now, decrypted; `""` for none. */
    private suspend fun storedPasskeys(api: KeepiqApi, keys: VaultKeys, id: String): String {
        val fresh = api.getSecret(id)?.let { VaultRow.from(it) } ?: throw IllegalStateException("The login is gone")
        return if (fresh.passkeys.isNullOrEmpty()) "" else keys.decryptField(fresh.passkeys)
    }

    /**
     * Creates a passkey for [rpId] and saves it in the vault. Throws
     * [ExcludedCredentialException] when the vault holds one of
     * [excludeCredentialIds] for this rpId, and a
     * [nl.conduction.keepiq.shared.crypto.KeepiqCryptoException] with
     * [WebAuthn.UNSUPPORTED_ALGORITHM] when ES256 is not allowed.
     */
    suspend fun create(
        api: KeepiqApi,
        keys: VaultKeys,
        index: AutofillIndex,
        request: CreateRequest,
        clientData: ClientData,
        nowMillis: Long,
    ): WebAuthn.Registration {
        if (!WebAuthn.supports(request.algorithms)) {
            throw nl.conduction.keepiq.shared.crypto.KeepiqCryptoException(WebAuthn.UNSUPPORTED_ALGORITHM)
        }
        if (request.excludeCredentialIds.isNotEmpty() && candidates(index, request.rpId, keys, request.excludeCredentialIds).isNotEmpty()) {
            throw ExcludedCredentialException()
        }
        val registration = WebAuthn.createCredential(
            rpId = request.rpId,
            rpName = request.rpName,
            userName = request.userName,
            userDisplayName = request.userDisplayName,
            userHandle = request.userHandle,
            algorithms = request.algorithms,
            clientData = clientData,
            createdAt = IsoTime.format(nowMillis),
        )
        val record = registration.record
        val login = matchingLogin(index, request.rpId, request.userName, keys)
        if (login != null) {
            api.updateSecret(login.id, ItemCodec.passkeysBody(LoginPasskeys.appended(storedPasskeys(api, keys, login.id), record), keys))
        } else {
            val typeId = api.listTypes().firstOrNull { (it["name"] as? JsonPrimitive)?.contentOrNull == AutofillEntry.LOGIN }
                ?.let { (it["id"] as? JsonPrimitive)?.contentOrNull }
            val parts = ItemParts(
                record.rpName.ifEmpty { request.rpId }, request.rpId, null, request.userName, "", null,
                passkeys = LoginPasskeys.appended("", record),
            )
            api.createSecret(ItemCodec.createBody(parts, typeId, keys))
        }
        return registration
    }

    /**
     * The login a new passkey for [userName] goes onto: the only same-site
     * login, not linked to an app, that the user may change, whose passkeys
     * can be read, and whose user name equals [userName] trimmed and ignoring
     * case. Null for none, for several, or for a blank [userName].
     */
    internal fun matchingLogin(index: AutofillIndex, rpId: String, userName: String, keys: VaultKeys): AutofillEntry? {
        val wanted = userName.trim()
        val site = SiteMatch.registrableDomain(rpId.lowercase())
        if (wanted.isEmpty() || site.isEmpty()) return null
        return index.entries.filter { e ->
            e.isLogin && !e.useOnly && !e.readOnly && AppLink.parse(e.url) == null &&
                SiteMatch.registrableDomain(SiteMatch.hostOf(e.url ?: "")) == site &&
                runCatching { keys.decryptField(e.login).trim() }.getOrNull().equals(wanted, ignoreCase = true) &&
                runCatching { e.passkeys.isNullOrEmpty() || LoginPasskeys.readable(keys.decryptField(e.passkeys)) }.getOrDefault(false)
        }.singleOrNull()
    }

    /**
     * The extension's relying-party rule (browser-extension/src/passkey/rp.js
     * rpIdAllowed), for a request a browser makes for a web origin: the rpId
     * is the origin's host or a parent domain of it that is not a public
     * suffix, and the origin is https (or localhost).
     */
    fun rpIdAllowed(rpId: String, origin: String): Boolean {
        val scheme = origin.substringBefore("://", "").lowercase()
        val host = SiteMatch.hostOf(origin)
        if (host.isEmpty()) return false
        if (scheme != "https" && host != "localhost") return false
        val rp = rpId.lowercase()
        if (rp.isEmpty()) return false
        if (rp == host) return true
        return host.endsWith(".$rp") && !SiteMatch.isPublicSuffix(rp)
    }
}

/** A passkey creation request, whatever platform it came from. */
class CreateRequest(
    val rpId: String,
    val rpName: String?,
    val userName: String,
    val userDisplayName: String,
    val userHandle: ByteArray?,
    val algorithms: List<Long>,
    val excludeCredentialIds: List<String>,
) {
    override fun toString(): String = "CreateRequest(rpId=$rpId)"
}
