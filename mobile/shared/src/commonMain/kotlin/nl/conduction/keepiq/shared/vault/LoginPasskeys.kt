// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.vault

import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.contentOrNull
import nl.conduction.keepiq.shared.crypto.PasskeyCredential

/** A login's `passkeys` holds something other than an array: it is never rewritten (web D5). */
class UnreadablePasskeysException : IllegalStateException("The passkeys of this login cannot be read.")

/**
 * A login's decrypted `passkeys` field: a JSON array of credentials in the
 * canonical passkey schema (src/passkey/loginPasskeys.js). Changing one
 * credential keeps every other entry as stored, members this app does not
 * know included (web D5).
 */
object LoginPasskeys {
    /** The readable credentials; none for an empty, unreadable or non-array value. */
    fun credentials(json: String): List<PasskeyCredential> =
        entries(json).mapNotNull { PasskeyCredential.parse(it.toString()) { "" } }

    /** Whether [json] is empty or an array, so a change keeps what is stored. */
    fun readable(json: String): Boolean = json.isEmpty() || runCatching { Json.parseToJsonElement(json) }.getOrNull() is JsonArray

    /** The array without [credentialId], or `""` when none remain. */
    fun without(json: String, credentialId: String): String =
        serialize(array(json).filter { idOf(it) != credentialId })

    /** The array with [counter] on [credentialId]. */
    fun withCounter(json: String, credentialId: String, counter: Long): String = serialize(
        array(json).map { e -> (e as? JsonObject)?.takeIf { idOf(it) == credentialId }?.let { JsonObject(it + ("counter" to JsonPrimitive(counter))) } ?: e },
    )

    /** The array with [credential] added; [json] may be `""`. */
    fun appended(json: String, credential: PasskeyCredential): String =
        serialize(array(json) + Json.parseToJsonElement(credential.toJson()))

    private fun entries(json: String): List<JsonElement> =
        if (json.isEmpty()) emptyList() else (runCatching { Json.parseToJsonElement(json) }.getOrNull() as? JsonArray) ?: emptyList()

    /** The entries to change; a value that is not an array refuses, so it is never overwritten. */
    private fun array(json: String): List<JsonElement> = if (readable(json)) entries(json) else throw UnreadablePasskeysException()

    private fun idOf(entry: JsonElement): String? = ((entry as? JsonObject)?.get("credentialId") as? JsonPrimitive)?.contentOrNull

    private fun serialize(entries: List<JsonElement>): String = if (entries.isEmpty()) "" else JsonArray(entries).toString()
}
