// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android

import android.content.ClipboardManager
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.ComposeTestRule
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextClearance
import androidx.compose.ui.test.performTextInput
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import nl.conduction.keepiq.shared.vault.VaultLockedException
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith

/**
 * Task group 3 on the emulator against the test server, over the demo vault
 * of `mobile/e2e/server.mjs seed`: after unlock the vault list, search, an
 * item with its password revealed and copied (marked sensitive), the
 * authenticator code with its countdown, a new login with a generated
 * password, its edit and its trash, the generator, a Send with its link,
 * the settings route, and the lock, after which the session's key is gone.
 */
@RunWith(AndroidJUnit4::class)
class VaultFlowsTest {
    @get:Rule
    val compose = createAndroidComposeRule<MainActivity>()

    @Before
    fun setUp() {
        E2e.requireServer()
        check(E2e.appPassword.isNotEmpty()) { "keepiqAppPassword was not passed" }
    }

    @Test
    fun vaultSearchRevealCopyTotpCreateEditTrashGeneratorSendAndLock() {
        // Pair with an app password and unlock: the vault list is what opens.
        compose.waitForTag("server")
        compose.onNodeWithTag("server").performTextInput(E2e.server)
        compose.onNodeWithTag("useAppPassword").performClick()
        compose.waitForTag("appPassword")
        compose.onNodeWithTag("loginName").performTextInput(E2e.user)
        compose.onNodeWithTag("appPassword").performTextInput(E2e.appPassword)
        compose.onNodeWithTag("connect").performClick()
        compose.waitForTag("masterPassword", 60_000)
        compose.onNodeWithTag("masterPassword").performTextInput(E2e.masterPassword)
        compose.onNodeWithTag("unlock").performClick()
        compose.waitForTag("unlocked", 60_000)
        compose.waitForText("Webmail (demo)", 60_000)
        compose.waitForText("Authenticator (demo)")
        compose.waitForText("Personal")
        E2e.shot("30-vault-list")
        val unlocked = E2e.app.state.screen.value as Screen.Unlocked

        // Search runs on the device, over every folder.
        compose.onNodeWithTag("search").performTextInput("bank")
        compose.waitForText("Bank (demo)")
        compose.waitUntilGone("Webmail (demo)")
        E2e.shot("31-search")
        compose.onNodeWithTag("search").performTextClearance()
        compose.waitForText("Webmail (demo)")

        // An item: the password hidden until shown, then copied as sensitive.
        compose.onNodeWithText("Webmail (demo)").performClick()
        compose.waitForText("anna.demo@example.com", 60_000)
        assertTrue(compose.onAllNodesWithText("Lantern-Orbit-42!").fetchSemanticsNodes().isEmpty())
        compose.onNodeWithContentDescription("Show Password").performClick()
        compose.waitForText("Lantern-Orbit-42!")
        E2e.shot("32-item-revealed")
        compose.onNodeWithContentDescription("Copy Password").performClick()
        compose.waitForText("Copied.")
        val (copied, sensitive) = clipboard()
        assertEquals("Lantern-Orbit-42!", copied)
        assertTrue("the copied password is marked sensitive", sensitive)
        E2e.shot("33-copied")
        compose.onNodeWithText("Back").performClick()

        // The authenticator code and its seconds left.
        compose.waitForText("Authenticator (demo)")
        compose.onNodeWithText("Authenticator (demo)").performClick()
        compose.waitUntil(60_000) { compose.nodes(totpCode).isNotEmpty() }
        compose.waitUntil(10_000) { compose.nodes(hasContentDescription("seconds left", substring = true)).isNotEmpty() }
        E2e.shot("34-totp")
        compose.onNodeWithText("Back").performClick()

        // A new login with a generated password.
        compose.onNodeWithContentDescription("Add an item").performClick()
        compose.waitUntil(30_000) { compose.nodes(field("Name")).isNotEmpty() }
        compose.onNode(field("Name")).performTextInput("Shop (demo)")
        compose.onNode(field("Website address")).performTextInput("https://shop.example.com")
        compose.onNode(field("User name")).performScrollTo().performTextInput("anna.demo@example.com")
        compose.onNodeWithText("Generate").performScrollTo().performClick()
        compose.waitForTag("generated")
        E2e.shot("35-generate-in-form")
        compose.onNodeWithText("Use this").performClick()
        val generated = compose.onNode(field("Password")).fetchSemanticsNode().config[SemanticsProperties.EditableText].text
        assertTrue("a generated password was filled in", generated.length >= 12)
        compose.onNodeWithText("Save").performScrollTo().performClick()
        compose.waitForText("Shop (demo)", 60_000)
        compose.waitForText("anna.demo@example.com")
        compose.onNodeWithContentDescription("Show Password").performClick()
        compose.waitForText(generated)
        E2e.shot("36-created")

        // Edit it.
        compose.onNodeWithText("Edit").performScrollTo().performClick()
        compose.waitUntil(30_000) { compose.nodes(field("Name")).isNotEmpty() }
        compose.onNode(field("Name")).performTextClearance()
        compose.onNode(field("Name")).performTextInput("Shop account (demo)")
        compose.onNodeWithText("Save").performScrollTo().performClick()
        compose.waitForText("Shop account (demo)", 60_000)
        E2e.shot("37-edited")

        // Trash it: gone from the list.
        compose.onNodeWithText("Move to trash").performScrollTo().performClick()
        compose.waitForTag("confirmTrash")
        compose.onNodeWithTag("confirmTrash").performClick()
        compose.waitForText("Webmail (demo)", 60_000)
        compose.waitUntilGone("Shop account (demo)", 60_000)
        E2e.shot("38-trashed")

        // The generator.
        compose.onNodeWithText("Generator").performClick()
        compose.waitForTag("generated")
        val first = generatedValue()
        compose.onNodeWithContentDescription("Generate a new value").performClick()
        compose.waitUntil(10_000) { generatedValue() != first }
        assertNotEquals(first, generatedValue())
        E2e.shot("39-generator")

        // A Send and its link.
        compose.onNodeWithText("Send").performClick()
        compose.onNodeWithContentDescription("New Send").performClick()
        compose.waitUntil(30_000) { compose.nodes(field("Text to send")).isNotEmpty() }
        compose.onNode(field("Text to send")).performTextInput("The demo door code is 2468.")
        compose.onNodeWithText("Create link").performScrollTo().performClick()
        compose.waitForText("Your link is ready", 60_000)
        val link = compose.nodes(hasText("/public/", substring = true)).single().config[SemanticsProperties.Text].joinToString("")
        assertTrue("a Send link with its key in the fragment: $link", link.startsWith(E2e.server) && link.contains("#"))
        E2e.shot("40-send-link")
        compose.onNodeWithText("Close").performScrollTo().performClick()
        compose.waitForText("Text", 30_000)
        E2e.shot("41-send-list")

        // The settings route from inside the vault, and back.
        compose.onNodeWithTag("settings").performClick()
        compose.waitForTag("newPin")
        E2e.shot("42-settings")
        compose.onNodeWithTag("back").performScrollTo().performClick()
        compose.waitForTag("unlocked")

        // Lock: the unlock screen, and the session's key no longer decrypts.
        compose.onNodeWithTag("lock").performClick()
        compose.waitForTag("masterPassword", 30_000)
        E2e.shot("43-locked")
        assertTrue(unlocked.vault.isLocked)
        assertFalse(E2e.app.state.screen.value is Screen.Unlocked)
        try {
            unlocked.session.keys.decryptField("AAAA")
            fail("the locked session still decrypts")
        } catch (e: VaultLockedException) {
            // The key is gone.
        }
    }

