// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.pairing

import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFailsWith
import kotlin.test.assertTrue

/** Task 2.1: the address form, the extension's normalizeServerUrl without its local-http exception. */
class ServerAddressTest {
    @Test
    fun aBareHostBecomesHttps() {
        assertEquals("https://cloud.example.com", ServerAddress.normalize("  cloud.example.com/ "))
    }

    @Test
    fun aPastedPageIsCutBackToTheServerFolder() {
        assertEquals("https://cloud.example.com/nextcloud", ServerAddress.normalize("https://cloud.example.com/nextcloud/index.php/apps/files/"))
        assertEquals("https://cloud.example.com", ServerAddress.normalize("https://Cloud.Example.com/apps/keepiq"))
        assertEquals("https://cloud.example.com:8443", ServerAddress.normalize("https://cloud.example.com:8443/login"))
        assertEquals("https://cloud.example.com", ServerAddress.normalize("https://cloud.example.com:443/"))
    }

    @Test
    fun plainHttpIsRefusedForEveryHost() {
        for (address in listOf("http://cloud.example.com", "http://localhost:8080", "http://10.0.2.2")) {
            val e = assertFailsWith<ServerAddressException> { ServerAddress.normalize(address) }
            assertTrue(e.message!!.contains("https"), address)
        }
    }

    @Test
    fun emptyCredentialsAndOtherSchemesAreRefused() {
        assertFailsWith<ServerAddressException> { ServerAddress.normalize("   ") }
        assertFailsWith<ServerAddressException> { ServerAddress.normalize("https://alice:secret@cloud.example.com") }
        assertFailsWith<ServerAddressException> { ServerAddress.normalize("ftp://cloud.example.com") }
    }
}
