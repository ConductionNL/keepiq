// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.crypto

import nl.conduction.keepiq.shared.send.IsoTime
import nl.conduction.keepiq.shared.vectors.Vectors
import nl.conduction.keepiq.shared.vectors.obj
import nl.conduction.keepiq.shared.vectors.str
import kotlin.test.Test
import kotlin.test.assertContentEquals
import kotlin.test.assertEquals
import kotlin.test.assertFailsWith
import kotlin.test.assertNotNull
import kotlin.test.assertNull
import kotlin.test.assertTrue

/**
 * Passkey creation in the core (tasks 5.1 to 5.3), against the rules of
 * browser-extension/src/passkey/webauthn.js createCredential: ES256 on
 * P-256, the all-zero AAGUID, `none` attestation, a 16-byte credential id,
 * flags UP, UV and AT, and the passkey item JSON the web app reads. The
 * extension's own registration (passkey.json `registration`) is rebuilt
 * byte for byte from its parts.
 */
class PasskeyCreateTest {
    private val challenge = ByteArray(32) { (it * 3).toByte() }
    private val origin = "https://login.example.nl"

    private fun create(algorithms: List<Long> = listOf(-7L, -257L), clientData: ClientData = ClientData.build(ClientData.CREATE, challenge, origin)) =
        WebAuthn.createCredential(
            rpId = "login.example.nl",
            rpName = "",
            userName = "alice@example.nl",
            userDisplayName = "Alice de Vries",
            userHandle = Encoding.utf8("user-7f3a"),
            algorithms = algorithms,
            clientData = clientData,
            createdAt = "2026-10-05T10:00:00.000Z",
        )

    @Test
    fun aNewPasskeyFollowsTheExtensionsShape() {
        val r = create()
        assertEquals(16, r.credentialId.size)
        val a = r.authenticatorData
        assertContentEquals(Primitives.sha256(Encoding.utf8("login.example.nl")), a.copyOfRange(0, 32))
        assertEquals(0x45, a[32].toInt() and 0xFF, "flags UP, UV and AT")
        assertContentEquals(ByteArray(4), a.copyOfRange(33, 37), "counter 0")
        assertContentEquals(ByteArray(16), a.copyOfRange(37, 53), "all-zero AAGUID")
        assertContentEquals(byteArrayOf(0, 16), a.copyOfRange(53, 55))
        assertContentEquals(r.credentialId, a.copyOfRange(55, 71))
        val point = EcKeys.pointOfSpki(r.publicKeySpki)
        assertContentEquals(EcKeys.cose(point), a.copyOfRange(71, a.size))
        assertEquals(148, a.size)

        // CBOR {fmt: "none", attStmt: {}, authData} in the extension's order.
        val head = Encoding.hex(r.attestationObject.copyOfRange(0, 30))
        assertEquals("a363666d74646e6f6e656761747453746d74a068617574684461746158" + "94", head)
        assertContentEquals(a, r.attestationObject.copyOfRange(30, r.attestationObject.size))

        // The item JSON the web app parses, with a PKCS#8 that carries its public key.
        val record = r.record
        assertEquals(Encoding.toBase64Url(r.credentialId), record.credentialId)
        assertEquals("login.example.nl", record.rpName, "an empty rpName falls back to the rpId")
        assertEquals(Encoding.toBase64Url(Encoding.utf8("user-7f3a")), record.userHandle)
        assertEquals(0L, record.counter)
        assertEquals(PasskeyCredential.ES256, record.algorithm)
        assertEquals(record, PasskeyCredential.parse(record.toJson()) { "unused" })
        assertTrue(record.privateKey.startsWith("-----BEGIN PRIVATE KEY-----\n") && record.privateKey.endsWith("-----END PRIVATE KEY-----\n"))
        val (_, embedded) = assertNotNull(EcKeys.parsePkcs8(WebAuthn.pkcs8FromPem(record.privateKey)))
        assertContentEquals(point, embedded)

        val clientData = assertNotNull(r.clientDataJSON)
        assertEquals(
            "{\"type\":\"webauthn.create\",\"challenge\":\"${Encoding.toBase64Url(challenge)}\",\"origin\":\"$origin\",\"crossOrigin\":false}",
            Encoding.fromUtf8(clientData),
        )
    }

