// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.ui

import androidx.compose.foundation.layout.Column
import androidx.compose.material3.MaterialTheme
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import nl.conduction.keepiq.android.passkey.PasskeySettings
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * The passkeys part of the autofill settings (mobile-passkey-provider,
 * "Android before version 14"): Android 13 hears that passkeys need
 * Android 14 while passwords and codes still fill; Android 14 gets the way
 * to choose Keepiq, until it is chosen.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [35])
class PasskeySettingsTest {
    @get:Rule
    val compose = createComposeRule()

    private fun show(sdk: Int, enabled: Boolean) = compose.setContent {
        MaterialTheme { Column { PasskeySettings(sdk = sdk, enabled = enabled) } }
    }

    @Test
    fun androidThirteenExplainsThatPasskeysNeedAndroidFourteen() {
        show(33, enabled = false)
        compose.onNodeWithText("Passkeys need Android 14. Keepiq still fills in your passwords and codes.").assertIsDisplayed()
        compose.onNodeWithTag("passkeyChoose").assertDoesNotExist()
    }

    @Test
    fun androidFourteenOffersToChooseKeepiqUntilItIsChosen() {
        show(34, enabled = false)
        compose.onNodeWithText("Choose Keepiq for passkeys").assertIsDisplayed()
    }

    @Test
    fun aChosenProviderSaysWhatItDoes() {
        show(34, enabled = true)
        compose.onNodeWithText("Keepiq saves your passkeys and signs you in with them in apps and browsers.").assertIsDisplayed()
        compose.onNodeWithTag("passkeyChoose").assertDoesNotExist()
    }
}
