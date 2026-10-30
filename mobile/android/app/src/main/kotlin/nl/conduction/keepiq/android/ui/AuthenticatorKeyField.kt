// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.ui

import android.view.View
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import nl.conduction.keepiq.android.R
import nl.conduction.keepiq.shared.vault.DraftProblem
import nl.conduction.keepiq.shared.vault.ItemDraft
import nl.conduction.keepiq.shared.vault.KeyEdit

/** The keyboard for a key: a seed is a second factor, so the keyboard must not learn or suggest it. */
internal val AUTHENTICATOR_KEY_KEYBOARD = KeyboardOptions(keyboardType = KeyboardType.Password, autoCorrectEnabled = false)

/**
 * The Authenticator key of an item (mobile-login-totp-passkeys D8). A saved
 * key is never loaded: the field says one is saved and offers Replace and
 * Remove, or with [removeOnly] Remove alone. Focus moves to the control
 * that took the chosen one's place. A typed key is masked until shown, as
 * a password is, and while the field is on screen the screen is kept out of
 * the autofill framework, so another autofill service is never offered the
 * seed to save (the web field's autocomplete="off").
 */
@Composable
internal fun AuthenticatorKeyField(draft: ItemDraft, problem: DraftProblem?, removeOnly: Boolean, onChange: (ItemDraft) -> Unit) {
    val view = LocalView.current
    DisposableEffect(view) {
        val before = view.importantForAutofill
        view.importantForAutofill = View.IMPORTANT_FOR_AUTOFILL_NO_EXCLUDE_DESCENDANTS
        onDispose { view.importantForAutofill = before }
    }
    var shown by remember { mutableStateOf(false) }
    val field = remember { FocusRequester() }
    val replace = remember { FocusRequester() }
    val remove = remember { FocusRequester() }
    val keep = remember { FocusRequester() }
    var moved by remember { mutableStateOf(false) }
    fun choose(edit: KeyEdit) {
        moved = true
        onChange(draft.copy(keyEdit = edit, newKey = ""))
    }
    val label = stringResource(R.string.edit_totp_key)
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        if (!draft.hasSavedKey || draft.keyEdit == KeyEdit.REPLACE) {
            TextInput(
                label,
                draft.newKey,
                problem,
                modifier = Modifier.focusRequester(field),
                keyboardOptions = AUTHENTICATOR_KEY_KEYBOARD,
                visualTransformation = if (shown) VisualTransformation.None else PasswordVisualTransformation(),
            ) { v ->
                onChange(draft.copy(newKey = v, keyEdit = if (draft.hasSavedKey || v.isNotBlank()) KeyEdit.REPLACE else KeyEdit.KEEP))
            }
            TextButton(onClick = { shown = !shown }) {
                Text(stringResource(if (shown) R.string.cd_hide_field else R.string.cd_show_field, label))
            }
        } else {
            // Announced when it changes: after Remove, focus lands on Keep and this line says what Remove did.
            Text(
                stringResource(if (draft.keyEdit == KeyEdit.REMOVE) R.string.edit_totp_removed else R.string.edit_totp_saved),
                style = MaterialTheme.typography.bodyMedium,
                modifier = Modifier.semantics { liveRegion = LiveRegionMode.Polite },
            )
        }
        if (draft.hasSavedKey) {
            // Each button names the field it acts on, as Show, Copy and Remove do elsewhere.
            val replaceCd = stringResource(R.string.cd_replace_totp_key)
            val removeCd = stringResource(R.string.cd_remove_totp_key)
            val keepCd = stringResource(R.string.cd_keep_totp_key)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                if (draft.keyEdit == KeyEdit.KEEP) {
                    if (!removeOnly) {
                        TextButton(
                            onClick = { choose(KeyEdit.REPLACE) },
                            modifier = Modifier.focusRequester(replace).semantics { contentDescription = replaceCd },
                        ) { Text(stringResource(R.string.action_replace)) }
                    }
                    TextButton(
                        onClick = { choose(KeyEdit.REMOVE) },
                        modifier = Modifier.focusRequester(remove).semantics { contentDescription = removeCd },
                    ) { Text(stringResource(R.string.action_remove)) }
                } else {
                    TextButton(
                        onClick = { choose(KeyEdit.KEEP) },
                        modifier = Modifier.focusRequester(keep).semantics { contentDescription = keepCd },
                    ) { Text(stringResource(R.string.action_keep_key)) }
                }
            }
        }
    }
    LaunchedEffect(draft.keyEdit) {
        if (!moved) return@LaunchedEffect
        when (draft.keyEdit) {
            KeyEdit.REPLACE -> field.requestFocus()
            KeyEdit.REMOVE -> keep.requestFocus()
            KeyEdit.KEEP -> (if (removeOnly) remove else replace).requestFocus()
        }
    }
}
