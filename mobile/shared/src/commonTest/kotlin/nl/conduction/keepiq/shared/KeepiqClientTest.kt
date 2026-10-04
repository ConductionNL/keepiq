// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared

import io.ktor.client.engine.mock.MockEngine
import io.ktor.client.engine.mock.respond
import io.ktor.client.request.HttpRequestData
import io.ktor.http.HttpHeaders
import io.ktor.http.HttpMethod
import io.ktor.http.HttpStatusCode
import io.ktor.http.headersOf
import kotlinx.io.IOException
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonArray
import kotlinx.serialization.json.buildJsonObject
import nl.conduction.keepiq.shared.account.InMemorySecureStorage
import nl.conduction.keepiq.shared.crypto.Encoding
import nl.conduction.keepiq.shared.crypto.argon2idAvailable
import nl.conduction.keepiq.shared.unlock.StaleUnlockKeyException
import nl.conduction.keepiq.shared.unlock.UnlockBlockedException
import nl.conduction.keepiq.shared.unlock.WrongMasterPasswordException
import nl.conduction.keepiq.shared.unlock.WrongPinException
import nl.conduction.keepiq.shared.vectors.Vectors
import nl.conduction.keepiq.shared.vectors.str
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFailsWith
import kotlin.test.assertFalse
import kotlin.test.assertIs
import kotlin.test.assertNull
import kotlin.test.assertTrue

/**
 * Tasks 2.1, 2.2, 2.3 (PIN), 2.6: pairing, unlock and unpair against a fake
 * server that answers as ExtensionController, Nextcloud's Login Flow v2 and
 * its app-password endpoint do.
 */
class KeepiqClientTest {
    private val envelope = Vectors.envelope
    private val seen = mutableListOf<HttpRequestData>()
    private val storage = InMemorySecureStorage()

    private var apiVersion = 1
    private var acceptedPassword = "app-password-1"
    private var unlockBlocked: String? = null
    private var revoked = mutableListOf<String>()
    private var offline = false

    private fun suiteJson() = buildJsonArray {
        add(
            buildJsonObject {
                put("id", JsonPrimitive("suite-1"))
                put("status", JsonPrimitive("active"))
                put("unlockKeyEpoch", JsonPrimitive(3))
                put("certificate", JsonPrimitive(envelope.str("certificatePem")))
                if (unlockBlocked == null) put("privateKey", JsonPrimitive(envelope.str("envelope")))
                unlockBlocked?.let { put("unlockBlocked", JsonPrimitive(it)) }
            },
        )
    }.toString()

    private val engine = MockEngine { request ->
        seen += request
        if (offline) throw IOException("no network")
        val path = request.url.encodedPath
        val auth = request.headers[HttpHeaders.Authorization]
        val password = auth?.removePrefix("Basic ")?.let { Encoding.fromUtf8(Encoding.fromBase64(it)).substringAfter(':') }
        val json = headersOf(HttpHeaders.ContentType, "application/json")
        when {
            path.endsWith("/index.php/login/v2") -> respond(
                """{"poll":{"token":"t","endpoint":"https://cloud.example.com/index.php/login/v2/poll"},"login":"https://cloud.example.com/index.php/login/v2/flow/x"}""",
                HttpStatusCode.OK,
                json,
            )
            path.endsWith("/login/v2/poll") -> respond(
                """{"server":"https://cloud.example.com","loginName":"alice","appPassword":"app-password-1"}""",
                HttpStatusCode.OK,
                json,
            )
            path.endsWith("/ocs/v2.php/core/apppassword") && request.method == HttpMethod.Delete -> {
                revoked += password ?: ""
                respond("""{"ocs":{"meta":{"status":"ok","statuscode":200},"data":[]}}""", HttpStatusCode.OK, json)
            }
            password != acceptedPassword -> respond("""{"error":"unauthorized"}""", HttpStatusCode.Unauthorized, json)
            path.endsWith("/api/v1/extension/pair") -> respond(
                """{"ok":true,"user":"alice","apiVersion":$apiVersion,"serverVersion":"2.4.0","capabilities":["match"]}""",
                HttpStatusCode.OK,
                json,
            )
            path.endsWith("/api/v1/extension/unpair") -> respond("""{"ok":true}""", HttpStatusCode.OK, json)
            path.endsWith("/api/v1/extension/policy") -> respond("""{"maxIdleMinutes":5}""", HttpStatusCode.OK, json)
            path.endsWith("/api/v1/suites") -> respond(suiteJson(), HttpStatusCode.OK, json)
            else -> respond("", HttpStatusCode.NotFound)
        }
    }

