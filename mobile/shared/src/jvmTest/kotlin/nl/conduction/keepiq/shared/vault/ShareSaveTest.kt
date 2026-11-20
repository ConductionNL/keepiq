// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.vault

import io.ktor.client.engine.mock.MockEngine
import io.ktor.client.engine.mock.respond
import io.ktor.http.HttpHeaders
import io.ktor.http.HttpStatusCode
import io.ktor.http.content.TextContent
import io.ktor.http.headersOf
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonArray
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.put
import nl.conduction.keepiq.shared.api.Account
import nl.conduction.keepiq.shared.api.KeepiqApi
import nl.conduction.keepiq.shared.crypto.RsaFields
import nl.conduction.keepiq.shared.crypto.RsaPrivateKey
import nl.conduction.keepiq.shared.crypto.RsaPublicKey
import nl.conduction.keepiq.shared.vectors.Vectors
import nl.conduction.keepiq.shared.vectors.str
import kotlin.test.AfterTest
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFailsWith
import kotlin.test.assertFalse
import kotlin.test.assertNull
import kotlin.test.assertTrue

/**
 * The share-save port (browser-extension/src/vault/share-save.ts) against a
 * fake server that answers `incomplete` with the copies and partner shares
 * it wants: each copy sealed to its holder's certificate, a large save
 * staged in chunks, partners verified against the web app's fixture chain.
 */
class ShareSaveTest {
    private companion object {
        const val BASE = "2026-10-03T09:00:00+00:00"
        const val SOURCE_AT = "2026-10-02T09:00:00+00:00"
        const val MALLORY = "mallory@cloud.partner.example"

        /** Inside the validity of every certificate of the extension's ca-constraints chain. */
        val NOW = nl.conduction.keepiq.shared.send.IsoTime.parseMillis("2027-06-01T00:00:00Z")!!
    }

    private val privatePem = Vectors.envelope.str("privateKeyPem")
    private val certificate = Vectors.envelope.str("certificatePem")
    private val keys = RsaVaultKeys.fromPem(privatePem, certificate, "suite-9", 2)
    private val publicKey = RsaPublicKey.fromPem(Vectors.envelope.str("publicKeyPem"))
    private val privateKey = RsaPrivateKey.fromPem(privatePem)
    private fun enc(text: String) = RsaFields.encrypt(text, publicKey)
    private fun dec(text: String) = RsaFields.decrypt(text, privateKey)

    /** A partner chain whose leaf key is in the fixture, so a test opens what was sealed to it. */
    private val c = Vectors.caConstraintsChain
    private val malloryKey = RsaPrivateKey.fromPem(c.str("malloryKey"))

    private val requests = mutableListOf<Pair<String, String>>()
    private val saves = mutableListOf<JsonObject>()
    private val lookups = mutableListOf<JsonObject>()

    /** The answers of the share-save PUTs, in order; the last one repeats. */
    private var answers: List<Pair<HttpStatusCode, String>> = listOf(HttpStatusCode.OK to "{}")

    /** What the federation lookup answers. */
    private var partnerAnswer: Pair<HttpStatusCode, String> = HttpStatusCode.OK to partner()

    /** Partners whose lookup answers that they can't be reached. */
    private var unreachable = setOf<String>()

    /** Users with a vault certificate; anyone else has none. */
    private var certified = setOf("bob", "carol")

