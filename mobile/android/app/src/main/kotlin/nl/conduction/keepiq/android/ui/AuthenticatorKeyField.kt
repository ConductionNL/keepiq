// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import nl.conduction.keepiq.android.R
import nl.conduction.keepiq.shared.vault.DraftProblem
import nl.conduction.keepiq.shared.vault.ItemDraft
import nl.conduction.keepiq.shared.vault.KeyEdit

/**
 * The Authenticator key of an item (mobile-login-totp-passkeys D8). A saved
 * key is never loaded: the field says one is saved and offers Replace and
 * Remove, or with [removeOnly] Remove alone. Focus moves to the control
 * that took the chosen one's place.
 */
@Composable
internal fun AuthenticatorKeyField(draft: ItemDraft, problem: DraftProblem?, removeOnly: Boolean, onChange: (ItemDraft) -> Unit) {
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
            TextInput(label, draft.newKey, problem, modifier = Modifier.focusRequester(field)) { v ->
                onChange(draft.copy(newKey = v, keyEdit = if (draft.hasSavedKey || v.isNotBlank()) KeyEdit.REPLACE else KeyEdit.KEEP))
            }
        } else {
            Text(
                stringResource(if (draft.keyEdit == KeyEdit.REMOVE) R.string.edit_totp_removed else R.string.edit_totp_saved),
                style = MaterialTheme.typography.bodyMedium,
            )
        }
        if (draft.hasSavedKey) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                if (draft.keyEdit == KeyEdit.KEEP) {
                    if (!removeOnly) {
                        TextButton(onClick = { choose(KeyEdit.REPLACE) }, modifier = Modifier.focusRequester(replace)) { Text(stringResource(R.string.action_replace)) }
                    }
                    TextButton(onClick = { choose(KeyEdit.REMOVE) }, modifier = Modifier.focusRequester(remove)) { Text(stringResource(R.string.action_remove)) }
                } else {
                    TextButton(onClick = { choose(KeyEdit.KEEP) }, modifier = Modifier.focusRequester(keep)) { Text(stringResource(R.string.action_keep_key)) }
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
