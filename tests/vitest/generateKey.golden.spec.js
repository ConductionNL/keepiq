/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the charset path of generateKey in src/generator/generator.js:
 * a grid of requests (length, the four kinds, minimums, ambiguity, exclusions)
 * under four org policies, each run with a seeded random source. Every result
 * or refusal goes into a digest per policy and exclusion group, compared with
 * fixtures/generateKey.golden.json.
 *
 * Each group is its own test. Set RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @spec openspec/specs/key-generator/spec.md#requirement-default-generation
 */

import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'
import { generateKey } from '../../src/generator/generator.js'

const GOLDEN = path.resolve(__dirname, 'fixtures/generateKey.golden.json')

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
 * @param {number} min The length floor.
 * @param {Array<string>} kinds The required kinds: upper, lower, digit, symbol.
 * @return {object} The raw policy.
 */
function rawPolicy(min, kinds) {
	return {
		policy_enabled: true,
		generator_min_length: min,
		generator_require_upper: kinds.includes('upper'),
		generator_require_lower: kinds.includes('lower'),
		generator_require_digit: kinds.includes('digit'),
		generator_require_symbol: kinds.includes('symbol'),
	}
}

const POLICIES = {
	none: null,
	floor: rawPolicy(24, []),
	all: rawPolicy(8, ['upper', 'lower', 'digit', 'symbol']),
	digitSymbol: rawPolicy(12, ['digit', 'symbol']),
}
const EXCLUSIONS = {
	none: undefined,
	letters: 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz',
	allButOne:
		'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*()-_=+[]{}|;:,.<>?',
	digitsSpecial: '0123456789!@#$%^&*()-_=+[]{}|;:,.<>?/',
}
const LENGTHS = [undefined, 0, 7, 8, 16, 128, 129, 12.5, '20', Number.NaN]
const COUNTS = [undefined, -1, 3, 2.7, 'x']
const FLAGS = [undefined, false, true]

/**
 * Every request in the grid, with a stable label.
 *
 * @return {Array<[string, object]>} label and request
 */
function requests() {
	const out = []
	let n = 0
	for (const length of LENGTHS) {
		for (let mask = 0; mask < 81; mask++) {
			const pick = (shift) => FLAGS[Math.floor(mask / 3 ** shift) % 3]
			for (const minDigits of COUNTS) {
				const minSpecial = COUNTS[n++ % COUNTS.length]
				const request = {
					length,
					includeUppercase: pick(0),
					includeLowercase: pick(1),
					includeDigits: pick(2),
					includeSpecialCharacters: pick(3),
					minDigits,
					minSpecial,
					avoidAmbiguous: FLAGS[mask % 3],
				}
				out.push([
					JSON.stringify(request, (_, v) =>
						v === undefined
							? '(undefined)'
							: Number.isNaN(v)
								? '(NaN)'
								: v,
					),
					request,
				])
			}
		}
	}
	return out
}

/**
 * The value, or the refusal, for one request.
 *
 * @param {object} options The request.
 * @param {object|null} policy The raw policy.
 * @param {number} seed The random seed.
 * @return {{value?: string, refused?: string}} The value or the refusal.
 */
function outcome(options, policy, seed) {
	try {
		return { value: generateKey(options, policy, seeded(seed)) }
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
 * One group's record: the case count, the refusals by message, the first
 * values in full and a digest over every outcome.
 *
 * @param {string} group `<policy>/<exclusion>`
 * @return {object} The record.
 */
function recordGroup(group) {
	const [policyName, excludedName] = group.split('/')
	const hash = digest()
	const refusals = {}
	const first = []
	requests().forEach(([label, request], seed) => {
		const result = outcome(
			{ ...request, excludedCharacters: EXCLUSIONS[excludedName] },
			POLICIES[policyName],
			seed,
		)
		if (result.refused) {
			refusals[result.refused] = (refusals[result.refused] ?? 0) + 1
		} else if (first.length < 12) {
			first.push([label, result.value])
		}
		hash.update(`${label}=${JSON.stringify(result)}\n`)
	})
	return { refusals, first, digest: hash.hex() }
}

const RECORD = process.env.RECORD_GOLDEN === '1'
const recorded = RECORD ? {} : JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))
const GROUPS = Object.keys(POLICIES).flatMap((p) =>
	Object.keys(EXCLUSIONS).map((e) => `${p}/${e}`),
)

describe('generateKey charset path (golden)', () => {
	it.each(GROUPS)(
		'%s',
		(group) => {
			const actual = recordGroup(group)
			if (RECORD) {
				recorded[group] = actual
				fs.writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
			}
			expect(actual).toEqual(recorded[group])
		},
		30_000,
	)
})

const REGEXES = [
	'[a-z]{8,12}',
	'[A-Z0-9]{10}',
	'[a-zA-Z0-9!@#]{16,20}',
	'[a-z]{4}',
	'[ab]{8}',
	'a{8}',
	'[a-z]{30,40}',
	'[0-9]{12}',
	'[A-Za-z]{8,10}[0-9]{2}',
	'[a-zA-Z0-9!@#]{20,30}',
	'[a-zA-Z0-9!@#]{24}',
	'[a-zA-Z0-9!@#]{20,23}',
	'(',
]

describe('generateKey regex path (golden)', () => {
	it.each(Object.keys(POLICIES))('%s', (policyName) => {
		const group = `regex/${policyName}`
		const actual = REGEXES.flatMap((regex) =>
			[1, 2, 3].map((seed) => [
				`${regex} #${seed}`,
				outcome({ regex }, POLICIES[policyName], seed),
			]),
		)
		if (RECORD) {
			recorded[group] = actual
			fs.writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
		}
		expect(actual).toEqual(recorded[group])
	})
})
