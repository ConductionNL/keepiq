// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.send

import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertNull

/** Reading a Send link (task 3.6) and the server's times. */
class SendLinkTest {
    @Test
    fun readsTheLinkTheWebAppWrites() {
        val link = SendLink.parse("https://cloud.example.nl/index.php/apps/keepiq/public/send/Ab3_tOkEn-42#k=FtBbamZW74VqO39N3C80iXwtemGi_DXXFacJpw8wZVI")!!
        assertEquals("Ab3_tOkEn-42", link.token)
        assertEquals("FtBbamZW74VqO39N3C80iXwtemGi_DXXFacJpw8wZVI", link.fragmentKey)
        assertEquals("https://cloud.example.nl/index.php/apps/keepiq/api/v1/public/sends/Ab3_tOkEn-42", link.apiBase)
    }

    @Test
    fun readsASubdirectoryAndALegacyQueryKey() {
        val link = SendLink.parse("https://example.org/nextcloud/apps/keepiq/public/send/t%2Dk?k=abc")!!
        assertEquals("t-k", link.token)
        assertEquals("abc", link.fragmentKey)
        assertEquals("https://example.org/nextcloud/apps/keepiq/api/v1/public/sends/t-k", link.apiBase)
    }

    @Test
    fun refusesWhatIsNotAnHttpsSendLink() {
        assertNull(SendLink.parse("http://cloud.example.nl/index.php/apps/keepiq/public/send/x#k=a"))
        assertNull(SendLink.parse("https://cloud.example.nl/index.php/apps/keepiq/public/share/link/x"))
        assertNull(SendLink.parse("https://cloud.example.nl/index.php/apps/keepiq/public/send/"))
        assertNull(SendLink.parse("niet een link"))
    }

    @Test
    fun readsIsoTimes() {
        assertEquals(1_791_194_400_000, IsoTime.parseMillis("2026-10-05T10:00:00+00:00"))
        assertEquals(1_791_194_400_000, IsoTime.parseMillis("2026-10-05T12:00:00+02:00"))
        assertEquals(1_791_194_400_123, IsoTime.parseMillis("2026-10-05T10:00:00.123Z"))
        assertEquals(0, IsoTime.parseMillis("1970-01-01T00:00:00Z"))
        assertNull(IsoTime.parseMillis("gisteren"))
        assertNull(IsoTime.parseMillis(null))
    }

    @Test
    fun minutesLeftRoundsAsTheExtensionDoes() {
        assertEquals(90, SendForm.minutesLeft(90 * 60_000L, 0))
        assertEquals(0, SendForm.minutesLeft(10_000L, 0))
        assertNull(SendForm.minutesLeft(null, 0))
    }
}
