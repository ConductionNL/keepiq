// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.passkey

import io.ktor.client.engine.mock.MockEngine
import io.ktor.client.engine.mock.respond
import io.ktor.http.HttpHeaders
import io.ktor.http.HttpMethod
import io.ktor.http.content.TextContent
import io.ktor.http.headersOf
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import nl.conduction.keepiq.shared.api.Account
import nl.conduction.keepiq.shared.api.KeepiqApi
import nl.conduction.keepiq.shared.autofill.AutofillEntry
import nl.conduction.keepiq.shared.autofill.AutofillIndex
import nl.conduction.keepiq.shared.crypto.ClientData
import nl.conduction.keepiq.shared.crypto.Encoding
import nl.conduction.keepiq.shared.crypto.KeepiqCryptoException
import nl.conduction.keepiq.shared.crypto.PasskeyCredential
import nl.conduction.keepiq.shared.crypto.RsaFields
import nl.conduction.keepiq.shared.crypto.RsaPrivateKey
import nl.conduction.keepiq.shared.crypto.RsaPublicKey
import nl.conduction.keepiq.shared.crypto.WebAuthn
import nl.conduction.keepiq.shared.vault.RsaVaultKeys
import nl.conduction.keepiq.shared.vault.SecretType
import nl.conduction.keepiq.shared.vault.VaultRow
import nl.conduction.keepiq.shared.vault.VaultState
import nl.conduction.keepiq.shared.vectors.Vectors
import nl.conduction.keepiq.shared.vectors.str
import kotlin.test.Test
import kotlin.test.assertContentEquals
import kotlin.test.assertEquals
import kotlin.test.assertFailsWith
import kotlin.test.assertFalse
import kotlin.test.assertNotNull
import kotlin.test.assertTrue

/**
 * The passkey provider's vault side (tasks 5.1 and 5.2) with real keys
 * against a fake server, as orchestrator.js does it: a passkey is found by
 * its rpId, a counter of 4 signs as 5 and the item stores 5, a counter of 0
 * stays 0 and writes nothing, a new passkey is saved as a `passkey` item,
 * and a request that allows only RS256 is declined before anything is sent.
 */
class PasskeysTest {
    private val privatePem = Vectors.envelope.str("privateKeyPem")
    private val keys = RsaVaultKeys.fromPem(privatePem, Vectors.envelope.str("certificatePem"), "suite-9", 2)
    private val publicKey = RsaPublicKey.fromPem(Vectors.envelope.str("publicKeyPem"))
    private val privateKey = RsaPrivateKey.fromPem(privatePem)
    private fun enc(text: String) = RsaFields.encrypt(text, publicKey)
    private fun dec(text: String) = RsaFields.decrypt(text, privateKey)

    private val requests = mutableListOf<Pair<String, String>>()
    private val bodies = mutableListOf<JsonObject>()
    private val engine = MockEngine { request ->
        val path = request.url.encodedPath.substringAfter("/apps/keepiq")
        requests += request.method.value to path
        (request.body as? TextContent)?.let { bodies += Json.parseToJsonElement(it.text).jsonObject }
        val body = when {
            path == "/api/v1/secret-types" -> """[{"id":"7","name":"login","fields":[]},{"id":"9","name":"passkey","fields":[]}]"""
            request.method == HttpMethod.Post -> """{"id":"new"}"""
            else -> """{"id":"p1"}"""
        }
        respond(body, headers = headersOf(HttpHeaders.ContentType, "application/json"))
    }
    private val api = KeepiqApi(KeepiqApi.httpClient(engine), Account("acc", "https://cloud.example.nl", "alice", "app-password"))

    /** A passkey made by the core itself, with its public key to verify against. */
    private fun made(rpId: String, counter: Long): Pair<PasskeyCredential, ByteArray> {
        val r = WebAuthn.createCredential(rpId, "Example", "alice@example.nl", "Alice", Encoding.utf8("u1"), listOf(-7L), ClientData.hashed(ByteArray(32)), "2026-10-05T10:00:00.000Z")
        return r.record.copy(counter = counter) to r.publicKeySpki
    }

    private fun entry(id: String, url: String, record: PasskeyCredential) =
        AutofillEntry(id, "Example", url, AutofillEntry.PASSKEY, null, enc(record.toJson()), false)

