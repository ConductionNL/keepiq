// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.autofill

import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonArray
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.contentOrNull
import nl.conduction.keepiq.shared.vault.VaultKeys
import nl.conduction.keepiq.shared.vault.VaultState

/**
 * One fillable item in the autofill index (design D5, "The autofill
 * index"). Name and address are the plaintext metadata the server sends;
 * [login] and [key] stay the RSA ciphertext the server sent, so the index
 * holds no plaintext secret. A locked phone can tell that a form has a
 * match, and an unlocked one decrypts only the item the user picks.
 */
data class AutofillEntry(
    override val id: String,
    override val name: String,
    override val url: String?,
    /** "login" or "totp". */
    val typeName: String,
    val login: String?,
    val key: String?,
    override val useOnly: Boolean,
    override val lastUsedAt: String? = null,
) : Matchable {
    val isLogin: Boolean get() = typeName == LOGIN
    val isTotp: Boolean get() = typeName == TOTP

    override fun toString(): String = "AutofillEntry(id=$id, type=$typeName)"

    companion object {
        const val LOGIN = "login"
        const val TOTP = "totp"
    }
}

/** Where a fill request comes from: a website the browser names, or an app. */
sealed class AutofillTarget {
    data class Web(val host: String) : AutofillTarget()

    /**
     * An app, with the websites Digital Asset Links verified for it
     * ([verifiedHosts]): their logins are offered too.
     */
    data class App(val identity: AppIdentity, val verifiedHosts: List<String> = emptyList()) : AutofillTarget()

    /** The host a saved login is filed under, and the never-save list is keyed by. */
    val saveKey: String
        get() = when (this) {
            is Web -> SiteMatch.hostOf(host)
            is App -> AppLink.SCHEME + identity.packageName
        }
}

/** The index of one account. */
class AutofillIndex(val entries: List<AutofillEntry>) {
    /**
     * The logins (or, with [totp], the authenticator items) for [target],
     * best first. A website gets the extension's site match, without the
     * items linked to an app. An app gets the
     * items linked to it by package and certificate, and the site match of
     * every website verified for it. A use-only item fills only on its own
     * site, as in the extension.
     */
    fun candidates(target: AutofillTarget, totp: Boolean = false): List<AutofillEntry> {
        val pool = entries.filter { if (totp) it.isTotp else it.isLogin }
        val scored: List<Scored<AutofillEntry>> = when (target) {
            // An item linked to an app is for that app only: its package
            // name reads like a host name, but no website owns it.
            is AutofillTarget.Web -> SiteMatch.filterForHost(SiteMatch.matchSecrets(pool.filter { AppLink.parse(it.url) == null }, target.host), target.host)
            is AutofillTarget.App -> {
                val linked = pool.filter { AppLink.parse(it.url)?.matches(target.identity) == true }.map { Scored(it, 100) }
                val viaSites = target.verifiedHosts.flatMap { host ->
                    SiteMatch.filterForHost(SiteMatch.matchSecrets(pool.filter { AppLink.parse(it.url) == null }, host), host)
                }
                (linked + viaSites)
                    .groupBy { it.item.id }
                    .map { (_, same) -> same.maxBy { it.score } }
                    .sortedWith { a, b ->
                        val byScore = b.score - a.score
                        if (byScore != 0) byScore else (b.item.lastUsedAt ?: "").compareTo(a.item.lastUsedAt ?: "")
                    }
            }
        }
        return scored.map { it.item }
    }

    /** Whether a save offer is withheld: a use-only item belongs to this site (useOnly.js blocksSavePrompt). */
    fun blocksSave(target: AutofillTarget): Boolean = when (target) {
        is AutofillTarget.Web -> SiteMatch.blocksSavePrompt(entries.filter { it.isLogin }, target.host)
        is AutofillTarget.App -> candidates(target).any { it.useOnly }
    }

