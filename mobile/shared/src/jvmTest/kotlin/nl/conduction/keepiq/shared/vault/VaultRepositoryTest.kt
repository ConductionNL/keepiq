// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.vault

import app.cash.sqldelight.driver.jdbc.sqlite.JdbcSqliteDriver
import io.ktor.client.engine.mock.MockEngine
import io.ktor.client.engine.mock.respond
import io.ktor.http.HttpHeaders
import io.ktor.http.HttpMethod
import io.ktor.http.HttpStatusCode
import io.ktor.http.content.TextContent
import io.ktor.http.headersOf
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import nl.conduction.keepiq.shared.api.Account
import nl.conduction.keepiq.shared.api.KeepiqApi
import nl.conduction.keepiq.shared.api.MissingCapabilityException
import nl.conduction.keepiq.shared.crypto.PasskeyCredential
import nl.conduction.keepiq.shared.crypto.Primitives
import nl.conduction.keepiq.shared.crypto.RsaFields
import nl.conduction.keepiq.shared.crypto.RsaPrivateKey
import nl.conduction.keepiq.shared.crypto.RsaPublicKey
import nl.conduction.keepiq.shared.store.UnlockKeySealer
import nl.conduction.keepiq.shared.store.VaultStore
import nl.conduction.keepiq.shared.store.db.KeepiqDatabase
import nl.conduction.keepiq.shared.sync.LockReason
import nl.conduction.keepiq.shared.sync.SyncListener
import nl.conduction.keepiq.shared.sync.SyncTrigger
import nl.conduction.keepiq.shared.sync.VaultSync
import nl.conduction.keepiq.shared.vectors.Vectors
import nl.conduction.keepiq.shared.vectors.str
import java.io.IOException
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertIs
import kotlin.test.assertNotNull
import kotlin.test.assertNull
import kotlin.test.assertTrue

/**
 * The vault screens' logic (tasks 3.1 to 3.4) against a fake server and the
 * real store: reading without decrypting, opening and decrypting one item,
 * writes encrypted to the suite's key in the web app's shapes, a refusal
 * inside an HTTP 200 shown as refused, use-only copies kept closed, and
 * offline reading with edits refused before anything is sent.
 */
class VaultRepositoryTest {
    private val privatePem = Vectors.envelope.str("privateKeyPem")
    private val keys = RsaVaultKeys.fromPem(privatePem, Vectors.envelope.str("certificatePem"), "suite-9", 2)
    private val publicKey = RsaPublicKey.fromPem(Vectors.envelope.str("publicKeyPem"))
    private val privateKey = RsaPrivateKey.fromPem(privatePem)

    private fun enc(text: String) = RsaFields.encrypt(text, publicKey)
    private fun dec(text: String) = RsaFields.decrypt(text, privateKey)

    private val requests = mutableListOf<Pair<String, String>>()
    private val bodies = mutableListOf<JsonObject>()
    private var offline = false
    private var refuseWrites = false
    private var cachingOff = false
    private var capabilities = """["match","totp-field","passkeys-field"]"""

    /** PUTs still to refuse as stale (409), and what changes on the server when one is. */
    private var staleWrites = 0
    private var afterStale: () -> Unit = {}

    private val loginRow = """{"id":"s1","name":"Huisbank","url":"https://mijn.huisbank.example","typeId":"1","folderId":"f2",
        "key":"${enc("geheim-1")}","login":"${enc("alice")}","additionalFields":"${enc("""{"notes":"pin bij balie","Klantnummer":"123","extra":42}""")}",
        "useOnly":false,"readOnly":false,"blocked":false,"updatedAt":"2026-10-03T09:00:00+00:00"}"""
    private val useOnlyRow = """{"id":"s2","name":"Leveranciersportaal","url":"https://portal.supplier.example","typeId":"1","folderId":null,
        "key":"${enc("niet-tonen")}","login":"${enc("bob")}","additionalFields":"${enc("""{"geheim":"x"}""")}",
        "useOnly":true,"readOnly":true,"blocked":false,"updatedAt":"2026-10-01T09:00:00+00:00"}"""
    private fun credential(id: String, userName: String) = PasskeyCredential(
        credentialId = id, rpId = "mail.example", rpName = "Mail", userName = userName, userDisplayName = "",
        userHandle = "dTE", privateKey = "pk-$id", algorithm = -7L, counter = 0L, transports = emptyList(),
        createdAt = "2026-03-12T10:00:00.000Z",
    )
    private fun passkeysOf(vararg ids: String) = "[" + ids.joinToString(",") { credential(it, "alice-$it").toJson() } + "]"
    private val seed = "JBSWY3DPEHPK3PXP"