    private val engine = MockEngine { request ->
        val path = request.url.encodedPath.substringAfter("/apps/keepiq")
        requests += request.method.value to path
        val sent = (request.body as? TextContent)?.let { Json.parseToJsonElement(it.text).jsonObject }
        val json = headersOf(HttpHeaders.ContentType, "application/json")
        when {
            path.endsWith("/share-save") -> {
                saves += sent!!
                val (status, body) = answers[minOf(saves.size - 1, answers.size - 1)]
                respond(body, status, json)
            }
            path == "/api/v1/shares/recipient-certificates" -> {
                lookups += sent!!
                val rows = sent["userIds"]!!.jsonArray.map { it.jsonPrimitive.content }.filter { it in certified }
                respond("""{"recipients":[${rows.joinToString(",") { """{"userId":"$it","shareable":true,"certificate":${JsonPrimitive(certificate)}}""" }}]}""", HttpStatusCode.OK, json)
            }
            path == "/api/v1/federation/recipient-certificate" && sent?.get("cloudId")?.jsonPrimitive?.content in unreachable ->
                respond("""{"message":"partner_unreachable"}""", HttpStatusCode.BadGateway, json)
            path == "/api/v1/federation/recipient-certificate" -> respond(partnerAnswer.second, partnerAnswer.first, json)
            else -> respond("{}", HttpStatusCode.OK, json)
        }
    }

    private val api = KeepiqApi(KeepiqApi.httpClient(engine), Account("acc", "https://cloud.example.nl", "alice", "pw"))
    private val shareSave = ShareSave(api, keys) { NOW }

    @AfterTest
    fun forgetPartners() = VerifiedPartners.forget()

    private fun partner(valueFields: String = """["totp","passkeys"]"""): String = buildJsonObject {
        put("cloudId", MALLORY)
        put("certificate", c.str("mallory"))
        put("chain", buildJsonArray { add(JsonPrimitive(c.str("intermediate"))); add(JsonPrimitive(c.str("root"))) })
        put("partnerRootFingerprint", c.str("rootFingerprint"))
        put("valueFields", Json.parseToJsonElement(valueFields))
    }.toString()

    private val partnerShare = """[{"shareId":"fs1","recipientCloudId":"$MALLORY"}]"""

    private fun incomplete(copies: String = "[]", federated: String = "[]", skipRefused: String = "[]") = HttpStatusCode.Conflict to
        """{"error":"incomplete","message":"m","copies":{"required":$copies,"skipRefused":$skipRefused},"federated":{"required":$federated}}"""

    private fun copy(id: String, user: String?) = """{"secretId":"$id","holder":"${if (user == null) "owner" else "recipient"}","targetUserId":${user?.let { "\"$it\"" } ?: "null"}}"""

    private val own = WriteContext.Source("s1", "owner", null, BASE)

    /** A form that showed the login in full: what partners get beside the typed key. */
    private val opened = Opened(mapOf("key" to enc("oud"), "login" to enc("alice"), "additionalFields" to null, "totp" to null, "passkeys" to null), BASE, BASE)

    private fun input(
        context: WriteContext = own,
        plain: Values = mapOf("key" to "nieuw"),
        opened: Opened? = this.opened,
        without: Set<String> = emptySet(),
        id: String = "s1",
    ) = ShareSaveInput(id, JsonObject(plain.mapValues { (_, v) -> v?.let { JsonPrimitive(enc(it)) } ?: JsonNull }), plain, BASE, context, opened, without)

    private fun JsonObject.list(name: String) = this[name]!!.jsonArray.map { it.jsonObject }

    @Test
    fun anOwnRowWithoutCopiesIsOneSave() = runTest {
        assertNull(shareSave.save(input()))
        val sent = saves.single()
        assertEquals(BASE, sent["baseUpdatedAt"]!!.jsonPrimitive.content)
        assertEquals("nieuw", dec(sent["patch"]!!.jsonObject["key"]!!.jsonPrimitive.content))
        assertTrue(sent.list("copies").isEmpty() && sent.list("federated").isEmpty())
    }

