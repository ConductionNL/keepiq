// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.pairing

import io.ktor.client.engine.mock.MockEngine
import io.ktor.client.engine.mock.MockRequestHandleScope
import io.ktor.client.engine.mock.respond
import io.ktor.client.request.HttpRequestData
import io.ktor.client.request.HttpResponseData
import io.ktor.http.HttpHeaders
import io.ktor.http.HttpStatusCode
import io.ktor.http.content.TextContent
import io.ktor.http.headersOf
import kotlinx.coroutines.test.TestScope
import kotlinx.coroutines.test.runTest
import nl.conduction.keepiq.shared.api.KeepiqApi
import nl.conduction.keepiq.shared.api.KeepiqApiException
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFailsWith
import kotlin.test.assertNull
import kotlin.test.assertTrue

/** Task 2.1: Login Flow v2 against answers recorded from Nextcloud 35 (core/Controller/ClientFlowLoginV2Controller). */
class LoginFlowV2Test {
    private val seen = mutableListOf<HttpRequestData>()

    private val initAnswer = """{"poll":{"token":"pollToken+/=","endpoint":"https://cloud.example.com/nextcloud/index.php/login/v2/poll"},""" +
        """"login":"https://cloud.example.com/nextcloud/index.php/login/v2/flow/loginToken"}"""

    private fun MockRequestHandleScope.json(body: String, status: HttpStatusCode = HttpStatusCode.OK) =
        respond(body, status, headersOf(HttpHeaders.ContentType, "application/json"))

    private fun TestScope.flow(handler: suspend MockRequestHandleScope.(HttpRequestData) -> HttpResponseData) = LoginFlowV2(
        KeepiqApi.httpClient(MockEngine { request -> seen += request; handler(request) }),
        "Keepiq for Android",
        { testScheduler.currentTime },
    )

    @Test
    fun startPostsToLoginV2WithTheClientNameAsUserAgent() = runTest {
        val start = flow { json(initAnswer) }.start("cloud.example.com/nextcloud/")
        val r = seen.single()
        assertEquals("https://cloud.example.com/nextcloud/index.php/login/v2", r.url.toString())
        assertEquals("POST", r.method.value)
        // Nextcloud names the app password after this header (task 2.4).
        assertEquals("Keepiq for Android", r.headers[HttpHeaders.UserAgent])
        assertEquals("https://cloud.example.com/nextcloud", start.server)
        assertEquals("https://cloud.example.com/nextcloud/index.php/login/v2/flow/loginToken", start.loginUrl)
    }

    @Test
    fun aPollEndpointOnAnotherHostIsRefused() = runTest {
        val e = assertFailsWith<KeepiqApiException> {
            flow { json(initAnswer.replace("https://cloud.example.com/nextcloud/index.php/login/v2/poll", "https://evil.example.net/poll")) }
                .start("https://cloud.example.com/nextcloud")
        }
        assertTrue(e.message!!.contains("another host"))
    }

    @Test
    fun aPollEndpointOverHttpIsRefused() = runTest {
        assertFailsWith<KeepiqApiException> {
            flow { json(initAnswer.replace("https://cloud.example.com/nextcloud/index.php/login/v2/poll", "http://cloud.example.com/nextcloud/index.php/login/v2/poll")) }
                .start("https://cloud.example.com/nextcloud")
        }
    }

    @Test
    fun plainHttpIsRefusedBeforeAnyRequest() = runTest {
        assertFailsWith<ServerAddressException> { flow { json(initAnswer) }.start("http://cloud.example.com") }
        assertTrue(seen.isEmpty())
    }

    @Test
    fun aServerWithoutLoginFlowIsNamed() = runTest {
        val e = assertFailsWith<KeepiqApiException> { flow { json("<html>", HttpStatusCode.NotFound) }.start("https://example.com") }
        assertEquals(404, e.status)
    }

    @Test
    fun pollSendsTheTokenAsAFormAndWaitsOn404() = runTest {
        var polls = 0
        val f = flow { request ->
            if (request.url.encodedPath.endsWith("/login/v2")) {
                json(initAnswer)
            } else {
                polls++
                if (polls < 3) {
                    json("[]", HttpStatusCode.NotFound)
                } else {
                    json("""{"server":"http://cloud.example.com/nextcloud","loginName":"alice","appPassword":"granted-app-password"}""")
                }
            }
        }
        val start = f.start("https://cloud.example.com/nextcloud")
        assertNull(f.poll(start))
        val body = (seen.last().body as TextContent).text
        assertEquals("token=pollToken%2B%2F%3D", body)
        val credentials = f.await(start, testScheduler.currentTime)
        assertEquals("alice", credentials.loginName)
        assertEquals("granted-app-password", credentials.appPassword)
        // The account keeps the https address the user typed, not the
        // flow's http one from behind a proxy.
        assertEquals("https://cloud.example.com/nextcloud", credentials.server)
        assertTrue(!credentials.toString().contains("granted-app-password"))
    }

    @Test
    fun pollingStopsAfterTwentyMinutes() = runTest {
        val f = flow { request ->
            if (request.url.encodedPath.endsWith("/login/v2")) json(initAnswer) else json("[]", HttpStatusCode.NotFound)
        }
        val start = f.start("https://cloud.example.com/nextcloud")
        val startedAt = testScheduler.currentTime
        val e = assertFailsWith<LoginFlowStoppedException> { f.await(start, startedAt) }
        assertTrue(e.timedOut)
        assertEquals(LoginFlowV2.TIMEOUT_MILLIS, testScheduler.currentTime - startedAt)
    }

    @Test
    fun aCancelledFlowStopsAtTheNextPoll() = runTest {
        var polls = 0
        val f = flow { request ->
            if (request.url.encodedPath.endsWith("/login/v2")) json(initAnswer) else { polls++; json("[]", HttpStatusCode.NotFound) }
        }
        val start = f.start("https://cloud.example.com/nextcloud")
        val e = assertFailsWith<LoginFlowStoppedException> { f.await(start, testScheduler.currentTime) { polls >= 2 } }
        assertEquals(false, e.timedOut)
        assertEquals(2, polls)
    }
}