    fun toJson(): String = buildJsonArray {
        for (e in entries) {
            add(
                buildJsonObject {
                    put("id", JsonPrimitive(e.id))
                    put("name", JsonPrimitive(e.name))
                    e.url?.let { put("url", JsonPrimitive(it)) }
                    put("type", JsonPrimitive(e.typeName))
                    e.login?.let { put("login", JsonPrimitive(it)) }
                    e.key?.let { put("key", JsonPrimitive(it)) }
                    if (e.useOnly) put("useOnly", JsonPrimitive(true))
                    e.lastUsedAt?.let { put("lastUsedAt", JsonPrimitive(it)) }
                },
            )
        }
    }.toString()

    companion object {
        val EMPTY = AutofillIndex(emptyList())

        /**
         * The index of a vault: logins and authenticator items that are not
         * trashed and not blocked. Blocked items are never offered, as in the
         * extension (extension-fill-and-capture).
         */
        fun of(state: VaultState): AutofillIndex {
            val typeNames = state.types.associate { it.id to it.name }
            return AutofillIndex(
                state.rows.filter { !it.trashed && !it.blocked }.mapNotNull { row ->
                    val typeName = row.typeId?.let { typeNames[it] } ?: AutofillEntry.LOGIN
                    if (typeName != AutofillEntry.LOGIN && typeName != AutofillEntry.TOTP) return@mapNotNull null
                    AutofillEntry(row.id, row.name, row.url, typeName, row.login, row.key, row.useOnly)
                },
            )
        }

        fun fromJson(text: String): AutofillIndex {
            val array = runCatching { Json.parseToJsonElement(text) as? JsonArray }.getOrNull() ?: return EMPTY
            return AutofillIndex(
                array.mapNotNull { element ->
                    val o = element as? JsonObject ?: return@mapNotNull null
                    AutofillEntry(
                        id = o.text("id") ?: return@mapNotNull null,
                        name = o.text("name") ?: "",
                        url = o.text("url"),
                        typeName = o.text("type") ?: AutofillEntry.LOGIN,
                        login = o.text("login"),
                        key = o.text("key"),
                        useOnly = o.text("useOnly") == "true",
                        lastUsedAt = o.text("lastUsedAt"),
                    )
                },
            )
        }

        private fun JsonObject.text(name: String): String? = (this[name] as? JsonPrimitive)?.contentOrNull
    }
}

/**
 * Where the platform keeps the autofill index: on Android a file sealed
 * with a device-bound Keystore key, on iOS the system's
 * ASCredentialIdentityStore plus a per-site file the AutoFill extension
 * reads. [persist] is false when the organisation keeps no offline copy:
 * the index then lives in memory only (design D5).
 */
interface AutofillIndexSink {
    fun replace(accountId: String, index: AutofillIndex, persist: Boolean, keys: VaultKeys)

    fun clear(accountId: String)
}

/**
 * Keeps the autofill index in step with the vault (task 4.6): every sync
 * that returns the vault rebuilds it, and a sync that locked the vault
 * (another suite, a new master password, the two-factor block) clears it,
 * as the store is cleared. The app clears it on unpair through
 * [nl.conduction.keepiq.shared.KeepiqClient.onWipe], and when the user
 * turns autofill off.
 *
 * The app sets [sink] once at start; VaultRepository reports each refresh.
 */
object AutofillIndexHub {
    var sink: AutofillIndexSink? = null

    /** Called with every state [nl.conduction.keepiq.shared.vault.VaultRepository.refresh] returns. */
    fun refreshed(accountId: String, state: VaultState, keys: VaultKeys) {
        val target = sink ?: return
        when {
            state.locked != null -> target.clear(accountId)
            // Offline without a copy, or a refused read: keep what the last sync built.
            state.needsConnection || state.problem != null -> Unit
            else -> target.replace(accountId, AutofillIndex.of(state), persist = !state.onlineOnly, keys = keys)
        }
    }

    fun clear(accountId: String) {
        sink?.clear(accountId)
    }
}