    private val client = KeepiqClient(storage, "Keepiq for Android", engine, clock = { 0L })

    @Test
    fun manualPairingStoresTheAccount() = runTest {
        val account = client.pairManually("cloud.example.com", "alice", "app-password-1")
        assertEquals("https://cloud.example.com", account.server)
        assertEquals(listOf(account.id), client.accounts.accounts().map { it.id })
        assertEquals(account.id, client.accounts.activeId())
        assertEquals("2.4.0", client.accounts.serverVersion(account.id))
    }

    @Test
    fun anUnsupportedApiVersionStoresNothing() = runTest {
        apiVersion = 2
        val e = assertFailsWith<PairingException> { client.pairManually("cloud.example.com", "alice", "app-password-1") }
        assertTrue(e.message!!.contains("API version 2"))
        assertTrue(client.accounts.accounts().isEmpty())
    }

    @Test
    fun aWrongAppPasswordIsNamedAndStoresNothing() = runTest {
        acceptedPassword = "app-password-1"
        val e = assertFailsWith<PairingException> { client.pairManually("cloud.example.com", "alice", "wrong") }
        assertEquals(401, e.status)
        assertEquals(KeepiqClient.pairingProblem(404), "Keepiq is not installed on this server, or the address is wrong.")
        assertTrue(client.accounts.accounts().isEmpty())
    }

    @Test
    fun loginFlowPairsWithTheGrantedAppPassword() = runTest {
        val start = client.startLogin("https://cloud.example.com")
        val account = client.finishLogin(start)
        assertEquals("alice", account.loginName)
        assertEquals("app-password-1", account.appPassword)
        assertEquals("Keepiq for Android", seen.first().headers[HttpHeaders.UserAgent])
    }

    @Test
    fun aLoginFlowThatCannotPairDeletesItsAppPassword() = runTest {
        apiVersion = 9
        val start = client.startLogin("https://cloud.example.com")
        assertFailsWith<PairingException> { client.finishLogin(start) }
        assertEquals(listOf("app-password-1"), revoked)
        assertTrue(client.accounts.accounts().isEmpty())
    }

    @Test
    fun aSixthAccountIsRefused() = runTest {
        for (i in 1..5) client.accounts.add("https://cloud$i.example.com", "alice", "p$i", null)
        val e = assertFailsWith<PairingException> { client.startLogin("https://cloud.example.com") }
        assertTrue(e.message!!.contains("up to 5"))
    }

    @Test
    fun unpairRevokesTheAppPasswordAndWipesTheDevice() = runTest {
        val account = client.pairManually("cloud.example.com", "alice", "app-password-1")
        storage.write("pin:${account.id}", "{}")
        val wiped = mutableListOf<String>()
        client.onWipe = { wiped += it }
        assertTrue(client.unpair(account.id))
        assertEquals(listOf("app-password-1"), revoked)
        assertTrue(seen.any { it.url.encodedPath.endsWith("/api/v1/extension/unpair") })
        assertEquals(listOf(account.id), wiped)
        assertTrue(client.accounts.accounts().isEmpty())
        assertTrue(storage.keys().none { it.contains(account.id) }, "left behind: ${storage.keys()}")
    }

    @Test
    fun unpairWipesTheDeviceAlsoOffline() = runTest {
        val account = client.pairManually("cloud.example.com", "alice", "app-password-1")
        offline = true
        assertFalse(client.unpair(account.id))
        assertTrue(client.accounts.accounts().isEmpty())
    }

    @Test
    fun theMasterPasswordOpensTheVault() = runTest {
        val account = client.pairManually("cloud.example.com", "alice", "app-password-1")
        val gate = assertIs<UnlockGate.Ready>(client.unlockGate(account.id))
        val vault = client.unlockWithMasterPassword(account.id, gate.suite, envelope.str("password"))
        assertEquals(envelope.str("privateKeyPem"), vault.privateKeyPem)
        assertEquals(envelope.str("unlockKeyHex"), Encoding.hex(vault.unlockKey()))
        assertEquals(3L, vault.unlockKeyEpoch)
    }

