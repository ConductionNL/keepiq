// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.pairing

import io.ktor.client.HttpClient
import io.ktor.client.request.header
import io.ktor.client.request.request
import io.ktor.client.request.setBody
import io.ktor.client.statement.bodyAsText
import io.ktor.http.ContentType
import io.ktor.http.HttpHeaders
import io.ktor.http.HttpMethod
import io.ktor.http.content.TextContent
import kotlinx.coroutines.delay
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.contentOrNull
import nl.conduction.keepiq.shared.api.KeepiqApiException

/** A started Login Flow v2: the page to open in the system browser, and where to poll. */
class LoginFlowStart(val server: String, val loginUrl: String, internal val pollEndpoint: String, internal val pollToken: String) {
    override fun toString(): String = "LoginFlowStart(server=$server, loginUrl=$loginUrl)"
}

/** What a finished flow hands over. The app password is never printed. */
class LoginFlowCredentials(val server: String, val loginName: String, val appPassword: String) {
    override fun toString(): String = "LoginFlowCredentials(server=$server, loginName=$loginName)"
}

/** The flow ran out of time (20 minutes) or the user cancelled it. Nothing was stored. */
class LoginFlowStoppedException(val timedOut: Boolean) : Exception(
    if (timedOut) "The sign-in took longer than 20 minutes. Start again." else "Sign-in cancelled.",
)

/**
 * Nextcloud Login Flow v2 (design D3), the way the Nextcloud desktop and
 * mobile clients use it:
 *
 * 1. `POST {server}/index.php/login/v2` answers a login page and a poll token.
 *    Nextcloud names the app password after this request's User-Agent, so
 *    [userAgent] is what the user later sees in the device list.
 * 2. The app opens the login page in the system browser.
 * 3. `POST {poll endpoint}` with the token answers 404 until the user granted
 *    access, then once the server, login name and a new app password.
 *
 * Polling stops after [TIMEOUT_MILLIS] or when [isCancelled] says so. The
 * poll endpoint must be https on the same host and port as the server, so
 * the token that buys an app password never goes anywhere else.
 */
class LoginFlowV2(
    private val client: HttpClient,
    private val userAgent: String,
    private val clock: () -> Long,
    private val pollIntervalMillis: Long = POLL_INTERVAL_MILLIS,
) {
    @Throws(Exception::class)
    suspend fun start(serverInput: String): LoginFlowStart {
        val server = ServerAddress.normalize(serverInput)
        val response = client.request("$server/index.php/login/v2") {
            method = HttpMethod.Post
            header(HttpHeaders.UserAgent, userAgent)
            header(HttpHeaders.Accept, "application/json")
        }
        val text = response.bodyAsText()
        val status = response.status.value
        if (status !in 200..299) {
            throw KeepiqApiException(status, "This address does not answer as a Nextcloud server ($status).")
        }
        val body = runCatching { Json.parseToJsonElement(text) as? JsonObject }.getOrNull()
            ?: throw KeepiqApiException(status, "This address does not answer as a Nextcloud server.")
        val poll = body["poll"] as? JsonObject
        val login = body.text("login")
        val endpoint = poll?.text("endpoint")
        val token = poll?.text("token")
        if (login == null || endpoint == null || token == null) {
            throw KeepiqApiException(status, "This address does not answer as a Nextcloud server.")
        }
        val origin = ServerAddress.origin(server)
        if (ServerAddress.origin(endpoint) != origin) {
            throw KeepiqApiException(0, "The server sent a sign-in address on another host. Keepiq stopped, for your safety.")
        }
        if (ServerAddress.origin(login) == null) {
            throw KeepiqApiException(0, "The server sent a sign-in page that is not https. Keepiq stopped, for your safety.")
        }
        return LoginFlowStart(server, login, endpoint, token)
    }

    /** One poll: the credentials, or null while the user has not granted access yet. */
    @Throws(Exception::class)
    suspend fun poll(start: LoginFlowStart): LoginFlowCredentials? {
        val response = client.request(start.pollEndpoint) {
            method = HttpMethod.Post
            header(HttpHeaders.UserAgent, userAgent)
            header(HttpHeaders.Accept, "application/json")
            setBody(TextContent("token=" + formEncode(start.pollToken), ContentType.Application.FormUrlEncoded))
        }
        val text = response.bodyAsText()
        val status = response.status.value
        if (status == 404) return null
        if (status !in 200..299) throw KeepiqApiException(status, "Signing in failed ($status).")
        val body = runCatching { Json.parseToJsonElement(text) as? JsonObject }.getOrNull()
        val loginName = body?.text("loginName")
        val appPassword = body?.text("appPassword")
        if (loginName.isNullOrEmpty() || appPassword.isNullOrEmpty()) {
            throw KeepiqApiException(status, "The server finished the sign-in without an app password.")
        }
        // The account is kept under the address the user typed: the flow's
        // own `server` can carry http behind a proxy that hides https.
        return LoginFlowCredentials(start.server, loginName, appPassword)
    }

    /**
     * Polls until the user granted access. Throws [LoginFlowStoppedException]
     * after 20 minutes, counted from [startedAtMillis], or when [isCancelled]
     * turns true. A network error while polling is retried: the phone may
     * switch networks while the user is in the browser.
     */
    @Throws(Exception::class)
    suspend fun await(start: LoginFlowStart, startedAtMillis: Long, isCancelled: () -> Boolean = { false }): LoginFlowCredentials {
        while (true) {
            if (isCancelled()) throw LoginFlowStoppedException(timedOut = false)
            if (clock() - startedAtMillis >= TIMEOUT_MILLIS) throw LoginFlowStoppedException(timedOut = true)
            val result = try {
                poll(start)
            } catch (e: KeepiqApiException) {
                throw e
            } catch (e: Exception) {
                if (e is kotlinx.coroutines.CancellationException) throw e
                null
            }
            if (result != null) return result
            delay(pollIntervalMillis)
        }
    }

    private fun JsonObject.text(name: String): String? = (this[name] as? JsonPrimitive)?.contentOrNull

    private fun formEncode(value: String): String {
        val safe = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_.*"
        val hex = "0123456789ABCDEF"
        val out = StringBuilder()
        for (b in value.encodeToByteArray()) {
            val c = b.toInt() and 0xFF
            when {
                c < 0x80 && safe.indexOf(c.toChar()) >= 0 -> out.append(c.toChar())
                c == 0x20 -> out.append('+')
                else -> out.append('%').append(hex[c shr 4]).append(hex[c and 0xF])
            }
        }
        return out.toString()
    }

    companion object {
        /** Design D3: the poll stops after 20 minutes. */
        const val TIMEOUT_MILLIS = 20L * 60_000L

        /** Nextcloud's own clients poll every few seconds. */
        const val POLL_INTERVAL_MILLIS = 2_000L
    }
}
