// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.crypto

import kotlinx.serialization.json.Json
import kotlinx.serialization.json.buildJsonArray
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import nl.conduction.keepiq.shared.vectors.Vectors
import nl.conduction.keepiq.shared.vectors.arr
import nl.conduction.keepiq.shared.vectors.obj
import nl.conduction.keepiq.shared.vectors.objects
import nl.conduction.keepiq.shared.vectors.str
import java.io.File
import kotlin.test.Test
import kotlin.test.assertTrue

/**
 * The reverse direction (task 1.4): this core encrypts the vector inputs and
 * writes the result for tests/vitest/crypto-vectors-kotlin.spec.js, which
 * opens it with src/crypto/rsa.js, src/crypto/aes.js and src/send/sendCrypto.js.
 */
class KotlinVectorsWriterTest {
    private val json = Json { prettyPrint = true; prettyPrintIndent = "\t" }

    @Test
    fun writeCiphertextForTheWebApp() {
        val out = File(System.getProperty("keepiq.kotlinVectorsOut") ?: "build/vectors/kotlin-output.json")
        val envelope = Vectors.envelope
        val publicKey = RsaPublicKey.fromPem(envelope.str("publicKeyPem"))
        val certificateKey = RsaPublicKey.fromPem(envelope.str("certificatePem"))
        val fieldCases = Vectors.fields.arr("cases").objects()
            .filter { it.str("name") != "leading byte order mark" }

        val plain = SendCrypto.sealPayload("Kotlin → web: één Send 🔐")
        val pwPayload = "Kotlin password send"
        val pwSealed = SendCrypto.sealPayload(pwPayload)
        val wrap = SendCrypto.wrapKey(pwSealed.rawKey, "web opens this")
        val masterPassword = "Kotlin master – wachtwoord ✓"

        val doc = buildJsonObject {
            put(
                "description",
                "Written by mobile/shared (KotlinVectorsWriterTest) for the inputs in tests/vectors/crypto. " +
                    "Fields are encrypted to envelope.json publicKeyPem; open them with its privateKeyPem.",
            )
            put(
                "fields",
                buildJsonArray {
                    for (c in fieldCases) {
                        add(
                            buildJsonObject {
                                put("name", c.str("name"))
                                put("plaintext", c.str("plaintext"))
                                put("ciphertext", RsaFields.encrypt(c.str("plaintext"), publicKey))
                            },
                        )
                    }
                    add(
                        buildJsonObject {
                            put("name", "encrypted to the X.509 certificate")
                            put("plaintext", "certificate path")
                            put("ciphertext", RsaFields.encrypt("certificate path", certificateKey))
                        },
                    )
                },
            )
            put(
                "envelope",
                buildJsonObject {
                    put("password", masterPassword)
                    put("privateKeyPem", envelope.str("privateKeyPem"))
                    put("envelope", PrivateKeyEnvelope.seal(envelope.str("privateKeyPem"), masterPassword))
                },
            )
            put(
                "sendWithoutPassword",
                buildJsonObject {
                    put("payload", "Kotlin → web: één Send 🔐")
                    put("encryptedPayload", plain.encryptedPayload)
                    put("link", SendCrypto.sendLink("https://cloud.example.nl/apps/keepiq/public", "tok", plain.rawKey))
                },
            )
            put(
                "sendWithPassword",
                buildJsonObject {
                    put("payload", pwPayload)
                    put("password", "web opens this")
                    put("encryptedPayload", pwSealed.encryptedPayload)
                    put("wrappedKey", wrap.wrappedKey)
                    put("argon2idSalt", wrap.argon2idSalt)
                },
            )
            val pk = Vectors.passkey
            val credential = PasskeyCredential.parse(pk.str("itemJson")) { "unused" }!!
            val assertion = WebAuthn.getAssertion(
                Encoding.fromBase64Url(pk.str("challengeBase64Url")),
                credential.rpId,
                pk.str("origin"),
                credential,
            )
            put(
                "passkeyAssertion",
                buildJsonObject {
                    put("publicKeySpki", pk.str("publicKeySpki"))
                    put("clientDataJSON", Encoding.toBase64(assertion.clientDataJSON!!))
                    put("authenticatorData", Encoding.toBase64(assertion.authenticatorData))
                    put("signatureDer", Encoding.toBase64(assertion.signature))
                    put("counter", assertion.counter)
                },
            )
            // Task 5.3: a passkey the core creates, for the extension to sign with,
            // and an assertion with the passkey the extension created.
            val challenge = Encoding.fromBase64Url(pk.str("challengeBase64Url"))
            val created = WebAuthn.createCredential(
                rpId = "login.example.nl",
                rpName = "Example Login",
                userName = "bob@example.nl",
                userDisplayName = "Bob Jansen",
                userHandle = Encoding.utf8("user-kotlin"),
                algorithms = listOf(-7L, -257L),
                clientData = ClientData.build(ClientData.CREATE, challenge, pk.str("origin")),
                createdAt = "2026-10-05T10:00:00.000Z",
            )
            put(
                "passkeyRegistration",
                buildJsonObject {
                    put("origin", pk.str("origin"))
                    put("challengeBase64Url", pk.str("challengeBase64Url"))
                    put("itemJson", created.record.toJson())
                    put("clientDataJSON", Encoding.toBase64(created.clientDataJSON!!))
                    put("attestationObject", Encoding.toBase64(created.attestationObject))
                },
            )
            val fromExtension = PasskeyCredential.parse(pk.obj("registration").str("itemJson")) { "unused" }!!
            val signed = WebAuthn.getAssertion(challenge, fromExtension.rpId, pk.str("origin"), fromExtension)
            put(
                "passkeyFromExtension",
                buildJsonObject {
                    put("clientDataJSON", Encoding.toBase64(signed.clientDataJSON!!))
                    put("authenticatorData", Encoding.toBase64(signed.authenticatorData))
                    put("signatureDer", Encoding.toBase64(signed.signature))
                },
            )
        }
        out.parentFile.mkdirs()
        out.writeText(json.encodeToString(kotlinx.serialization.json.JsonObject.serializer(), doc) + "\n")
        assertTrue(out.length() > 0)
    }
}
