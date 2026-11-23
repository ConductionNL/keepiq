// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.vault

import kotlinx.coroutines.CancellationException
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import nl.conduction.keepiq.shared.api.KeepiqApiException
import nl.conduction.keepiq.shared.sync.LockReason

/** Why a write did not go through. The screens turn the kind into text in the user's language. */
enum class WriteProblemKind {
    /** The device is offline: edits need a connection (design D5, no offline edits in v1). */
    OFFLINE,

    /** 423: a key migration holds the vault. */
    KEY_MIGRATION,

    /** 403 or 428 without a reason: the encryption suite is blocked. */
    SUITE_BLOCKED,

    /** The server refused and said why; [WriteProblem.serverMessage] holds its words. */
    SERVER_MESSAGE,

    /** The server refused without saying why. */
    REFUSED,

    /** The server could not be reached. */
    UNREACHABLE,

    /** Something else failed. */
    FAILED,

    /** A copy shared to view only: a change would reach that copy alone. */
    VIEW_ONLY,

    /** The source of a shared copy changed after the form opened. */
    STALE_SOURCE,

    /** The people the item is shared with kept changing during the save. */
    INCOMPLETE,

    /** Partner shares get the whole value, and the phone can't vouch for a field it did not open. */
    UNVERIFIED,

    /** A field the save must pass on could not be decrypted. */
    UNREADABLE,

    /** A recipient's encryption key could not be read. */
    BAD_CERTIFICATE,

    /** A partner can't be reached; [WriteProblem.partners] can be left out with Save without them. */
    PARTNER_UNREACHABLE,

    /** A partner's Keepiq is too old for a field the save adds; [WriteProblem.requiredVersion] names the one it needs. */
    PARTNER_UPGRADE_REQUIRED,

    /** A partner certificate isn't valid by this phone's clock. */
    PARTNER_CLOCK,

    /** Too many partner lookups. */
    PARTNER_LOOKUP_LIMIT,

    /** This server can't share with partner organisations. */
    FEDERATION_UNAVAILABLE,

    /** This server's Keepiq has no share-save. */
    KEEPIQ_OUTDATED,

    /** The server sent a value the app doesn't hold for this item, even after a sync: it is not opened or changed. */
    CONFLICT,

    /** The sync locked the vault; [WriteProblem.lockReason] says why. */
    LOCKED,

    /** The item is not in the vault the device holds, even after a sync. */
    NOT_FOUND,
}

/** A refusal the phone already put in words, thrown through a write that reports it as [problem]. */
class WriteProblemException(val problem: WriteProblem) : Exception(problem.kind.name)

