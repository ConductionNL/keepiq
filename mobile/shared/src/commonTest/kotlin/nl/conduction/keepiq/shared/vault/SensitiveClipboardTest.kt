// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.vault

import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertTrue

/** The sensitive clipboard with a timeout (task 3.3), on a fake clock. */
class SensitiveClipboardTest {
    private val written = mutableListOf<Pair<String, Int>>()
    private val cleared = mutableListOf<String>()
    private val timers = mutableListOf<Triple<Long, () -> Unit, BooleanArray>>()
    private var setting = 60

    private val clipboard = SensitiveClipboard(
        port = object : ClipboardPort {
            override fun writeSensitive(text: String, expiresInSeconds: Int) {
                written += text to expiresInSeconds
            }

            override fun clearIfOurs(token: String) {
                cleared += token
            }
        },
        scheduler = object : Scheduler {
            override fun schedule(delayMillis: Long, action: ScheduledAction): Cancellable {
                val cancelled = booleanArrayOf(false)
                timers += Triple(delayMillis, { action.run() }, cancelled)
                return Cancellable { cancelled[0] = true }
            }
        },
        clearSeconds = ClearDelay { setting },
    )

    private fun fireLive() = timers.filter { !it.third[0] }.forEach { it.second() }

    @Test
    fun aCopyIsMarkedSensitiveAndClearedAfterTheDefaultMinute() {
        assertEquals(60, clipboard.write("geheim"))
        assertEquals(listOf("geheim" to 60), written)
        assertEquals(60_000L, timers.single().first)
        assertTrue(cleared.isEmpty())
        fireLive()
        assertEquals(listOf(SensitiveClipboard.tokenOf("geheim")), cleared)
        assertTrue(cleared.single() != "geheim", "the token is a hash, not the value")
    }

    @Test
    fun aNewCopyRestartsTheTimerSoOnlyTheNewestIsCleared() {
        clipboard.write("eerste")
        setting = 10
        clipboard.write("tweede")
        fireLive()
        assertEquals(listOf(SensitiveClipboard.tokenOf("tweede")), cleared)
        assertEquals(10_000L, timers.last().first)
    }

    @Test
    fun zeroNeverClearsAndAnUnknownDelayFallsBackToTheDefault() {
        setting = 0
        assertEquals(0, clipboard.write("blijft"))
        assertTrue(timers.isEmpty())
        setting = 7
        assertEquals(60, clipboard.write("x"))
    }

    @Test
    fun lockingClearsAtOnce() {
        clipboard.write("geheim")
        clipboard.clearNow("geheim")
        fireLive()
        assertEquals(listOf(SensitiveClipboard.tokenOf("geheim")), cleared)
    }
}