    /** A login with its own seed and two passkeys; what GET /secrets/s4 answers. */
    private var mailRow = mailRowWith(passkeysOf("a", "b"))
    private fun mailRowWith(passkeys: String) = """{"id":"s4","name":"Mail","url":"https://mail.example","typeId":"1","folderId":null,
        "key":"${enc("geheim-4")}","login":"${enc("alice")}","totp":"${enc(seed)}","passkeys":"${enc(passkeys)}",
        "useOnly":false,"readOnly":false,"blocked":false,"updatedAt":"2026-10-03T09:00:00+00:00"}"""
    private val useOnlyMailRow = """{"id":"s5","name":"Gedeelde mail","url":"https://mail.example","typeId":"1","folderId":null,
        "login":"${enc("bob")}","totp":"${enc(seed)}","passkeys":"${enc(passkeysOf("c"))}",
        "useOnly":true,"readOnly":true,"blocked":false,"updatedAt":"2026-10-03T09:00:00+00:00"}"""
    private val blockedRow = """{"id":"s3","name":"Ánders","url":null,"typeId":"2","folderId":null,"blocked":true,"blockedReason":"Old suite","updatedAt":"2026-10-01T08:00:00+00:00"}"""
    private val types = """[{"id":"1","name":"login","fields":[{"key":"klantnummer","label":"Klantnummer","kind":"text","required":true}]},{"id":"2","name":"note","fields":[]}]"""
    private val folders = """[{"id":"f1","name":"Werk","parentId":null},{"id":"f2","name":"Bank","parentId":"f1"},{"id":"f3","name":"Algemeen","parentId":null}]"""

    private val engine = MockEngine { request ->
        val path = request.url.encodedPath.substringAfter("/apps/keepiq")
        // The capability check before a write of `totp` or `passkeys` (KeepiqApi.requireLoginFields).
        if (path == "/api/v1/extension/pair") {
            return@MockEngine respond("""{"ok":true,"apiVersion":1,"capabilities":$capabilities}""", HttpStatusCode.OK, headersOf(HttpHeaders.ContentType, "application/json"))
        }
        requests += request.method.value to path
        (request.body as? TextContent)?.let { bodies += Json.parseToJsonElement(it.text).jsonObject }
        if (offline) throw IOException("no network")
        if (request.method == HttpMethod.Put && staleWrites > 0) {
            staleWrites--
            afterStale()
            return@MockEngine respond("""{"message":"This secret changed since your copy was made"}""", HttpStatusCode.Conflict, headersOf(HttpHeaders.ContentType, "application/json"))
        }
        val write = request.method != HttpMethod.Get
        val body = when {
            write && refuseWrites -> """{"ocs":{"meta":{"status":"failure","statuscode":403,"message":""},"data":{"error":"read_only","message":"You can only read this item."}}}"""
            path == "/api/v1/offline/manifest" && cachingOff ->
                return@MockEngine respond("""{"message":"Offline caching is off"}""", HttpStatusCode(428, "Precondition Required"))
            path == "/api/v1/suites" -> """[{"id":"suite-9","status":"active","unlockKeyEpoch":2}]"""
            path == "/api/v1/secrets" && request.method == HttpMethod.Get -> """{"items":[$loginRow],"total":1}"""
            path == "/api/v1/folders" && request.method == HttpMethod.Get -> folders
            path == "/api/v1/secret-types" -> types
            path == "/api/v1/offline/manifest" -> """{"suite":{"id":"suite-9","status":"active","unlockKeyEpoch":2},"secrets":[$loginRow,$useOnlyRow,$blockedRow],"folders":$folders,"types":$types,"unlockBlocked":null}"""
            path == "/api/v1/secrets/s1" && request.method == HttpMethod.Get -> loginRow
            path == "/api/v1/secrets/s2" && request.method == HttpMethod.Get -> useOnlyRow
            path == "/api/v1/secrets/s4" && request.method == HttpMethod.Get -> mailRow
            path == "/api/v1/secrets/s5" && request.method == HttpMethod.Get -> useOnlyMailRow
            path == "/api/v1/secrets" && request.method == HttpMethod.Post -> """{"id":"new-1"}"""
            path.startsWith("/api/v1/folders/") && path.endsWith("/children") -> """{"directSecretCount":2,"subfolders":[]}"""
            else -> "{}"
        }
        respond(body, HttpStatusCode.OK, headersOf(HttpHeaders.ContentType, "application/json"))
    }

