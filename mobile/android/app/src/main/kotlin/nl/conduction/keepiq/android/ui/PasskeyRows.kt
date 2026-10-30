// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.ui

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import nl.conduction.keepiq.android.R
import nl.conduction.keepiq.shared.vault.PasskeySummary
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import java.util.Locale

/**
 * The passkeys of a login, one row each with the date it was added and,
 * when there are several, the account it is for (mobile-login-totp-passkeys
 * D6). No site, credential id or key is shown. Where [mayDelete], each row
 * offers Delete; [focusIndex] moves focus to that row's Delete after a
 * passkey before it went (D7), and [onFocused] tells the caller it moved,
 * so the request is not made again when the item reopens.
 */
@Composable
internal fun PasskeyRows(
    passkeys: List<PasskeySummary>,
    mayDelete: Boolean,
    focusIndex: Int?,
    onDelete: (PasskeySummary) -> Unit,
    onFocused: () -> Unit = {},
) {
    val locale = LocalConfiguration.current.locales[0]
    val requesters = remember(passkeys) { passkeys.map { FocusRequester() } }
    for ((i, pk) in passkeys.withIndex()) {
        val added = pk.createdAt?.let { passkeyAdded(it, locale, ZoneId.systemDefault()) }
        val account = pk.userName?.takeIf { passkeys.size > 1 }
        val text = when {
            account != null && added != null -> stringResource(R.string.detail_passkey_added_for, account, added)
            account != null -> stringResource(R.string.detail_passkey_saved_for, account)
            added != null -> stringResource(R.string.detail_passkey_added, added)
            else -> stringResource(R.string.detail_passkey_saved)
        }
        Column(Modifier.fillMaxWidth()) {
            Text(stringResource(R.string.detail_passkey), style = MaterialTheme.typography.labelMedium)
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(text, modifier = Modifier.weight(1f))
                if (mayDelete) {
                    val cd = account?.let { stringResource(R.string.cd_delete_passkey_for, it) } ?: stringResource(R.string.cd_delete_passkey)
                    TextButton(
                        onClick = { onDelete(pk) },
                        modifier = Modifier.focusRequester(requesters[i]).semantics { contentDescription = cd },
                    ) { Text(stringResource(R.string.action_delete)) }
                }
            }
        }
    }
    LaunchedEffect(focusIndex, passkeys) {
        if (mayDelete && focusIndex != null && focusIndex < requesters.size) {
            requesters[focusIndex].requestFocus()
            onFocused()
        }
    }
}

/** Asks before a passkey is deleted: the site no longer accepts it afterwards. */
@Composable
internal fun PasskeyDeleteDialog(onConfirm: () -> Unit, onDismiss: () -> Unit) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.passkey_delete_title)) },
        text = { Text(stringResource(R.string.passkey_delete_body)) },
        confirmButton = { TextButton(onClick = onConfirm) { Text(stringResource(R.string.action_delete)) } },
        dismissButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.action_cancel)) } },
    )
}

/** A passkey's `createdAt` as a long date in [locale], or null when it is not an ISO instant. */
internal fun passkeyAdded(createdAt: String, locale: Locale, zone: ZoneId): String? = runCatching {
    DateTimeFormatter.ofLocalizedDate(FormatStyle.LONG).withLocale(locale).withZone(zone).format(Instant.parse(createdAt))
}.getOrNull()