    @Test
    fun eachCopyIsSealedToItsHolderAndOneWithoutAVaultKeyIsSkipped() = runTest {
        answers = listOf(incomplete(copies = "[${copy("c1", "bob")},${copy("c2", "dave")}]"), HttpStatusCode.OK to "{}")
        shareSave.save(input(plain = mapOf("key" to "nieuw", "login" to null)))
        assertEquals(listOf("bob", "dave"), lookups.single()["userIds"]!!.jsonArray.map { it.jsonPrimitive.content })
        val sealed = saves.last().list("copies").single()
        assertEquals("c1", sealed["secretId"]!!.jsonPrimitive.content)
        assertEquals("nieuw", dec(sealed["key"]!!.jsonPrimitive.content))
        // Exactly the fields the patch carries, with the same null-ness.
        assertEquals(JsonNull, sealed["login"])
        assertEquals(setOf("secretId", "key", "login"), sealed.keys)
        assertEquals(listOf("c2" to "no_vault_key"), saves.last().list("skipped").map { it["secretId"]!!.jsonPrimitive.content to it["reason"]!!.jsonPrimitive.content })
    }

    @Test
    fun aWritersCopySavesThroughTheSourceOnTheVersionTheFormOpened() = runTest {
        val writer = WriteContext.Source("src", "write", certificate, "2026-10-04T09:00:00+00:00")
        answers = listOf(incomplete(copies = "[${copy("src", null)}]"), HttpStatusCode.OK to """{"federated":{"ownerOnly":true}}""")
        val note = shareSave.save(input(context = writer, id = "c9", opened = Opened(opened.shown, BASE, SOURCE_AT)))
        // The source version the form opened, not the current one; partner shares are the owner's to write.
        assertEquals(SOURCE_AT, saves.first()["sourceBaseUpdatedAt"]!!.jsonPrimitive.content)
        assertFalse("federated" in saves.first())
        assertEquals("nieuw", dec(saves.last().list("copies").single()["key"]!!.jsonPrimitive.content))
        assertEquals(ShareSaveNote(ownerOnly = true), note)
    }

    @Test
    fun aFormOpenedWhileItsRowWasSavedAloneIsStale() = runTest {
        val writer = WriteContext.Source("src", "write", certificate, BASE)
        val e = assertFailsWith<ShareSaveException> { shareSave.save(input(context = writer, id = "c9", opened = Opened(opened.shown, BASE, null))) }
        assertEquals(ShareSave.STALE_SOURCE, e.code)
        assertTrue(saves.isEmpty())
    }

    @Test
    fun aCopySharedToViewOnlyIsRefusedBeforeAnythingIsSent() = runTest {
        val e = assertFailsWith<ShareSaveException> { shareSave.save(input(context = WriteContext.Source("src", "read", null, BASE), id = "c9")) }
        assertEquals(ShareSave.VIEW_ONLY, e.code)
        assertTrue(requests.isEmpty())
    }

    @Test
    fun moreThanABatchOfCopiesIsStagedInChunks() = runTest {
        val many = (1..150).joinToString(",") { copy("c$it", "bob") }
        answers = listOf(incomplete(copies = "[$many]"), HttpStatusCode.Accepted to """{"stageId":"st1"}""", HttpStatusCode.OK to "{}")
        shareSave.save(input())
        val (chunk, last) = saves.takeLast(2)
        assertEquals(100, chunk.list("copies").size)
        assertEquals(JsonObject(mapOf("final" to JsonPrimitive(false))), chunk["stage"])
        assertFalse("patch" in chunk)
        assertEquals(50, last.list("copies").size)
        assertEquals("st1", last["stage"]!!.jsonObject["id"]!!.jsonPrimitive.content)
        assertEquals(JsonPrimitive(true), last["stage"]!!.jsonObject["final"])
    }

    @Test
    fun aStageTheServerForgotStartsOver() = runTest {
        val many = (1..101).joinToString(",") { copy("c$it", "bob") }
        answers = listOf(
            incomplete(copies = "[$many]"),
            HttpStatusCode.Accepted to """{"stageId":"st1"}""",
            HttpStatusCode.NotFound to """{"error":"stage_unknown","message":"gone"}""",
            HttpStatusCode.Accepted to """{"stageId":"st2"}""",
            HttpStatusCode.OK to "{}",
        )
        shareSave.save(input())
        assertEquals(JsonObject(mapOf("final" to JsonPrimitive(false))), saves[3]["stage"], "a new stage, not st1")
        assertEquals("st2", saves[4]["stage"]!!.jsonObject["id"]!!.jsonPrimitive.content)
    }

