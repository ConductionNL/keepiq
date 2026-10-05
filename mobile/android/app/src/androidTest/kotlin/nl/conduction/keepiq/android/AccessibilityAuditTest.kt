// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android

import android.content.Context
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isRoot
import androidx.compose.ui.test.junit4.accessibility.enableAccessibilityChecks
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTextInput
import androidx.compose.ui.test.tryPerformAccessibilityChecks
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.filters.SdkSuppress
import androidx.test.platform.app.InstrumentationRegistry
import com.google.android.apps.common.testing.accessibility.framework.AccessibilityCheckPreset
import com.google.android.apps.common.testing.accessibility.framework.AccessibilityCheckResultDescriptor
import com.google.android.apps.common.testing.accessibility.framework.AccessibilityCheckResult.AccessibilityCheckResultType
import com.google.android.apps.common.testing.accessibility.framework.AccessibilityViewCheckResult
import com.google.android.apps.common.testing.accessibility.framework.integrations.espresso.AccessibilityValidator
import java.io.File
import org.hamcrest.CoreMatchers
import org.hamcrest.Matcher
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith

/**
 * Automatic accessibility audit of the main screens (task 3.1), with the
 * Accessibility Test Framework for Android (Apache 2.0) through Compose's
 * `enableAccessibilityChecks`: connect, unlock, the vault list, an item, a
 * one-time code, the item form, the generator, Send and its form, and the
 * settings. The checks run on every action and once more on each screen.
 *
 * Every finding is collected first, so one run names all of them; the test
 * then fails on any error. The findings go to files/e2e-shots/a11y-findings.txt,
 * which android-run.sh pulls with the screenshots. Suppressions live in
 * [A11ySuppressions], each with its reason.
 *
 * Compose's hook needs Android 14 (API 34), so this class runs on the API 34
 * job only. A TalkBack pass by hand is still open (task 3.1).
 */
@RunWith(AndroidJUnit4::class)
@SdkSuppress(minSdkVersion = 34)
class AccessibilityAuditTest {
    @get:Rule
    val compose = createAndroidComposeRule<MainActivity>()

    private var screen = "start"
    private val errors = linkedMapOf<String, String>()
    private val warnings = linkedMapOf<String, String>()
    private val screensChecked = mutableListOf<String>()

    @Before
    fun setUp() {
        E2e.requireServer()
        check(E2e.appPassword.isNotEmpty()) { "keepiqAppPassword was not passed" }
        val descriptor = AccessibilityCheckResultDescriptor()
        val validator = AccessibilityValidator()
            .setCheckPreset(AccessibilityCheckPreset.LATEST)
            .setRunChecksFromRootView(true)
            // Contrast needs pixels: Compose does not report text colours.
            .setCaptureScreenshots(true)
            // Collect, then fail once at the end with the whole list.
            .setThrowExceptionFor(null)
            .setSuppressingResultMatcher(A11ySuppressions.matcher)
            .addCheckListener(
                object : AccessibilityValidator.AccessibilityCheckListener {
                    override fun onResults(context: Context, results: List<AccessibilityViewCheckResult>) {
                        for (result in results) {
                            val target = when (result.type) {
                                AccessibilityCheckResultType.ERROR -> errors
                                AccessibilityCheckResultType.WARNING -> warnings
                                else -> continue
                            }
                            val text = "${result.sourceCheckClass.simpleName}: ${descriptor.describeResult(result)}"
                            if (text !in target) target[text] = screen
                        }
                    }
                },
            )
        compose.enableAccessibilityChecks(validator)
    }

