// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.passkey

import kotlinx.coroutines.CancellationException
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.contentOrNull
import nl.conduction.keepiq.shared.api.KeepiqApi
import nl.conduction.keepiq.shared.api.KeepiqApiException
import nl.conduction.keepiq.shared.autofill.AppLink
import nl.conduction.keepiq.shared.autofill.AutofillEntry
import nl.conduction.keepiq.shared.autofill.AutofillIndex
import nl.conduction.keepiq.shared.autofill.SiteMatch
import nl.conduction.keepiq.shared.crypto.ClientData
import nl.conduction.keepiq.shared.crypto.PasskeyCredential
import nl.conduction.keepiq.shared.crypto.WebAuthn
import nl.conduction.keepiq.shared.send.IsoTime
import nl.conduction.keepiq.shared.sync.SyncTrigger
import nl.conduction.keepiq.shared.vault.ItemCodec
import nl.conduction.keepiq.shared.vault.ItemParts
import nl.conduction.keepiq.shared.vault.LoginGoneException
import nl.conduction.keepiq.shared.vault.LoginPasskeys
import nl.conduction.keepiq.shared.vault.MobileSession
import nl.conduction.keepiq.shared.vault.PasskeysWrite
import nl.conduction.keepiq.shared.vault.ServerUnreachableException
import nl.conduction.keepiq.shared.vault.ShareSave
import nl.conduction.keepiq.shared.vault.ShareSaveException
import nl.conduction.keepiq.shared.vault.VaultKeys
import nl.conduction.keepiq.shared.vault.VaultRepository
import nl.conduction.keepiq.shared.vault.VaultRow
import nl.conduction.keepiq.shared.vault.VaultState
import nl.conduction.keepiq.shared.vault.WriteProblemException

/**
 * A stored passkey for the asked relying party, decrypted for one request:
 * a Passkey item, or with [onLogin] one credential of a login's `passkeys`.
 * [held]: the item's value ciphertexts as the index holds them ([AutofillEntry.held]).
 */
class PasskeyChoice(
    val itemId: String,
    val name: String,
    val credential: PasskeyCredential,
    val onLogin: Boolean = false,
    val held: Map<String, String?>,
) {
    /** Tells apart the passkeys of one login: what the platform's choice carries back. */
    val id: String get() = itemId + "|" + credential.credentialId

    /** What the platform shows: the user name, else the display name, else the item name. */
    val label: String get() = credential.userName.ifEmpty { credential.userDisplayName }.ifEmpty { name }

    override fun toString(): String = "PasskeyChoice(id=$itemId)"
}

/** The passkey chosen from the index is no longer on its login as the server holds it now. */
class PasskeyGoneException : IllegalStateException("This passkey is no longer in your vault.")

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
 *   (orchestrator.js handleGet). On a login, the credential is read from
 *   the login as the server holds it now and signs from there, so its
 *   counter is the stored one plus one and a passkey deleted elsewhere
 *   refuses. A write-back that fails fails the request, as in the extension.
 * - Before a write, the item's ciphertext must be what the index holds, read
 *   again through `resync` once ([indexHolds]): a ciphertext the device
 *   doesn't hold may be another item's (ADR-002).
 * - [create]: a new ES256 passkey saved onto the one matching login, else
 *   onto a new login named rpName or rpId with the rpId as address
 *   (mobile-login-totp-passkeys D12). An excluded credential refuses before
 *   anything is made.
 *
 * The rpId MUST come from the origin the operating system verified; the
 * callers check that before they come here.
 */
object Passkeys {
    /** Refusals for how a standalone passkey item is shared, which the phone can't resolve: it signs without writing the counter. */
    private val COUNTER_LEFT_BEHIND = setOf(ShareSave.VIEW_ONLY, ShareSave.UNVERIFIED, ShareSave.PARTNER_UNREACHABLE, ShareSave.PARTNER_UPGRADE_REQUIRED)

    /**
     * Whether the index holds [fresh]'s [fields] for item [id], read again
     * through [resync] once when it doesn't, so a row changed elsewhere since
     * the index was built gets through ([ShareSave.holds]). A [resync] that
     * could not read the vault throws ([reread]), so the write fails with
     * that reason instead of as a mismatch.
     */
    private suspend fun indexHolds(fresh: VaultRow, held: Map<String, String?>, fields: Set<String>, resync: suspend () -> AutofillIndex?): Boolean {
        if (ShareSave.holds(fresh, held.filterKeys { it in fields })) return true
        val again = resync()?.entries?.firstOrNull { it.id == fresh.id } ?: return false
        return ShareSave.holds(fresh, again.held.filterKeys { it in fields })
    }

