// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.vectors

import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.long
import nl.conduction.keepiq.shared.crypto.Encoding

/** The vectors from tests/vectors/crypto, written by the web app's own modules. */
internal object Vectors {
    private fun load(parts: List<String>): JsonObject =
        Json.parseToJsonElement(Encoding.fromUtf8(Encoding.fromBase64(parts.joinToString("")))).jsonObject

    val envelope: JsonObject by lazy { load(GeneratedVectors.envelope) }
    val fields: JsonObject by lazy { load(GeneratedVectors.fields) }
    val send: JsonObject by lazy { load(GeneratedVectors.send) }
    val totp: JsonObject by lazy { load(GeneratedVectors.totp) }
    val passkey: JsonObject by lazy { load(GeneratedVectors.passkey) }
}

internal fun JsonObject.str(name: String): String = getValue(name).jsonPrimitive.content
internal fun JsonObject.num(name: String): Long = getValue(name).jsonPrimitive.long
internal fun JsonObject.obj(name: String): JsonObject = getValue(name).jsonObject
internal fun JsonObject.arr(name: String): JsonArray = getValue(name).jsonArray
internal fun JsonArray.objects(): List<JsonObject> = map { it.jsonObject }