    private val api = KeepiqApi(KeepiqApi.httpClient(engine), Account("a", "https://cloud.example.nl", "alice", "pw"))
    private val store = VaultStore(
        JdbcSqliteDriver(JdbcSqliteDriver.IN_MEMORY).also { KeepiqDatabase.Schema.create(it) },
        UnlockKeySealer(Primitives.randomBytes(32)),
    )
    private val locks = mutableListOf<LockReason>()
    private var now = 50_000_000L
    private val sync = VaultSync(api, store, object : SyncListener {
        override fun lock(reason: LockReason) {
            locks += reason
        }

        override fun deleteUnlockWraps() = Unit
    }, clock = { now })
    private val repo = VaultRepository(api, keys, store, sync, clock = { now })

    @Test
    fun theListShowsNamesAndUrlsWithoutDecryptingAnything() = runTest {
        val state = repo.refresh(SyncTrigger.START)
        assertEquals(listOf("Ánders", "Huisbank", "Leveranciersportaal"), state.index.map { it.name })
        val huisbank = state.index.single { it.id == "s1" }
        assertEquals("Bank", huisbank.folderName)
        assertEquals("login", huisbank.typeName)
        assertTrue(state.index.single { it.id == "s2" }.useOnly)
        assertEquals(now, state.syncedAtMillis)
        assertFalse(state.offline)
        // Only the manifest: no item was fetched to build the list.
        assertEquals(listOf("GET" to "/api/v1/offline/manifest"), requests)
    }

    @Test
    fun searchMatchesNameAndAddressAndFoldersFollowTheTree() = runTest {
        val state = repo.refresh(SyncTrigger.START)
        assertEquals(listOf("s1"), VaultIndex.filter(state.index, "HUISBANK.ex").map { it.id })
        assertEquals(listOf("s2"), VaultIndex.filter(state.index, "supplier").map { it.id })
        assertEquals(listOf("s1"), VaultIndex.filter(state.index, folderId = "f2").map { it.id })
        assertEquals(listOf("s3", "s2"), VaultIndex.filter(state.index, folderId = VaultIndex.NO_FOLDER).map { it.id })
        assertEquals(listOf("Algemeen" to 0, "Werk" to 0, "Bank" to 1), VaultIndex.folderTree(state.folders).map { it.name to it.depth })
        assertEquals("Werk / Bank", VaultIndex.folderPath(state.folders, "f2"))
        assertEquals(ListState.NO_MATCH, VaultIndex.listState(state.index, VaultIndex.filter(state.index, "zzz")))
    }

    @Test
    fun openingAnItemDecryptsItsFieldsAndTypedValues() = runTest {
        repo.refresh(SyncTrigger.START)
        val item = assertIs<OpenResult.Opened>(repo.open("s1")).item
        assertEquals("alice", item.login)
        assertEquals("geheim-1", item.secret)
        assertEquals("pin bij balie", item.notes)
        assertEquals(listOf("Klantnummer" to "123"), item.typedValues.map { it.first.label to it.second })
        assertEquals(listOf("extra" to "42"), item.extraFields)
        assertFalse(item.fromCache)
    }

    @Test
    fun aUseOnlyCopyNeverOpensItsValue() = runTest {
        repo.refresh(SyncTrigger.START)
        val item = assertIs<OpenResult.Opened>(repo.open("s2")).item
        assertEquals("", item.secret)
        assertNull(item.additionalFields)
        assertEquals("bob", item.login)
        assertTrue(item.row.useOnly)
        // An edit of a use-only copy is refused before anything is sent.
        val before = requests.size
        assertIs<WriteResult.Refused>(repo.update(item, ItemCodec.draft(item, item.type).copy(secret = "nieuw")))
        assertEquals(before, requests.size)
    }

    @Test
    fun aBlockedItemIsNotDecrypted() {
        val row = VaultRow.from(Json.parseToJsonElement(blockedRow).jsonObject)!!
        val item = ItemCodec.open(row, null, keys, fromCache = false)
        assertEquals("", item.secret)
        assertEquals("Old suite", item.row.blockedReason)
    }

