// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.autofill

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
import nl.conduction.keepiq.shared.crypto.RsaFields
import nl.conduction.keepiq.shared.crypto.RsaPrivateKey
import nl.conduction.keepiq.shared.crypto.RsaPublicKey
import nl.conduction.keepiq.shared.crypto.Totp
import nl.conduction.keepiq.shared.vault.RsaVaultKeys
import nl.conduction.keepiq.shared.vectors.Vectors
import nl.conduction.keepiq.shared.vectors.str
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertTrue

/**
 * Filling and saving with real keys against a fake server (tasks 4.1 to
 * 4.3): only matched items are decrypted, a code is the item's current
 * TOTP, and a submitted login is saved, updated or left alone as the
 * extension decides, encrypted to the suite key.
 */
class AutofillSaverTest {
    private val privatePem = Vectors.envelope.str("privateKeyPem")
    private val keys = RsaVaultKeys.fromPem(privatePem, Vectors.envelope.str("certificatePem"), "suite-9", 2)
    private val publicKey = RsaPublicKey.fromPem(Vectors.envelope.str("publicKeyPem"))
    private val privateKey = RsaPrivateKey.fromPem(privatePem)
    private fun enc(text: String) = RsaFields.encrypt(text, publicKey)
    private fun dec(text: String) = RsaFields.decrypt(text, privateKey)

    private val print = "AB:CD:EF:01"
    private val app = AppIdentity("com.example.bank", setOf(print))
    private val seed = "otpauth://totp/Example:alice?secret=JBSWY3DPEHPK3PXP&issuer=Example"

    private val index = AutofillIndex(
        listOf(
            AutofillEntry("w1", "Example", "https://example.com", "login", enc("alice"), enc("one"), false),
            AutofillEntry("w2", "Example two", "https://www.example.com", "login", enc("bob"), enc("two"), false),
            AutofillEntry("a1", "Bank", AppLink("com.example.bank", setOf(print)).toUrl(), "login", enc("carol"), enc("three"), false),
            AutofillEntry("t1", "Example code", "https://example.com", "totp", null, enc(seed), false),
            AutofillEntry("x", "Broken", "https://example.com", "login", "not ciphertext", "not ciphertext", false),
        ),
    )

    private val requests = mutableListOf<Pair<String, String>>()
    private val bodies = mutableListOf<JsonObject>()
    private val engine = MockEngine { request ->
        val path = request.url.encodedPath.substringAfter("/apps/keepiq")
        requests += request.method.value to path
        (request.body as? TextContent)?.let { bodies += Json.parseToJsonElement(it.text).jsonObject }
        val body = when {
            path == "/api/v1/secret-types" -> """[{"id":"7","name":"login","fields":[]},{"id":"8","name":"totp","fields":[]}]"""
            request.method == HttpMethod.Post -> """{"id":"new"}"""
            else -> """{"id":"w1"}"""
        }
        respond(body, headers = headersOf(HttpHeaders.ContentType, "application/json"))
    }
    private val api = KeepiqApi(KeepiqApi.httpClient(engine), Account("acc", "https://cloud.example.nl", "alice", "app-password"))
    private val saver = AutofillSaver(api, keys)

    @Test
    fun onlyMatchedItemsAreDecryptedAndABrokenOneIsLeftOut() {
        val web = AutofillChoices.logins(index, AutofillTarget.Web("www.example.com"), keys)
        assertEquals(listOf("w2", "w1"), web.map { it.id })
        assertEquals("bob" to "two", web[0].login to web[0].password)
        assertEquals(listOf("a1"), AutofillChoices.logins(index, AutofillTarget.App(app), keys).map { it.id })
        assertTrue(AutofillChoices.logins(index, AutofillTarget.App(app.copy(certFingerprints = setOf("00:11"))), keys).isEmpty())
    }

    @Test
    fun aCodeIsTheCurrentTotpOfTheMatchedItem() {
        val now = 1_760_000_000_000L
        val codes = AutofillChoices.codes(index, AutofillTarget.Web("example.com"), keys, now)
        assertEquals(listOf("t1"), codes.map { it.id })
        assertEquals(Totp.generate(Totp.parse(seed), now), codes[0].code)
        assertTrue(AutofillChoices.codes(index, AutofillTarget.Web("other.org"), keys, now).isEmpty())
    }

    @Test
    fun aNewLoginIsSavedForTheSiteEncryptedToTheSuiteKey() = runTest {
        assertEquals(SaveResult.SAVED, saver.save(AutofillTarget.Web("signup.example.org"), index, "dave", "fresh"))
        val body = bodies.single()
        assertEquals(listOf("GET" to "/api/v1/secret-types", "POST" to "/api/v1/secrets"), requests)
        assertEquals("signup.example.org", body["name"]!!.jsonPrimitive.content)
        assertEquals("https://signup.example.org", body["url"]!!.jsonPrimitive.content)
        assertEquals("7", body["typeId"]!!.jsonPrimitive.content)
        assertEquals("dave", dec(body["login"]!!.jsonPrimitive.content))
        assertEquals("fresh", dec(body["key"]!!.jsonPrimitive.content))
    }

    @Test
    fun anAppLoginIsSavedUnderItsPackageAndCertificate() = runTest {
        assertEquals(SaveResult.SAVED, saver.save(AutofillTarget.App(AppIdentity("org.example.shop", setOf(print))), index, "erin", "e", appLabel = "Shop"))
        val body = bodies.single()
        assertEquals("Shop", body["name"]!!.jsonPrimitive.content)
        assertEquals("androidapp://org.example.shop#sha256_cert_fingerprints=$print", body["url"]!!.jsonPrimitive.content)
    }

    @Test
    fun aChangedPasswordUpdatesTheOneLoginWithThatName() = runTest {
        assertEquals(SaveResult.UPDATED, saver.save(AutofillTarget.Web("example.com"), index, "alice", "changed"))
        assertEquals(listOf("PUT" to "/api/v1/secrets/w1"), requests)
        assertEquals(setOf("key"), bodies.single().keys)
        assertEquals("changed", dec(bodies.single()["key"]!!.jsonPrimitive.content))
    }

    @Test
    fun theSameLoginOrAUseOnlySiteSendsNothing() = runTest {
        assertEquals(SaveResult.UNCHANGED, saver.save(AutofillTarget.Web("example.com"), index, "alice", "one"))
        val shared = AutofillIndex(listOf(AutofillEntry("u", "Shared", "https://example.com", "login", enc("x"), enc("y"), true)))
        assertEquals(SaveResult.REFUSED, saver.save(AutofillTarget.Web("example.com"), shared, "alice", "new"))
        assertEquals(SaveResult.REFUSED, saver.save(AutofillTarget.Web("example.com"), index, "alice", ""))
        assertTrue(requests.isEmpty())
    }
}
