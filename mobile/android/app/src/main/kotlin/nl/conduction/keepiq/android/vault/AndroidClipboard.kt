// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.vault

import android.content.ClipData
import android.content.ClipDescription
import android.content.ClipboardManager
import android.content.Context
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.os.PersistableBundle
import nl.conduction.keepiq.shared.vault.PendingClear
import nl.conduction.keepiq.shared.vault.ClipboardPort
import nl.conduction.keepiq.shared.vault.ScheduledAction
import nl.conduction.keepiq.shared.vault.ClearScheduler
import nl.conduction.keepiq.shared.vault.SensitiveClipboard

/**
 * The system clipboard for [SensitiveClipboard] (task 3.3). Every clip is
 * marked sensitive with ClipDescription.EXTRA_IS_SENSITIVE (and its
 * pre-Android 13 key), so the system and keyboards hide the preview.
 *
 * Android 10 and later only lets the focused app read the clipboard, so
 * "is it still ours" is answered by the last token this app wrote and by
 * the change listener while the app is in front.
 */
class AndroidClipboard(context: Context) : ClipboardPort {
    private val manager = context.getSystemService(ClipboardManager::class.java)
    private var ourToken: String? = null

    init {
        manager.addPrimaryClipChangedListener {
            val label = manager.primaryClipDescription?.label?.toString()
            if (label != LABEL) ourToken = null
        }
    }

    override fun writeSensitive(text: String, expiresInSeconds: Int) {
        val clip = ClipData.newPlainText(LABEL, text)
        clip.description.extras = PersistableBundle().apply {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                putBoolean(ClipDescription.EXTRA_IS_SENSITIVE, true)
            } else {
                putBoolean("android.content.extra.IS_SENSITIVE", true)
            }
        }
        manager.setPrimaryClip(clip)
        ourToken = SensitiveClipboard.tokenOf(text)
    }

    override fun clearIfOurs(token: String) {
        if (ourToken != token) return
        manager.clearPrimaryClip()
        ourToken = null
    }

    companion object {
        const val LABEL = "Keepiq"
    }
}

/** Main-thread timers for [SensitiveClipboard]. */
class HandlerScheduler : ClearScheduler {
    private val handler = Handler(Looper.getMainLooper())

    override fun schedule(delayMillis: Long, action: ScheduledAction): PendingClear {
        val runnable = Runnable { action.run() }
        handler.postDelayed(runnable, delayMillis)
        return PendingClear { handler.removeCallbacks(runnable) }
    }
}

/** The app's own settings for these screens, per device. */
class VaultPreferences(context: Context) {
    private val prefs = context.getSharedPreferences("keepiq-vault", Context.MODE_PRIVATE)

    var clipboardClearSeconds: Int
        get() = prefs.getInt(KEY_CLIPBOARD, SensitiveClipboard.DEFAULT_CLEAR_SECONDS)
        set(value) = prefs.edit().putInt(KEY_CLIPBOARD, value).apply()

    private companion object {
        const val KEY_CLIPBOARD = "clipboard-clear-seconds"
    }
}
