// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.crypto

import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonArray
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.intOrNull
import kotlinx.serialization.json.longOrNull
import kotlinx.serialization.json.put

/**
 * The passkey item: one WebAuthn credential as JSON in the encrypted `key`
 * field of a `passkey` item. Field order, defaults and the required fields
 * follow src/passkey/passkey.js (buildPasskeyCredential).
 */
data class PasskeyCredential(
    val credentialId: String,
    val rpId: String,
    val rpName: String,
    val userName: String,
    val userDisplayName: String,
    val userHandle: String,
    val privateKey: String,
    val algorithm: Long,
    val counter: Long,
    val transports: List<String>,
    val createdAt: String,
) {
    /** serializePasskey: the JSON stored, encrypted, in the item's `key` field. */
    fun toJson(): String = buildJsonObject {
        put("credentialId", credentialId)
        put("rpId", rpId)
        put("rpName", rpName)
        put("userName", userName)
        put("userDisplayName", userDisplayName)
        put("userHandle", userHandle)
        put("privateKey", privateKey)
        put("algorithm", algorithm)
        put("counter", counter)
        put("transports", buildJsonArray { transports.forEach { add(JsonPrimitive(it)) } })
        put("createdAt", createdAt)
    }.toString()

    companion object {
        const val TYPE_NAME = "passkey"
        const val ES256 = -7L

        /**
         * parsePasskey: null when the JSON does not parse or a required field
         * (credentialId, rpId, privateKey) is missing or empty. [now] fills an
         * absent createdAt, as the web app does with the current time.
         */
        fun parse(json: String, now: () -> String): PasskeyCredential? {
            val obj = try {
                Json.parseToJsonElement(json) as? JsonObject
            } catch (e: Exception) {
                null
            } ?: return null
            fun str(name: String): String? = (obj[name] as? JsonPrimitive)?.takeIf { it.isString }?.content
            fun loose(name: String): String {
                val p = obj[name] as? JsonPrimitive ?: return ""
                return if (p.isString) p.content else p.toString()
            }
            val credentialId = str("credentialId")?.ifEmpty { null } ?: return null
            val rpId = str("rpId")?.ifEmpty { null } ?: return null
            val privateKey = str("privateKey")?.ifEmpty { null } ?: return null
            fun integer(name: String): Long? = (obj[name] as? JsonPrimitive)?.takeIf { !it.isString }?.let {
                it.longOrNull ?: it.intOrNull?.toLong()
            }
            return PasskeyCredential(
                credentialId = credentialId,
                rpId = rpId,
                rpName = loose("rpName"),
                userName = loose("userName"),
                userDisplayName = loose("userDisplayName"),
                userHandle = loose("userHandle"),
                privateKey = privateKey,
                algorithm = integer("algorithm") ?: ES256,
                counter = integer("counter") ?: 0L,
                transports = (obj["transports"] as? JsonArray)?.map {
                    (it as? JsonPrimitive)?.let { p -> if (p.isString) p.content else p.toString() } ?: it.toString()
                } ?: emptyList(),
                createdAt = str("createdAt")?.ifEmpty { null } ?: now(),
            )
        }
    }
}

/**
 * WebAuthn assertion with a stored ES256 passkey, byte for byte as
 * browser-extension/src/passkey/webauthn.js getAssertion builds it:
 * clientDataJSON `{"type":"webauthn.get","challenge":…,"origin":…,"crossOrigin":false}`,
 * authenticator data SHA-256(rpId) + flags UP|UV + uint32 counter, and a DER
 * ECDSA P-256 signature over authenticatorData + SHA-256(clientDataJSON).
 * A stored counter of 0 stays 0; a non-zero counter goes up by one.
 */
object WebAuthn {
    private const val FLAG_UP = 0x01
    private const val FLAG_UV = 0x04

    class Assertion(
        val clientDataJSON: ByteArray,
        val authenticatorData: ByteArray,
        val signature: ByteArray,
        val userHandle: ByteArray?,
        val rawId: ByteArray,
        /** The counter to write back to the item (unchanged when it was 0). */
        val counter: Long,
    )

    /**
     * Signs [challenge] for [rpId] at [origin]. The rpId MUST come from the
     * operating system's verified origin, never from page content (design D7).
     */
    fun getAssertion(challenge: ByteArray, rpId: String, origin: String, stored: PasskeyCredential): Assertion {
        if (stored.algorithm != PasskeyCredential.ES256) {
            throw KeepiqCryptoException("unsupported-algorithm")
        }
        val clientData = "{\"type\":\"webauthn.get\",\"challenge\":${JsonPrimitive(Encoding.toBase64Url(challenge))}," +
            "\"origin\":${JsonPrimitive(origin)},\"crossOrigin\":false}"
        val clientDataJSON = Encoding.utf8(clientData)
        val nextCounter = if (stored.counter > 0) stored.counter + 1 else 0L
        val authData = authenticatorData(rpId, FLAG_UP or FLAG_UV, nextCounter)
        val signature = Primitives.ecdsaP256Sign(
            pkcs8FromPem(stored.privateKey),
            authData + Primitives.sha256(clientDataJSON),
        )
        return Assertion(
            clientDataJSON = clientDataJSON,
            authenticatorData = authData,
            signature = signature,
            userHandle = stored.userHandle.takeIf { it.isNotEmpty() }?.let { Encoding.fromBase64Url(it) },
            rawId = Encoding.fromBase64Url(stored.credentialId),
            counter = nextCounter,
        )
    }

    /** Verifies a DER ES256 assertion signature with a DER SPKI public key. */
    fun verify(spki: ByteArray, authenticatorData: ByteArray, clientDataJSON: ByteArray, signature: ByteArray): Boolean =
        Primitives.ecdsaP256Verify(spki, authenticatorData + Primitives.sha256(clientDataJSON), signature)

    internal fun authenticatorData(rpId: String, flags: Int, counter: Long): ByteArray =
        Primitives.sha256(Encoding.utf8(rpId)) + byteArrayOf(flags.toByte()) + Encoding.uint32BigEndian(counter)

    internal fun pkcs8FromPem(pem: String): ByteArray = Encoding.fromBase64(
        pem.replace(Regex("-----BEGIN [^-]+-----"), "")
            .replace(Regex("-----END [^-]+-----"), "")
            .filterNot { it.isWhitespace() },
    )
}