    @Test
    fun theIndexKeepsPasskeysAndNeverOffersThemAsPasswords() {
        val types = listOf(SecretType("t-login", "login", null, emptyList()), SecretType("t-pk", "passkey", null, emptyList()))
        val rows = listOf(
            VaultRow.from(Json.parseToJsonElement("""{"id":"p1","name":"Example","url":"login.example.nl","typeId":"t-pk","key":"x"}""").jsonObject)!!,
            VaultRow.from(Json.parseToJsonElement("""{"id":"l1","name":"Example","url":"https://login.example.nl","typeId":"t-login","key":"y","login":"z"}""").jsonObject)!!,
        )
        val index = AutofillIndex.of(VaultState(rows, emptyList(), types, 1L, offline = false, onlineOnly = false, needsConnection = false, locked = null))
        assertEquals(listOf("p1" to true, "l1" to false), index.entries.map { it.id to it.isPasskey })
        val web = nl.conduction.keepiq.shared.autofill.AutofillTarget.Web("login.example.nl")
        assertEquals(listOf("l1"), index.candidates(web).map { it.id })
        assertEquals(index.entries, AutofillIndex.fromJson(index.toJson()).entries)
    }

    @Test
    fun candidatesMatchTheExactRpIdAndTheAllowList() {
        val (mine, _) = made("login.example.nl", 0)
        val (sibling, _) = made("other.example.nl", 0)
        val (elsewhere, _) = made("login.example.org", 0)
        val index = AutofillIndex(
            listOf(
                entry("p1", "login.example.nl", mine),
                entry("p2", "other.example.nl", sibling),
                entry("p3", "login.example.org", elsewhere),
                AutofillEntry("broken", "Broken", "login.example.nl", AutofillEntry.PASSKEY, null, "not ciphertext", false),
            ),
        )
        assertEquals(listOf("p1"), Passkeys.candidates(index, "login.example.nl", keys).map { it.itemId })
        assertEquals(listOf("p1"), Passkeys.candidates(index, "LOGIN.example.nl", keys, listOf(mine.credentialId)).map { it.itemId })
        assertTrue(Passkeys.candidates(index, "login.example.nl", keys, listOf(sibling.credentialId)).isEmpty())
        assertTrue(Passkeys.candidates(index, "example.nl", keys).isEmpty(), "a parent domain is another rpId")
        assertEquals("alice@example.nl", Passkeys.candidates(index, "login.example.nl", keys).single().label)
    }

    @Test
    fun aCounterOfFourSignsAsFiveAndTheItemStoresFive() = runTest {
        val (record, spki) = made("login.example.nl", 4)
        val choice = Passkeys.candidates(AutofillIndex(listOf(entry("p1", "login.example.nl", record))), "login.example.nl", keys).single()
        val hash = ByteArray(32) { 7 }
        val assertion = Passkeys.sign(api, keys, choice, ClientData.hashed(hash), "login.example.nl")
        assertEquals(5L, assertion.counter)
        assertContentEquals(Encoding.uint32BigEndian(5), assertion.authenticatorData.copyOfRange(33, 37))
        assertTrue(WebAuthn.verifyHash(spki, assertion.authenticatorData, hash, assertion.signature))
        assertEquals(listOf("PUT" to "/api/v1/secrets/p1"), requests)
        assertEquals(setOf("key"), bodies.single().keys)
        assertEquals(5L, PasskeyCredential.parse(dec(bodies.single()["key"]!!.jsonPrimitive.content)) { "" }!!.counter)
    }

    @Test
    fun aCounterOfZeroStaysZeroAndWritesNothing() = runTest {
        val (record, _) = made("login.example.nl", 0)
        val choice = PasskeyChoice("p1", "Example", record)
        assertEquals(0L, Passkeys.sign(api, keys, choice, ClientData.build(ClientData.GET, ByteArray(32), "https://login.example.nl"), "login.example.nl").counter)
        assertTrue(requests.isEmpty())
    }

    @Test
    fun aNewPasskeyIsSavedAsAPasskeyItemForItsRpId() = runTest {
        val request = WebAuthnJson.creation(
            """{"challenge":"AAEC","rp":{"id":"login.example.nl","name":"Example Login"},"user":{"id":"dTE","name":"alice@example.nl","displayName":"Alice"},
            "pubKeyCredParams":[{"type":"public-key","alg":-8},{"type":"public-key","alg":-7}],"excludeCredentials":[]}""",
        )!!
        val r = Passkeys.create(api, keys, AutofillIndex.EMPTY, request, ClientData.hashed(ByteArray(32)), 1_791_194_400_000L)
        assertEquals(listOf("GET" to "/api/v1/secret-types", "POST" to "/api/v1/secrets"), requests)
        val body = bodies.single()
        assertEquals("Example Login", body["name"]!!.jsonPrimitive.content)
        assertEquals("login.example.nl", body["url"]!!.jsonPrimitive.content)
        assertEquals("9", body["typeId"]!!.jsonPrimitive.content)
        assertFalse("login" in body)
        val saved = assertNotNull(PasskeyCredential.parse(dec(body["key"]!!.jsonPrimitive.content)) { "" })
        assertEquals(r.record, saved)
        assertEquals("2026-10-05T10:00:00.000Z", saved.createdAt)
        assertContentEquals(Encoding.utf8("u1"), Encoding.fromBase64Url(saved.userHandle))
    }

