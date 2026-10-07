// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.ui

import android.text.format.DateUtils
import androidx.compose.runtime.Composable
import androidx.compose.ui.res.stringResource
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import nl.conduction.keepiq.android.R
import nl.conduction.keepiq.shared.generator.GeneratorErrorCode
import nl.conduction.keepiq.shared.generator.GeneratorException
import nl.conduction.keepiq.shared.send.SendFormProblem
import nl.conduction.keepiq.shared.vault.DraftProblem
import nl.conduction.keepiq.shared.vault.VaultLockedException
import nl.conduction.keepiq.shared.vault.WriteProblem
import nl.conduction.keepiq.shared.vault.WriteProblemKind

/**
 * Runs shared work (network, store, RSA, Argon2id) off the main thread. Work
 * the lock overtook ends as a cancellation: the screen that asked is gone.
 */
suspend fun <T> io(block: suspend () -> T): T = withContext(Dispatchers.IO) {
    try {
        block()
    } catch (e: VaultLockedException) {
        throw CancellationException("The vault was locked", e)
    }
}

@Composable
fun writeProblemText(problem: WriteProblem): String = when (problem.kind) {
    WriteProblemKind.OFFLINE -> stringResource(R.string.write_offline)
    WriteProblemKind.KEY_MIGRATION -> stringResource(R.string.write_key_migration)
    WriteProblemKind.SUITE_BLOCKED -> stringResource(R.string.write_suite_blocked)
    WriteProblemKind.SERVER_MESSAGE -> stringResource(R.string.write_server, problem.serverMessage ?: "")
    WriteProblemKind.REFUSED -> stringResource(R.string.write_refused)
    WriteProblemKind.UNREACHABLE -> stringResource(R.string.write_unreachable)
    WriteProblemKind.FAILED -> stringResource(R.string.write_failed)
}

@Composable
fun draftProblemText(problem: DraftProblem): String = stringResource(
    when (problem) {
        DraftProblem.NAME_MISSING -> R.string.problem_name_missing
        DraftProblem.TOO_LONG -> R.string.problem_too_long
        DraftProblem.FIELD_NAME_MISSING -> R.string.problem_field_name_missing
        DraftProblem.FIELD_NAME_RESERVED -> R.string.problem_field_name_reserved
        DraftProblem.FIELD_NAME_TAKEN -> R.string.problem_field_name_taken
        DraftProblem.NOT_AN_AUTHENTICATOR_SECRET -> R.string.problem_not_totp
        DraftProblem.REQUIRED -> R.string.problem_required
    },
)

@Composable
fun sendProblemText(problem: SendFormProblem): String = stringResource(
    when (problem) {
        SendFormProblem.NOTHING_TO_SEND -> R.string.send_nothing
        SendFormProblem.CUSTOM_HOURS -> R.string.send_hours_range
        SendFormProblem.CUSTOM_HOURS_TOO_MANY -> R.string.send_hours_max
        SendFormProblem.VIEWS_OUT_OF_RANGE -> R.string.send_views_range
        SendFormProblem.PASSWORD_NOT_AVAILABLE -> R.string.send_password_unavailable
    },
)

@Composable
fun generatorProblemText(error: GeneratorException): String = when (error.code) {
    GeneratorErrorCode.PASSPHRASE_OFF -> stringResource(R.string.gen_passphrase_off)
    GeneratorErrorCode.NO_KIND_CHOSEN -> stringResource(R.string.gen_error, stringResource(R.string.gen_err_no_kind))
    GeneratorErrorCode.CHARSET_EMPTY, GeneratorErrorCode.CHARSET_TOO_SMALL ->
        stringResource(R.string.gen_error, stringResource(R.string.gen_err_charset))
    GeneratorErrorCode.LENGTH_TOO_SHORT, GeneratorErrorCode.LENGTH_TOO_LONG, GeneratorErrorCode.PASSPHRASE_WORDS ->
        stringResource(R.string.gen_error, stringResource(R.string.gen_err_length))
    else -> stringResource(R.string.gen_error, stringResource(R.string.gen_err_other))
}

/** "5 minutes ago", in the device's language. */
fun relativeTime(millis: Long): String =
    DateUtils.getRelativeTimeSpanString(millis, System.currentTimeMillis(), DateUtils.MINUTE_IN_MILLIS).toString()
