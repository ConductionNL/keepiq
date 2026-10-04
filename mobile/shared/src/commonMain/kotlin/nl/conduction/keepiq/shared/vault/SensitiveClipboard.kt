// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.vault

/**
 * The platform clipboard, as the copy timer needs it. Android marks the clip
 * sensitive (ClipDescription.EXTRA_IS_SENSITIVE on API 33+); iOS writes it
 * local only with an expiration date, so the system clears it even when the
 * app is gone.
 */
interface ClipboardPort {
    /** Writes [text] marked as sensitive, so the system hides its preview. */
    fun writeSensitive(text: String, expiresInSeconds: Int)

    /** Clears the clipboard if it still holds what this app wrote, by [token] (a hash, never the value). */
    fun clearIfOurs(token: String)
}

/**
 * Schedules one delayed action and cancels it; the platform's main-thread
 * timer. Interfaces rather than function types, so Swift implements them
 * without Kotlin's boxed function signatures.
 */
interface ClearScheduler {
    fun schedule(delayMillis: Long, action: ScheduledAction): PendingClear
}

fun interface ScheduledAction {
    fun run()
}

fun interface PendingClear {
    fun cancel()
}

/** The user's clipboard delay in seconds, read at each copy. */
fun interface ClearDelay {
    fun seconds(): Int
}

/**
 * Copy with a timeout (mobile-vault "View and copy an item"). Every copy is
 * marked sensitive, and cleared after the user's delay: the extension's
 * choices (browser-extension/src/background/clipboard-clear.js), with the
 * mobile spec's default of 60 seconds. A later copy restarts the timer, so
 * only the newest value is on the clipboard and it gets its full delay.
 * 0 means never cleared by the app.
 */
class SensitiveClipboard(
    private val port: ClipboardPort,
    private val scheduler: ClearScheduler,
    private val clearSeconds: ClearDelay,
) {
    private var pending: PendingClear? = null

    /**
     * Copies [text] and returns the seconds until it is cleared (0: not
     * cleared). Not named copy: Objective-C reads copy… as a method family.
     */
    fun write(text: String): Int {
        val seconds = clearSeconds.seconds().takeIf { it in CLEAR_CHOICES } ?: DEFAULT_CLEAR_SECONDS
        pending?.cancel()
        pending = null
        port.writeSensitive(text, seconds)
        if (seconds > 0) {
            val token = tokenOf(text)
            pending = scheduler.schedule(
                seconds * 1000L,
                ScheduledAction {
                    pending = null
                    port.clearIfOurs(token)
                },
            )
        }
        return seconds
    }

    /** Clears now, for a lock or an unpair. */
    fun clearNow(text: String?) {
        pending?.cancel()
        pending = null
        if (text != null) port.clearIfOurs(tokenOf(text))
    }

    companion object {
        /** CLEAR_CHOICES of the extension, in seconds. */
        val CLEAR_CHOICES = listOf(0, 10, 20, 30, 60, 120, 300)

        /** The mobile spec's default clipboard timeout. */
        const val DEFAULT_CLEAR_SECONDS = 60

        /** A token for "is this still our copy": a SHA-256 of the text, never the text itself. */
        fun tokenOf(text: String): String = nl.conduction.keepiq.shared.crypto.Encoding.toBase64(
            nl.conduction.keepiq.shared.crypto.Digest.sha256(nl.conduction.keepiq.shared.crypto.Encoding.utf8(text)),
        )
    }
}
