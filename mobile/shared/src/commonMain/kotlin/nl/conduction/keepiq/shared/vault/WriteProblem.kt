// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.vault

import kotlinx.coroutines.CancellationException
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import nl.conduction.keepiq.shared.api.KeepiqApiException

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
}

/** A refused or failed write. The old value stays; nothing is shown as saved. */
data class WriteProblem(val kind: WriteProblemKind, val serverMessage: String? = null, val status: Int = 0) {
    companion object {
        /**
         * writeErrorMessage (browser-extension/src/lib/item-form.js): Keepiq's
         * OCS routes refuse with 428 and a code (keepiq#673), a plain
         * controller with 403; 400 and 409 carry their own message.
         */
        fun from(error: Throwable): WriteProblem {
            if (error is CancellationException) throw error
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
