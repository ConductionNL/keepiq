// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.passkey

import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonArray
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.longOrNull
import kotlinx.serialization.json.put
import nl.conduction.keepiq.shared.crypto.Encoding
import nl.conduction.keepiq.shared.crypto.PasskeyCredential
import nl.conduction.keepiq.shared.crypto.WebAuthn

/** A get request: the challenge, the rpId it names, and the credential ids it allows (base64url). */
class GetRequest(val challenge: ByteArray, val rpId: String?, val allowCredentialIds: List<String>) {
    override fun toString(): String = "GetRequest(rpId=$rpId)"
}

/**
 * The WebAuthn JSON that Android's Credential Manager hands a provider
 * (PublicKeyCredentialCreationOptionsJSON and PublicKeyCredentialRequestOptionsJSON,
 * binary members in base64url), and the JSON a provider answers with
 * (RegistrationResponseJSON and AuthenticationResponseJSON). Null for a
 * request that does not parse: the provider then offers nothing.
 */
object WebAuthnJson {
    private val json = Json { ignoreUnknownKeys = true }

    fun creation(text: String): CreateRequest? {
        val o = parse(text) ?: return null
        val rp = o["rp"] as? JsonObject
        val user = o["user"] as? JsonObject ?: return null
        val rpId = rp?.str("id") ?: return null
        return CreateRequest(
            rpId = rpId,
            rpName = rp.str("name"),
            userName = user.str("name") ?: return null,
            userDisplayName = user.str("displayName") ?: "",
            userHandle = user.str("id")?.let { runCatching { Encoding.fromBase64Url(it) }.getOrNull() },
            algorithms = (o["pubKeyCredParams"] as? JsonArray)?.mapNotNull { p ->
                ((p as? JsonObject)?.get("alg") as? JsonPrimitive)?.longOrNull
            } ?: emptyList(),
            excludeCredentialIds = ids(o["excludeCredentials"]),
        )
    }

    /** The creation request's challenge, for the clientDataJSON Keepiq builds itself. */
    fun challenge(text: String): ByteArray? = parse(text)?.str("challenge")?.let { runCatching { Encoding.fromBase64Url(it) }.getOrNull() }

    fun get(text: String): GetRequest? {
        val o = parse(text) ?: return null
        val challenge = o.str("challenge")?.let { runCatching { Encoding.fromBase64Url(it) }.getOrNull() } ?: return null
        return GetRequest(challenge, o.str("rpId"), ids(o["allowCredentials"]))
    }

    fun registrationResponse(r: WebAuthn.Registration): String = buildJsonObject {
        val id = Encoding.toBase64Url(r.credentialId)
        put("id", id)
        put("rawId", id)
        put("type", "public-key")
        put("authenticatorAttachment", "platform")
        put(
            "response",
            buildJsonObject {
                // A caller that sent only the hash built the clientDataJSON itself and uses its own.
                put("clientDataJSON", r.clientDataJSON?.let { Encoding.toBase64Url(it) } ?: "")
                put("attestationObject", Encoding.toBase64Url(r.attestationObject))
                put("transports", buildJsonArray { add(JsonPrimitive("internal")); add(JsonPrimitive("hybrid")) })
                put("authenticatorData", Encoding.toBase64Url(r.authenticatorData))
                put("publicKey", Encoding.toBase64Url(r.publicKeySpki))
                put("publicKeyAlgorithm", PasskeyCredential.ES256)
            },
        )
        put("clientExtensionResults", buildJsonObject {})
    }.toString()

    fun assertionResponse(a: WebAuthn.Assertion): String = buildJsonObject {
        val id = Encoding.toBase64Url(a.rawId)
        put("id", id)
        put("rawId", id)
        put("type", "public-key")
        put("authenticatorAttachment", "platform")
        put(
            "response",
            buildJsonObject {
                put("clientDataJSON", a.clientDataJSON?.let { Encoding.toBase64Url(it) } ?: "")
                put("authenticatorData", Encoding.toBase64Url(a.authenticatorData))
                put("signature", Encoding.toBase64Url(a.signature))
                a.userHandle?.let { put("userHandle", Encoding.toBase64Url(it)) }
            },
        )
        put("clientExtensionResults", buildJsonObject {})
    }.toString()

    private fun parse(text: String): JsonObject? = runCatching { json.parseToJsonElement(text) as? JsonObject }.getOrNull()

    /** Credential ids as the stored record writes them: base64url without padding. */
    private fun ids(element: kotlinx.serialization.json.JsonElement?): List<String> =
        (element as? JsonArray)?.mapNotNull { (it as? JsonObject)?.str("id") }?.map { id ->
            runCatching { Encoding.toBase64Url(Encoding.fromBase64Url(id.trimEnd('='))) }.getOrDefault(id)
        } ?: emptyList()

    private fun JsonObject.str(name: String): String? = (this[name] as? JsonPrimitive)?.takeIf { it.isString }?.contentOrNull
}
