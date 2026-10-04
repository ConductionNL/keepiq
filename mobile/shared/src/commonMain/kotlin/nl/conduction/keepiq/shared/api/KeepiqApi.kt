// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.api

import io.ktor.client.HttpClient
import io.ktor.client.engine.HttpClientEngine
import io.ktor.client.request.header
import io.ktor.client.request.request
import io.ktor.client.request.setBody
import io.ktor.client.statement.bodyAsText
import io.ktor.http.ContentType
import io.ktor.http.HttpHeaders
import io.ktor.http.HttpMethod
import io.ktor.http.URLProtocol
import io.ktor.http.Url
import io.ktor.http.content.TextContent
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.intOrNull
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.longOrNull
import nl.conduction.keepiq.shared.crypto.Encoding

/** A paired account: the server address and the Nextcloud app password. */
data class Account(val id: String, val server: String, val loginName: String, val appPassword: String) {
    override fun toString(): String = "Account(id=$id, server=$server, loginName=$loginName)"
}

/**
 * A request the server refused or that never reached it. [status] is the
 * HTTP status, or the OCS `meta.statuscode` when the server wrapped a
 * refusal in an HTTP 200 envelope, or 0 when the request was not sent.
 * [code] is the body's `error`, else its `code` (keepiq#673).
 */
open class KeepiqApiException(
    val status: Int,
    message: String,
    val code: String? = null,
    val body: String = "",
) : Exception(message)

/** The server address is not https, so the app password is never sent. */
class InsecureServerException(server: String) :
    KeepiqApiException(0, "Keepiq needs an https address, so your app password is never sent in clear: $server")

/**
 * The keepiq API, called the way the browser extension calls it
 * (browser-extension/src/lib/api.js request, design D3):
 * `{server}/index.php/apps/keepiq{path}`, HTTP Basic with the app password,
 * `OCS-APIRequest: true`, JSON, no cookies, no redirects. An OCS envelope
 * whose `meta.statuscode` is 400 or more is an error even under HTTP 200.
 * Plain http is refused for every host.
 */
class KeepiqApi(private val client: HttpClient, private val account: Account) {
    private val base: String = account.server.trimEnd('/')

    init {
        val url = runCatching { Url(base) }.getOrNull()
        if (url == null || url.protocol != URLProtocol.HTTPS || !base.startsWith("https://", ignoreCase = true)) {
            throw InsecureServerException(account.server)
        }
    }

    /** Sends one request and returns the parsed JSON body, or null for an empty answer. */
    suspend fun request(method: HttpMethod, path: String, body: JsonElement? = null): JsonElement? {
        val auth = "Basic " + Encoding.toBase64(Encoding.utf8("${account.loginName}:${account.appPassword}"))
        val response = client.request(base + "/index.php/apps/keepiq" + path) {
            this.method = method
            header(HttpHeaders.Authorization, auth)
            header("OCS-APIRequest", "true")
            header(HttpHeaders.Accept, "application/json")
            if (body != null) setBody(TextContent(body.toString(), ContentType.Application.Json))
        }
        val text = response.bodyAsText()
        val status = response.status.value
        if (status !in 200..299) {
            throw KeepiqApiException(status, "Keepiq ${method.value} $path failed ($status)", refusalCode(text), text)
        }
        if (status == 204 || text.isBlank()) return null
        val data = try {
            Json.parseToJsonElement(text)
        } catch (e: Exception) {
            throw KeepiqApiException(status, "Keepiq ${method.value} $path answered with something that is not JSON", body = text)
        }
        val meta = ((data as? JsonObject)?.get("ocs") as? JsonObject)?.get("meta") as? JsonObject
        val ocsStatus = (meta?.get("statuscode") as? JsonPrimitive)?.let { it.intOrNull ?: it.contentOrNull?.toIntOrNull() }
        if (ocsStatus != null && ocsStatus >= 400) {
            throw KeepiqApiException(ocsStatus, "Keepiq ${method.value} $path failed ($ocsStatus)", body = text)
        }
        return data
    }

    /** GET /api/v1/offline/manifest: suite, secrets, folders and types in one answer. */
    suspend fun offlineManifest(): Manifest = Manifest.from(request(HttpMethod.Get, "/api/v1/offline/manifest")?.jsonObject ?: JsonObject(emptyMap()))