    @Test
    fun onlyRs256OrAnExcludedCredentialIsDeclinedBeforeAnythingIsSent() = runTest {
        val rs256 = WebAuthnJson.creation("""{"challenge":"AA","rp":{"id":"login.example.nl"},"user":{"id":"dTE","name":"a"},"pubKeyCredParams":[{"type":"public-key","alg":-257}]}""")!!
        val e = assertFailsWith<KeepiqCryptoException> { Passkeys.create(api, keys, AutofillIndex.EMPTY, rs256, ClientData.hashed(ByteArray(32)), 0) }
        assertEquals(WebAuthn.UNSUPPORTED_ALGORITHM, e.message)

        val (record, _) = made("login.example.nl", 0)
        val index = AutofillIndex(listOf(entry("p1", "login.example.nl", record)))
        val excluded = WebAuthnJson.creation(
            """{"challenge":"AA","rp":{"id":"login.example.nl"},"user":{"id":"dTE","name":"a"},"excludeCredentials":[{"type":"public-key","id":"${record.credentialId}"}]}""",
        )!!
        assertFailsWith<ExcludedCredentialException> { Passkeys.create(api, keys, index, excluded, ClientData.hashed(ByteArray(32)), 0) }
        assertTrue(requests.isEmpty())
    }

    @Test
    fun theJsonAnswersCarryWhatTheCallerVerifies() {
        val get = assertNotNull(WebAuthnJson.get("""{"challenge":"AAEC","rpId":"login.example.nl","allowCredentials":[{"type":"public-key","id":"qrvM3Q=="}]}"""))
        assertContentEquals(byteArrayOf(0, 1, 2), get.challenge)
        assertEquals(listOf("qrvM3Q"), get.allowCredentialIds, "padding is dropped, as the stored record writes ids")
        assertEquals(null, WebAuthnJson.get("not json"))
        assertEquals(null, WebAuthnJson.creation("""{"rp":{"id":"x"},"user":{}}"""), "a request without user.name is not a request")

        val (record, _) = made("login.example.nl", 0)
        val a = WebAuthn.getAssertion(ClientData.build(ClientData.GET, byteArrayOf(1), "android:apk-key-hash:abc"), "login.example.nl", record)
        val json = Json.parseToJsonElement(WebAuthnJson.assertionResponse(a)).jsonObject
        assertEquals(record.credentialId, json["id"]!!.jsonPrimitive.content)
        assertEquals("public-key", json["type"]!!.jsonPrimitive.content)
        val response = json["response"]!!.jsonObject
        assertContentEquals(a.signature, Encoding.fromBase64Url(response["signature"]!!.jsonPrimitive.content))
        assertContentEquals(a.clientDataJSON, Encoding.fromBase64Url(response["clientDataJSON"]!!.jsonPrimitive.content))
        assertEquals(record.userHandle, response["userHandle"]!!.jsonPrimitive.content)

        val r = WebAuthn.createCredential("login.example.nl", null, "a", "", null, emptyList(), ClientData.hashed(ByteArray(32)), "")
        val reg = Json.parseToJsonElement(WebAuthnJson.registrationResponse(r)).jsonObject["response"]!!.jsonObject
        assertContentEquals(r.attestationObject, Encoding.fromBase64Url(reg["attestationObject"]!!.jsonPrimitive.content))
        assertContentEquals(r.publicKeySpki, Encoding.fromBase64Url(reg["publicKey"]!!.jsonPrimitive.content))
        assertEquals("-7", reg["publicKeyAlgorithm"]!!.jsonPrimitive.content)
    }

    @Test
    fun aBrowserMayUseItsOwnHostOrAParentThatIsNotAPublicSuffix() {
        assertTrue(Passkeys.rpIdAllowed("login.example.nl", "https://login.example.nl"))
        assertTrue(Passkeys.rpIdAllowed("example.nl", "https://login.example.nl"))
        assertFalse(Passkeys.rpIdAllowed("evil.nl", "https://login.example.nl"))
        assertFalse(Passkeys.rpIdAllowed("nl", "https://login.example.nl"))
        assertFalse(Passkeys.rpIdAllowed("login.example.nl", "http://login.example.nl"))
        assertTrue(Passkeys.rpIdAllowed("localhost", "http://localhost:8080"))
        assertFalse(Passkeys.rpIdAllowed("", "https://login.example.nl"))
    }
}
