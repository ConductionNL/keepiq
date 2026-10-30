// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.ui

import android.view.View
import androidx.compose.foundation.layout.Column
import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.input.InputMode
import androidx.compose.ui.platform.LocalInputModeManager
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.assert
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsFocused
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.assertIsOn
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextInput
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import nl.conduction.keepiq.shared.generator.GeneratorPolicy
import nl.conduction.keepiq.shared.generator.GeneratorSettings
import nl.conduction.keepiq.shared.vault.DecryptedItem
import nl.conduction.keepiq.shared.vault.FormKind
import nl.conduction.keepiq.shared.vault.ItemDraft
import nl.conduction.keepiq.shared.vault.KeyEdit
import nl.conduction.keepiq.shared.vault.PasskeySummary
import nl.conduction.keepiq.shared.vault.SecretType
import nl.conduction.keepiq.shared.vault.TypeField
import nl.conduction.keepiq.shared.vault.VaultRow
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test
import java.time.ZoneOffset
import java.util.Locale
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

    @Test
    fun theSettingsAutofillEntryOffersTheChoiceOnlyWhenKeepiqIsNotTheService() {
        var enabled by mutableStateOf(false)
        val calls = mutableListOf<String>()
        compose.setContent {
            MaterialTheme {
                Column { AutofillEntry(enabled = enabled, onChoose = { calls += "choose" }, onNeverList = { calls += "never" }) }
            }
        }
        compose.onNodeWithText("Keepiq does not fill in logins yet. Choose Keepiq as your autofill service.").assertExists()
        compose.onNodeWithText("Choose Keepiq for autofill").performClick()
        compose.onNodeWithText("Show where Keepiq never saves").performClick()
        assertEquals(listOf("choose", "never"), calls)

        enabled = true
        compose.onNodeWithText("Keepiq fills in your logins in apps and browsers.").assertExists()
        compose.onNodeWithText("Choose Keepiq for autofill").assertDoesNotExist()
        compose.onNodeWithText("Show where Keepiq never saves").assertExists()
    }

    private fun passkey(id: String, user: String?, createdAt: String?) = PasskeySummary(id, "mail.example", "Mail", user, createdAt)

    private fun mail(useOnly: Boolean = false, readOnly: Boolean = useOnly, passkeys: List<PasskeySummary> = emptyList(), seed: String = "JBSWY3DPEHPK3PXP") =
        DecryptedItem(
            row = row(useOnly).copy(readOnly = readOnly), typeName = "login", type = login, login = "alice",
            secret = if (useOnly) "" else "geheim-1", additionalFields = null, additionalFieldsError = false, passkey = null,
            fromCache = false, totpSeed = seed, hasSavedKey = seed.isNotEmpty(), passkeys = if (useOnly) emptyList() else passkeys,
        )

    private fun showMail(
        item: DecryptedItem,
        offline: Boolean = false,
        deleted: MutableList<String> = mutableListOf(),
        focusPasskey: Int? = null,
        onFocused: () -> Unit = {},
    ) =
        compose.setContent {
            // Focus moves for keyboard and switch users; in touch mode Compose gives buttons no focus.
            val inputMode = LocalInputModeManager.current
            var keyboard by remember { mutableStateOf(false) }
            LaunchedEffect(Unit) { keyboard = inputMode.requestInputMode(InputMode.Keyboard) }
            if (keyboard) MaterialTheme {
                ItemDetail(
                    item = item, folderPath = "Geen map", offline = offline, problem = null, modifier = Modifier,
                    onCopy = {}, onEdit = {}, onMove = {}, onTrash = {}, onSendLogin = {},
                    onDeletePasskey = { deleted += it.credentialId }, focusPasskey = focusPasskey, onPasskeyFocused = onFocused,
                )
            }
        }

    @Test
    fun aLoginShowsItsCodeButNeverItsKey() {
        showMail(mail())
        compose.onNodeWithText("Authenticator code").assertExists()
        compose.onNodeWithContentDescription("Copy Authenticator code").assertExists()
        compose.onNodeWithText("JBSWY3DPEHPK3PXP", substring = true).assertDoesNotExist()
    }

    @Test
    fun aPasskeyRowShowsTheDateItWasAdded() {
        assertEquals("March 12, 2026", passkeyAdded("2026-03-12T10:00:00.000Z", Locale.US, ZoneOffset.UTC))
        // An offset, as an import may write it; the date is the instant's date in the phone's zone.
        assertEquals("March 12, 2026", passkeyAdded("2026-03-12T10:00:00+01:00", Locale.US, ZoneOffset.UTC))
        assertEquals("March 11, 2026", passkeyAdded("2026-03-12T00:30:00+01:00", Locale.US, ZoneOffset.UTC))
        showMail(mail(passkeys = listOf(passkey("a", "alice", "2026-03-12T10:00:00.000Z"))))
        compose.onNodeWithText("Passkey").assertExists()
        compose.onNodeWithText("Added March 12, 2026").assertExists()
        compose.onNodeWithText("mail.example", substring = true).assertDoesNotExist()
    }

    @Test
    fun twoPasskeysNameTheirAccounts() {
        showMail(mail(passkeys = listOf(passkey("a", "alice", "2026-03-12T10:00:00.000Z"), passkey("b", "alice-work", "2026-04-01T10:00:00.000Z"))))
        compose.onNodeWithText("alice · added March 12, 2026").assertExists()
        compose.onNodeWithText("alice-work · added April 1, 2026").assertExists()
    }

    @Test
    fun aPasskeyWithoutAReadableDateReadsSaved() {
        assertEquals(null, passkeyAdded("yesterday", Locale.US, ZoneOffset.UTC))
        showMail(mail(passkeys = listOf(passkey("a", "alice", null))))
        compose.onNodeWithText("Passkey saved").assertExists()
    }

    @Test
    fun deleteNamesThePasskeyAndReportsIt() {
        val deleted = mutableListOf<String>()
        showMail(mail(passkeys = listOf(passkey("a", "alice", null), passkey("b", "alice-work", null))), deleted = deleted)
        compose.onNodeWithContentDescription("Delete passkey for alice-work").performScrollTo().performClick()
        assertEquals(listOf("b"), deleted)
    }

    @Test
    fun theDeleteDialogAsksFirst() {
        val calls = mutableListOf<String>()
        compose.setContent { MaterialTheme { PasskeyDeleteDialog(account = null, onConfirm = { calls += "yes" }, onDismiss = { calls += "no" }) } }
        compose.onNodeWithText("Delete this passkey?").assertExists()
        compose.onNodeWithText("Cancel").performClick()
        compose.onNodeWithText("Delete").performClick()
        assertEquals(listOf("no", "yes"), calls)
    }

    @Test
    fun theDeleteDialogNamesTheAccount() {
        compose.setContent { MaterialTheme { PasskeyDeleteDialog(account = "alice-work", onConfirm = {}, onDismiss = {}) } }
        compose.onNodeWithText("Delete the passkey for alice-work?").assertExists()
    }

    @Test
    fun aReadOnlyCopyOffersNoDelete() {
        val one = listOf(passkey("a", "alice", null))
        showMail(mail(readOnly = true, passkeys = one))
        compose.onNodeWithText("Passkey saved").assertExists()
        compose.onNodeWithContentDescription("Delete passkey").assertDoesNotExist()
    }

    @Test
    fun anOfflineCopyOffersNoDelete() {
        showMail(mail(passkeys = listOf(passkey("a", "alice", null))), offline = true)
        compose.onNodeWithContentDescription("Delete passkey").assertDoesNotExist()
    }

    @Test
    fun aUseOnlyCopyShowsItsCodeButNoPasskeys() {
        showMail(mail(useOnly = true, passkeys = listOf(passkey("a", "alice", null))))
        compose.onNodeWithText("Authenticator code").assertExists()
        compose.onNodeWithText("Passkey saved").assertDoesNotExist()
    }

    @Test
    fun afterADeleteFocusGoesToTheNextPasskeyElseEdit() {
        showMail(mail(passkeys = listOf(passkey("b", "alice-work", null))), focusPasskey = 0)
        compose.onNodeWithContentDescription("Delete passkey").assertIsFocused()
    }

    @Test
    fun afterTheLastDeleteFocusGoesToEdit() {
        showMail(mail(), focusPasskey = 0)
        compose.onNodeWithText("Edit").assertIsFocused()
    }

    @Test
    fun theFocusRequestIsClearedOnceTheNextRowHasFocus() {
        var cleared = 0
        showMail(mail(passkeys = listOf(passkey("b", "alice-work", null))), focusPasskey = 0, onFocused = { cleared++ })
        compose.onNodeWithContentDescription("Delete passkey").assertIsFocused()
        assertEquals(1, cleared)
    }

    @Test
    fun theFocusRequestIsClearedOnceEditHasFocus() {
        var cleared = 0
        showMail(mail(), focusPasskey = 0, onFocused = { cleared++ })
        compose.onNodeWithText("Edit").assertIsFocused()
        assertEquals(1, cleared)
    }

    private var keyDraft by mutableStateOf(ItemDraft(kind = FormKind.LOGIN, typeId = "1"))

    private fun showKeyField(draft: ItemDraft, removeOnly: Boolean = false) {
        keyDraft = draft
        compose.setContent {
            val inputMode = LocalInputModeManager.current
            var keyboard by remember { mutableStateOf(false) }
            LaunchedEffect(Unit) { keyboard = inputMode.requestInputMode(InputMode.Keyboard) }
            if (keyboard) MaterialTheme { Column { AuthenticatorKeyField(keyDraft, null, removeOnly) { keyDraft = it } } }
        }
    }

    private val keyLabel = "Authenticator key (base32 secret or otpauth link)"

    @Test
    fun aNewItemOffersNeitherAuthenticatorNorPasskey() {
        val types = listOf("login", "note", "totp", "passkey").map { SecretType(it, it, null, emptyList()) }
        assertEquals(listOf("login", "note"), creatableTypes(types).map { it.name })
    }

    @Test
    fun aLoginWithoutAKeyTakesOne() {
        showKeyField(ItemDraft(kind = FormKind.LOGIN, typeId = "1"))
        compose.onNodeWithText(keyLabel).performTextInput("JBSWY3DPEHPK3PXP")
        assertEquals(KeyEdit.REPLACE, keyDraft.keyEdit)
        assertEquals("JBSWY3DPEHPK3PXP", keyDraft.newKey)
    }

    @Test
    fun aSavedKeyIsNeverShown() {
        showKeyField(ItemDraft(kind = FormKind.LOGIN, typeId = "1", hasSavedKey = true))
        compose.onNodeWithText("Authenticator key saved").assertExists()
        compose.onNodeWithText("Replace").assertExists()
        compose.onNodeWithText("Remove").assertExists()
        compose.onNodeWithText(keyLabel).assertDoesNotExist()
    }

    @Test
    fun replaceShowsAnEmptyFieldAndKeepGoesBack() {
        showKeyField(ItemDraft(kind = FormKind.LOGIN, typeId = "1", hasSavedKey = true))
        compose.onNodeWithText("Replace").performClick()
        assertEquals(KeyEdit.REPLACE, keyDraft.keyEdit)
        compose.onNodeWithText(keyLabel).assertIsFocused()
        compose.onNodeWithText("Keep saved key").performClick()
        assertEquals(KeyEdit.KEEP, keyDraft.keyEdit)
        assertEquals("", keyDraft.newKey)
        compose.onNodeWithText("Replace").assertIsFocused()
    }

    @Test
    fun removeMarksTheKeyForRemoval() {
        showKeyField(ItemDraft(kind = FormKind.LOGIN, typeId = "1", hasSavedKey = true))
        compose.onNodeWithText("Remove").performClick()
        assertEquals(KeyEdit.REMOVE, keyDraft.keyEdit)
        compose.onNodeWithText("The key is removed when you save.").assertExists()
        compose.onNodeWithText("Keep saved key").assertIsFocused()
    }

    @Test
    fun anotherTypeWithAKeyOffersRemoveOnly() {
        showKeyField(ItemDraft(kind = FormKind.NOTE, typeId = "2", hasSavedKey = true), removeOnly = true)
        compose.onNodeWithText("Authenticator key saved").assertExists()
        compose.onNodeWithText("Replace").assertDoesNotExist()
        compose.onNodeWithText("Remove").performClick()
        compose.onNodeWithText("Keep saved key").performClick()
        compose.onNodeWithText("Remove").assertIsFocused()
    }

    @Test
    fun theKeyFieldIsMaskedAndKeepsTheScreenOutOfAutofill() {
        var view: View? = null
        var shown by mutableStateOf(false)
        compose.setContent {
            view = LocalView.current
            MaterialTheme { if (shown) AuthenticatorKeyField(ItemDraft(kind = FormKind.LOGIN, typeId = "1"), null, removeOnly = false) {} }
        }
        compose.waitForIdle()
        val before = view!!.importantForAutofill
        shown = true
        compose.waitForIdle()
        assertEquals(View.IMPORTANT_FOR_AUTOFILL_NO_EXCLUDE_DESCENDANTS, view!!.importantForAutofill)
        compose.onNodeWithText(keyLabel).assert(SemanticsMatcher.keyIsDefined(SemanticsProperties.Password))
        compose.onNodeWithText("Show $keyLabel").performClick()
        compose.onNodeWithText(keyLabel).assert(SemanticsMatcher.keyNotDefined(SemanticsProperties.Password))
        shown = false
        compose.waitForIdle()
        assertEquals(before, view!!.importantForAutofill)
    }

    @Test
    fun theKeyFieldKeepsTheKeyboardFromLearningTheKey() {
        assertEquals(KeyboardType.Password, AUTHENTICATOR_KEY_KEYBOARD.keyboardType)
        assertEquals(false, AUTHENTICATOR_KEY_KEYBOARD.autoCorrectEnabled)
    }

    @Test
    fun anAuthenticatorItemShowsItsOwnKeysCodeNamedApart() {
        val totp = SecretType("3", "totp", "Authenticator", emptyList())
        val item = DecryptedItem(
            row = row(useOnly = false).copy(typeId = "3"), typeName = "totp", type = totp, login = "", secret = "JBSWY3DPEHPK3PXP",
            additionalFields = null, additionalFieldsError = false, passkey = null, fromCache = false,
            totpSeed = "GEZDGNBVGY3TQOJQ", hasSavedKey = true,
        )
        show(item, mutableListOf())
        compose.onNodeWithText("Authenticator code").assertExists()
        compose.onNodeWithText("Code from the item's own key").assertExists()
        compose.onNodeWithContentDescription("Copy Code from the item's own key").assertExists()
    }

    private val vault = FakeVault()

    private fun showEdit(id: String) {
        compose.setContent {
            MaterialTheme {
                ItemEditScreen(vault.session, id, null, null, GeneratorSettings(), Modifier, onSaved = {}, onCancel = {})
            }
        }
        compose.waitUntil(5_000) { compose.onAllNodesWithText("Edit item").fetchSemanticsNodes().isNotEmpty() }
    }

    @Test
    fun anAuthenticatorItemWithItsOwnKeyOffersRemoveOnly() {
        vault.put("t1", FakeVault.row("t1", "Code", "3", key = "JBSWY3DPEHPK3PXP", totp = "GEZDGNBVGY3TQOJQ"))
        showEdit("t1")
        compose.onNodeWithText("Authenticator key saved").performScrollTo().assertExists()
        compose.onNodeWithText("Remove").assertExists()
        compose.onNodeWithText("Replace").assertDoesNotExist()
    }
}