    @Test
    fun aShareListThatKeepsChangingStopsAfterThreeRounds() = runTest {
        answers = listOf(incomplete(copies = "[${copy("c1", "bob")}]"), incomplete(copies = "[${copy("c2", "bob")}]"), incomplete(copies = "[${copy("c3", "bob")}]"))
        val e = assertFailsWith<ShareSaveException> { shareSave.save(input()) }
        assertEquals(ShareSave.INCOMPLETE, e.code)
        assertEquals(3, saves.size)
    }

    @Test
    fun aPartnerGetsTheWholeValueSealedToItsVerifiedCertificate() = runTest {
        answers = listOf(incomplete(federated = partnerShare), HttpStatusCode.OK to "{}")
        assertNull(shareSave.save(input()))
        val entry = saves.last().list("federated").single()
        assertEquals("fs1", entry["shareId"]!!.jsonPrimitive.content)
        assertTrue(Regex("^[0-9a-f]{64}$").matches(entry["certFingerprint"]!!.jsonPrimitive.content))
        // The typed key and the login the form showed, sealed to the partner; what the item does not hold is null.
        assertEquals("nieuw", RsaFields.decrypt(entry["key"]!!.jsonPrimitive.content, malloryKey))
        assertEquals("alice", RsaFields.decrypt(entry["login"]!!.jsonPrimitive.content, malloryKey))
        assertEquals(JsonNull, entry["additionalFields"])
        assertEquals(JsonNull, entry["totp"])
    }

    @Test
    fun aPartnerShareNeedsTheFormsRecord() = runTest {
        answers = listOf(incomplete(federated = partnerShare))
        assertEquals(ShareSave.UNVERIFIED, assertFailsWith<ShareSaveException> { shareSave.save(input(opened = null)) }.code)
        // A record of another version than the save is checked against vouches for nothing.
        val older = Opened(opened.shown, "2026-10-01T09:00:00+00:00", BASE)
        assertEquals(ShareSave.UNVERIFIED, assertFailsWith<ShareSaveException> { shareSave.save(input(opened = older)) }.code)
    }

    @Test
    fun anUnreachablePartnerStopsTheSaveUntilTheUserSavesWithoutThem() = runTest {
        partnerAnswer = HttpStatusCode.BadGateway to """{"message":"partner_unreachable"}"""
        answers = listOf(incomplete(federated = partnerShare), HttpStatusCode.OK to "{}")
        val e = assertFailsWith<ShareSaveException> { shareSave.save(input()) }
        assertEquals(ShareSave.PARTNER_UNREACHABLE, e.code)
        assertEquals(listOf(PartnerShare("fs1", MALLORY)), e.partners)

        saves.clear()
        val note = shareSave.save(input(without = setOf("fs1")))
        assertEquals(listOf("fs1" to "partner_unreachable"), saves.last().list("suspend").map { it["shareId"]!!.jsonPrimitive.content to it["reason"]!!.jsonPrimitive.content })
        assertEquals(ShareSaveNote(paused = listOf(MALLORY)), note)
    }

    @Test
    fun saveWithoutThemSuspendsOnlyTheUnreachablePartner() = runTest {
        val carol = "carol@cloud.partner.example"
        unreachable = setOf(carol)
        val shares = """[{"shareId":"fs1","recipientCloudId":"$carol"},{"shareId":"fs2","recipientCloudId":"$MALLORY"}]"""
        answers = listOf(incomplete(copies = "[${copy("c1", "bob")}]", federated = shares), HttpStatusCode.OK to "{}")
        assertEquals(listOf(PartnerShare("fs1", carol)), assertFailsWith<ShareSaveException> { shareSave.save(input()) }.partners)

        saves.clear()
        shareSave.save(input(without = setOf("fs1")))
        val last = saves.last()
        assertEquals(listOf("fs1"), last.list("suspend").map { it["shareId"]!!.jsonPrimitive.content })
        assertEquals(listOf("fs2"), last.list("federated").map { it["shareId"]!!.jsonPrimitive.content })
        assertEquals(listOf("c1"), last.list("copies").map { it["secretId"]!!.jsonPrimitive.content })
    }

