// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.crypto

import kotlinx.serialization.json.jsonPrimitive
import nl.conduction.keepiq.shared.vectors.Vectors
import nl.conduction.keepiq.shared.vectors.arr
import nl.conduction.keepiq.shared.vectors.num
import nl.conduction.keepiq.shared.vectors.obj
import nl.conduction.keepiq.shared.vectors.objects
import nl.conduction.keepiq.shared.vectors.str
import kotlin.test.Test
import kotlin.test.assertContentEquals
import kotlin.test.assertEquals
import kotlin.test.assertFailsWith
import kotlin.test.assertNotNull
import kotlin.test.assertTrue

/**
 * The phone reads what the web app wrote (mobile-shared-core spec, scenario
 * "The phone reads what the web app wrote"). Every value comes from
 * tests/vectors/crypto, produced by the web app's own modules.
 */
class CryptoVectorsTest {
    private val envelope = Vectors.envelope
    private val privateKey by lazy { RsaPrivateKey.fromPem(envelope.str("privateKeyPem")) }

    @Test
    fun envelopeOpensWithItsPassword() {
        assertEquals(envelope.str("privateKeyPem"), PrivateKeyEnvelope.open(envelope.str("envelope"), envelope.str("password")))
    }

    @Test
    fun unlockKeyMatchesAndOpensTheEnvelope() {
        val parts = PrivateKeyEnvelope.decode(envelope.str("envelope"))
        val key = PrivateKeyEnvelope.deriveUnlockKey(envelope.str("password"), parts.salt)
        assertEquals(envelope.str("unlockKeyHex"), Encoding.hex(key))
        assertEquals(envelope.str("privateKeyPem"), PrivateKeyEnvelope.openWithUnlockKey(envelope.str("envelope"), key))
    }

    @Test
    fun envelopeRefusesTheWrongPassword() {
        assertFailsWith<KeepiqCryptoException> {
            PrivateKeyEnvelope.open(envelope.str("envelope"), envelope.str("wrongPassword"))
        }
    }

    @Test
    fun envelopeRefusesAnUnknownVersion() {
        val e = assertFailsWith<UnsupportedEnvelopeVersionException> {
            PrivateKeyEnvelope.open(envelope.str("unsupportedVersionEnvelope"), envelope.str("password"))
        }
        assertEquals(2L, e.version)
    }

    @Test
    fun everyFieldCiphertextDecrypts() {
        val cases = Vectors.fields.arr("cases").objects()
        assertTrue(cases.size >= 7)
        for (c in cases) {
            val raw = Encoding.fromBase64(c.str("ciphertext"))
            assertEquals(c.num("chunkCount"), Encoding.readUint32BigEndian(raw, 0), c.str("name"))
            assertEquals(c.str("decryptsTo"), RsaFields.decrypt(c.str("ciphertext"), privateKey), c.str("name"))
        }
    }

    @Test
    fun fieldsRoundTripThroughTheKeyAndTheCertificate() {
        val text = "a".repeat(445) + "é" + "漢字🔐".repeat(200)
        for (pem in listOf(envelope.str("publicKeyPem"), envelope.str("certificatePem"))) {
            val ciphertext = RsaFields.encrypt(text, RsaPublicKey.fromPem(pem))
            val raw = Encoding.fromBase64(ciphertext)
            val chunks = (Encoding.utf8(text).size + 445) / 446
            assertEquals(chunks.toLong(), Encoding.readUint32BigEndian(raw, 0))
            assertEquals(4 + 512 * chunks, raw.size)
            assertEquals(text, RsaFields.decrypt(ciphertext, privateKey))
        }
        // The core chunks like the web app: the same chunk count for every vector plaintext.
        val publicKey = RsaPublicKey.fromPem(envelope.str("publicKeyPem"))
        for (c in Vectors.fields.arr("cases").objects()) {
            val mine = Encoding.fromBase64(RsaFields.encrypt(c.str("plaintext"), publicKey))
            assertEquals(c.num("chunkCount"), Encoding.readUint32BigEndian(mine, 0), c.str("name"))
        }
        val empty = RsaFields.encrypt("", RsaPublicKey.fromPem(envelope.str("publicKeyPem")))
        assertEquals(4 + 512, Encoding.fromBase64(empty).size)
        assertEquals("", RsaFields.decrypt(empty, privateKey))
    }

    @Test
    fun sendWithoutPasswordOpensAndBuildsTheSameLink() {
        val v = Vectors.send.obj("withoutPassword")
        val rawKey = Encoding.fromBase64Url(v.str("rawKeyBase64Url"))
        assertEquals(v.str("payload"), SendCrypto.openPayload(v.str("encryptedPayload"), rawKey))
        assertEquals(v.str("link"), SendCrypto.sendLink(v.str("publicBase"), v.str("token"), rawKey))
        assertEquals(v.str("linkWithoutKey"), SendCrypto.sendLink(v.str("publicBase"), v.str("token"), null))
    }

