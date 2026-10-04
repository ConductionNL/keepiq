// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared

import kotlinx.coroutines.runBlocking
import nl.conduction.keepiq.shared.account.InMemorySecureStorage
import nl.conduction.keepiq.shared.api.Account
import nl.conduction.keepiq.shared.api.KeepiqApiException
import nl.conduction.keepiq.shared.api.platformHttpEngine
import nl.conduction.keepiq.shared.unlock.WrongMasterPasswordException
import org.junit.Assume.assumeTrue
import java.net.URI
import java.net.http.HttpClient
import java.net.http.HttpRequest
import java.net.http.HttpResponse
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFailsWith
import kotlin.test.assertIs
import kotlin.test.assertTrue

/**
 * The shared core against the real test server, before any emulator boots
 * (.github/workflows/mobile-e2e.yml runs it with -Pkeepiq.liveServer and
 * fails when it was skipped). Pair through Login Flow v2, unlock, unpair,
 * and the old app password gets 401.
 */
class LiveServerTest {
    private val server = System.getProperty("keepiq.liveServer").orEmpty()
    private val masterPassword = System.getProperty("keepiq.liveMasterPassword").orEmpty()

    @Test
    fun liveServerPairUnlockUnpair() = runBlocking {
        assumeTrue("set -Pkeepiq.liveServer to run against a test server", server.startsWith("https://"))
        val client = KeepiqClient(InMemorySecureStorage(), "Keepiq for Android (live jvm test)", platformHttpEngine())

        val start = client.startLogin(server)
        val grant = HttpClient.newHttpClient().send(
            HttpRequest.newBuilder(URI("$server/__e2e/grant"))
                .header("Content-Type", "application/json")
                .POST(HttpRequest.BodyPublishers.ofString("""{"loginUrl":"${start.loginUrl}"}"""))
                .build(),
            HttpResponse.BodyHandlers.ofString(),
        )
        assertEquals(200, grant.statusCode(), grant.body())
        val account = client.finishLogin(start)

        val gate = assertIs<UnlockGate.Ready>(client.unlockGate(account.id))
        assertFailsWith<WrongMasterPasswordException> { client.unlockWithMasterPassword(account.id, gate.suite, "wrong") }
        val vault = client.unlockWithMasterPassword(account.id, gate.suite, masterPassword)
        assertTrue(vault.privateKeyPem.contains("PRIVATE KEY"))
        assertTrue((client.maxIdleMinutes(account.id) ?: 0) > 0)

        assertTrue(client.unpair(account.id), "Nextcloud deleted the app password")
        val e = assertFailsWith<KeepiqApiException> { client.api(Account("", account.server, account.loginName, account.appPassword)).pair() }
        assertEquals(401, e.status)
    }
}
