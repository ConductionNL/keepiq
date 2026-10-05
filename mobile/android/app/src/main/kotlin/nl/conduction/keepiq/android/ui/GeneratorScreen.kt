// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

@file:OptIn(ExperimentalMaterial3Api::class)

package nl.conduction.keepiq.android.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.toggleable
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.Card
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.SegmentedButton
import androidx.compose.material3.SegmentedButtonDefaults
import androidx.compose.material3.SingleChoiceSegmentedButtonRow
import androidx.compose.material3.Slider
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.unit.dp
import nl.conduction.keepiq.android.R
import nl.conduction.keepiq.shared.generator.Generator
import nl.conduction.keepiq.shared.generator.GeneratorException
import nl.conduction.keepiq.shared.generator.GeneratorMode
import nl.conduction.keepiq.shared.generator.GeneratorPolicy
import nl.conduction.keepiq.shared.generator.GeneratorSettings

/**
 * The generator (task 3.5), on its own tab and inside the item editor.
 * Values are made on the device. The organisation's policy sets the shortest
 * length that can be picked and switches on the kinds of character it
 * requires; those switches show that the policy decides them.
 */
@Composable
fun GeneratorScreen(
    policy: GeneratorPolicy?,
    settings: GeneratorSettings,
    onSettings: (GeneratorSettings) -> Unit,
    onCopy: ((String) -> Unit)?,
    modifier: Modifier,
    onValue: (String) -> Unit = {},
) {
    val safe = settings.sanitized(policy)
    var round by remember { mutableIntStateOf(0) }
    val outcome = remember(safe, policy, round) { runCatching { safe.generate(policy) } }
    LaunchedEffect(outcome) { onValue(outcome.getOrNull() ?: "") }
    val passphraseAllowed = policy?.allowPassphrase != false

    Column(modifier.verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        SingleChoiceSegmentedButtonRow(Modifier.fillMaxWidth()) {
            SegmentedButton(
                selected = safe.mode == GeneratorMode.PASSWORD,
                onClick = { onSettings(safe.copy(mode = GeneratorMode.PASSWORD)) },
                shape = SegmentedButtonDefaults.itemShape(0, 2),
            ) { Text(stringResource(R.string.gen_password)) }
            SegmentedButton(
                selected = safe.mode == GeneratorMode.PASSPHRASE,
                enabled = passphraseAllowed,
                onClick = { onSettings(safe.copy(mode = GeneratorMode.PASSPHRASE)) },
                shape = SegmentedButtonDefaults.itemShape(1, 2),
            ) { Text(stringResource(R.string.gen_passphrase)) }
        }
        if (!passphraseAllowed) Text(stringResource(R.string.gen_passphrase_off), style = MaterialTheme.typography.bodySmall)

        Card(Modifier.fillMaxWidth()) {
            Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(12.dp)) {
                val value = outcome.getOrNull()
                val error = outcome.exceptionOrNull() as? GeneratorException
                Text(
                    value ?: error?.let { generatorProblemText(it) } ?: "",
                    fontFamily = if (value != null) FontFamily.Monospace else null,
                    color = if (value == null) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurface,
                    modifier = Modifier.weight(1f).testTag("generated").semantics { liveRegion = LiveRegionMode.Polite },
                )
                IconButton(onClick = { round++ }) {
                    Icon(Icons.Filled.Refresh, contentDescription = stringResource(R.string.cd_regenerate))
                }
                if (onCopy != null && value != null) {
                    TextButton(onClick = { onCopy(value) }) { Text(stringResource(R.string.action_copy)) }
                }
            }
        }
        policy?.let { Text(stringResource(R.string.gen_policy, it.minLength), style = MaterialTheme.typography.bodySmall) }

        if (safe.mode == GeneratorMode.PASSWORD) {
            val p = safe.password
            val min = settings.minimumLength(policy)
            Text(stringResource(R.string.gen_length, p.length))
            Slider(
                value = p.length.toFloat(),
                onValueChange = { onSettings(safe.copy(password = p.copy(length = it.toInt()))) },
                valueRange = min.toFloat()..Generator.MAX_LENGTH.toFloat(),
            )
            Toggle(stringResource(R.string.gen_upper), p.includeUppercase, locked = policy?.requireUpper == true) {
                onSettings(safe.copy(password = p.copy(includeUppercase = it)))
            }
            Toggle(stringResource(R.string.gen_lower), p.includeLowercase, locked = policy?.requireLower == true) {
                onSettings(safe.copy(password = p.copy(includeLowercase = it)))
            }
            Toggle(stringResource(R.string.gen_digits), p.includeDigits, locked = policy?.requireDigit == true) {
                onSettings(safe.copy(password = p.copy(includeDigits = it)))
            }
            Toggle(stringResource(R.string.gen_symbols), p.includeSpecialCharacters, locked = policy?.requireSymbol == true) {
                onSettings(safe.copy(password = p.copy(includeSpecialCharacters = it)))
            }
            if (p.includeDigits) {
                Text(stringResource(R.string.gen_min_digits, p.minDigits))
                Slider(value = p.minDigits.toFloat(), onValueChange = { onSettings(safe.copy(password = p.copy(minDigits = it.toInt()))) }, valueRange = 0f..9f, steps = 8)
            }
            if (p.includeSpecialCharacters) {
                Text(stringResource(R.string.gen_min_symbols, p.minSpecial))
                Slider(value = p.minSpecial.toFloat(), onValueChange = { onSettings(safe.copy(password = p.copy(minSpecial = it.toInt()))) }, valueRange = 0f..9f, steps = 8)
            }
            Toggle(stringResource(R.string.gen_avoid_ambiguous), p.avoidAmbiguous, locked = false) {
                onSettings(safe.copy(password = p.copy(avoidAmbiguous = it)))
            }
        } else {
            val w = safe.passphrase
            Text(stringResource(R.string.gen_words, w.words))
            Slider(
                value = w.words.toFloat(),
                onValueChange = { onSettings(safe.copy(passphrase = w.copy(words = it.toInt()))) },
                valueRange = Generator.MIN_WORDS.toFloat()..Generator.MAX_WORDS.toFloat(),
                steps = Generator.MAX_WORDS - Generator.MIN_WORDS - 1,
            )
            OutlinedTextField(
                value = w.separator,
                onValueChange = { onSettings(safe.copy(passphrase = w.copy(separator = it.take(3)))) },
                label = { Text(stringResource(R.string.gen_separator)) },
                singleLine = true,
            )
            Toggle(stringResource(R.string.gen_capitalise), w.capitalise, locked = policy?.requireUpper == true) {
                onSettings(safe.copy(passphrase = w.copy(capitalise = it)))
            }
            Toggle(stringResource(R.string.gen_number), w.includeNumber, locked = policy?.requireDigit == true) {
                onSettings(safe.copy(passphrase = w.copy(includeNumber = it)))
            }
        }
    }
}

/** A switch row; a switch the policy decides is on, locked, and says so. */
@Composable
private fun Toggle(label: String, checked: Boolean, locked: Boolean, onChange: (Boolean) -> Unit) {
    Row(
        verticalAlignment = Alignment.CenterVertically,
        modifier = Modifier
            .fillMaxWidth()
            .toggleable(value = checked || locked, enabled = !locked, role = Role.Switch, onValueChange = onChange)
            .padding(vertical = 4.dp),
    ) {
        Column(Modifier.weight(1f)) {
            Text(label)
            if (locked) Text(stringResource(R.string.gen_policy_required), style = MaterialTheme.typography.bodySmall)
        }
        Switch(checked = checked || locked, onCheckedChange = null, enabled = !locked)
    }
}