    @Test
    fun aCreatedLoginIsEncryptedToTheSuiteKeyInTheWebAppsShape() = runTest {
        val state = repo.refresh(SyncTrigger.START)
        val type = state.typeNamed("login")!!
        val draft = ItemCodec.draft(null, type).copy(
            name = " Gemeente portaal ", url = "https://gemeente.example", login = "", secret = "Gegenereerd-20-tekens!",
            typed = mapOf("klantnummer" to "998"), notes = "",
        )
        assertTrue(ItemCodec.validate(draft, type).isEmpty())
        assertEquals(WriteResult.Saved("new-1"), repo.create(draft, type))
        val body = bodies.single()
        assertEquals("Gemeente portaal", body["name"]!!.jsonPrimitive.content)
        assertEquals("1", body["typeId"]!!.jsonPrimitive.content)
        assertEquals(JsonNull, body["folderId"])
        assertEquals("Gegenereerd-20-tekens!", dec(body["key"]!!.jsonPrimitive.content))
        assertFalse("login" in body, "an empty login is left out, as the web app does")
        assertEquals("""{"Klantnummer":"998"}""", dec(body["additionalFields"]!!.jsonPrimitive.content))
        assertFalse(body.toString().contains("Gegenereerd"), "no plaintext value in the request")
        // A write syncs after it went through.
        assertEquals("GET" to "/api/v1/offline/manifest", requests.last())
    }

    @Test
    fun anUpdateSendsOnlyWhatChanged() = runTest {
        repo.refresh(SyncTrigger.START)
        val item = assertIs<OpenResult.Opened>(repo.open("s1")).item
        val draft = ItemCodec.draft(item, item.type)
        assertEquals("123", draft.typed["klantnummer"])
        assertEquals(mapOf("extra" to JsonPrimitive(42)), draft.preserved)
        assertIs<WriteResult.Saved>(repo.update(item, draft.copy(secret = "nieuw-wachtwoord", login = "")))
        val body = bodies.single()
        assertEquals(setOf("key", "login"), body.keys)
        assertEquals("nieuw-wachtwoord", dec(body["key"]!!.jsonPrimitive.content))
        assertEquals(JsonNull, body["login"])
    }

    @Test
    fun editingTheNotesRewritesTheAdditionalFieldsAndKeepsTheRest() = runTest {
        repo.refresh(SyncTrigger.START)
        val item = assertIs<OpenResult.Opened>(repo.open("s1")).item
        assertIs<WriteResult.Saved>(repo.update(item, ItemCodec.draft(item, item.type).copy(notes = "nieuw")))
        val body = bodies.single()
        assertEquals(setOf("additionalFields"), body.keys)
        val fields = Json.parseToJsonElement(dec(body["additionalFields"]!!.jsonPrimitive.content)).jsonObject
        assertEquals(JsonPrimitive(42), fields["extra"])
        assertEquals("123", fields["Klantnummer"]!!.jsonPrimitive.content)
        assertEquals("nieuw", fields["notes"]!!.jsonPrimitive.content)
    }

    @Test
    fun aRefusalInsideAnHttp200IsShownAsRefusedNeverAsSaved() = runTest {
        repo.refresh(SyncTrigger.START)
        val item = assertIs<OpenResult.Opened>(repo.open("s1")).item
        refuseWrites = true
        val result = assertIs<WriteResult.Refused>(repo.update(item, ItemCodec.draft(item, item.type).copy(secret = "x")))
        assertEquals(WriteProblemKind.SERVER_MESSAGE, result.problem.kind)
        assertEquals("You can only read this item.", result.problem.serverMessage)
        // No sync after a refused write: the stored value stays.
        assertEquals("PUT" to "/api/v1/secrets/s1", requests.last())
        assertEquals("geheim-1", dec(store.secret("s1")!!.key!!))
    }

    @Test
    fun moveSendsOnlyTheFolderAndTrashDeletes() = runTest {
        repo.refresh(SyncTrigger.START)
        assertIs<WriteResult.Saved>(repo.move("s1", "f3"))
        assertEquals(JsonObject(mapOf("folderId" to JsonPrimitive("f3"))), bodies.single())
        assertIs<WriteResult.Saved>(repo.trash("s1"))
        assertTrue(("DELETE" to "/api/v1/secrets/s1") in requests)
    }