    @Test
    fun aPartnerWhoseCertificateFailsIsSuspendedAndNamed() = runTest {
        partnerAnswer = HttpStatusCode.OK to partner().replace(c.str("rootFingerprint"), "0".repeat(64))
        answers = listOf(incomplete(federated = partnerShare), HttpStatusCode.OK to "{}")
        val note = shareSave.save(input())
        assertEquals("untrusted_root", saves.last().list("suspend").single()["reason"]!!.jsonPrimitive.content)
        assertEquals(ShareSaveNote(suspended = listOf(MALLORY)), note)
    }

    @Test
    fun anEmptyPartnerCertificateIsJudgedMalformed() = runTest {
        partnerAnswer = HttpStatusCode.OK to partner().replace(JsonPrimitive(c.str("mallory")).toString(), "\"\"")
        answers = listOf(incomplete(federated = partnerShare), HttpStatusCode.OK to "{}")
        shareSave.save(input())
        assertEquals("malformed", saves.last().list("suspend").single()["reason"]!!.jsonPrimitive.content)
    }

    @Test
    fun onlyAnArmouredKeyOfAHolderWhoseShareableIsTrueIsSealedTo() = runTest {
        answers = listOf(incomplete(copies = "[${copy("src", null)}]"))
        val bare = Vectors.envelope.str("publicKeyPem").lines().filterNot { it.startsWith("-----") }.joinToString("")
        val writer = WriteContext.Source("src", "write", bare, BASE)
        val e = assertFailsWith<ShareSaveException> { shareSave.save(input(context = writer, id = "c9", opened = Opened(opened.shown, BASE, BASE))) }
        assertEquals(ShareSave.BAD_CERTIFICATE, e.code)
    }

    @Test
    fun aRecipientMarkedShareableOnlyByTheStringTrueIsSkipped() = runTest {
        val engine = MockEngine { request ->
            val path = request.url.encodedPath.substringAfter("/apps/keepiq")
            val json = headersOf(HttpHeaders.ContentType, "application/json")
            when {
                path.endsWith("/share-save") -> {
                    saves += Json.parseToJsonElement((request.body as TextContent).text).jsonObject
                    if (saves.size == 1) respond(incomplete(copies = "[${copy("c1", "bob")}]").second, HttpStatusCode.Conflict, json) else respond("{}", HttpStatusCode.OK, json)
                }
                else -> respond("""{"recipients":[{"userId":"bob","shareable":"true","certificate":${JsonPrimitive(certificate)}}]}""", HttpStatusCode.OK, json)
            }
        }
        ShareSave(KeepiqApi(KeepiqApi.httpClient(engine), Account("acc", "https://cloud.example.nl", "alice", "pw")), keys) { NOW }.save(input())
        assertTrue(saves.last().list("copies").isEmpty())
        assertEquals("c1", saves.last().list("skipped").single()["secretId"]!!.jsonPrimitive.content)
    }

    @Test
    fun aPartnerVerifiedForAFailedSaveIsNotLookedUpAgain() = runTest {
        answers = listOf(incomplete(federated = partnerShare), HttpStatusCode.Forbidden to """{"error":"read_only","message":"m"}""")
        assertFailsWith<nl.conduction.keepiq.shared.api.KeepiqApiException> { shareSave.save(input()) }
        answers = listOf(incomplete(federated = partnerShare), HttpStatusCode.OK to "{}")
        saves.clear()
        shareSave.save(input())
        assertEquals(1, requests.count { it.second == "/api/v1/federation/recipient-certificate" })
    }

