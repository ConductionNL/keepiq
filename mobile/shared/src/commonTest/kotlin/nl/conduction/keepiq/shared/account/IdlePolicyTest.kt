// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.account

import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFailsWith
import kotlin.test.assertFalse
import kotlin.test.assertTrue

/** Task 2.5: the idle choices, the organisation cap and the timer. */
class IdlePolicyTest {
    @Test
    fun theOrganisationCapsTheUsersChoice() {
        // The spec's scenario: a maximum of 5 and a choice of 60 locks after 5.
        assertEquals(5, IdlePolicy.effectiveMinutes(60, 5))
        assertEquals(1, IdlePolicy.effectiveMinutes(1, 5))
        assertEquals(240, IdlePolicy.effectiveMinutes(240, 240))
    }

    @Test
    fun anUnreadablePolicyNeverLengthensTheDelay() {
        assertEquals(15, IdlePolicy.effectiveMinutes(60, null))
        assertEquals(5, IdlePolicy.effectiveMinutes(5, null))
    }

    @Test
    fun anUnknownChoiceFallsBackToTheDefault() {
        assertEquals(15, IdlePolicy.effectiveMinutes(7, 60))
    }

    @Test
    fun theSettingsOfferOnlyChoicesWithinTheCap() {
        assertEquals(listOf(1, 5), IdlePolicy.offeredChoices(5))
        assertEquals(IdlePolicy.CHOICES, IdlePolicy.offeredChoices(null))
    }

    @Test
    fun theTimerExpiresAfterTheDelayAndATouchRestartsIt() {
        var now = 0L
        val timer = IdleTimer({ now }, minutes = 5)
        now = 4 * 60_000L + 59_999L
        assertFalse(timer.expired())
        timer.touch()
        now += 4 * 60_000L
        assertFalse(timer.expired())
        assertEquals(60_000L, timer.remainingMillis())
        now += 60_000L
        assertTrue(timer.expired())
        assertEquals(0L, timer.remainingMillis())
    }

    @Test
    fun theStoreKeepsOnlyOfferedChoices() {
        val store = AccountStore(InMemorySecureStorage())
        val account = store.add("https://cloud.example.com", "alice", "p", null)
        store.updateSettings(account.id, AccountSettings(idleMinutes = 60, biometric = true))
        assertEquals(AccountSettings(60, true), store.settings(account.id))
        assertFailsWith<IllegalArgumentException> { store.updateSettings(account.id, AccountSettings(idleMinutes = 7)) }
    }
}