    @Test
    fun foldersAreCreatedRenamedAndDeleted() = runTest {
        repo.refresh(SyncTrigger.START)
        assertIs<WriteResult.Saved>(repo.createFolder(" Privé ", "f1"))
        assertEquals("""{"name":"Privé","parentId":"f1"}""", bodies.last().toString())
        assertIs<WriteResult.Saved>(repo.renameFolder("f3", "Thuis"))
        assertEquals("""{"name":"Thuis"}""", bodies.last().toString())
        assertEquals(FolderDeleteKind.ITEMS, repo.folderDeleteKind("f3"))
        assertIs<WriteResult.Saved>(repo.deleteFolder("f3", FolderDeleteKind.ITEMS, deleteItems = false))
        assertTrue(requests.any { it.first == "DELETE" && it.second == "/api/v1/folders/f3" })
        assertIs<WriteResult.Refused>(repo.deleteFolder("f1", FolderDeleteKind.SUBFOLDERS, deleteItems = false))
    }

    @Test
    fun offlineTheVaultReadsFromTheStoreAndRefusesEditsBeforeSending() = runTest {
        repo.refresh(SyncTrigger.START)
        val syncedAt = now
        offline = true
        now += 3_600_000
        val state = repo.refresh(SyncTrigger.FOREGROUND)
        assertTrue(state.offline)
        assertEquals(syncedAt, state.syncedAtMillis)
        assertEquals(3, state.index.size)
        val item = assertIs<OpenResult.Opened>(repo.open("s1")).item
        assertTrue(item.fromCache)
        assertEquals("geheim-1", item.secret)
        val sent = requests.size
        val result = assertIs<WriteResult.Refused>(repo.update(item, ItemCodec.draft(item, item.type).copy(secret = "x")))
        assertEquals(WriteProblemKind.OFFLINE, result.problem.kind)
        assertIs<WriteResult.Refused>(repo.createFolder("x", null))
        assertEquals(sent, requests.size, "nothing is sent while offline")
    }

    @Test
    fun withCachingOffAndNoNetworkTheVaultNeedsAConnection() = runTest {
        val noStore = VaultRepository(api, keys, store = null, sync = null, clock = { now })
        assertEquals(3, noStore.refresh(SyncTrigger.START).index.size)
        offline = true
        val state = noStore.refresh(SyncTrigger.FOREGROUND)
        assertTrue(state.needsConnection)
        assertTrue(state.index.isEmpty())
    }

    @Test
    fun withCachingOffTheStoreStaysEmptyAndOfflineTheVaultNeedsAConnection() = runTest {
        repo.refresh(SyncTrigger.START)
        cachingOff = true
        val online = repo.refresh(SyncTrigger.MANUAL)
        assertTrue(online.onlineOnly)
        assertEquals(listOf("Huisbank"), online.index.map { it.name })
        assertTrue(store.secrets().isEmpty(), "nothing is kept on the device")
        offline = true
        val state = repo.refresh(SyncTrigger.FOREGROUND)
        assertTrue(state.needsConnection)
        assertTrue(state.index.isEmpty())
    }

    @Test
    fun anEpochChangeLocksWithoutAStoreToo() = runTest {
        val other = RsaVaultKeys.fromPem(privatePem, Vectors.envelope.str("certificatePem"), "suite-9", 1)
        val state = VaultRepository(api, other, store = null, sync = null, clock = { now }).refresh(SyncTrigger.START)
        assertEquals(LockReason.MASTER_PASSWORD_CHANGED, state.locked)
        assertNotNull(repo.refresh(SyncTrigger.START))
    }

    @Test
    fun writeProblemsReadTheExtensionsRules() {
        fun p(status: Int, body: String) = WriteProblem.from(nl.conduction.keepiq.shared.api.KeepiqApiException(status, "x", null, body))
        assertEquals(WriteProblemKind.KEY_MIGRATION, p(423, "").kind)
        assertEquals(WriteProblemKind.SUITE_BLOCKED, p(403, """{"error":"forbidden"}""").kind)
        assertEquals(WriteProblem(WriteProblemKind.SERVER_MESSAGE, "Policy says no", 428), p(428, """{"code":"policy","message":"Policy says no"}"""))
        assertEquals(WriteProblem(WriteProblemKind.SERVER_MESSAGE, "Name taken", 409), p(409, """{"message":"Name taken"}"""))
        assertEquals(WriteProblemKind.UNREACHABLE, WriteProblem.from(IOException()).kind)
    }

