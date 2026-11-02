// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.ui

import android.view.View
import android.view.accessibility.AccessibilityNodeInfo
import androidx.compose.runtime.withFrameNanos
import androidx.compose.ui.Modifier
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.node.RootForTest
import androidx.compose.ui.semantics.SemanticsPropertyKey
import androidx.compose.ui.semantics.getAllSemanticsNodes
import androidx.compose.ui.semantics.semantics

/**
 * Moves focus to one control after an action took the focused one away
 * (mobile-login-totp-passkeys D7, D8). [FocusRequester] moves input focus,
 * which keyboard and switch users follow; in touch mode, which TalkBack
 * uses, Compose gives a button no input focus. So the move also asks the
 * screen's accessibility node provider to put accessibility focus on the
 * control (ACTION_ACCESSIBILITY_FOCUS). Compose performs that only while
 * touch exploration is on, so without a screen reader nothing changes.
 * Put [focusMove] on the control.
 */
internal class FocusMove {
    internal val requester = FocusRequester()

    /** Moves input focus now and, once the frame is laid out, accessibility focus. */
    suspend fun move(view: View) {
        requester.requestFocus()
        withFrameNanos { }
        moveAccessibilityFocus(view)
    }

    /**
     * The control is found by the marker [focusMove] puts in its semantics;
     * its semantics id is its virtual view id in the provider.
     */
    private fun moveAccessibilityFocus(view: View): Boolean {
        val owner = (view as? RootForTest)?.semanticsOwner ?: return false
        val node = owner.getAllSemanticsNodes(mergingEnabled = false).firstOrNull { it.config.getOrElseNullable(FocusMoveKey) { null } === this } ?: return false
        return view.accessibilityNodeProvider?.performAction(node.id, AccessibilityNodeInfo.ACTION_ACCESSIBILITY_FOCUS, null) ?: false
    }
}

private val FocusMoveKey = SemanticsPropertyKey<FocusMove>("KeepiqFocusMove")

internal fun Modifier.focusMove(move: FocusMove): Modifier = focusRequester(move.requester).semantics { this[FocusMoveKey] = move }