    @Test
    fun sendWithPasswordUnwrapsAndOpens() {
        val v = Vectors.send.obj("withPassword")
        if (!argon2idAvailable) {
            assertFailsWith<UnsupportedOperationException> {
                SendCrypto.unwrapKey(v.str("wrappedKey"), v.str("argon2idSalt"), v.str("password"))
            }
            return
        }
        val rawKey = SendCrypto.unwrapKey(v.str("wrappedKey"), v.str("argon2idSalt"), v.str("password"))
        assertEquals(v.str("rawKeyBase64Url"), Encoding.toBase64Url(rawKey))
        assertEquals(v.str("payload"), SendCrypto.openPayload(v.str("encryptedPayload"), rawKey))
        assertFailsWith<KeepiqCryptoException> {
            SendCrypto.unwrapKey(v.str("wrappedKey"), v.str("argon2idSalt"), v.str("password") + "x")
        }
    }

    @Test
    fun argon2idKnownAnswer() {
        if (!argon2idAvailable) return
        val v = Vectors.send.obj("argon2idKnownAnswer")
        val salt = ByteArray(16) { 1 }
        assertEquals(Encoding.hex(salt), v.str("saltHex"))
        val key = argon2id(
            Encoding.utf8(v.str("password")),
            salt,
            v.num("memoryKiB").toInt(),
            v.num("iterations").toInt(),
            v.num("parallelism").toInt(),
            32,
        )
        assertEquals(v.str("keyHex"), Encoding.hex(key))
    }

    @Test
    fun everyTotpCodeMatches() {
        val cases = Vectors.totp.arr("cases").objects()
        val seen = mutableSetOf<Pair<String, Int>>()
        for (c in cases) {
            val params = Totp.parse(c.str("uri"))
            assertEquals(c.str("algorithm"), params.algorithm, c.str("uri"))
            assertEquals(c.num("digits").toInt(), params.digits, c.str("uri"))
            assertEquals(c.num("period").toInt(), params.period, c.str("uri"))
            assertEquals(c.str("code"), Totp.generate(params, c.num("epochMs")), "${c.str("uri")} at ${c.num("epochMs")}")
            seen += params.algorithm to params.digits
        }
        for (alg in listOf("SHA1", "SHA256", "SHA512")) {
            for (digits in listOf(6, 8)) assertTrue((alg to digits) in seen, "$alg/$digits covered")
        }
    }

    @Test
    fun totpRefusesWhatTheWebAppRefuses() {
        for (r in Vectors.totp.arr("refused").objects()) {
            assertFailsWith<KeepiqCryptoException>(r.str("uri")) { Totp.parse(r.str("uri")) }
        }
    }

    @Test
    fun passkeyItemJsonIsStable() {
        val json = Vectors.passkey.str("itemJson")
        val credential = assertNotNull(PasskeyCredential.parse(json) { "unused" })
        assertEquals(json, credential.toJson())
    }

    @Test
    fun webAssertionsVerifyAndKotlinBuildsTheSameBytes() {
        val v = Vectors.passkey
        val spki = Encoding.fromBase64(v.str("publicKeySpki"))
        val credential = assertNotNull(PasskeyCredential.parse(v.str("itemJson")) { "unused" })
        val challenge = Encoding.fromBase64Url(v.str("challengeBase64Url"))
        for (a in v.arr("assertions").objects()) {
            val authData = Encoding.fromBase64(a.str("authenticatorData"))
            val clientData = Encoding.fromBase64(a.str("clientDataJSON"))
            assertTrue(WebAuthn.verify(spki, authData, clientData, Encoding.fromBase64(a.str("signatureDer"))))

            val stored = credential.copy(counter = a.num("storedCounter"))
            val mine = WebAuthn.getAssertion(challenge, stored.rpId, v.str("origin"), stored)
            assertContentEquals(clientData, mine.clientDataJSON)
            assertContentEquals(authData, mine.authenticatorData)
            assertContentEquals(Encoding.fromBase64(a.str("userHandle")), mine.userHandle)
            assertContentEquals(Encoding.fromBase64(a.str("rawId")), mine.rawId)
            assertEquals(a.num("nextCounter"), mine.counter)
            assertTrue(WebAuthn.verify(spki, mine.authenticatorData, mine.clientDataJSON, mine.signature))
            // DER: SEQUENCE of two INTEGERs.
            assertEquals(0x30, mine.signature[0].toInt() and 0xFF)
        }
    }

    @Test
    fun aTamperedSignatureDoesNotVerify() {
        val v = Vectors.passkey
        val a = v.arr("assertions").objects().first()
        val authData = Encoding.fromBase64(a.str("authenticatorData"))
        authData[authData.size - 1] = (authData[authData.size - 1] + 1).toByte()
        val ok = WebAuthn.verify(
            Encoding.fromBase64(v.str("publicKeySpki")),
            authData,
            Encoding.fromBase64(a.str("clientDataJSON")),
            Encoding.fromBase64(a.str("signatureDer")),
        )
        assertEquals(false, ok)
        assertEquals(v.getValue("origin").jsonPrimitive.content, "https://login.example.nl")
    }
}