    /** The newest `updatedAt` and the total, for the cheap freshness check. */
    suspend fun latestSecret(): Freshness {
        val data = request(HttpMethod.Get, "/api/v1/secrets?sort=updated_at&direction=desc&limit=1")?.jsonObject
        val top = (data?.get("items") as? JsonArray)?.firstOrNull()?.jsonObject?.get("updatedAt")?.jsonPrimitive?.contentOrNull
        val total = (data?.get("total") as? JsonPrimitive)?.longOrNull ?: 0L
        return Freshness(top, total)
    }

    /** Every secret, page by page, for an organisation without offline caching. */
    suspend fun listSecrets(): List<JsonObject> {
        val items = ArrayList<JsonObject>()
        for (page in 1..MAX_SECRET_PAGES) {
            val data = request(HttpMethod.Get, "/api/v1/secrets?page=$page&limit=$SECRETS_PAGE_SIZE")?.jsonObject
            val batch = (data?.get("items") as? JsonArray)?.map { it.jsonObject } ?: emptyList()
            items += batch
            val total = (data?.get("total") as? JsonPrimitive)?.longOrNull ?: 0L
            if (batch.size < SECRETS_PAGE_SIZE || items.size >= total) return items
        }
        throw KeepiqApiException(413, "The vault has more items than can be read page by page")
    }

    suspend fun listFolders(): List<JsonObject> = itemsOf(request(HttpMethod.Get, "/api/v1/folders"))

    suspend fun listTypes(): List<JsonObject> = itemsOf(request(HttpMethod.Get, "/api/v1/secret-types"))

    /** The active suite, or null when there is none. */
    suspend fun activeSuite(): Suite? = itemsOf(request(HttpMethod.Get, "/api/v1/suites"))
        .firstOrNull { it["status"]?.jsonPrimitive?.contentOrNull == "active" }
        ?.let { Suite.from(it) }

    /** POST /api/v1/secrets with an already-encrypted body. */
    suspend fun createSecret(body: JsonObject): JsonObject? = request(HttpMethod.Post, "/api/v1/secrets", body) as? JsonObject

    /** PUT /api/v1/secrets/{id} with an already-encrypted body. */
    suspend fun updateSecret(id: String, body: JsonObject): JsonObject? =
        request(HttpMethod.Put, "/api/v1/secrets/" + encodePath(id), body) as? JsonObject

    /** GET /api/v1/secrets/{id}: one secret with its ciphertext, fetched fresh. */
    suspend fun getSecret(id: String): JsonObject? =
        request(HttpMethod.Get, "/api/v1/secrets/" + encodePath(id)) as? JsonObject

    /** DELETE /api/v1/secrets/{id}: moves the secret to the trash. */
    suspend fun trashSecret(id: String) {
        request(HttpMethod.Delete, "/api/v1/secrets/" + encodePath(id))
    }

    /** POST /api/v1/secrets/{id}/used: records a fill of a use-only copy for its owner. */
    suspend fun reportUseOnlyFill(id: String) {
        request(HttpMethod.Post, "/api/v1/secrets/" + encodePath(id) + "/used")
    }

    /** POST /api/v1/folders. */
    suspend fun createFolder(name: String, parentId: String?): JsonObject? = request(
        HttpMethod.Post,
        "/api/v1/folders",
        JsonObject(mapOf("name" to JsonPrimitive(name), "parentId" to (parentId?.let { JsonPrimitive(it) } ?: JsonNull))),
    ) as? JsonObject

    /** PUT /api/v1/folders/{id}: only the name is sent. */
    suspend fun renameFolder(id: String, name: String) {
        request(HttpMethod.Put, "/api/v1/folders/" + encodePath(id), JsonObject(mapOf("name" to JsonPrimitive(name))))
    }

    /** GET /api/v1/folders/{id}/children: the direct item count and the subfolders. */
    suspend fun folderChildren(id: String): JsonObject? =
        request(HttpMethod.Get, "/api/v1/folders/" + encodePath(id) + "/children") as? JsonObject

    /** DELETE /api/v1/folders/{id}, with `?cascade=` for a leaf folder that holds items. */
    suspend fun deleteFolder(id: String, cascade: String? = null) {
        val query = if (cascade != null) "?cascade=" + encodePath(cascade) else ""
        request(HttpMethod.Delete, "/api/v1/folders/" + encodePath(id) + query)
    }