    @Test
    fun draftsAreCheckedAsTheExtensionChecksThem() {
        val type = SecretType("1", "login", null, listOf(TypeField("k", "Klantnummer", "text", true)))
        val draft = ItemCodec.draft(null, type).copy(fields = listOf("url" to "x", "Klantnummer" to "y", "" to "z"))
        val problems = ItemCodec.validate(draft, type)
        assertEquals(DraftProblem.NAME_MISSING, problems["name"])
        assertEquals(DraftProblem.FIELD_NAME_RESERVED, problems["field-0"])
        assertEquals(DraftProblem.FIELD_NAME_TAKEN, problems["field-1"])
        assertEquals(DraftProblem.FIELD_NAME_MISSING, problems["field-2"])
        assertEquals(DraftProblem.REQUIRED, problems["typed-k"])
        val totp = ItemCodec.draft(null, SecretType("3", "totp", null, emptyList())).copy(name = "x", secret = "not base32 !")
        assertEquals(DraftProblem.NOT_AN_AUTHENTICATOR_SECRET, ItemCodec.validate(totp, null)["secret"])
    }

    @Test
    fun cardsKeepTheWebAppsMemberOrder() {
        val type = SecretType("4", "card", null, emptyList())
        val draft = ItemCodec.draft(null, type).copy(name = "Pas", composite = mapOf("cvv" to "123", "number" to "4111111111111111"))
        assertEquals(
            """{"number":"4111111111111111","expiry":"","cvv":"123","pin":"","cardholder":""}""",
            ItemCodec.parts(draft, type).key,
        )
    }

    @Test
    fun opensALoginsCodeAndPasskeys() = runTest {
        repo.refresh(SyncTrigger.START)
        val item = assertIs<OpenResult.Opened>(repo.open("s4")).item
        assertNotNull(item.totp)
        assertTrue(item.hasSavedKey)
        assertEquals("geheim-4", item.secret)
        assertEquals(listOf("a", "b"), item.passkeys.map { it.credentialId })
        assertEquals(listOf("alice-a", "alice-b"), item.passkeys.map { it.userName })
        assertEquals("2026-03-12T10:00:00.000Z", item.passkeys.first().createdAt)
        assertFalse(item.passkeysError)
    }

    @Test
    fun aUseOnlyCopyOpensItsCodeButNoPasskeys() = runTest {
        repo.refresh(SyncTrigger.START)
        val item = assertIs<OpenResult.Opened>(repo.open("s5")).item
        assertNotNull(item.totp)
        assertTrue(item.passkeys.isEmpty())
        assertEquals("", item.secret)
    }

    @Test
    fun anEditThatKeepsTheKeySendsNeitherField() = runTest {
        repo.refresh(SyncTrigger.START)
        val item = assertIs<OpenResult.Opened>(repo.open("s4")).item
        val draft = ItemCodec.draft(item, item.type)
        assertTrue(draft.hasSavedKey)
        assertEquals(KeyEdit.KEEP, draft.keyEdit)
        assertIs<WriteResult.Saved>(repo.update(item, draft.copy(name = "Mail thuis")))
        assertEquals(setOf("name"), bodies.single().keys)
    }

    @Test
    fun replacingTheKeySendsItEncrypted() = runTest {
        repo.refresh(SyncTrigger.START)
        val item = assertIs<OpenResult.Opened>(repo.open("s1")).item
        val draft = ItemCodec.draft(item, item.type).copy(keyEdit = KeyEdit.REPLACE, newKey = " $seed ")
        assertIs<WriteResult.Saved>(repo.update(item, draft))
        val body = bodies.single()
        assertEquals(setOf("totp"), body.keys)
        assertEquals(seed, dec(body["totp"]!!.jsonPrimitive.content))
    }

    @Test
    fun removingTheKeySendsAnEmptyString() = runTest {
        repo.refresh(SyncTrigger.START)
        val item = assertIs<OpenResult.Opened>(repo.open("s4")).item
        assertIs<WriteResult.Saved>(repo.update(item, ItemCodec.draft(item, item.type).copy(keyEdit = KeyEdit.REMOVE)))
        assertEquals(JsonObject(mapOf("totp" to JsonPrimitive(""))), bodies.single())
    }

