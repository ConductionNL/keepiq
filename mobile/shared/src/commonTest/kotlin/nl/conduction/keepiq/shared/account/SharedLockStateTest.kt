// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.account

import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertNull
import kotlin.test.assertTrue

/**
 * Task 2.5: the autofill extension honours the app's lock state, its idle
 * time and the organisation's cap.
 *
 * @spec openspec/changes/clients-mobile-apps/specs/mobile-pairing-and-unlock/spec.md
 */
class SharedLockStateTest {
    private var now = 1_000_000L
    private val storage = InMemorySecureStorage()
    private val state = SharedLockState(storage) { now }
    private val minute = 60_000L

    @Test
    fun keysStayOpenWithinTheIdleTime() {
        val kept = now
        now += 14 * minute
        assertTrue(state.isOpen("a", kept, 15))
        now += minute
        assertFalse(state.isOpen("a", kept, 15))
    }

    @Test
    fun theOrganisationCapShortensTheExtensionsIdleTime() {
        // The spec's scenario: a maximum of 5 and a choice of 60 locks after 5.
        state.recordOrganisationMax("a", 5)
        val kept = now
        now += 4 * minute
        assertTrue(state.isOpen("a", kept, 60))
        now += minute
        assertFalse(state.isOpen("a", kept, 60))
        assertEquals(5, state.effectiveMinutes("a", 60))
    }

    @Test
    fun anUnreadablePolicyFallsBackToTheDefaultCap() {
        state.recordOrganisationMax("a", 240)
        state.recordOrganisationMax("a", null)
        assertNull(state.organisationMax("a"))
        assertEquals(15, state.effectiveMinutes("a", 60))
        state.recordOrganisationMax("a", 0)
        assertNull(state.organisationMax("a"))
    }

    @Test
    fun aLockOfTheAppClosesKeysOpenedBefore() {
        val kept = now
        now += minute
        state.recordLock("a")
        now += minute
        assertFalse(state.isOpen("a", kept, 60))
    }

    @Test
    fun keysOpenedAfterTheAppLockedStayOpen() {
        state.recordLock("a")
        now += minute
        val kept = now
        now += minute
        assertTrue(state.isOpen("a", kept, 60))
    }

    @Test
    fun aLockInTheSameMillisecondCloses() {
        state.recordLock("a")
        assertFalse(state.isOpen("a", now, 60))
    }

    @Test
    fun aLockOfOneAccountLeavesAnotherOpen() {
        val kept = now
        now += minute
        state.recordLock("b")
        assertTrue(state.isOpen("a", kept, 60))
        assertFalse(state.isOpen("b", kept, 60))
    }

    @Test
    fun aClockThatWentBackCloses() {
        val kept = now
        now -= minute
        assertFalse(state.isOpen("a", kept, 60))
    }

    @Test
    fun forgetDeletesTheAccountsValues() {
        state.recordLock("a")
        state.recordOrganisationMax("a", 5)
        state.recordLock("b")
        state.forget("a")
        assertNull(state.lastLockMillis("a"))
        assertNull(state.organisationMax("a"))
        assertTrue(storage.keys().none { it.endsWith(":a") }, "left behind: ${storage.keys()}")
        assertEquals(now, state.lastLockMillis("b"))
    }
}
