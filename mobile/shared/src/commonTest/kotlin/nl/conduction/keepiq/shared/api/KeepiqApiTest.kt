// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.api

import io.ktor.client.engine.mock.MockEngine
import io.ktor.client.engine.mock.MockRequestHandleScope
import io.ktor.client.engine.mock.respond
import io.ktor.client.request.HttpRequestData
import io.ktor.client.request.HttpResponseData
import io.ktor.http.HttpHeaders
import io.ktor.http.HttpMethod
import io.ktor.http.HttpStatusCode
import io.ktor.http.headersOf
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFailsWith
import kotlin.test.assertNull
import kotlin.test.assertTrue

/**
 * The API contract shared with the browser extension (task 1.5), against
 * responses recorded from the server's controllers.
 */
class KeepiqApiTest {
    private val account = Account("acc-1", "https://cloud.example.nl/nextcloud/", "alice", "app-pass-WORD-1")
    private val seen = mutableListOf<HttpRequestData>()

    private fun api(handler: suspend MockRequestHandleScope.(HttpRequestData) -> HttpResponseData): KeepiqApi =
        KeepiqApi(
            KeepiqApi.httpClient(
                MockEngine { request ->
                    seen += request
                    handler(request)
                },
            ),
            account,
        )

    private fun MockRequestHandleScope.json(body: String, status: HttpStatusCode = HttpStatusCode.OK, extra: Map<String, String> = emptyMap()) =
        respond(
            body,
            status,
            headersOf(*(mapOf(HttpHeaders.ContentType to "application/json") + extra).map { it.key to listOf(it.value) }.toTypedArray()),
        )

    @Test
    fun requestsCarryTheExtensionsHeadersAndPath() = runTest {
        api { json("""{"items":[{"id":"s1","updatedAt":"2026-10-04T10:00:00+00:00"}],"total":12}""") }.latestSecret()
        val r = seen.single()
        assertEquals(
            "https://cloud.example.nl/nextcloud/index.php/apps/keepiq/api/v1/secrets?sort=updated_at&direction=desc&limit=1",
            r.url.toString(),
        )
        assertEquals("Basic YWxpY2U6YXBwLXBhc3MtV09SRC0x", r.headers[HttpHeaders.Authorization])
        assertEquals("true", r.headers["OCS-APIRequest"])
        assertEquals("application/json", r.headers[HttpHeaders.Accept])
        assertNull(r.headers[HttpHeaders.Cookie])
    }

    @Test
    fun theFreshnessCheckReadsTopAndTotal() = runTest {
        val f = api { json("""{"items":[{"id":"s1","updatedAt":"2026-10-04T10:00:00+00:00"}],"total":12}""") }.latestSecret()
        assertEquals(Freshness("2026-10-04T10:00:00+00:00", 12), f)
        val empty = api { json("""{"items":[],"total":0}""") }.latestSecret()
        assertEquals(Freshness(null, 0), empty)
    }

    @Test
    fun aRefusalInsideHttp200IsAnError() = runTest {
        // Recorded: Nextcloud's OCSMiddleware wraps a 403 from an OCSController in HTTP 200.
        val e = assertFailsWith<KeepiqApiException> {
            api {
                json("""{"ocs":{"meta":{"status":"failure","statuscode":403,"message":"Forbidden"},"data":[]}}""")
            }.updateSecret("s1", buildJsonObject { put("name", JsonPrimitive("x")) })
        }
        assertEquals(403, e.status)
    }

    @Test
    fun anHttpErrorCarriesStatusAndRefusalCode() = runTest {
        val e = assertFailsWith<KeepiqApiException> {
            api { json("""{"error":"offline_cache_disabled","message":"Offline caching is disabled"}""", HttpStatusCode(428, "Precondition Required")) }
                .offlineManifest()
        }
        assertEquals(428, e.status)
        assertEquals("offline_cache_disabled", e.code)
        val unauthorized = assertFailsWith<KeepiqApiException> {
            api { json("""{"message":""}""", HttpStatusCode.Unauthorized) }.listFolders()
        }
        assertEquals(401, unauthorized.status)
    }

    @Test
    fun noCookieIsStoredOrSent() = runTest {
        val client = api { json("""{"items":[],"total":0}""", extra = mapOf(HttpHeaders.SetCookie to "nc_session_id=abc; Path=/; Secure")) }
        client.latestSecret()
        client.latestSecret()
        assertEquals(2, seen.size)
        assertTrue(seen.all { it.headers[HttpHeaders.Cookie] == null })
    }

    @Test
    fun redirectsAreNotFollowed() = runTest {
        val e = assertFailsWith<KeepiqApiException> {
            api { respond("", HttpStatusCode.Found, headersOf(HttpHeaders.Location, "http://evil.example/steal")) }.listTypes()
        }
        assertEquals(302, e.status)
        assertEquals(1, seen.size)
    }

    @Test
    fun plainHttpIsRefusedBeforeAnythingIsSent() {
        for (server in listOf("http://cloud.example.nl", "http://localhost:8080", "cloud.example.nl", "ftp://x")) {
            assertFailsWith<InsecureServerException>(server) {
                KeepiqApi(KeepiqApi.httpClient(MockEngine { error("must not send") }), account.copy(server = server))
            }
        }
    }

    @Test
    fun theManifestParsesSuiteSecretsAndTheBlockSignal() = runTest {
        val m = api {
            json(
                """{"suite":{"id":"suite-9","status":"active","unlockKeyEpoch":3,"certificate":"-----BEGIN CERTIFICATE-----","privateKey":"AAAA"},
                "secrets":[{"id":"s1","name":"Bank","url":"https://bank.example","key":"AAAB","login":null,"updatedAt":"2026-10-01T00:00:00+00:00"}],
                "folders":[{"id":"f1","name":"Werk","parentId":null}],"types":[{"id":"1","name":"login"}],
                "syncedAt":"2026-10-04T10:00:00+00:00","unlockBlocked":null,"offlineEditsEnabled":false}""",
            )
        }.offlineManifest()
        assertEquals("suite-9", m.suite?.id)
        assertEquals(3L, m.suite?.unlockKeyEpoch)
        assertEquals(1, m.secrets.size)
        assertNull(m.unlockBlocked)
        val blocked = api { json("""{"suite":null,"secrets":[],"folders":[],"types":[],"unlockBlocked":"two_factor_required"}""") }
            .offlineManifest()
        assertNull(blocked.suite)
        assertEquals("two_factor_required", blocked.unlockBlocked)
    }

    @Test
    fun secretsAreReadPageByPage() = runTest {
        val client = api { request ->
            val page = request.url.parameters["page"]!!.toInt()
            val count = if (page < 3) 100 else 7
            val items = (1..count).joinToString(",") { """{"id":"p$page-$it"}""" }
            json("""{"items":[$items],"total":207}""")
        }
        assertEquals(207, client.listSecrets().size)
        assertEquals(listOf("1", "2", "3"), seen.map { it.url.parameters["page"] })
    }

    @Test
    fun writesSendJsonWithTheMethodAndEncodedId() = runTest {
        api { json("""{"id":"a/b"}""") }.updateSecret("a/b", buildJsonObject { put("key", JsonPrimitive("CT")) })
        val r = seen.single()
        assertEquals(HttpMethod.Put, r.method)
        assertTrue(r.url.encodedPath.endsWith("/api/v1/secrets/a%2Fb"), r.url.encodedPath)
    }
}