    @Test
    fun aNewLoginSendsItsKeyOnlyWhenSet() = runTest {
        val type = repo.refresh(SyncTrigger.START).typeNamed("login")
        val draft = ItemCodec.draft(null, type).copy(name = "Nieuw", typed = mapOf("klantnummer" to "1"))
        assertIs<WriteResult.Saved>(repo.create(draft, type))
        assertFalse("totp" in bodies.last())
        assertFalse("passkeys" in bodies.last())
        assertIs<WriteResult.Saved>(repo.create(draft.copy(keyEdit = KeyEdit.REPLACE, newKey = seed), type))
        assertEquals(seed, dec(bodies.last()["totp"]!!.jsonPrimitive.content))
    }

    @Test
    fun aKeyTheGeneratorCannotReadIsRefused() {
        val draft = ItemCodec.draft(null, null).copy(name = "x")
        assertEquals(DraftProblem.NOT_AN_AUTHENTICATOR_SECRET, ItemCodec.validate(draft.copy(keyEdit = KeyEdit.REPLACE, newKey = "not a key!"), null)["totp"])
        assertNull(ItemCodec.validate(draft.copy(keyEdit = KeyEdit.REPLACE, newKey = seed), null)["totp"])
        assertNull(ItemCodec.validate(draft.copy(keyEdit = KeyEdit.REMOVE), null)["totp"])
        assertNull(ItemCodec.validate(draft, null)["totp"])
    }

    @Test
    fun deletingAPasskeyKeepsTheOthersFromTheFreshRow() = runTest {
        repo.refresh(SyncTrigger.START)
        mailRow = mailRowWith(passkeysOf("a"))
        val item = assertIs<OpenResult.Opened>(repo.open("s4")).item
        // Another client added "b" since the phone opened the login.
        mailRow = mailRowWith(passkeysOf("a", "b"))
        assertIs<WriteResult.Saved>(repo.deletePasskey(item, "a", "mail.example"))
        assertEquals("PUT" to "/api/v1/secrets/s4", requests.first { it.first == "PUT" })
        val body = bodies.single()
        assertEquals(setOf("passkeys", "baseUpdatedAt"), body.keys)
        assertEquals("2026-10-03T09:00:00+00:00", body["baseUpdatedAt"]!!.jsonPrimitive.content)
        assertEquals(listOf("b"), LoginPasskeys.credentials(dec(body["passkeys"]!!.jsonPrimitive.content)).map { it.credentialId })
    }

    @Test
    fun aDeleteRefusedAsStaleIsMadeOnceMoreOnTheNewRow() = runTest {
        repo.refresh(SyncTrigger.START)
        val item = assertIs<OpenResult.Opened>(repo.open("s4")).item
        staleWrites = 1
        // Another client added "c" between the phone's read and its write.
        afterStale = { mailRow = mailRowWith(passkeysOf("a", "b", "c")).replace("2026-10-03T09:00:00+00:00", "2026-10-03T10:00:00+00:00") }
        assertIs<WriteResult.Saved>(repo.deletePasskey(item, "a", "mail.example"))
        assertEquals(listOf("PUT", "PUT"), requests.filter { it.first == "PUT" }.map { it.first })
        val body = bodies.last()
        assertEquals("2026-10-03T10:00:00+00:00", body["baseUpdatedAt"]!!.jsonPrimitive.content)
        assertEquals(listOf("b", "c"), LoginPasskeys.credentials(dec(body["passkeys"]!!.jsonPrimitive.content)).map { it.credentialId })

        staleWrites = 2
        val refused = assertIs<WriteResult.Refused>(repo.deletePasskey(item, "b", "mail.example"))
        assertEquals(409, refused.problem.status)
    }

    @Test
    fun deletingTheLastPasskeySendsAnEmptyString() = runTest {
        repo.refresh(SyncTrigger.START)
        mailRow = mailRowWith(passkeysOf("a"))
        val item = assertIs<OpenResult.Opened>(repo.open("s4")).item
        assertIs<WriteResult.Saved>(repo.deletePasskey(item, "a", "mail.example"))
        assertEquals(JsonObject(mapOf("passkeys" to JsonPrimitive(""), "baseUpdatedAt" to JsonPrimitive("2026-10-03T09:00:00+00:00"))), bodies.single())
    }