    @Test
    fun thePasskeySignsAndTheSignatureVerifiesWithItsPublicKey() {
        val r = create()
        val assertion = WebAuthn.getAssertion(ByteArray(32) { 9 }, "login.example.nl", origin, r.record)
        assertTrue(WebAuthn.verify(r.publicKeySpki, assertion.authenticatorData, assertion.clientDataJSON!!, assertion.signature))
        assertEquals(0L, assertion.counter)
        assertContentEquals(r.credentialId, assertion.rawId)
        assertContentEquals(Encoding.utf8("user-7f3a"), assertion.userHandle)
    }

    @Test
    fun aHashFromTheCallerIsSignedAsGiven() {
        val hash = Primitives.sha256(Encoding.utf8("the browser's own clientDataJSON"))
        val r = create(clientData = ClientData.hashed(hash))
        assertNull(r.clientDataJSON)
        val stored = r.record.copy(counter = 4)
        val assertion = WebAuthn.getAssertion(ClientData.hashed(hash), "login.example.nl", stored)
        assertNull(assertion.clientDataJSON)
        assertEquals(5L, assertion.counter)
        assertContentEquals(Encoding.uint32BigEndian(5), assertion.authenticatorData.copyOfRange(33, 37))
        assertTrue(WebAuthn.verifyHash(r.publicKeySpki, assertion.authenticatorData, hash, assertion.signature))
    }

    @Test
    fun aRequestForAnotherAlgorithmIsDeclined() {
        val e = assertFailsWith<KeepiqCryptoException> { create(algorithms = listOf(-257L)) }
        assertEquals(WebAuthn.UNSUPPORTED_ALGORITHM, e.message)
        assertTrue(WebAuthn.supports(emptyList()), "no list means the default, which includes ES256")
    }

    @Test
    fun theExtensionsRegistrationIsRebuiltByteForByte() {
        val v = Vectors.passkey.obj("registration")
        val record = assertNotNull(PasskeyCredential.parse(v.str("itemJson")) { "unused" })
        val (_, point) = assertNotNull(EcKeys.parsePkcs8(WebAuthn.pkcs8FromPem(record.privateKey)))
        val credentialId = Encoding.fromBase64Url(record.credentialId)
        assertEquals(16, credentialId.size)
        val authData = WebAuthn.authenticatorData(record.rpId, 0x45, 0L) + WebAuthn.attestedCredentialData(credentialId, assertNotNull(point))
        assertContentEquals(Encoding.fromBase64(v.str("attestationObject")), WebAuthn.attestationObject(authData))

        // The phone signs with the key the extension made, and the extension's public key verifies it.
        val clientData = Encoding.fromBase64(v.str("clientDataJSON"))
        assertTrue(Encoding.fromUtf8(clientData).startsWith("{\"type\":\"webauthn.create\""))
        val assertion = WebAuthn.getAssertion(ByteArray(32) { 1 }, record.rpId, v.str("origin"), record)
        assertTrue(WebAuthn.verify(EcKeys.spki(point), assertion.authenticatorData, assertion.clientDataJSON!!, assertion.signature))
    }

    @Test
    fun isoTimeIsWrittenAsJavaScriptWritesIt() {
        assertEquals("1970-01-01T00:00:00.000Z", IsoTime.format(0))
        assertEquals("2024-02-29T23:59:59.999Z", IsoTime.format(IsoTime.parseMillis("2024-02-29T23:59:59.999Z")!!))
        assertEquals("2026-10-05T10:00:00.050Z", IsoTime.format(IsoTime.parseMillis("2026-10-05T12:00:00.05+02:00")!!))
    }
}