    @Test
    fun mainScreensPassTheAccessibilityChecks() {
        // Connect.
        compose.waitForTag("server")
        audit("connect")
        compose.onNodeWithTag("server").performTextInput(E2e.server)
        compose.onNodeWithTag("useAppPassword").performClick()
        compose.waitForTag("appPassword")
        audit("connect with an app password")
        compose.onNodeWithTag("loginName").performTextInput(E2e.user)
        compose.onNodeWithTag("appPassword").performTextInput(E2e.appPassword)
        compose.onNodeWithTag("connect").performClick()

        // Unlock.
        compose.waitForTag("masterPassword", 60_000)
        audit("unlock")
        compose.onNodeWithTag("masterPassword").performTextInput(E2e.masterPassword)
        compose.onNodeWithTag("unlock").performClick()

        // The vault list.
        compose.waitForTag("unlocked", 60_000)
        compose.waitForText("Webmail (demo)", 60_000)
        compose.waitForText("Last synced", 60_000)
        audit("vault list")

        // An item, its password shown.
        compose.onNodeWithText("Webmail (demo)").performClick()
        compose.waitForText("anna.demo@example.com", 60_000)
        audit("item detail")
        compose.onNodeWithContentDescription("Show Password").performClick()
        compose.waitForText("Lantern-Orbit-42!")
        audit("item detail, password shown")
        compose.onNodeWithText("Back").performClick()

        // A one-time code and its countdown.
        compose.waitForText("Authenticator (demo)")
        compose.onNodeWithText("Authenticator (demo)").performClick()
        compose.waitUntil(10_000) {
            compose.onAllNodes(hasContentDescription("seconds left", substring = true)).fetchSemanticsNodes().isNotEmpty()
        }
        audit("item detail, one-time code")
        compose.onNodeWithText("Back").performClick()

        // The item form.
        compose.waitForText("Webmail (demo)")
        compose.onNodeWithContentDescription("Add an item").performClick()
        compose.waitUntil(30_000) { compose.onAllNodes(hasSetTextAction() and hasText("Name")).fetchSemanticsNodes().isNotEmpty() }
        audit("item form")
        compose.onNodeWithText("Back").performClick()

        // The generator.
        compose.waitForText("Generator")
        compose.onNodeWithText("Generator").performClick()
        compose.waitForTag("generated")
        audit("generator")

        // Send and its form.
        compose.onNodeWithText("Send").performClick()
        compose.waitForText("Open a Send link")
        audit("send list")
        compose.onNodeWithContentDescription("New Send").performClick()
        compose.waitUntil(30_000) { compose.onAllNodes(hasSetTextAction() and hasText("Text to send")).fetchSemanticsNodes().isNotEmpty() }
        audit("send form")
        compose.onNodeWithText("Back").performClick()

        // The settings.
        compose.onNodeWithTag("settings").performClick()
        compose.waitForTag("newPin")
        audit("settings")

        report()
    }

    /** Runs the checks over the whole window of [name], and names the screen for what the next actions find. */
    private fun audit(name: String) {
        screen = name
        compose.waitForIdle()
        compose.onAllNodes(isRoot()).tryPerformAccessibilityChecks()
        screensChecked += name
        E2e.shot("a11y-" + name.replace(Regex("[^a-z0-9]+"), "-"))
    }

    private fun report() {
        val text = buildString {
            appendLine("Screens checked: ${screensChecked.joinToString(", ")}")
            appendLine("Errors: ${errors.size}")
            errors.forEach { (finding, where) -> appendLine("ERROR on $where: $finding") }
            appendLine("Warnings: ${warnings.size}")
            warnings.forEach { (finding, where) -> appendLine("WARNING on $where: $finding") }
            appendLine("Suppressed (A11ySuppressions): ${A11ySuppressions.reasons.joinToString("; ").ifEmpty { "none" }}")
        }
        val dir = File(InstrumentationRegistry.getInstrumentation().targetContext.filesDir, "e2e-shots").apply { mkdirs() }
        File(dir, "a11y-findings.txt").writeText(text)
        android.util.Log.i("KeepiqA11y", text)
        assertTrue("accessibility errors:\n$text", errors.isEmpty())
    }
}

/**
 * Findings the audit leaves out, each a documented false positive with its
 * reason. Real findings are fixed in the app, never listed here.
 */
object A11ySuppressions {
    /** One line per suppression, for the report. */
    val reasons: List<String> = emptyList()

    val matcher: Matcher<in AccessibilityViewCheckResult> = CoreMatchers.not(CoreMatchers.anything())
}
