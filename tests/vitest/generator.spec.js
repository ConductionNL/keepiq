/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Client-side key generator (`src/generator/generator.js`).
 *
 * The cases mirror tests/Unit/Service/KeyGeneratorServiceTest.php one for
 * one, so the browser generator keeps the server's options, policy clamp
 * and refusals. Each repeats where the outcome is random.
 *
 * @spec openspec/specs/key-generator/spec.md#requirement-default-generation
 * @spec openspec/specs/key-generator/spec.md#requirement-regex-override
 * @spec openspec/specs/org-password-policies/spec.md#requirement-generator-locked-to-policy
 * @spec openspec/changes/client-side-key-generator/specs/passphrase-generator/spec.md#requirement-generate-a-passphrase
 * @spec openspec/changes/client-side-key-generator/specs/passphrase-generator/spec.md#requirement-passphrases-follow-the-organisation-password-policy
 */

import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { EFF_LARGE_WORDLIST } from '../../src/generator/eff-large-wordlist.js'
import {
	generateKey,
	generatePassphrase,
	GeneratorError,
	generatorPolicy,
	passphraseAllowed,
	randomInt,
} from '../../src/generator/generator.js'
import { seededRandom } from '../vectors/seeded-random.mjs'

const SPECIAL = /[!@#$%^&*()\-_=+[\]{}|;:,.<>?/]/
const RUNS = 50

/**
 * Run `fn` RUNS times.
 *
 * @param {Function} fn The check.
 */
function repeat(fn) {
	for (let i = 0; i < RUNS; i++) {
		fn()
	}
}

describe('charset generation', () => {
	it('defaults to 16 characters from letters, digits and symbols', () => {
		repeat(() => {
			const key = generateKey()
			expect(key).toHaveLength(16)
			expect(key).toMatch(/^[A-Za-z0-9!@#$%^&*()\-_=+[\]{}|;:,.<>?/]{16}$/)
		})
	})

	it('honours a custom length, and accepts the minimum of 8', () => {
		expect(generateKey({ length: 64 })).toHaveLength(64)
		expect(generateKey({ length: 8 })).toHaveLength(8)
		expect(generateKey({ length: 128 })).toHaveLength(128)
	})

	it('leaves symbols out when asked', () => {
		repeat(() =>
			expect(
				generateKey({ length: 32, includeSpecialCharacters: false }),
			).toMatch(/^[A-Za-z0-9]{32}$/),
		)
	})

	it('never emits an excluded character', () => {
		repeat(() => {
			expect(
				generateKey({ length: 64, excludedCharacters: '0Ol1I' }),
			).not.toMatch(/[0Ol1I]/)
			expect(
				generateKey({ length: 64, excludedCharacters: '{}[]' }),
			).not.toMatch(/[{}[\]]/)
		})
	})

	it('refuses a length below 8 or above 128', () => {
		expect(() => generateKey({ length: 6 })).toThrow(
			'Length must be at least 8 characters',
		)
		expect(() => generateKey({ length: 129 })).toThrow(
			'Length must not exceed 128 characters',
		)
	})

	it('refuses a set that exclusions shrink below two characters', () => {
		const all = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'
		expect(() =>
			generateKey({
				includeSpecialCharacters: false,
				excludedCharacters: all.slice(1),
			}),
		).toThrow('at least 2 distinct characters')
		expect(() =>
			generateKey({
				includeSpecialCharacters: false,
				excludedCharacters: all,
			}),
		).toThrow('The character set is empty after exclusions')
	})

	it('generates from a set of exactly two characters', () => {
		const all = 'CDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'
		const key = generateKey({
			includeSpecialCharacters: false,
			excludedCharacters: all,
		})
		expect(key).toMatch(/^[AB]{16}$/)
	})
})

describe('regex generation', () => {
	it('meets an exact length and the pattern itself', () => {
		repeat(() => {
			const key = generateKey({ regex: '^[a-zA-Z0-9]{16}$' })
			expect(key).toHaveLength(16)
			expect(key).toMatch(/^[a-zA-Z0-9]{16}$/)
		})
	})

	it('stays inside a length range', () => {
		repeat(() => {
			const key = generateKey({ regex: '^[a-z0-9]{8,16}$' })
			expect(key.length).toBeGreaterThanOrEqual(8)
			expect(key.length).toBeLessThanOrEqual(16)
		})
	})

	it('reads a negated class as printable ASCII minus its members', () => {
		repeat(() => {
			const key = generateKey({ regex: '^[^\\s<>]{16}$' })
			expect(key).toHaveLength(16)
			expect(key).not.toMatch(/[\s<>]/)
		})
	})

	it('accepts PHP-style delimiters', () => {
		expect(generateKey({ regex: '/^[a-z]{12}$/' })).toMatch(/^[a-z]{12}$/)
	})

	it('ignores the other options when a regex is given', () => {
		expect(
			generateKey({
				length: 99,
				excludedCharacters: 'abc',
				regex: '^[a-z]{20}$',
			}),
		).toMatch(/^[a-z]{20}$/)
	})

	it('refuses a pattern without a quantifier, invalid syntax, too short or too small a set', () => {
		expect(() => generateKey({ regex: '^[a-zA-Z0-9]$' })).toThrow(
			'length quantifier',
		)
		expect(() => generateKey({ regex: '^[a-z' })).toThrow(GeneratorError)
		expect(() => generateKey({ regex: '^[a-zA-Z]{5}$' })).toThrow(
			'at least 8 characters',
		)
		expect(() => generateKey({ regex: '^[a]{16}$' })).toThrow(
			'at least 2 distinct characters',
		)
	})
})

describe('org policy', () => {
	it('raises the length to the floor and forces the required classes', () => {
		const policy = {
			policy_enabled: true,
			generator_min_length: 20,
			generator_require_digit: true,
			generator_require_symbol: true,
		}
		repeat(() => {
			const key = generateKey(
				{ length: 8, includeSpecialCharacters: false },
				policy,
			)
			expect(key).toHaveLength(20)
			expect(key).toMatch(/[0-9]/)
			expect(key).toMatch(SPECIAL)
		})
	})

	it('restores a required class that the exclusions removed', () => {
		const policy = {
			policy_enabled: true,
			generator_min_length: 12,
			generator_require_digit: true,
		}
		repeat(() =>
			expect(
				generateKey(
					{ length: 12, excludedCharacters: '0123456789' },
					policy,
				),
			).toMatch(/[0-9]/),
		)
	})

	it('refuses a regex that cannot meet the policy', () => {
		const policy = {
			policy_enabled: true,
			generator_min_length: 16,
			generator_require_digit: true,
		}
		expect(() => generateKey({ regex: '[a-z]{8,10}' }, policy)).toThrow(
			'policy minimum length',
		)
		expect(() => generateKey({ regex: '[a-z]{16,32}' }, policy)).toThrow('digit')
	})

	it('changes nothing when the policy is off', () => {
		expect(
			generateKey(
				{ length: 8 },
				{ policy_enabled: false, generator_min_length: 64 },
			),
		).toHaveLength(8)
		expect(generatorPolicy(null)).toBeNull()
	})

	it('never lets the floor drop below 8', () => {
		expect(
			generatorPolicy({ policy_enabled: true, generator_min_length: '4' })
				.minLength,
		).toBe(8)
	})

	it('caps the floor at the maximum length of 128', () => {
		const policy = { policy_enabled: true, generator_min_length: 200 }
		expect(generatorPolicy(policy).minLength).toBe(128)
		expect(
			generatorPolicy({ policy_enabled: true, generator_min_length: '500' })
				.minLength,
		).toBe(128)
		expect(generateKey({}, policy)).toHaveLength(128)
		const phrase = generatePassphrase({}, policy)
		expect(phrase.length).toBeGreaterThanOrEqual(128)
		expect(phrase.length).toBeLessThan(150)
	})

	it('keeps every required class at the floor, also when a minimum fills the length', () => {
		const policy = {
			policy_enabled: true,
			generator_min_length: 8,
			generator_require_upper: true,
			generator_require_lower: true,
			generator_require_digit: true,
			generator_require_symbol: true,
		}
		for (let i = 0; i < 500; i++) {
			const key = generateKey({ length: 9, minDigits: 9 }, policy)
			expect(key).toHaveLength(12)
			expect(key).toMatch(/[A-Z]/)
			expect(key).toMatch(/[a-z]/)
			expect(key).toMatch(SPECIAL)
			expect(key.replace(/[^0-9]/g, '')).toHaveLength(9)
		}
	})
})

describe('minimums', () => {
	it('grows the length to the sum of the minimums, one for every other chosen kind', () => {
		for (const [options, length] of [
			[{ length: 8, minDigits: 6, minSpecial: 6 }, 14],
			[{ length: 10, minDigits: 5, minSpecial: 5 }, 12],
		]) {
			for (let i = 0; i < 200; i++) {
				const key = generateKey(options)
				expect(key).toHaveLength(length)
				expect(key).toMatch(/[A-Z]/)
				expect(key).toMatch(/[a-z]/)
				expect(key.replace(/[^0-9]/g, '').length).toBeGreaterThanOrEqual(
					options.minDigits,
				)
				expect(
					key.replace(/[A-Za-z0-9]/g, '').length,
				).toBeGreaterThanOrEqual(options.minSpecial)
			}
		}
	})

	it('counts a fractional minimum as the whole number below it', () => {
		for (const seed of [1, 42, 2026]) {
			expect(
				generateKey(
					{ length: 8, minDigits: 7.5, minSpecial: 1.9 },
					null,
					seededRandom(seed),
				),
			).toBe(
				generateKey(
					{ length: 8, minDigits: 7, minSpecial: 1 },
					null,
					seededRandom(seed),
				),
			)
		}
	})

	it('puts the forced characters at uniformly random positions', () => {
		// Seven digits and one upper-case letter: the letter must land on
		// each of the 8 positions about 1 in 8 times.
		const runs = 4000
		const hits = new Array(8).fill(0)
		for (let i = 0; i < runs; i++) {
			const key = generateKey({
				length: 8,
				includeLowercase: false,
				includeSpecialCharacters: false,
				minDigits: 7,
			})
			hits[key.search(/[A-Z]/)]++
		}
		for (const count of hits) {
			expect(count / runs).toBeGreaterThan(0.09)
			expect(count / runs).toBeLessThan(0.16)
		}
	})
})

describe('randomness', () => {
	it('spreads values over the whole range', () => {
		const seen = new Set()
		for (let i = 0; i < 2000; i++) {
			seen.add(randomInt(0, 9))
		}
		expect(seen.size).toBe(10)
	})

	it('uses only crypto.getRandomValues, never Math.random', () => {
		const source = readFileSync(
			new URL('../../src/generator/generator.js', import.meta.url),
			'utf8',
		)
		expect(source).toContain('crypto.getRandomValues')
		expect(source).not.toMatch(/Math\.random/)
	})
})

describe('passphrase generation', () => {
	const WORDS = new Set(EFF_LARGE_WORDLIST)

	it('uses the full EFF large word list', () => {
		expect(EFF_LARGE_WORDLIST).toHaveLength(7776)
		expect(WORDS.size).toBe(7776)
	})

	it('defaults to five list words joined by hyphens', () => {
		repeat(() => {
			const words = generatePassphrase().split('-')
			expect(words).toHaveLength(5)
			for (const word of words) {
				expect(WORDS.has(word)).toBe(true)
			}
		})
	})

	it('never draws the four hyphenated list words', () => {
		const drawn = new Set()
		for (let i = 0; i < EFF_LARGE_WORDLIST.length; i++) {
			const pick = (min, max) => Math.min(min + i, max)
			drawn.add(
				generatePassphrase({ words: 4, separator: ' ' }, null, pick).split(
					' ',
				)[0],
			)
		}
		expect(drawn.size).toBe(7772)
		for (const word of ['drop-down', 'felt-tip', 't-shirt', 'yo-yo']) {
			expect(drawn.has(word)).toBe(false)
		}
	})

	it('six words with a space as separator', () => {
		const words = generatePassphrase({ words: 6, separator: ' ' }).split(' ')
		expect(words).toHaveLength(6)
		expect(words.every((w) => WORDS.has(w))).toBe(true)
	})

	it('capitalises each word and adds one digit when asked', () => {
		repeat(() => {
			const phrase = generatePassphrase({
				words: 4,
				separator: ' ',
				capitalise: true,
				includeNumber: true,
			})
			const words = phrase.split(' ')
			expect(words.every((w) => /^[A-Z]/.test(w))).toBe(true)
			expect(phrase.match(/[0-9]/g)).toHaveLength(1)
		})
	})

	it('refuses fewer than 4 or more than 12 words', () => {
		expect(() => generatePassphrase({ words: 3 })).toThrow(
			'A passphrase must have 4 to 12 words',
		)
		expect(() => generatePassphrase({ words: 13 })).toThrow(
			'A passphrase must have 4 to 12 words',
		)
	})

	it('meets a policy of 30 characters and a digit from 4 words', () => {
		const policy = {
			policy_enabled: true,
			generator_min_length: 30,
			generator_require_digit: true,
		}
		repeat(() => {
			const phrase = generatePassphrase({ words: 4 }, policy)
			expect(phrase.length).toBeGreaterThanOrEqual(30)
			expect(phrase).toMatch(/[0-9]/)
		})
	})

	it('capitalises for a required upper case and uses a symbol separator for a required symbol', () => {
		const policy = {
			policy_enabled: true,
			generator_min_length: 8,
			generator_require_upper: true,
			generator_require_symbol: true,
		}
		repeat(() => {
			const phrase = generatePassphrase({ words: 4, separator: ' ' }, policy)
			expect(phrase).toMatch(/[A-Z]/)
			expect(phrase).toMatch(SPECIAL)
		})
	})

	it('refuses when the organisation switched passphrases off', () => {
		const policy = { policy_enabled: true, generator_allow_passphrase: false }
		expect(passphraseAllowed(policy)).toBe(false)
		expect(() => generatePassphrase({}, policy)).toThrow(
			'Your organisation has switched passphrases off',
		)
		expect(passphraseAllowed({ policy_enabled: true })).toBe(true)
		expect(passphraseAllowed(null)).toBe(true)
	})
})
