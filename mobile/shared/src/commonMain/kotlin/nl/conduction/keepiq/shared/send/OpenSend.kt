// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.send

import io.ktor.client.HttpClient
import io.ktor.client.request.header
import io.ktor.client.request.request
import io.ktor.client.statement.bodyAsText
import io.ktor.http.HttpHeaders
import io.ktor.http.HttpMethod
import kotlinx.coroutines.CancellationException
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.booleanOrNull
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.longOrNull
import nl.conduction.keepiq.shared.crypto.Encoding
import nl.conduction.keepiq.shared.crypto.SendCrypto

/**
 * A Keepiq Send link: `{origin}{/prefix}/apps/keepiq/public/send/{token}`,
 * with `#k=<base64url key>` when no password protects it
 * (src/send/sendCrypto.js sendLink). The prefix is `/index.php` on a server
 * without pretty URLs, or a subdirectory.
 */
data class SendLink(val apiBase: String, val token: String, val fragmentKey: String?) {
    override fun toString(): String = "SendLink(apiBase=$apiBase)"

    companion object {
        private const val MARKER = "/apps/keepiq/public/send/"

        /** Reads a link, or null when it is not an https Keepiq Send link. */
        fun parse(link: String): SendLink? {
            val text = link.trim()
            if (!text.startsWith("https://", ignoreCase = true)) return null
            val hashAt = text.indexOf('#')
            val beforeHash = if (hashAt >= 0) text.substring(0, hashAt) else text
            val fragment = if (hashAt >= 0) text.substring(hashAt + 1) else ""
            val noQuery = beforeHash.substringBefore('?')
            val at = noQuery.indexOf(MARKER)
            if (at < 0) return null
            val token = percentDecode(noQuery.substring(at + MARKER.length).trimEnd('/'))
            if (token.isEmpty() || token.contains('/')) return null
            // As EphemeralSendAccess.vue: `#k=` first, a legacy `?k=` second.
            val key = when {
                fragment.startsWith("k=") -> percentDecode(fragment.substring(2))
                else -> beforeHash.substringAfter('?', "").split('&').firstOrNull { it.startsWith("k=") }?.substring(2)?.let { percentDecode(it) }
            }?.takeIf { it.isNotEmpty() }
            return SendLink(noQuery.substring(0, at) + "/apps/keepiq/api/v1/public/sends/" + SendCrypto.encodeUriComponent(token), token, key)
        }

        private fun percentDecode(text: String): String {
            if (!text.contains('%')) return text
            val out = ArrayList<Byte>()
            var i = 0
            while (i < text.length) {
                val c = text[i]
                if (c == '%' && i + 2 < text.length) {
                    val hex = text.substring(i + 1, i + 3).toIntOrNull(16)
                    if (hex != null) {
                        out += hex.toByte()
                        i += 3
                        continue
                    }
                }
                Encoding.utf8(c.toString()).forEach { out += it }
                i++
            }
            return Encoding.fromUtf8(out.toByteArray())
        }
    }
}

/** What opening a Send ended in. */
sealed class OpenSendResult {
    /** The Send can be opened with the key in the link; opening uses a view. */
    data class Ready(val payloadType: String) : OpenSendResult()

    /** The Send asks for a password before it can be opened. */
    data class NeedsPassword(val payloadType: String) : OpenSendResult()

    /** Opened: one view is used up. [burned] when that was the last one. */
    data class Opened(val payload: String, val payloadType: String, val burned: Boolean) : OpenSendResult() {
        override fun toString(): String = "Opened(payloadType=$payloadType, burned=$burned)"
    }

    /** A wrong password. The Send is destroyed after 5. */
    data class WrongPassword(val attemptsLeft: Long, val burned: Boolean) : OpenSendResult()

    /** Missing, expired or used up: the server does not tell them apart. */
    data object Gone : OpenSendResult()

    /** The link has no key and the Send has no password: it cannot be opened. */
    data object NoKey : OpenSendResult()

    /** The server could not be reached, or answered something else. */
    data class Failed(val status: Int) : OpenSendResult()
}

/**
 * Opens a Send link in the app, as the public page does it
 * (src/views/EphemeralSendAccess.vue and ephemeralSend.accessSend): peek,
 * fetch the ciphertext, decrypt on the device, and only then confirm the
 * view. A wrong password is reported, so the Send burns after 5. Nothing
 * here is signed in: the recipient may have no account on that server.
 */
class OpenSendClient(private val client: HttpClient) {
    /** Peeks: whether a password is needed. Uses no view. */
    suspend fun peek(link: SendLink): OpenSendResult = call(HttpMethod.Get, link.apiBase) { data ->
        val type = data.text("payloadType") ?: "text"
        when {
            data.bool("hasPassword") -> OpenSendResult.NeedsPassword(type)
            link.fragmentKey == null -> OpenSendResult.NoKey
            else -> OpenSendResult.Ready(type)
        }
    } ?: OpenSendResult.Failed(0)

    /** Fetches, decrypts and confirms. [password] is used when the Send has one. */
    suspend fun open(link: SendLink, password: String): OpenSendResult {
        var data: JsonObject? = null
        val early = call(HttpMethod.Post, link.apiBase + "/access") { data = it; null }
        if (early != null) return early
        val access = data ?: return OpenSendResult.Failed(0)
        val hasPassword = access.bool("hasPassword")
        val plaintext = try {
            val rawKey = if (hasPassword) {
                SendCrypto.unwrapKey(access.text("wrappedKey") ?: "", access.text("argon2idSalt") ?: "", password)
            } else {
                Encoding.fromBase64Url(link.fragmentKey ?: return OpenSendResult.NoKey)
            }
            SendCrypto.openPayload(access.text("encryptedPayload") ?: "", rawKey)
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            if (!hasPassword) return OpenSendResult.Failed(0)
            var failure: JsonObject? = null
            call(HttpMethod.Post, link.apiBase + "/failure") { failure = it; null }?.let { return it }
            val f = failure ?: JsonObject(emptyMap())
            return OpenSendResult.WrongPassword((f["attemptsLeft"] as? JsonPrimitive)?.longOrNull ?: 0, f.bool("burned"))
        }
        var confirm: JsonObject? = null
        call(HttpMethod.Post, link.apiBase + "/confirm") { confirm = it; null }?.let { return it }
        return OpenSendResult.Opened(plaintext, access.text("payloadType") ?: "text", confirm?.bool("burned") == true)
    }

    /** One public request. Returns a result to stop with, or what [onData] returns. */
    private suspend fun call(method: HttpMethod, url: String, onData: (JsonObject) -> OpenSendResult?): OpenSendResult? {
        val (status, text) = try {
            val response = client.request(url) {
                this.method = method
                header(HttpHeaders.Accept, "application/json")
            }
            response.status.value to response.bodyAsText()
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            return OpenSendResult.Failed(0)
        }
        if (status == 404) return OpenSendResult.Gone
        if (status !in 200..299) return OpenSendResult.Failed(status)
        val data = runCatching { Json.parseToJsonElement(text) as? JsonObject }.getOrNull() ?: return OpenSendResult.Failed(status)
        return onData(data)
    }

    private fun JsonObject.text(name: String): String? = (this[name] as? JsonPrimitive)?.contentOrNull

    private fun JsonObject.bool(name: String): Boolean = (this[name] as? JsonPrimitive)?.booleanOrNull == true
}