    /** GET /api/settings/policy, or null when it cannot be read: an unread policy never blocks. */
    suspend fun fetchPolicy(): JsonObject? = try {
        request(HttpMethod.Get, "/api/settings/policy") as? JsonObject
    } catch (e: kotlinx.coroutines.CancellationException) {
        throw e
    } catch (e: Exception) {
        null
    }

    /** POST /api/v1/sends with an already-encrypted body. */
    suspend fun createSend(body: JsonObject): JsonObject? = request(HttpMethod.Post, "/api/v1/sends", body) as? JsonObject

    /** GET /api/v1/sends: the account's own sends, metadata only. */
    suspend fun listSends(): List<JsonObject> = (request(HttpMethod.Get, "/api/v1/sends") as? JsonArray)
        ?.mapNotNull { it as? JsonObject } ?: emptyList()

    /** DELETE /api/v1/sends/{id}: ends a send. */
    suspend fun revokeSend(id: String) {
        request(HttpMethod.Delete, "/api/v1/sends/" + encodePath(id))
    }

    /** The public base for recipient links on this account's server. */
    val publicBase: String get() = "$base/index.php/apps/keepiq/public"

    private fun itemsOf(data: JsonElement?): List<JsonObject> = when (data) {
        is JsonArray -> data.map { it.jsonObject }
        is JsonObject -> (data["items"] as? JsonArray)?.map { it.jsonObject } ?: emptyList()
        else -> emptyList()
    }

    companion object {
        /** SecretService::MAX_LIMIT. */
        const val SECRETS_PAGE_SIZE = 100
        const val MAX_SECRET_PAGES = 1000

        /** An HttpClient for [KeepiqApi]: no redirects, non-2xx handled by [request]. */
        fun httpClient(engine: HttpClientEngine): HttpClient = HttpClient(engine) {
            expectSuccess = false
            followRedirects = false
        }

        internal fun refusalCode(text: String): String? = try {
            val obj = Json.parseToJsonElement(text.ifBlank { "{}" }) as? JsonObject
            (obj?.get("error") ?: obj?.get("code"))?.takeIf { it !is JsonNull }?.jsonPrimitive?.contentOrNull
        } catch (e: Exception) {
            null
        }

        private fun encodePath(segment: String): String = nl.conduction.keepiq.shared.crypto.SendCrypto.encodeUriComponent(segment)
    }
}

/** The answer of the cheap freshness check. */
data class Freshness(val top: String?, val total: Long)

/** The active encryption suite, as far as sync needs it. */
data class Suite(
    val id: String,
    val unlockKeyEpoch: Long?,
    val certificate: String?,
    val privateKey: String?,
    val unlockBlocked: String?,
) {
    companion object {
        fun from(obj: JsonObject): Suite = Suite(
            id = obj["id"]?.jsonPrimitive?.contentOrNull ?: "",
            unlockKeyEpoch = (obj["unlockKeyEpoch"] as? JsonPrimitive)?.takeIf { !it.isString }?.longOrNull,
            certificate = (obj["certificate"] as? JsonPrimitive)?.contentOrNull,
            privateKey = (obj["privateKey"] as? JsonPrimitive)?.contentOrNull,
            unlockBlocked = (obj["unlockBlocked"] as? JsonPrimitive)?.contentOrNull,
        )
    }
}

/** GET /api/v1/offline/manifest (lib/Service/OfflineManifestService::buildForUser). */
data class Manifest(
    val suite: Suite?,
    val secrets: List<JsonObject>,
    val folders: List<JsonObject>,
    val types: List<JsonObject>,
    val unlockBlocked: String?,
) {
    companion object {
        fun from(obj: JsonObject): Manifest = Manifest(
            suite = (obj["suite"] as? JsonObject)?.let { Suite.from(it) },
            secrets = (obj["secrets"] as? JsonArray)?.map { it.jsonObject } ?: emptyList(),
            folders = (obj["folders"] as? JsonArray)?.map { it.jsonObject } ?: emptyList(),
            types = (obj["types"] as? JsonArray)?.map { it.jsonObject } ?: emptyList(),
            unlockBlocked = (obj["unlockBlocked"] as? JsonPrimitive)?.contentOrNull,
        )
    }
}

internal fun JsonObject.string(name: String): String? = (this[name] as? JsonPrimitive)?.contentOrNull
