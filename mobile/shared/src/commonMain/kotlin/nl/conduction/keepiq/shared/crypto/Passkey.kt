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
 * The client data a WebAuthn signature covers. Built here as the browser
 * extension builds it when the caller sends a challenge and Keepiq knows the
 * verified origin (an Android app); or only its hash, when the caller built
 * the JSON itself (a browser on Android, and every request on iOS).
 */
class ClientData private constructor(
    /** The clientDataJSON, or null when the caller sent only its hash. */
    val json: ByteArray?,
    /** SHA-256 of the clientDataJSON: what the signature covers. */
    val hash: ByteArray,
) {
    companion object {
        /**
         * `{"type":…,"challenge":…,"origin":…,"crossOrigin":false}`, the
         * member order and spelling of webauthn.js. [origin] MUST be the
         * origin the operating system verified, never one from the request.
         */
        fun build(type: String, challenge: ByteArray, origin: String): ClientData {
            val text = "{\"type\":${JsonPrimitive(type)},\"challenge\":${JsonPrimitive(Encoding.toBase64Url(challenge))}," +
                "\"origin\":${JsonPrimitive(origin)},\"crossOrigin\":false}"
            val json = Encoding.utf8(text)
            return ClientData(json, Primitives.sha256(json))
        }

        /** The hash the caller computed over its own clientDataJSON. */
        fun hashed(hash: ByteArray): ClientData {
            require(hash.size == 32) { "clientDataHash must be 32 bytes" }
            return ClientData(null, hash.copyOf())
        }

        const val GET = "webauthn.get"
        const val CREATE = "webauthn.create"
    }
}

/**
 * WebAuthn with an ES256 passkey, byte for byte as
 * browser-extension/src/passkey/webauthn.js builds it.
 *
 * - Assertion: clientDataJSON `{"type":"webauthn.get","challenge":…,"origin":…,"crossOrigin":false}`,
 *   authenticator data SHA-256(rpId) + flags UP|UV + uint32 counter, and a DER
 *   ECDSA P-256 signature over authenticatorData + SHA-256(clientDataJSON).
 *   A stored counter of 0 stays 0; a non-zero counter goes up by one.
 * - Creation (createCredential): a new P-256 key, a 16-byte random
 *   credential id, the all-zero AAGUID, flags UP|UV|AT, counter 0 and a
 *   `none` attestation object.
 */
object WebAuthn {
    private const val FLAG_UP = 0x01
    private const val FLAG_UV = 0x04
    private const val FLAG_AT = 0x40
    private val AAGUID = ByteArray(16)
    const val CREDENTIAL_ID_BYTES = 16

    class Assertion(
        /** The clientDataJSON signed over, or null when the caller sent only its hash. */
        val clientDataJSON: ByteArray?,
        val authenticatorData: ByteArray,
        val signature: ByteArray,
        val userHandle: ByteArray?,
        val rawId: ByteArray,
        /** The counter to write back to the item (unchanged when it was 0). */
        val counter: Long,
    )

    /** A new passkey: the item to save, and what the caller gets back. */
    class Registration(
        /** The passkey item JSON (PasskeyCredential.toJson) to save as a `passkey` item. */
        val record: PasskeyCredential,
        val credentialId: ByteArray,
        /** The clientDataJSON, or null when the caller sent only its hash. */
        val clientDataJSON: ByteArray?,
        val authenticatorData: ByteArray,
        /** CBOR `{fmt: "none", attStmt: {}, authData}`. */
        val attestationObject: ByteArray,
        /** DER SubjectPublicKeyInfo of the new key. */
        val publicKeySpki: ByteArray,
    )

    /** Thrown for a request that names only algorithms other than ES256: the caller declines so another provider can answer. */
    const val UNSUPPORTED_ALGORITHM = "unsupported-algorithm"

    /** Whether a request's pubKeyCredParams allow ES256. An empty list allows the default, which includes it. */
    fun supports(algorithms: List<Long>): Boolean = algorithms.isEmpty() || PasskeyCredential.ES256 in algorithms

    /**
     * Signs [challenge] for [rpId] at [origin]. The rpId MUST come from the
     * operating system's verified origin, never from page content (design D7).
     */
    fun getAssertion(challenge: ByteArray, rpId: String, origin: String, stored: PasskeyCredential): Assertion =
        getAssertion(ClientData.build(ClientData.GET, challenge, origin), rpId, stored)