    @Test
    fun aPartnerClockOutsideTheCertificateStopsTheSave() = runTest {
        val early = ShareSave(api, keys) { 0L }
        answers = listOf(incomplete(federated = partnerShare))
        assertEquals(ShareSave.PARTNER_CLOCK, assertFailsWith<ShareSaveException> { early.save(input()) }.code)
    }

    @Test
    fun aPartnerTooOldForANewKeyRefusesTheSave() = runTest {
        partnerAnswer = HttpStatusCode.OK to partner(valueFields = "[]")
        answers = listOf(incomplete(federated = partnerShare))
        val e = assertFailsWith<ShareSaveException> { shareSave.save(input(plain = mapOf("totp" to "JBSWY3DPEHPK3PXP"))) }
        assertEquals(ShareSave.PARTNER_UPGRADE_REQUIRED, e.code)
        assertEquals(ShareSave.TOTP_PASSKEYS_FIELDS_SINCE, e.requiredVersion)
        assertEquals(1, saves.size)
    }

    @Test
    fun theServersRefusalsReadAsTheExtensionReadsThem() = runTest {
        answers = listOf(HttpStatusCode.Conflict to """{"error":"stale_source","message":"m","code":"stale_source"}""")
        assertEquals(ShareSave.STALE_SOURCE, assertFailsWith<ShareSaveException> { shareSave.save(input()) }.code)
        answers = listOf(HttpStatusCode.Forbidden to """{"error":"not_allowed","message":"m","code":"not_allowed"}""")
        assertEquals(ShareSave.FORBIDDEN, assertFailsWith<ShareSaveException> { shareSave.save(input()) }.code)
        // Nextcloud's page for a route this Keepiq does not have.
        answers = listOf(HttpStatusCode.NotFound to "<html>Not found</html>")
        assertEquals(ShareSave.KEEPIQ_OUTDATED, assertFailsWith<ShareSaveException> { shareSave.save(input()) }.code)
        assertEquals(WriteProblemKind.KEEPIQ_OUTDATED, WriteProblem.from(ShareSaveException(ShareSave.KEEPIQ_OUTDATED)).kind)
    }

    @Test
    fun writeContextReadsTheSourceOrTheOwnRow() = runTest {
        val read = { body: String ->
            val engine = MockEngine { respond(body, HttpStatusCode.OK, headersOf(HttpHeaders.ContentType, "application/json")) }
            ShareSave(KeepiqApi(KeepiqApi.httpClient(engine), Account("acc", "https://cloud.example.nl", "alice", "pw")), keys) { 0L }
        }
        assertEquals(WriteContext.OwnRow, read("""{"sourceSecretId":null,"effectiveGrade":"none"}""").readContext("x"))
        val source = read("""{"sourceSecretId":"src","effectiveGrade":"manage","ownerCertificate":"","sourceUpdatedAt":"$BASE"}""").readContext("x")
        assertEquals(WriteContext.Source("src", "manage", null, BASE), source)
        assertFalse(source.isViewOnly("x"), "a manager writes every copy")
        assertTrue(WriteContext.Source("src", "read", null, BASE).isViewOnly("x"))
        assertFalse(WriteContext.Source("x", "none", null, BASE).isViewOnly("x"), "the source itself")
    }

    @Test
    fun aPasskeySaveVouchesOnlyForWhatHoldsNothing() {
        val row = VaultRow("l1", "Mail", null, "1", null, enc("geheim"), enc(""), enc("{}"), BASE, useOnly = false, readOnly = false, blocked = false, blockedReason = null, totp = null, passkeys = enc("[]"))
        val record = ShareSave.openedForPasskeys(row, keys, SOURCE_AT)
        assertEquals(setOf("totp", "passkeys", "login", "additionalFields"), record.shown.keys, "the key holds a password the save never showed")
        assertEquals(SOURCE_AT, record.sourceUpdatedAt)
    }
}
