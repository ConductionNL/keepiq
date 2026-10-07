// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android

import android.app.Activity
import android.app.Instrumentation.ActivityResult
import android.content.Intent
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextInput
import androidx.test.espresso.intent.Intents
import androidx.test.espresso.intent.Intents.intending
import androidx.test.espresso.intent.matcher.IntentMatchers.hasAction
import androidx.test.ext.junit.runners.AndroidJUnit4
import kotlinx.coroutines.runBlocking
import nl.conduction.keepiq.shared.api.Account
import nl.conduction.keepiq.shared.api.KeepiqApiException
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith

/**
 * The whole of task group 2 on the emulator against the test server, in the
 * order a user meets it: pair through Login Flow v2 (2.1), see the phone in
 * the device list (2.4), a wrong and a right master password (2.2), lock,
 * a PIN with a wrong try (2.3), the device lock (2.5), and unpair, after
 * which the old app password gets 401 (2.6).
 *
 * The system browser is replaced by the e2e helper: the test catches the
 * Custom Tab intent, and the helper signs in on the very login page the
 * intent carried, and grants access, as the user would.
 */
@RunWith(AndroidJUnit4::class)
class PairUnlockUnpairTest {
    @get:Rule
    val compose = createAndroidComposeRule<MainActivity>()

    @Before
    fun setUp() {
        E2e.requireServer()
        Intents.init()
        intending(hasAction(Intent.ACTION_VIEW)).respondWith(ActivityResult(Activity.RESULT_OK, null))
    }

    @After
    fun tearDown() {
        Intents.release()
    }

    @Test
    fun pairUnlockLockPinDeviceLockAndUnpair() {
        // 2.1: the address form, then the browser sign-in.
        compose.waitForTag("server")
        E2e.shot("01-connect")
        compose.onNodeWithTag("server").performTextInput(E2e.server)
        compose.onNodeWithTag("signIn").performClick()
        compose.waitForTag("waiting")
        E2e.shot("02-waiting-for-browser")
        val loginUrl = Intents.getIntents().last { it.action == Intent.ACTION_VIEW }.data.toString()
        assertTrue("the login page is on the test server: $loginUrl", loginUrl.startsWith(E2e.server + "/"))
        E2e.helper("/grant", """{"loginUrl":${jsonString(loginUrl)},"user":${jsonString(E2e.user)}}""")

        // Paired: the unlock screen.
        compose.waitForTag("masterPassword", 60_000)
        val account = E2e.app.state.client.accounts.accounts().single()
        assertEquals(E2e.user, account.loginName)
        E2e.shot("03-unlock")

        // 2.4: Nextcloud names the app password after the app.
        val tokens = E2e.helper("/tokens")
        assertTrue("device list: $tokens", tokens.contains("Keepiq for Android"))

        // 2.2: a wrong master password unlocks nothing.
        compose.onNodeWithTag("masterPassword").performTextInput("not the master password")
        compose.onNodeWithTag("unlock").performClick()
        compose.waitForText("This master password is not correct.", 60_000)
        E2e.shot("04-wrong-master-password")

        // 2.2: the right one opens the vault.
        compose.onNodeWithTag("masterPassword").performTextInput(E2e.masterPassword)
        compose.onNodeWithTag("unlock").performClick()
        compose.waitForTag("unlocked", 60_000)
        E2e.shot("05-unlocked")

        // 2.3: a PIN.
        compose.onNodeWithTag("settings").performClick()
        compose.waitForTag("newPin")
        compose.onNodeWithTag("newPin").performTextInput("246810")
        compose.onNodeWithTag("setPin").performClick()
        compose.waitForTag("removePin", 60_000)
        E2e.shot("06-settings-pin-set")
        compose.onNodeWithTag("back").performScrollTo().performClick()

        // Lock, a wrong PIN, then the right one.
        compose.waitForTag("lock")
        compose.onNodeWithTag("lock").performClick()
        compose.waitForTag("pin", 30_000)
        E2e.shot("07-locked-pin")
        compose.onNodeWithTag("pin").performTextInput("000000")
        compose.onNodeWithTag("unlockPin").performClick()
        compose.waitForText("Wrong PIN. 4 tries left.", 60_000)
        E2e.shot("08-wrong-pin")
        compose.onNodeWithTag("pin").performTextInput("246810")
        compose.onNodeWithTag("unlockPin").performClick()
        compose.waitForTag("unlocked", 60_000)

        // 2.5: the phone locks, so the vault locks.
        E2e.shell("input keyevent KEYCODE_SLEEP")
        Thread.sleep(1_500)
        E2e.shell("input keyevent KEYCODE_WAKEUP")
        E2e.shell("wm dismiss-keyguard")
        compose.waitForTag("pin", 30_000)
        E2e.shot("09-locked-after-screen-off")
        compose.onNodeWithTag("pin").performTextInput("246810")
        compose.onNodeWithTag("unlockPin").performClick()
        compose.waitForTag("unlocked", 60_000)

        // 2.6: unpair.
        compose.onNodeWithTag("settings").performClick()
        compose.waitForTag("unpair")
        compose.onNodeWithTag("unpair").performScrollTo().performClick()
        compose.waitForTag("confirmUnpair")
        E2e.shot("10-unpair-confirm")
        compose.onNodeWithTag("confirmUnpair").performClick()
        compose.waitForText("The app password was deleted in Nextcloud.", 60_000)
        E2e.shot("11-unpaired")
        assertTrue(E2e.app.state.client.accounts.accounts().isEmpty())
        assertTrue(!E2e.app.state.client.pins.has(account.id))

        // The old app password no longer works on the server.
        runBlocking {
            try {
                E2e.app.state.client.api(Account("", account.server, account.loginName, account.appPassword)).pair()
                fail("the old app password still pairs")
            } catch (e: KeepiqApiException) {
                assertEquals(401, e.status)
            }
        }
        val after = E2e.helper("/tokens")
        assertTrue("device list after unpair: $after", !after.contains("Keepiq for Android"))
    }

    private fun jsonString(value: String): String =
        "\"" + value.replace("\\", "\\\\").replace("\"", "\\\"") + "\""
}