    /**
     * The vault read again from the server for a `resync`, by a provider
     * that holds no store of it, as its index; a [WriteProblemException] when
     * that read went offline, locked the vault or was refused
     * ([VaultState.readProblem]). [onRefreshed] sees the read either way.
     */
    suspend fun reread(api: KeepiqApi, keys: VaultKeys, onRefreshed: (VaultState) -> Unit = {}): AutofillIndex {
        val state = VaultRepository(api, keys, null, null, MobileSession::nowMillis, onRefreshed).refresh(SyncTrigger.MANUAL)
        state.readProblem?.let { throw WriteProblemException(it) }
        return AutofillIndex.of(state)
    }

    fun candidates(index: AutofillIndex, rpId: String, keys: VaultKeys, allowCredentialIds: List<String> = emptyList()): List<PasskeyChoice> {
        val rp = rpId.lowercase()
        val site = SiteMatch.registrableDomain(rp)
        if (site.isEmpty()) return emptyList()
        fun usable(record: PasskeyCredential) =
            record.rpId.lowercase() == rp && (allowCredentialIds.isEmpty() || record.credentialId in allowCredentialIds)
        return index.entries.filter { (it.isPasskey || it.hasPasskeys) && SiteMatch.registrableDomain(it.url ?: "") == site }.flatMap { e ->
            if (e.isPasskey) {
                val record = runCatching { PasskeyCredential.parse(keys.decryptField(e.key)) { "" } }.getOrNull()
                listOfNotNull(record?.takeIf(::usable)?.let { PasskeyChoice(e.id, e.name, it, held = e.held) })
            } else {
                val records = runCatching { LoginPasskeys.credentials(keys.decryptField(e.passkeys)) }.getOrDefault(emptyList())
                records.filter(::usable).map { PasskeyChoice(e.id, e.name, it, onLogin = true, held = e.held) }
            }
        }
    }

    /**
     * Signs for [rpId] with [choice] and writes a non-zero counter back to its
     * item, through share-save on the item as read now, so every copy gets it.
     * No client makes a standalone passkey item any more (passkeys live on
     * logins), so one that share-save refuses for how it is shared
     * ([COUNTER_LEFT_BEHIND]) still signs: only its counter stays behind, and
     * a relying party may then see it repeat. Every other refusal fails.
     */
    suspend fun sign(
        api: KeepiqApi,
        keys: VaultKeys,
        choice: PasskeyChoice,
        clientData: ClientData,
        rpId: String,
        resync: suspend () -> AutofillIndex? = { null },
    ): WebAuthn.Assertion {
        if (choice.onLogin) return signOnLogin(api, keys, choice, clientData, rpId, resync)
        val assertion = WebAuthn.getAssertion(clientData, rpId, choice.credential)
        if (assertion.counter > 0 && assertion.counter != choice.credential.counter) {
            val updated = choice.credential.copy(counter = assertion.counter).toJson()
            val fresh = api.getSecret(choice.itemId)?.let { VaultRow.from(it) } ?: throw PasskeyGoneException()
            val parts = ItemParts(choice.name, rpId, null, "", updated, null)
            try {
                if (!indexHolds(fresh, choice.held, ShareSave.PASSKEY_WRITE_HELD, resync)) throw ShareSaveException(ShareSave.CONFLICT)
                ShareSave(api, keys, MobileSession::nowMillis)
                    .saveFresh(fresh, ItemCodec.updateBody(parts, setOf("key"), keys), mapOf("key" to updated), vouchEmpty = true)
            } catch (e: ShareSaveException) {
                // The counter is not written; see above. Anything else, such as a ciphertext the index doesn't hold, fails the sign-in.
                if (e.code !in COUNTER_LEFT_BEHIND) throw e
            }
        }
        return assertion
    }