    @Test
    fun aWrongMasterPasswordUnlocksNothing() = runTest {
        val account = client.pairManually("cloud.example.com", "alice", "app-password-1")
        val gate = assertIs<UnlockGate.Ready>(client.unlockGate(account.id))
        assertFailsWith<WrongMasterPasswordException> {
            client.unlockWithMasterPassword(account.id, gate.suite, envelope.str("wrongPassword"))
        }
    }

    @Test
    fun theTwoFactorBlockOffersNoUnlock() = runTest {
        val account = client.pairManually("cloud.example.com", "alice", "app-password-1")
        unlockBlocked = "two_factor_required"
        val gate = assertIs<UnlockGate.Blocked>(client.unlockGate(account.id))
        assertEquals("two_factor_required", gate.code)
        assertTrue(gate.message.contains("two-factor"))
    }

    @Test
    fun anOfflinePhoneUnlocksWithTheSuiteFromTheLastUnlock() = runTest {
        val account = client.pairManually("cloud.example.com", "alice", "app-password-1")
        client.unlockGate(account.id)
        offline = true
        val gate = assertIs<UnlockGate.Ready>(client.unlockGate(account.id))
        assertTrue(gate.offline)
        assertEquals("suite-1", gate.suite.id)
    }

    @Test
    fun aBlockedSuiteIsNotKeptForOfflineUse() = runTest {
        val account = client.pairManually("cloud.example.com", "alice", "app-password-1")
        client.unlockGate(account.id)
        unlockBlocked = "two_factor_required"
        client.unlockGate(account.id)
        offline = true
        assertFailsWith<PairingException> { client.unlockGate(account.id) }
    }

    @Test
    fun theDirectGateRefusesABlockedSuiteToo() = runTest {
        val account = client.pairManually("cloud.example.com", "alice", "app-password-1")
        val gate = assertIs<UnlockGate.Ready>(client.unlockGate(account.id))
        assertFailsWith<UnlockBlockedException> {
            client.unlockWithMasterPassword(account.id, gate.suite.copy(unlockBlocked = "two_factor_required"), envelope.str("password"))
        }
    }

    @Test
    fun aStaleUnlockKeyDeletesThePin() = runTest {
        val account = client.pairManually("cloud.example.com", "alice", "app-password-1")
        val gate = assertIs<UnlockGate.Ready>(client.unlockGate(account.id))
        storage.write("pin:${account.id}", "{}")
        assertFailsWith<StaleUnlockKeyException> { client.unlockWithKey(account.id, gate.suite, ByteArray(32)) }
        assertFalse(client.pins.has(account.id))
    }

    @Test
    fun thePinOpensTheVaultAndFiveWrongOnesWipeIt() = runTest {
        if (!argon2idAvailable) return@runTest
        val account = client.pairManually("cloud.example.com", "alice", "app-password-1")
        val gate = assertIs<UnlockGate.Ready>(client.unlockGate(account.id))
        val vault = client.unlockWithMasterPassword(account.id, gate.suite, envelope.str("password"))
        assertFailsWith<IllegalArgumentException> { client.setPin(vault, "12345") }
        client.setPin(vault, "246810")
        assertEquals(envelope.str("privateKeyPem"), client.unlockWithPin(account.id, gate.suite, "246810").privateKeyPem)
        for (left in 4 downTo 1) {
            assertEquals(left, assertFailsWith<WrongPinException> { client.unlockWithPin(account.id, gate.suite, "000000") }.triesLeft)
        }
        // The right PIN after four wrong ones still works, and resets the count.
        client.unlockWithPin(account.id, gate.suite, "246810")
        repeat(4) { assertFailsWith<WrongPinException> { client.unlockWithPin(account.id, gate.suite, "000000") } }
        assertEquals(0, assertFailsWith<WrongPinException> { client.unlockWithPin(account.id, gate.suite, "000000") }.triesLeft)
        assertFalse(client.pins.has(account.id))
        assertEquals(0, assertFailsWith<WrongPinException> { client.unlockWithPin(account.id, gate.suite, "246810") }.triesLeft)
    }

    @Test
    fun theOrganisationsIdleMaximumIsRead() = runTest {
        val account = client.pairManually("cloud.example.com", "alice", "app-password-1")
        assertEquals(5, client.maxIdleMinutes(account.id))
        offline = true
        assertNull(client.maxIdleMinutes(account.id))
    }
}
