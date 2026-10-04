/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Writes tests/vectors/generator/cases.json: what the web generator
 * (src/generator/generator.js) returns for fixed options, organisation
 * policies and seeded random sources, or the refusal it gives. The native
 * apps' generator must give the same value for the same draws
 * (mobile-send-and-generator "Generate passwords and passphrases"), and
 * tests/vitest/generator-vectors.spec.js re-checks the web module against
 * the same file.
 *
 * Usage, from the repository root:
 *
 *   node tests/vectors/generate-generator-vectors.mjs
 *
 * @spec openspec/changes/clients-mobile-apps/specs/mobile-send-and-generator/spec.md#requirement-generate-passwords-and-passphrases
 */

import { writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { generateKey, generatePassphrase } from '../../src/generator/generator.js'
import { seededRandom } from './seeded-random.mjs'

const HERE = dirname(fileURLToPath(import.meta.url))

const POLICY_20_DIGIT = {
	policy_enabled: true,
	generator_min_length: 20,
	generator_require_digit: true,
}
const POLICY_ALL = {
	policy_enabled: true,
	generator_min_length: '24',
	generator_require_upper: true,
	generator_require_lower: true,
	generator_require_digit: true,
	generator_require_symbol: true,
}
const POLICY_UPPER_SYMBOL = {
	policy_enabled: true,
	generator_min_length: 8,
	generator_require_upper: true,
	generator_require_symbol: true,
}
const POLICY_30_DIGIT = {
	policy_enabled: true,
	generator_min_length: 30,
	generator_require_digit: true,
}
const POLICY_OFF = { policy_enabled: false, generator_min_length: 64 }
const POLICY_NO_PASSPHRASE = {
	policy_enabled: true,
	generator_allow_passphrase: false,
}
const EXTENSION_DEFAULT = {
	length: 14,
	includeUppercase: true,
	includeLowercase: true,
	includeDigits: true,
	includeSpecialCharacters: false,
	minDigits: 1,
	minSpecial: 1,
	avoidAmbiguous: true,
}

const CASES = [
	['default 16 characters', 'password', {}, null],
	['the extension defaults', 'password', EXTENSION_DEFAULT, null],
	['64 characters', 'password', { length: 64 }, null],
	['the minimum of 8', 'password', { length: 8 }, null],
	['128 characters', 'password', { length: 128 }, null],
	['no symbols', 'password', { length: 32, includeSpecialCharacters: false }, null],
	['excluded characters', 'password', { length: 40, excludedCharacters: '0Ol1I{}[]' }, null],
	['avoid ambiguous', 'password', { length: 30, avoidAmbiguous: true }, null],
	['digits only', 'password', { length: 12, includeUppercase: false, includeLowercase: false, includeSpecialCharacters: false }, null],
	['minimum digits and symbols', 'password', { length: 10, minDigits: 4, minSpecial: 3 }, null],
	['minimums longer than the length', 'password', { length: 8, minDigits: 6, minSpecial: 6 }, null],
	['a set of exactly two', 'password', { includeSpecialCharacters: false, excludedCharacters: 'CDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789' }, null],
	['policy 20 with a digit', 'password', { length: 8, includeDigits: false, includeSpecialCharacters: false }, POLICY_20_DIGIT],
	['policy with every class', 'password', { length: 10, includeUppercase: false, includeSpecialCharacters: false }, POLICY_ALL],
	['policy restores excluded digits', 'password', { length: 12, excludedCharacters: '0123456789' }, POLICY_20_DIGIT],
	['policy off changes nothing', 'password', { length: 8 }, POLICY_OFF],
	['regex exact length', 'password', { regex: '^[a-zA-Z0-9]{16}$' }, null],
	['regex range', 'password', { regex: '^[a-z0-9]{8,16}$' }, null],
	['regex negated class', 'password', { regex: '^[^\\s<>]{16}$' }, null],
	['regex PHP delimiters', 'password', { regex: '/^[a-z]{12}$/' }, null],
	['regex meets the policy floor', 'password', { regex: '[a-z0-9]{8,32}' }, POLICY_20_DIGIT],
	['too short', 'password', { length: 6 }, null],
	['too long', 'password', { length: 129 }, null],
	['no kind chosen', 'password', { includeUppercase: false, includeLowercase: false, includeDigits: false, includeSpecialCharacters: false }, null],
	['set of one', 'password', { includeSpecialCharacters: false, excludedCharacters: 'BCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789' }, null],
	['empty set', 'password', { includeSpecialCharacters: false, excludedCharacters: 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789' }, null],
	['regex without a quantifier', 'password', { regex: '^[a-zA-Z0-9]$' }, null],
	['regex too short', 'password', { regex: '^[a-zA-Z]{5}$' }, null],
	['regex below the policy', 'password', { regex: '[a-z]{8,10}' }, POLICY_20_DIGIT],
	['regex without the required digit', 'password', { regex: '[a-z]{20,32}' }, POLICY_20_DIGIT],
	['passphrase default', 'passphrase', {}, null],
	['passphrase six words with spaces', 'passphrase', { words: 6, separator: ' ' }, null],
	['passphrase capitalised with a number', 'passphrase', { words: 4, separator: ' ', capitalise: true, includeNumber: true }, null],
	['passphrase twelve words, empty separator', 'passphrase', { words: 12, separator: '' }, null],
	['passphrase policy 30 with a digit', 'passphrase', { words: 4 }, POLICY_30_DIGIT],
	['passphrase upper and symbol', 'passphrase', { words: 4, separator: ' ' }, POLICY_UPPER_SYMBOL],
	['passphrase three words', 'passphrase', { words: 3 }, null],
	['passphrase thirteen words', 'passphrase', { words: 13 }, null],
	['passphrase switched off', 'passphrase', {}, POLICY_NO_PASSPHRASE],
]

const SEEDS = [1, 42, 2026, 0x9e3779b9]

const cases = []
for (const [name, kind, options, policy] of CASES) {
	for (const seed of SEEDS) {
		const rand = seededRandom(seed)
		const entry = { name, kind, options, policy, seed }
		try {
			entry.expected =
				kind === 'passphrase'
					? generatePassphrase(options, policy, rand)
					: generateKey(options, policy, rand)
		} catch (e) {
			entry.error = e.message
		}
		cases.push(entry)
	}
}

writeFileSync(
	join(HERE, 'generator', 'cases.json'),
	JSON.stringify({ about: 'Web generator outputs for seeded random sources; see generate-generator-vectors.mjs', cases }, null, '\t') + '\n',
)
console.log(`wrote ${cases.length} generator cases`)
