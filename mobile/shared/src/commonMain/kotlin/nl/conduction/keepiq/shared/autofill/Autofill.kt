// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.autofill

import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.contentOrNull
import nl.conduction.keepiq.shared.api.KeepiqApi
import nl.conduction.keepiq.shared.crypto.Totp
import nl.conduction.keepiq.shared.vault.ItemCodec
import nl.conduction.keepiq.shared.vault.ItemParts
import nl.conduction.keepiq.shared.vault.VaultKeys

/** A login decrypted for one fill: only the item the form matched. */
data class LoginChoice(val id: String, val name: String, val login: String, val password: String, val useOnly: Boolean) {
    override fun toString(): String = "LoginChoice(id=$id)"
}

/** A one-time code computed for one fill. */
data class CodeChoice(val id: String, val name: String, val code: String) {
    override fun toString(): String = "CodeChoice(id=$id)"
}

/**
 * What the system autofill offers once the vault is unlocked: the matched
 * items, decrypted one by one. An item that cannot be decrypted is left out
 * rather than failing the whole fill.
 */
object AutofillChoices {
    fun logins(index: AutofillIndex, target: AutofillTarget, keys: VaultKeys): List<LoginChoice> =
        index.candidates(target).mapNotNull { e ->
            runCatching { LoginChoice(e.id, e.name, keys.decryptField(e.login), keys.decryptField(e.key), e.useOnly) }.getOrNull()
        }

    /** The current code of each matched authenticator item (task 4.3 and 4.5). */
    fun codes(index: AutofillIndex, target: AutofillTarget, keys: VaultKeys, nowMillis: Long): List<CodeChoice> =
        index.candidates(target, totp = true).mapNotNull { e ->
            runCatching { CodeChoice(e.id, e.name, Totp.generate(Totp.parse(keys.decryptField(e.key)), nowMillis)) }.getOrNull()
        }
}

/** What saving a submitted login did. */
enum class SaveResult { SAVED, UPDATED, UNCHANGED, REFUSED }

/**
 * Saves a login typed into an app or a website (task 4.2), as the
 * extension's saveHeldCapture does: update the one stored login with this
 * user name, do nothing when the same login is stored or several could be
 * meant, else create a login item. A use-only item for the site withholds
 * the save, and so does the never-save list, which the caller checks first.
 */
class AutofillSaver(private val api: KeepiqApi, private val keys: VaultKeys) {
    suspend fun save(target: AutofillTarget, index: AutofillIndex, login: String, password: String, appLabel: String? = null, webScheme: String? = null): SaveResult {
        if (password.isEmpty() || index.blocksSave(target)) return SaveResult.REFUSED
        val offer = Capture.classifyCandidates(index.saveCandidates(target), login, password) { e ->
            PlainLogin(keys.decryptField(e.login), keys.decryptField(e.key))
        }
        return when (offer) {
            SaveOffer.None -> SaveResult.UNCHANGED
            is SaveOffer.Update -> {
                val parts = ItemParts(offer.name, "", null, login, password, null)
                api.updateSecret(offer.id, ItemCodec.updateBody(parts, setOf("key"), keys))
                SaveResult.UPDATED
            }
            SaveOffer.Save -> {
                val typeId = api.listTypes().firstOrNull { (it["name"] as? JsonPrimitive)?.contentOrNull == "login" }
                    ?.let { (it["id"] as? JsonPrimitive)?.contentOrNull }
                val (name, url) = when (target) {
                    is AutofillTarget.Web -> {
                        val host = SiteMatch.hostOf(target.host)
                        host to ((if (webScheme == "http") "http" else "https") + "://" + host)
                    }
                    is AutofillTarget.App -> (appLabel ?: target.identity.packageName) to AppLink.of(target.identity).toUrl()
                }
                api.createSecret(ItemCodec.createBody(ItemParts(name, url, null, login, password, null), typeId, keys))
                SaveResult.SAVED
            }
        }
    }
}