    /**
     * A passkey on a login signs from the login as the server holds it now,
     * read fresh, with one exception below: the counter it sends is the
     * stored one plus one,
     * and a passkey deleted elsewhere refuses with [PasskeyGoneException],
     * whatever its counter. The counter goes back with the row's `updatedAt`
     * as `baseUpdatedAt`; when the login changed in between, it is read and
     * signed once more (PasskeysWrite). One exception to reading fresh: when
     * the fresh read gets no answer at all ([ServerUnreachableException]), a
     * passkey whose counter is zero signs from the index, since it writes
     * nothing back; one with a counter fails, as its write-back would. The
     * index cannot know that such a passkey was deleted on another device or
     * gained a counter there since, so offline it still signs (counter 0) and
     * the relying party decides. Any other failure, such as a value that
     * cannot be decrypted, fails. Before the counter is written the login's
     * `totp` must be the one the index holds ([ShareSave.holds]); its
     * `passkeys` hold the chosen credential, so they are the login's own.
     */
    private suspend fun signOnLogin(
        api: KeepiqApi,
        keys: VaultKeys,
        choice: PasskeyChoice,
        clientData: ClientData,
        rpId: String,
        resync: suspend () -> AutofillIndex?,
    ): WebAuthn.Assertion {
        var fetched = false
        return try {
            PasskeysWrite.rewrite(api, keys, choice.itemId, held = { indexHolds(it, choice.held, setOf("totp"), resync) }) { _, stored ->
                fetched = true
                val record = LoginPasskeys.credentials(stored)
                    .firstOrNull { it.credentialId == choice.credential.credentialId && it.rpId == choice.credential.rpId }
                    ?: throw PasskeyGoneException()
                val assertion = WebAuthn.getAssertion(clientData, rpId, record)
                val write = assertion.counter > 0 && assertion.counter != record.counter
                PasskeysWrite.Change(if (write) LoginPasskeys.withCounter(stored, record.credentialId, record.rpId, assertion.counter) else null, assertion)
            }
        } catch (e: CancellationException) {
            throw e
        } catch (e: LoginGoneException) {
            throw PasskeyGoneException()
        } catch (e: ServerUnreachableException) {
            // Only a fresh read that got no answer at all; a decrypt or parse failure after an answer fails the sign-in.
            if (!fetched && choice.credential.counter == 0L) WebAuthn.getAssertion(clientData, rpId, choice.credential) else throw e
        }
    }

    /**
     * Creates a passkey for [rpId] and saves it in the vault. Throws
     * [ExcludedCredentialException] when the vault holds one of
     * [excludeCredentialIds] for this rpId, a
     * [nl.conduction.keepiq.shared.crypto.KeepiqCryptoException] with
     * [WebAuthn.UNSUPPORTED_ALGORITHM] when ES256 is not allowed, and a
     * [nl.conduction.keepiq.shared.api.MissingCapabilityException] before
     * any key is made when the server does not store `passkeys`.
     */
    suspend fun create(
        api: KeepiqApi,
        keys: VaultKeys,
        index: AutofillIndex,
        request: CreateRequest,
        clientData: ClientData,
        nowMillis: Long,
        resync: suspend () -> AutofillIndex? = { null },
    ): WebAuthn.Registration {
        if (!WebAuthn.supports(request.algorithms)) {
            throw nl.conduction.keepiq.shared.crypto.KeepiqCryptoException(WebAuthn.UNSUPPORTED_ALGORITHM)
        }
        if (request.excludeCredentialIds.isNotEmpty() && candidates(index, request.rpId, keys, request.excludeCredentialIds).isNotEmpty()) {
            throw ExcludedCredentialException()
        }
        // The passkey is stored only in `passkeys`: a server without the field would drop it after answering 2xx.
        api.requireLoginFields()
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
        // The index carries no owner: a login the server refuses `passkeys` on, read fresh, gets none and a new login is made.
        // A matched login deleted elsewhere since the index was built is no match either: a new login is made.
        val appended = login != null && try {
            PasskeysWrite.rewrite(api, keys, login.id, held = { indexHolds(it, login.held, ShareSave.PASSKEY_WRITE_HELD, resync) }) { fresh, stored ->
                if (mayTakePasskeys(fresh)) PasskeysWrite.Change(LoginPasskeys.appended(stored, record), true) else PasskeysWrite.Change(null, false)
            }
        } catch (e: LoginGoneException) {
            false
        } catch (e: KeepiqApiException) {
            if (e.status != 404) throw e
            false
        } catch (e: ShareSaveException) {
            // A login shared with the user to view only, or one the index doesn't hold as the server sent it, takes no passkey: a new login is made.
            if (e.code != ShareSave.VIEW_ONLY && e.code != ShareSave.CONFLICT) throw e
            false
        }
        if (!appended) {
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
     * Whether the server takes `passkeys` on [fresh]: a row the user may
     * change and that a user owns. The server refuses them on an
     * application-owned secret (SecretService::refuseApplicationValueFields).
     */
    private fun mayTakePasskeys(fresh: VaultRow): Boolean =
        !fresh.useOnly && !fresh.readOnly && !fresh.blocked && (fresh.ownerType ?: "user") == "user"

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