    /** A six-digit code shown as two groups of three. */
    private val totpCode = SemanticsMatcher("a TOTP code") { node ->
        node.config.getOrElse(SemanticsProperties.Text) { emptyList() }.any { Regex("^\\d{3} \\d{3}$").matches(it.text) }
    }

    /** An input labelled [label]. */
    private fun field(label: String): SemanticsMatcher = hasSetTextAction() and hasText(label)

    private fun ComposeTestRule.nodes(matcher: SemanticsMatcher) =
        onAllNodes(matcher, useUnmergedTree = false).fetchSemanticsNodes()

    private fun generatedValue(): String =
        compose.onNodeWithTag("generated", useUnmergedTree = true).fetchSemanticsNode()
            .config.getOrElse(SemanticsProperties.Text) { emptyList() }.joinToString("")

    /** The clipboard's text and whether it is marked sensitive, read on the main thread while the app has focus. */
    private fun clipboard(): Pair<String, Boolean> {
        var result = "" to false
        InstrumentationRegistry.getInstrumentation().runOnMainSync {
            val clip = E2e.app.getSystemService(ClipboardManager::class.java).primaryClip
            val text = clip?.getItemAt(0)?.text?.toString() ?: ""
            val sensitive = clip?.description?.extras?.getBoolean("android.content.extra.IS_SENSITIVE") == true
            result = text to sensitive
        }
        return result
    }
}
