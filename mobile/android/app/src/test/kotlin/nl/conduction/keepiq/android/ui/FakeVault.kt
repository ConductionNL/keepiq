// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.ui

import io.ktor.client.engine.mock.MockEngine
import io.ktor.client.engine.mock.respond
import io.ktor.http.HttpHeaders
import io.ktor.http.HttpMethod
import io.ktor.http.HttpStatusCode
import io.ktor.http.headersOf
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import nl.conduction.keepiq.android.vault.VaultSession
import nl.conduction.keepiq.shared.api.Account
import nl.conduction.keepiq.shared.api.KeepiqApi
import nl.conduction.keepiq.shared.crypto.PasskeyCredential
import nl.conduction.keepiq.shared.sync.SyncTrigger
import nl.conduction.keepiq.shared.vault.VaultKeys

/** Passes every field through as it is: the screen tests need no real keys. */
internal class PlainKeys : VaultKeys {
    override val suiteId: String = "suite-1"
    override val unlockKeyEpoch: Long? = null

    override fun decryptField(ciphertext: String?): String = ciphertext ?: ""

    override fun encryptField(plaintext: String): String = plaintext
}

/**
 * A [VaultSession] over a fake server, for the screens that take a session
 * (ItemDetailScreen, ItemEditScreen): [rows] answer the manifest and
 * GET /secrets/{id}, every request is recorded, and a write answers `{}`.
 */
internal class FakeVault {
    val rows = LinkedHashMap<String, String>()

    /** Every PUT is refused as the server refuses a write the user may not make. */
    @Volatile var refuseWrites = false
    private val requests = mutableListOf<Pair<String, String>>()
    private val types = """[{"id":"1","name":"login","label":"Login","fields":[]},{"id":"2","name":"note","label":"Note","fields":[]},""" +
        """{"id":"3","name":"totp","label":"Authenticator","fields":[]}]"""

    private val engine = MockEngine { request ->
        val path = request.url.encodedPath.substringAfter("/apps/keepiq")
        synchronized(requests) { requests += request.method.value to path }
        val json = headersOf(HttpHeaders.ContentType, "application/json")
        if (refuseWrites && request.method == HttpMethod.Put) {
            return@MockEngine respond("""{"error":"read_only","message":"You can only read this item."}""", HttpStatusCode.Forbidden, json)
        }
        val body = when {
            path == "/api/v1/extension/pair" -> """{"ok":true,"apiVersion":1,"capabilities":["totp-field","passkeys-field"]}"""
            path == "/api/v1/offline/manifest" ->
                """{"suite":{"id":"suite-1","status":"active"},"secrets":[${synchronized(rows) { rows.values.joinToString(",") }}],"folders":[],"types":$types}"""
            path.startsWith("/api/v1/secrets/") && request.method == HttpMethod.Get ->
                synchronized(rows) { rows[path.removePrefix("/api/v1/secrets/")] }
                    ?: return@MockEngine respond("""{"message":"Secret not found"}""", HttpStatusCode.NotFound, json)
            else -> "{}"
        }
        respond(body, HttpStatusCode.OK, json)
    }

    private val account = Account("acc", "https://cloud.example.nl", "alice", "pw")
    val session = VaultSession(account, KeepiqApi(KeepiqApi.httpClient(engine), account), PlainKeys(), null, null)

    /** The writes of one method and path, such as "PUT" to "/api/v1/secrets/s4". */
    fun count(method: String, path: String): Int = synchronized(requests) { requests.count { it == method to path } }

    /** Changes what the server holds for [id], as another client or this phone's own write would. */
    fun replace(id: String, row: String) {
        synchronized(rows) { rows[id] = row }
    }

    /** Adds a row and reads the vault, so the types are known to the screens. */
    fun put(id: String, row: String) {
        synchronized(rows) { rows[id] = row }
        runBlocking { session.repository.refresh(SyncTrigger.START) }
    }

    companion object {
        fun row(id: String, name: String, typeId: String, key: String = "", login: String = "", totp: String = "", passkeys: List<PasskeyCredential> = emptyList()): String =
            buildJsonObject {
                put("id", id)
                put("name", name)
                put("url", "https://mail.example")
                put("typeId", typeId)
                put("key", key)
                put("login", login)
                put("totp", totp)
                put("passkeys", if (passkeys.isEmpty()) "" else "[" + passkeys.joinToString(",") { it.toJson() } + "]")
                put("useOnly", false)
                put("readOnly", false)
                put("blocked", false)
                put("updatedAt", JsonPrimitive("2026-10-03T09:00:00+00:00"))
            }.toString()

        fun credential(id: String, userName: String) = PasskeyCredential(
            credentialId = id, rpId = "mail.example", rpName = "Mail", userName = userName, userDisplayName = "",
            userHandle = "dTE", privateKey = "pk-$id", algorithm = -7L, counter = 0L, transports = emptyList(),
            createdAt = "",
        )
    }
}
