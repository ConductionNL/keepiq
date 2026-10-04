// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.send

import io.ktor.client.engine.mock.MockEngine
import io.ktor.client.engine.mock.respond
import io.ktor.http.HttpHeaders
import io.ktor.http.HttpMethod
import io.ktor.http.HttpStatusCode
import io.ktor.http.content.TextContent
import io.ktor.http.headersOf
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import nl.conduction.keepiq.shared.api.Account
import nl.conduction.keepiq.shared.api.KeepiqApi
import nl.conduction.keepiq.shared.crypto.Encoding
import nl.conduction.keepiq.shared.crypto.SendCrypto
import nl.conduction.keepiq.shared.vault.WriteProblemKind
import java.io.IOException
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertIs
import kotlin.test.assertNotNull
import kotlin.test.assertNull
import kotlin.test.assertTrue

/**
 * Send create, list and delete (task 3.6) against a fake server, and opening
 * a Send link in the app as the public page does. The payload is sealed on
 * the device: the server sees ciphertext, and the key rides the fragment only
 * without a password.
 */
class SendServiceTest {
    private val requests = mutableListOf<Pair<String, String>>()
    private var created: JsonObject? = null
    private var offline = false
    private var failures = 0

    private val engine = MockEngine { request ->
        val path = request.url.encodedPath
        requests += request.method.value to path
        if (offline) throw IOException("no network")
        (request.body as? TextContent)?.let { created = Json.parseToJsonElement(it.text).jsonObject }
        val c = created
        val body = when {
            path.endsWith("/api/v1/sends") && request.method == HttpMethod.Post -> """{"id":"send-1","token":"Tok_en-42"}"""
            path.endsWith("/api/v1/sends") -> """[{"id":"send-1","payloadType":"credential","createdAt":"2026-10-04T10:00:00+00:00","expiresAt":"2026-10-05T10:00:00+00:00","viewCount":0,"maxViews":1,"hasPassword":true,"status":"active"}]"""
            path.endsWith("/public/sends/Tok_en-42") -> """{"payloadType":"text","hasPassword":${c?.get("hasPassword")},"remainingViews":1}"""
            path.endsWith("/public/sends/Tok_en-42/access") ->
                """{"encryptedPayload":${c!!["encryptedPayload"]},"payloadType":"text","hasPassword":${c["hasPassword"]},"wrappedKey":${c["wrappedKey"] ?: "null"},"argon2idSalt":${c["argon2idSalt"] ?: "null"}}"""
            path.endsWith("/confirm") -> """{"burned":true,"remainingViews":0}"""
            path.endsWith("/failure") -> { failures++; """{"burned":false,"attemptsLeft":${5 - failures}}""" }
            path.endsWith("/public/sends/gone") -> return@MockEngine respond("""{"message":"Send not found"}""", HttpStatusCode.NotFound)
            else -> "{}"
        }
        respond(body, HttpStatusCode.OK, headersOf(HttpHeaders.ContentType, "application/json"))
    }
    private val api = KeepiqApi(KeepiqApi.httpClient(engine), Account("a", "https://cloud.example.nl/", "alice", "pw"))
    private val sends = SendService(api)
    private val opener = OpenSendClient(KeepiqApi.httpClient(engine))

    @Test
    fun aTextSendCarriesItsKeyInTheFragmentOnly() = runTest {
        val result = assertIs<SendResult.Done<CreatedSend>>(
            sends.create(SendPayloadType.TEXT, "Wi-Fi: zomer☀2026", "1", SendExpiry.DAY, null, "", passwordAvailable = true),
        ).value
        val body = created!!
        assertEquals("text", body["payloadType"]!!.jsonPrimitive.content)
        assertEquals(1, body["maxViews"]!!.jsonPrimitive.content.toInt())
        assertEquals(86_400, body["ttlSeconds"]!!.jsonPrimitive.content.toInt())
        assertEquals("false", body["hasPassword"]!!.jsonPrimitive.content)
        assertFalse(body.toString().contains("zomer"))
        assertTrue(result.link.startsWith("https://cloud.example.nl/index.php/apps/keepiq/public/send/Tok_en-42#k="))
        val key = Encoding.fromBase64Url(result.link.substringAfter("#k="))
        assertEquals("Wi-Fi: zomer☀2026", SendCrypto.openPayload(body["encryptedPayload"]!!.jsonPrimitive.content, key))
        assertFalse(body.toString().contains(result.link.substringAfter("#k=")), "the key never reaches the server")
    }

