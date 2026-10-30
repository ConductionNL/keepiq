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

    /**
     * The array without the credential [credentialId] for [rpId], or `""`
     * when none remain. The pair names one credential: an import keeps one
     * id for two rpIds as two entries (src/import/loginValueFields.js).
     */
    fun without(json: String, credentialId: String, rpId: String): String =
        serialize(array(json).filter { !it.isCredential(credentialId, rpId) })

    /** The array with [counter] on the credential [credentialId] for [rpId]. */
    fun withCounter(json: String, credentialId: String, rpId: String, counter: Long): String = serialize(
        array(json).map { e -> (e as? JsonObject)?.takeIf { it.isCredential(credentialId, rpId) }?.let { JsonObject(it + ("counter" to JsonPrimitive(counter))) } ?: e },
    )

    /** The array with [credential] added; [json] may be `""`. */
    fun appended(json: String, credential: PasskeyCredential): String =
        serialize(array(json) + Json.parseToJsonElement(credential.toJson()))

    private fun entries(json: String): List<JsonElement> =
        if (json.isEmpty()) emptyList() else (runCatching { Json.parseToJsonElement(json) }.getOrNull() as? JsonArray) ?: emptyList()

    /** The entries to change; a value that is not an array refuses, so it is never overwritten. */
    private fun array(json: String): List<JsonElement> = if (readable(json)) entries(json) else throw UnreadablePasskeysException()

    private fun JsonElement.isCredential(credentialId: String, rpId: String): Boolean =
        member("credentialId") == credentialId && member("rpId") == rpId

    private fun JsonElement.member(name: String): String? = ((this as? JsonObject)?.get(name) as? JsonPrimitive)?.contentOrNull

    private fun serialize(entries: List<JsonElement>): String = if (entries.isEmpty()) "" else JsonArray(entries).toString()
}