    /** Signs [clientData] for [rpId] with the stored key. */
    fun getAssertion(clientData: ClientData, rpId: String, stored: PasskeyCredential): Assertion {
        if (stored.algorithm != PasskeyCredential.ES256) {
            throw KeepiqCryptoException(UNSUPPORTED_ALGORITHM)
        }
        val nextCounter = if (stored.counter > 0) stored.counter + 1 else 0L
        val authData = authenticatorData(rpId, FLAG_UP or FLAG_UV, nextCounter)
        val signature = Primitives.ecdsaP256Sign(pkcs8FromPem(stored.privateKey), authData + clientData.hash)
        return Assertion(
            clientDataJSON = clientData.json,
            authenticatorData = authData,
            signature = signature,
            userHandle = stored.userHandle.takeIf { it.isNotEmpty() }?.let { Encoding.fromBase64Url(it) },
            rawId = Encoding.fromBase64Url(stored.credentialId),
            counter = nextCounter,
        )
    }

    /**
     * Creates a passkey for [rpId], as createCredential in webauthn.js does.
     * Throws [KeepiqCryptoException] with [UNSUPPORTED_ALGORITHM] when
     * [algorithms] does not allow ES256. [createdAt] is the ISO time the
     * web app's buildPasskeyCredential would fill in.
     */
    fun createCredential(
        rpId: String,
        rpName: String?,
        userName: String,
        userDisplayName: String,
        userHandle: ByteArray?,
        algorithms: List<Long>,
        clientData: ClientData,
        createdAt: String,
    ): Registration {
        if (!supports(algorithms)) throw KeepiqCryptoException(UNSUPPORTED_ALGORITHM)
        val keys = Primitives.ecdsaP256Generate()
        val credentialId = Primitives.randomBytes(CREDENTIAL_ID_BYTES)
        val authData = authenticatorData(rpId, FLAG_UP or FLAG_UV or FLAG_AT, 0L) +
            attestedCredentialData(credentialId, keys.point)
        val record = PasskeyCredential(
            credentialId = Encoding.toBase64Url(credentialId),
            rpId = rpId,
            rpName = rpName?.ifEmpty { null } ?: rpId,
            userName = userName,
            userDisplayName = userDisplayName,
            userHandle = userHandle?.let { Encoding.toBase64Url(it) } ?: "",
            privateKey = EcKeys.pem(EcKeys.pkcs8(keys.d, keys.point), "PRIVATE KEY"),
            algorithm = PasskeyCredential.ES256,
            counter = 0L,
            transports = emptyList(),
            createdAt = createdAt,
        )
        keys.d.fill(0)
        return Registration(
            record = record,
            credentialId = credentialId,
            clientDataJSON = clientData.json,
            authenticatorData = authData,
            attestationObject = attestationObject(authData),
            publicKeySpki = EcKeys.spki(keys.point),
        )
    }

    /** Verifies a DER ES256 assertion signature with a DER SPKI public key. */
    fun verify(spki: ByteArray, authenticatorData: ByteArray, clientDataJSON: ByteArray, signature: ByteArray): Boolean =
        verifyHash(spki, authenticatorData, Primitives.sha256(clientDataJSON), signature)

    /** Verifies a DER ES256 signature over authenticatorData + clientDataHash. */
    fun verifyHash(spki: ByteArray, authenticatorData: ByteArray, clientDataHash: ByteArray, signature: ByteArray): Boolean =
        Primitives.ecdsaP256Verify(spki, authenticatorData + clientDataHash, signature)

    /** AAGUID ‖ uint16 length ‖ credentialId ‖ COSE key. */
    internal fun attestedCredentialData(credentialId: ByteArray, point: ByteArray): ByteArray =
        AAGUID + byteArrayOf((credentialId.size shr 8).toByte(), credentialId.size.toByte()) + credentialId + EcKeys.cose(point)

    /** `{fmt: "none", attStmt: {}, authData}` in the extension's key order. */
    internal fun attestationObject(authData: ByteArray): ByteArray = Cbor.encode(
        Cbor.OrderedMap(listOf("fmt" to "none", "attStmt" to Cbor.OrderedMap(emptyList()), "authData" to authData)),
    )

    internal fun authenticatorData(rpId: String, flags: Int, counter: Long): ByteArray =
        Primitives.sha256(Encoding.utf8(rpId)) + byteArrayOf(flags.toByte()) + Encoding.uint32BigEndian(counter)

    internal fun pkcs8FromPem(pem: String): ByteArray = Encoding.fromBase64(
        pem.replace(Regex("-----BEGIN [^-]+-----"), "")
            .replace(Regex("-----END [^-]+-----"), "")
            .filterNot { it.isWhitespace() },
    )
}
