// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.account

/**
 * The idle lock (design D4, "Locking"): the user picks one of [CHOICES],
 * capped by the organisation's `maxIdleMinutes` from
 * `/api/v1/extension/policy`. When that maximum could not be read, the cap
 * is the default, so an unreachable policy never lengthens the delay
 * (browser-extension/src/background/router.js FALLBACK_MAX_IDLE_MINUTES).
 */
object IdlePolicy {
    val CHOICES: List<Int> = listOf(1, 5, 15, 30, 60, 240)
    const val DEFAULT_MINUTES = 15

    /** The delay that applies: the lower of the user's choice and the organisation's maximum. */
    fun effectiveMinutes(choice: Int, organisationMax: Int?): Int {
        val picked = if (choice in CHOICES) choice else DEFAULT_MINUTES
        val cap = organisationMax?.takeIf { it > 0 } ?: DEFAULT_MINUTES
        return minOf(picked, cap)
    }

    /** The choices the settings screen offers: those within the organisation's maximum. */
    fun offeredChoices(organisationMax: Int?): List<Int> {
        val cap = organisationMax?.takeIf { it > 0 } ?: return CHOICES
        return CHOICES.filter { it <= cap }.ifEmpty { listOf(CHOICES.first()) }
    }
}

/**
 * Counts idle time. The app calls [touch] on every user interaction and
 * asks [expired] when it comes back to the foreground and on a timer. The
 * clock is wall time, so idle time also runs while the app is in the
 * background or the process is gone (the app persists [lastActivityMillis]).
 */
class IdleTimer(private val clock: () -> Long, var minutes: Int, var lastActivityMillis: Long = clock()) {
    fun touch() {
        lastActivityMillis = clock()
    }

    fun expired(): Boolean = clock() - lastActivityMillis >= minutes * 60_000L

    /** Milliseconds until the lock, never below zero. */
    fun remainingMillis(): Long = maxOf(0L, lastActivityMillis + minutes * 60_000L - clock())
}
