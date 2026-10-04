// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.ui

import androidx.compose.material3.MaterialTheme
import androidx.compose.ui.Modifier
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.assertIsOn
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import nl.conduction.keepiq.shared.generator.GeneratorPolicy
import nl.conduction.keepiq.shared.generator.GeneratorSettings
import nl.conduction.keepiq.shared.vault.DecryptedItem
import nl.conduction.keepiq.shared.vault.SecretType
import nl.conduction.keepiq.shared.vault.TypeField
import nl.conduction.keepiq.shared.vault.VaultRow
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * The item detail and generator screens on the JVM (tasks 3.1 and 3.5),
 * through Compose's semantics tree, which is also what TalkBack reads:
 * a password is hidden until shown, every Show and Copy button names its
 * field, a use-only copy offers neither, and the policy locks the kinds of
 * character it requires.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [35])
class ScreensTest {
    @get:Rule
    val compose = createComposeRule()

    private val login = SecretType("1", "login", "Login", listOf(TypeField("pin", "Pincode", "hidden", false)))

    private fun row(useOnly: Boolean) = VaultRow(
        id = "s1", name = "Huisbank", url = "https://mijn.huisbank.example", typeId = "1", folderId = null,
        key = "ct", login = "ct", additionalFields = null, updatedAt = null,
        useOnly = useOnly, readOnly = useOnly, blocked = false, blockedReason = null,
    )

    private fun item(useOnly: Boolean) = DecryptedItem(
        row = row(useOnly), typeName = "login", type = login, login = "alice",
        secret = if (useOnly) "" else "geheim-1",
        additionalFields = if (useOnly) null else Json.parseToJsonElement("""{"Pincode":"4321"}""").jsonObject,
        additionalFieldsError = false, passkey = null, fromCache = false,
    )

    private fun show(item: DecryptedItem, copied: MutableList<String>) = compose.setContent {
        MaterialTheme {
            ItemDetail(
                item = item, folderPath = "Geen map", offline = false, problem = null, modifier = Modifier,
                onCopy = { copied += it }, onEdit = {}, onMove = {}, onTrash = {}, onSendLogin = {},
            )
        }
    }

    @Test
    fun aPasswordIsHiddenUntilShownAndCopiesThroughTheCallback() {
        val copied = mutableListOf<String>()
        show(item(useOnly = false), copied)
        compose.onNodeWithText("geheim-1").assertDoesNotExist()
        compose.onNodeWithContentDescription("Show Password").performClick()
        compose.onNodeWithText("geheim-1").assertIsDisplayed()
        compose.onNodeWithContentDescription("Copy Password").performClick()
        compose.onNodeWithContentDescription("Copy User name").performClick()
        assertEquals(listOf("geheim-1", "alice"), copied)
        // A hidden typed field is masked too, and names itself.
        compose.onNodeWithText("4321").assertDoesNotExist()
        compose.onNodeWithContentDescription("Show Pincode").assertExists()
    }

    @Test
    fun aUseOnlyCopyOffersNoRevealAndNoCopyOfItsValue() {
        show(item(useOnly = true), mutableListOf())
        compose.onNodeWithContentDescription("Show Password").assertDoesNotExist()
        compose.onNodeWithContentDescription("Copy Password").assertDoesNotExist()
        compose.onNodeWithText("Shared as use only. You can fill it, but not view or copy the value.").assertExists()
        compose.onNodeWithText("Edit").assertDoesNotExist()
    }

    @Test
    fun thePolicyLocksTheDigitSwitchAndRaisesTheLength() {
        val policy = GeneratorPolicy.from(
            Json.parseToJsonElement("""{"policy_enabled":true,"generator_min_length":20,"generator_require_digit":true}""").jsonObject,
        )
        var value = ""
        compose.setContent {
            MaterialTheme {
                GeneratorScreen(
                    policy = policy,
                    settings = GeneratorSettings(password = GeneratorSettings.DEFAULT_PASSWORD.copy(includeDigits = false)),
                    onSettings = {},
                    onCopy = null,
                    modifier = Modifier,
                    onValue = { value = it },
                )
            }
        }
        compose.onNodeWithText("Length: 20").assertExists()
        compose.onNodeWithText("Your organisation asks for at least 20 characters.").assertExists()
        compose.onNodeWithText("0 to 9", useUnmergedTree = true).assertExists()
        compose.waitForIdle()
        assertEquals(20, value.length)
        assert(value.any { it.isDigit() }) { value }
    }
}