/** A refused or failed write. The old value stays; nothing is shown as saved. */
data class WriteProblem(
    val kind: WriteProblemKind,
    val serverMessage: String? = null,
    val status: Int = 0,
    /** The partner shares a partner refusal names. */
    val partners: List<PartnerShare> = emptyList(),
    val requiredVersion: String? = null,
    val lockReason: LockReason? = null,
) {
    /** Swift gets no default arguments, so it builds a problem of just a kind with this `init(kind:)`. */
    constructor(kind: WriteProblemKind) : this(kind, null)

    /** The partners' cloud ids, for the message. */
    val partnerNames: String get() = partners.joinToString(", ") { it.recipientCloudId }

    companion object {
        private val SHARE_SAVE_KINDS = mapOf(
            ShareSave.VIEW_ONLY to WriteProblemKind.VIEW_ONLY,
            ShareSave.STALE_SOURCE to WriteProblemKind.STALE_SOURCE,
            ShareSave.INCOMPLETE to WriteProblemKind.INCOMPLETE,
            ShareSave.UNVERIFIED to WriteProblemKind.UNVERIFIED,
            ShareSave.UNREADABLE to WriteProblemKind.UNREADABLE,
            ShareSave.BAD_CERTIFICATE to WriteProblemKind.BAD_CERTIFICATE,
            ShareSave.PARTNER_UNREACHABLE to WriteProblemKind.PARTNER_UNREACHABLE,
            ShareSave.PARTNER_UPGRADE_REQUIRED to WriteProblemKind.PARTNER_UPGRADE_REQUIRED,
            ShareSave.PARTNER_CLOCK to WriteProblemKind.PARTNER_CLOCK,
            ShareSave.PARTNER_LOOKUP_LIMIT to WriteProblemKind.PARTNER_LOOKUP_LIMIT,
            ShareSave.FEDERATION_UNAVAILABLE to WriteProblemKind.FEDERATION_UNAVAILABLE,
            ShareSave.KEEPIQ_OUTDATED to WriteProblemKind.KEEPIQ_OUTDATED,
            ShareSave.FORBIDDEN to WriteProblemKind.REFUSED,
            ShareSave.CONFLICT to WriteProblemKind.CONFLICT,
        )

        /**
         * writeErrorMessage (browser-extension/src/lib/item-form.js): Keepiq's
         * OCS routes refuse with 428 and a code (keepiq#673), a plain
         * controller with 403; 400 and 409 carry their own message.
         */
        fun from(error: Throwable): WriteProblem {
            if (error is CancellationException) throw error
            if (error is WriteProblemException) return error.problem
            if (error is ShareSaveException) {
                return WriteProblem(SHARE_SAVE_KINDS[error.code] ?: WriteProblemKind.FAILED, partners = error.partners, requiredVersion = error.requiredVersion)
            }
            // Refused on the phone after the server answered: a login's passkeys that cannot be rewritten, or a login that is gone.
            if (error is UnreadablePasskeysException || error is LoginGoneException) return WriteProblem(WriteProblemKind.FAILED, error.message)
            if (error !is KeepiqApiException) return WriteProblem(WriteProblemKind.UNREACHABLE)
            val status = error.status
            val outer = parseBody(error.body)
            // An OCS envelope carries the refusal in ocs.data (statuscode >= 400 under HTTP 200).
            val ocs = outer["ocs"] as? JsonObject
            val body = (ocs?.get("data") as? JsonObject) ?: outer
            val message = body.text("message") ?: (ocs?.get("meta") as? JsonObject)?.text("message")?.takeIf { it.isNotBlank() }
            return when {
                status == 0 -> WriteProblem(WriteProblemKind.UNREACHABLE)
                status == 423 -> WriteProblem(WriteProblemKind.KEY_MIGRATION, status = status)
                status == 403 || status == 428 -> {
                    val code = body.text("code") ?: error.code
                    val err = body.text("error")
                    if (code != null || (err != null && err != "forbidden")) {
                        if (message != null) WriteProblem(WriteProblemKind.SERVER_MESSAGE, message, status) else WriteProblem(WriteProblemKind.REFUSED, status = status)
                    } else {
                        WriteProblem(WriteProblemKind.SUITE_BLOCKED, status = status)
                    }
                }
                status == 400 || status == 409 ->
                    if (message != null) WriteProblem(WriteProblemKind.SERVER_MESSAGE, message, status) else WriteProblem(WriteProblemKind.REFUSED, status = status)
                status in 400..499 ->
                    if (message != null) WriteProblem(WriteProblemKind.SERVER_MESSAGE, message, status) else WriteProblem(WriteProblemKind.REFUSED, status = status)
                else -> WriteProblem(WriteProblemKind.FAILED, message, status)
            }
        }

        private fun parseBody(text: String): JsonObject =
            runCatching { Json.parseToJsonElement(text.ifBlank { "{}" }) as? JsonObject }.getOrNull() ?: JsonObject(emptyMap())
    }
}

/** The result of a write. */
sealed class WriteResult {
    data class Saved(val id: String?) : WriteResult()

    data class Refused(val problem: WriteProblem) : WriteResult()
}