    @Test
    fun aServerPairedBeforeTheFieldsTakesNoCodeOrPasskeyWrite() = runTest {
        capabilities = """["match"]"""
        val type = repo.refresh(SyncTrigger.START).typeNamed("login")
        val item = assertIs<OpenResult.Opened>(repo.open("s4")).item
        val results = listOf(
            repo.deletePasskey(item, "a", "mail.example"),
            repo.update(item, ItemCodec.draft(item, item.type).copy(keyEdit = KeyEdit.REMOVE)),
            repo.create(ItemCodec.draft(null, type).copy(name = "Nieuw", typed = mapOf("klantnummer" to "1"), keyEdit = KeyEdit.REPLACE, newKey = seed), type),
        )
        for (r in results) {
            val problem = assertIs<WriteResult.Refused>(r).problem
            assertEquals(WriteProblemKind.SERVER_MESSAGE, problem.kind)
            assertEquals(MissingCapabilityException.MESSAGE, problem.serverMessage)
        }
        assertTrue(requests.none { it.first == "PUT" || it.first == "POST" }, "$requests")
        // A change that writes neither field still goes through.
        assertIs<WriteResult.Saved>(repo.update(item, ItemCodec.draft(item, item.type).copy(name = "Mail 2")))
    }

    @Test
    fun theEditFormNeverHoldsTheSavedKey() = runTest {
        repo.refresh(SyncTrigger.START)
        val shown = assertIs<OpenResult.Opened>(repo.open("s4")).item
        assertEquals(seed, shown.totpSeed)
        val edited = assertIs<OpenResult.Opened>(repo.openForEdit("s4")).item
        assertEquals("", edited.totpSeed)
        assertTrue(edited.hasSavedKey)
        assertNull(edited.totp)
        // Saving the form without touching the key sends no `totp`.
        assertIs<WriteResult.Saved>(repo.update(edited, ItemCodec.draft(edited, edited.type).copy(name = "Mail 2")))
        assertFalse("totp" in bodies.last())
    }

    @Test
    fun aUseOnlyOrReadOnlyCopyCannotDeleteAPasskey() = runTest {
        repo.refresh(SyncTrigger.START)
        val useOnly = assertIs<OpenResult.Opened>(repo.open("s5")).item
        assertIs<WriteResult.Refused>(repo.deletePasskey(useOnly, "c", "mail.example"))
        // A read-only copy the user may still use: only the readOnly guard refuses it.
        mailRow = mailRowWith(passkeysOf("a", "b")).replace("\"readOnly\":false", "\"readOnly\":true")
        val readOnly = assertIs<OpenResult.Opened>(repo.open("s4")).item
        assertTrue(readOnly.row.readOnly && !readOnly.row.useOnly)
        assertIs<WriteResult.Refused>(repo.deletePasskey(readOnly, "a", "mail.example"))
        assertTrue(requests.none { it.first == "PUT" })
    }

    @Test
    fun aDeleteNeverRewritesAFreshValueThatIsNotAnArray() = runTest {
        repo.refresh(SyncTrigger.START)
        val item = assertIs<OpenResult.Opened>(repo.open("s4")).item
        // Another client wrote one object instead of an array since the phone opened the login.
        mailRow = mailRowWith(credential("a", "alice-a").toJson())
        assertIs<WriteResult.Refused>(repo.deletePasskey(item, "a", "mail.example"))
        assertTrue(requests.none { it.first == "PUT" })
    }

    @Test
    fun aFieldThatCannotBeDecryptedKeepsTheRestOfTheItemOpen() = runTest {
        repo.refresh(SyncTrigger.START)
        mailRow = """{"id":"s4","name":"Mail","url":"https://mail.example","typeId":"1","key":"${enc("geheim-4")}","login":"${enc("alice")}",
            "totp":"bm90IGNpcGhlcnRleHQ=","passkeys":"bm90IGNpcGhlcnRleHQ=","useOnly":false,"readOnly":false,"blocked":false}"""
        val item = assertIs<OpenResult.Opened>(repo.open("s4")).item
        assertEquals("geheim-4", item.secret)
        assertTrue(item.hasSavedKey)
        assertNull(item.totp)
        assertTrue(item.passkeys.isEmpty())
        assertTrue(item.passkeysError)
    }
}