    @Test
    fun theLinkOpensInTheAppOnceAndConfirmsTheView() = runTest {
        val link = assertIs<SendResult.Done<CreatedSend>>(
            sends.create(SendPayloadType.TEXT, "eenmalig", "1", SendExpiry.HOUR, null, "", passwordAvailable = true),
        ).value.link
        val parsed = assertNotNull(SendLink.parse(link))
        assertEquals("https://cloud.example.nl/index.php/apps/keepiq/api/v1/public/sends/Tok_en-42", parsed.apiBase)
        assertIs<OpenSendResult.Ready>(opener.peek(parsed))
        assertEquals(OpenSendResult.Opened("eenmalig", "text", burned = true), opener.open(parsed, ""))
        assertEquals(listOf("GET", "POST", "POST"), requests.filter { it.second.contains("/public/") }.map { it.first })
    }

    @Test
    fun aPasswordSendHasNoKeyInTheLinkAndCountsWrongPasswords() = runTest {
        val made = assertIs<SendResult.Done<CreatedSend>>(
            sends.create(SendPayloadType.CREDENTIAL, SendForm.credentialPayload("alice", "geheim"), "3", SendExpiry.CUSTOM, "48", "lang wachtwoord", passwordAvailable = true),
        ).value
        assertTrue(made.hasPassword)
        assertFalse(made.link.contains("#"))
        val body = created!!
        assertEquals(172_800, body["ttlSeconds"]!!.jsonPrimitive.content.toInt())
        assertNotNull(body["wrappedKey"])
        assertNotNull(body["argon2idSalt"])
        val parsed = SendLink.parse(made.link)!!
        assertNull(parsed.fragmentKey)
        assertIs<OpenSendResult.NeedsPassword>(opener.peek(parsed))
        assertEquals(OpenSendResult.WrongPassword(4, false), opener.open(parsed, "fout"))
        assertEquals(OpenSendResult.Opened("Username: alice\nPassword: geheim", "text", true), opener.open(parsed, "lang wachtwoord"))
    }

    @Test
    fun theFormRefusesWhatTheExtensionRefuses() = runTest {
        fun problem(r: SendResult<*>) = assertIs<SendResult.Problem>(r).form
        assertEquals(SendFormProblem.NOTHING_TO_SEND, problem(sends.create(SendPayloadType.TEXT, "  ", "1", SendExpiry.DAY, null, "", true)))
        assertEquals(SendFormProblem.VIEWS_OUT_OF_RANGE, problem(sends.create(SendPayloadType.TEXT, "x", "101", SendExpiry.DAY, null, "", true)))
        assertEquals(SendFormProblem.CUSTOM_HOURS, problem(sends.create(SendPayloadType.TEXT, "x", "1", SendExpiry.CUSTOM, "0", "", true)))
        assertEquals(SendFormProblem.CUSTOM_HOURS_TOO_MANY, problem(sends.create(SendPayloadType.TEXT, "x", "1", SendExpiry.CUSTOM, "721", "", true)))
        assertEquals(SendFormProblem.PASSWORD_NOT_AVAILABLE, problem(sends.create(SendPayloadType.TEXT, "x", "1", SendExpiry.DAY, null, "pw", false)))
        assertTrue(requests.isEmpty())
    }

    @Test
    fun listAndDeleteAndNoSendWhileOffline() = runTest {
        val list = assertIs<SendResult.Done<List<SendSummary>>>(sends.list()).value
        assertEquals(listOf("send-1"), list.map { it.id })
        assertTrue(list.single().hasPassword)
        assertEquals(1_791_194_400_000, IsoTime.parseMillis(list.single().expiresAt))
        assertIs<SendResult.Done<Unit>>(sends.delete("send-1"))
        assertEquals("DELETE" to "/index.php/apps/keepiq/api/v1/sends/send-1", requests.last())
        offline = true
        val refused = assertIs<SendResult.Problem>(sends.create(SendPayloadType.TEXT, "x", "1", SendExpiry.DAY, null, "", true))
        assertEquals(WriteProblemKind.OFFLINE, refused.write!!.kind)
    }

    @Test
    fun aGoneSendSaysSo() = runTest {
        val link = SendLink.parse("https://cloud.example.nl/apps/keepiq/public/send/gone#k=AAAA")!!
        assertEquals("https://cloud.example.nl/apps/keepiq/api/v1/public/sends/gone", link.apiBase)
        assertEquals(OpenSendResult.Gone, opener.peek(link))
    }
}
