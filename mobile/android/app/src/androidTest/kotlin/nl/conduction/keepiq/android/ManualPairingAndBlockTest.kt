// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android

import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTextInput
import androidx.test.ext.junit.runners.AndroidJUnit4
import org.junit.Assert.assertEquals
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith

/**
 * The fallback pairing with an app password (2.1), and the two-factor block
 * (2.2). android-run.sh turns on the organisation's two-factor policy
 * before this class runs; the demo user has no second factor, so the server
 * withholds the key and the app must say why and offer no unlock field.
 */
@RunWith(AndroidJUnit4::class)
class ManualPairingAndBlockTest {
    @get:Rule
    val compose = createAndroidComposeRule<MainActivity>()

    @Before
    fun setUp() {
        E2e.requireServer()
        check(E2e.appPassword.isNotEmpty()) { "keepiqAppPassword was not passed" }
    }

    @Test
    fun pairWithAnAppPasswordAndMeetTheTwoFactorBlock() {
        compose.waitForTag("server")
        compose.onNodeWithTag("server").performTextInput(E2e.server)
        compose.onNodeWithTag("useAppPassword").performClick()
        compose.waitForTag("appPassword")
        compose.onNodeWithTag("loginName").performTextInput(E2e.user)
        compose.onNodeWithTag("appPassword").performTextInput(E2e.appPassword)
        E2e.shot("20-app-password")
        compose.onNodeWithTag("connect").performClick()

        compose.waitForTag("blocked", 60_000)
        compose.waitForText("two-factor authentication")
        compose.onNodeWithTag("masterPassword").assertDoesNotExist()
        compose.onNodeWithTag("pin").assertDoesNotExist()
        assertEquals(1, E2e.app.state.client.accounts.accounts().size)
        E2e.shot("21-two-factor-required")
    }
}
