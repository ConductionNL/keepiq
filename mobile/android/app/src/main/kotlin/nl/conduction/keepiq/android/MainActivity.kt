// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android

import android.content.ActivityNotFoundException
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.browser.customtabs.CustomTabsIntent
import androidx.fragment.app.FragmentActivity
import androidx.lifecycle.lifecycleScope
import kotlinx.coroutines.launch
import nl.conduction.keepiq.android.ui.KeepiqRoot

/**
 * The one activity. A FragmentActivity, because BiometricPrompt needs one.
 * The login page opens in a Custom Tab, in the user's own browser (design
 * D3); when the user comes back without finishing, the app polls once more
 * and then shows the address form again.
 */
class MainActivity : FragmentActivity() {
    private val state: AppState get() = (application as KeepiqApp).state
    private var browserOpen = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent { KeepiqRoot(state, this, ::openBrowser) }
        // Back in front when the browser sign-in paired an account.
        lifecycleScope.launch {
            state.screen.collect { screen ->
                if (browserOpen && screen is Screen.Unlock) {
                    browserOpen = false
                    startActivity(
                        Intent(this@MainActivity, MainActivity::class.java)
                            .addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP),
                    )
                }
            }
        }
    }

    override fun onResume() {
        super.onResume()
        if (browserOpen) {
            browserOpen = false
            state.browserClosed()
        }
    }

    override fun onUserInteraction() {
        super.onUserInteraction()
        state.touch()
    }

    private fun openBrowser(url: String) {
        try {
            CustomTabsIntent.Builder().setShowTitle(true).build().launchUrl(this, Uri.parse(url))
            browserOpen = true
        } catch (e: ActivityNotFoundException) {
            state.cancelLogin()
            state.reportProblem("No browser is installed. Use an app password instead.")
        }
    }
}
