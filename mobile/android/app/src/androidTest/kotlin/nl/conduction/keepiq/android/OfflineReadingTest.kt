// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android

import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextInput
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.jsonPrimitive
import nl.conduction.keepiq.shared.vault.WriteProblemKind
import nl.conduction.keepiq.shared.vault.WriteResult
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import kotlin.concurrent.thread

/**
 * Task 3.4 on the emulator against the test server: offline reading with
 * the last-synced note and refused edits.
 *
 * Pair and unlock online, so the vault syncs into the offline store. Lock,
 * cut the network (Wi-Fi and mobile data off, and airplane mode where the
 * shell can set it), and prove the server is out of reach from the app's
 * own process before going on. Unlock offline, see the list from the store
 * with the note that names the last sync, open an item from the store and
 * reveal its password, and find every edit refused: the buttons are off, and
 * a trash call on the repository comes back refused as offline without
 * reaching the server. Then the network comes back, a manual sync says
 * "Last synced" again, and the item is still on the server.
 */
@RunWith(AndroidJUnit4::class)
class OfflineReadingTest {
    @get:Rule
    val compose = createAndroidComposeRule<MainActivity>()

    @Before
    fun setUp() {
        E2e.requireServer()
        check(E2e.appPassword.isNotEmpty()) { "keepiqAppPassword was not passed" }
        Network.restore()
    }

    /** Whatever happened, the next test class gets its network back. */
    @After
    fun tearDown() {
        Network.restore()
    }

    @Test
    fun syncThenReadOfflineWithTheLastSyncedNoteAndRefusedEdits() {
        // Online: pair, unlock, and the sync fills the store.
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
        compose.waitForText("Last synced", 60_000)
        E2e.shot("50-online-synced")
        val online = E2e.app.state.screen.value as Screen.Unlocked
        val webmailId = online.session.repository.state.rows.single { it.name == "Webmail (demo)" }.id

        // Lock, then cut the network, and check that it is really cut.
        compose.onNodeWithTag("lock").performClick()
        compose.waitForTag("masterPassword", 30_000)
        Network.cut()
        Network.awaitUnreachable()
        E2e.shot("51-network-cut")
        // The unlock screen asks the server again and falls back to the suite of the last unlock.
        val accountId = online.vault.accountId
        InstrumentationRegistry.getInstrumentation().runOnMainSync { E2e.app.state.refreshGate(accountId) }
        compose.waitForText("You are offline. Keepiq unlocks with what this phone stored", 90_000)
        E2e.shot("51-offline-unlock")

        // Unlock offline: the list comes from the store, with the time of the last sync.
        compose.onNodeWithTag("masterPassword").performTextInput(E2e.masterPassword)
        compose.onNodeWithTag("unlock").performClick()
        compose.waitForTag("unlocked", 60_000)
        compose.waitForText("You are offline. You see the copy from", 90_000)
        compose.waitForText("Webmail (demo)")
        compose.waitForText("Personal")
        // No add button while offline.
        assertTrue(compose.onAllNodes(hasContentDescription("Add an item")).fetchSemanticsNodes().isEmpty())
        E2e.shot("52-offline-list")
        val offline = E2e.app.state.screen.value as Screen.Unlocked
        assertTrue("the vault state says offline", offline.session.repository.state.offline)
        assertTrue("the offline state keeps the last sync time", offline.session.repository.state.syncedAtMillis != null)

        // An item from the store: the note, its login, and its password on request.
        compose.onNodeWithText("Webmail (demo)").performClick()
        compose.waitForText("This is the copy from your last sync", 60_000)
        compose.waitForText("anna.demo@example.com")
        compose.onNodeWithContentDescription("Show Password").performClick()
        compose.waitForText("Lantern-Orbit-42!")
        E2e.shot("53-offline-item")

        // Every edit is refused: the buttons are off, with the reason under them.
        compose.onNode(hasText("Edit") and hasClickAction()).performScrollTo().assertIsNotEnabled()
        compose.onNode(hasText("Move") and hasClickAction()).assertIsNotEnabled()
        compose.onNode(hasText("Move to trash") and hasClickAction()).performScrollTo().assertIsNotEnabled()
        compose.waitForText("Edits need a connection. Nothing was changed.")
        E2e.shot("54-offline-edit-refused")
        // And a write that gets past the screen is refused before it is sent.
        val trash = onOwnThread { offline.session.repository.trash(webmailId) }
        assertTrue("trash offline is refused: $trash", trash is WriteResult.Refused)
        assertEquals(WriteProblemKind.OFFLINE, (trash as WriteResult.Refused).problem.kind)
        compose.onNodeWithText("Back").performClick()
        compose.waitForText("Webmail (demo)")

        // The network comes back: a manual sync, and the item is still on the server.
        Network.restore()
        Network.awaitReachable()
        compose.onNodeWithContentDescription("Sync now").performClick()
        compose.waitForText("Last synced", 90_000)
        compose.waitUntilGone("You are offline", 30_000)
        E2e.shot("55-back-online")
        val onServer = onOwnThread { offline.session.api.listSecrets() }
            .map { it["name"]?.jsonPrimitive?.content }
        assertTrue("the refused trash changed nothing on the server: $onServer", "Webmail (demo)" in onServer)
    }

    /** Runs a suspend call on a thread of its own (see VaultFlowsTest). */
    private fun <T> onOwnThread(block: suspend () -> T): T {
        var result: Result<T>? = null
        thread { result = runCatching { runBlocking { block() } } }.join()
        return result!!.getOrThrow()
    }
}
