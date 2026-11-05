/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for generatePassphrase and for the character classes of
 * generateKey's regex path (escapes, ranges, negation), each run with a
 * seeded random source under five org policies. The passphrase groups keep
 * the refusals, the first values and a digest of every outcome. Compared with
 * fixtures/generatePassphrase.golden.json.
 *
 * Each group is its own test. Set RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @spec openspec/specs/key-generator/spec.md#requirement-default-generation
 */

import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'
import { generateKey, generatePassphrase } from '../../src/generator/generator.js'

const GOLDEN = path.resolve(__dirname, 'fixtures/generatePassphrase.golden.json')

/**
 * A seeded linear congruential draw, so every run sees the same values.
 *
 * @param {number} seed The seed.
 * @return {Function} rand(min, max)
 */
function seeded(seed) {
	let state = seed >>> 0
	return (min, max) => {
		state = (Math.imul(state, 1664525) + 1013904223) >>> 0
		return min + (state % (max - min + 1))
	}
}

/**
 * A raw org policy as the server returns it.
 *
 * @param {object} values The policy values.
 * @return {object} The raw policy.
 */
function rawPolicy(values) {
	return { policy_enabled: true, generator_min_length: 12, ...values }
}

const POLICIES = {
	none: null,
	disabled: { policy_enabled: false },
	'no passphrase': rawPolicy({ generator_allow_passphrase: false }),
	'upper digit symbol, floor 40': rawPolicy({
		generator_min_length: 40,
		generator_require_upper: true,
		generator_require_digit: true,
		generator_require_symbol: true,
	}),
	'symbol only': rawPolicy({ generator_require_symbol: true }),
}

const WORDS = [undefined, 3, 4, 5, 12, 13, 4.5, '6', 'x']
const SEPARATORS = [undefined, '-', ' ', '', '!', '.!']
const FLAGS = [undefined, false, true, 'true']

/**
 * Every passphrase request in the grid, with a stable label.
 *
 * @return {Array<[string, object]>}
 */
function requests() {
	const out = []
	for (const words of WORDS) {
		for (const separator of SEPARATORS) {
			for (const capitalise of FLAGS) {
				for (const includeNumber of FLAGS) {
					const options = { words, separator, capitalise, includeNumber }
					out.push([
						JSON.stringify(options, (_, v) =>
							v === undefined ? '(undefined)' : v,
						),
						options,
					])
				}
			}
		}
	}
	return out
}

const PATTERNS = [
	'[\\d]{8}',
	'[\\w]{10}',
	'[\\s\\d]{8}',
	'[a\\-z]{8}',
	'[\\.\\]x]{8}',
	'[z-a]{8}',
	'[a-]{8}',
	'[^a-z]{10}',
	'[^\\w]{10}',
	'[A-Fx-z0-3]{12}',
	'\\d{8}',
	'[\\q]{8}',
]

/**
 * The value, or the refusal.
 *
 * @param {Function} make The generation.
 * @return {object}
 */
function outcome(make) {
	try {
		return { value: make() }
	} catch (error) {
		return { refused: `${error.name}: ${error.message}` }
	}
}

/**
 * A running FNV-1a digest (two 32-bit lanes) of the recorded lines. It only
 * detects change between runs, so it need not be a cryptographic hash.
 *
 * @return {{update: Function, hex: Function}} The digest.
 */
function digest() {
	let a = 0x811c9dc5
	let b = 0x01000193
	return {
		update(text) {
			for (let i = 0; i < text.length; i++) {
				const code = text.charCodeAt(i)
				a = Math.imul(a ^ code, 0x01000193) >>> 0
				b = Math.imul(b ^ code, 0x811c9dc5 | 1) >>> 0
			}
		},
		hex: () => a.toString(16).padStart(8, '0') + b.toString(16).padStart(8, '0'),
	}
}

/**
 * A group's record: refusals by message, the first values in full and a
 * digest over every outcome.
 *
 * @param {Array<[string, object]>} outcomes Label and outcome per request.
 * @return {object}
 */
function summary(outcomes) {
	const hash = digest()
	const refusals = {}
	const first = []
	for (const [label, result] of outcomes) {
		if (result.refused) {
			refusals[result.refused] = (refusals[result.refused] ?? 0) + 1
		} else if (first.length < 12) {
			first.push([label, result.value])
		}
		hash.update(`${label}=${JSON.stringify(result)}\n`)
	}
	return { refusals, first, digest: hash.hex() }
}

const RECORD = process.env.RECORD_GOLDEN === '1'
const recorded = RECORD ? {} : JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))

/**
 * Record or compare one group.
 *
 * @param {string} group The group name.
 * @param {Array|object} actual The outcomes, or their summary.
 * @return {void}
 */
function check(group, actual) {
	if (RECORD) {
		recorded[group] = actual
		fs.writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
	}
	expect(actual).toEqual(recorded[group])
}

describe('generatePassphrase and regex classes (golden)', () => {
	it.each(Object.keys(POLICIES))('passphrase: %s', (policyName) => {
		check(
			`passphrase: ${policyName}`,
			summary(
				requests().map(([label, options], seed) => [
					label,
					outcome(() =>
						generatePassphrase(
							options,
							POLICIES[policyName],
							seeded(seed),
						),
					),
				]),
			),
		)
	})

	it.each(Object.keys(POLICIES))('regex classes: %s', (policyName) => {
		check(
			`regex classes: ${policyName}`,
			PATTERNS.flatMap((regex) =>
				[1, 2].map((seed) => [
					`${regex} #${seed}`,
					outcome(() =>
						generateKey({ regex }, POLICIES[policyName], seeded(seed)),
					),
				]),
			),
		)
	})
})
