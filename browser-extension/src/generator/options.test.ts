import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import {
	DEFAULT_PASSPHRASE, DEFAULT_PASSWORD, defaultOptions, effective, lengthFloor, MIN_COUNT, minimumLength, PASSPHRASE_WORDS, PASSWORD_LENGTH, sanitize, type GeneratorPolicy,
} from './options'

const cases: Array<{ name: string; kind: string; options: Record<string, unknown> }> = JSON.parse(
	readFileSync(new URL('../../../tests/vectors/generator/cases.json', import.meta.url), 'utf8'),
).cases

describe('generator defaults', () => {
	it('are the options the shared vectors call the extension defaults', () => {
		const vector = cases.find((c) => c.name === 'the extension defaults')
		expect(vector?.kind).toBe('password')
		expect(DEFAULT_PASSWORD).toEqual(vector?.options)
	})

	it('sit inside their ranges', () => {
		expect(DEFAULT_PASSWORD.length).toBeGreaterThanOrEqual(PASSWORD_LENGTH.min)
		expect(DEFAULT_PASSWORD.length).toBeLessThanOrEqual(PASSWORD_LENGTH.max)
		for (const count of [DEFAULT_PASSWORD.minDigits, DEFAULT_PASSWORD.minSpecial]) {
			expect(count).toBeGreaterThanOrEqual(MIN_COUNT.min)
			expect(count).toBeLessThanOrEqual(MIN_COUNT.max)
		}
		expect(DEFAULT_PASSPHRASE.words).toBeGreaterThanOrEqual(PASSPHRASE_WORDS.min)
		expect(DEFAULT_PASSPHRASE.words).toBeLessThanOrEqual(PASSPHRASE_WORDS.max)
	})

	it('hands out a fresh copy each time, so editing one never changes the defaults', () => {
		const options = defaultOptions()
		options.password.length = 40
		options.username.email = 'a@example.org'
		expect(defaultOptions().password.length).toBe(14)
		expect(DEFAULT_PASSWORD.length).toBe(14)
		expect(defaultOptions().username.email).toBe('')
	})
})

describe('sanitize', () => {
	const password = (patch: Record<string, unknown>) => sanitize({ password: { ...DEFAULT_PASSWORD, ...patch } }).password

	it('reads anything that is not options as the defaults', () => {
		for (const raw of [undefined, null, 'x', 42, [], {}]) expect(sanitize(raw)).toEqual(defaultOptions())
	})

	it('keeps valid options as they are', () => {
		const options = defaultOptions()
		options.tab = 'username'
		options.passphrase = { words: 7, separator: '.', capitalise: true, includeNumber: true }
		options.username = { type: 'plus', capitalize: false, includeNumber: false, email: 'a@example.org', domain: 'example.org', mode: 'website' }
		expect(sanitize(options)).toEqual(options)
	})

	it('clamps numbers to their ranges and rounds them', () => {
		expect(password({ length: 3 }).length).toBe(PASSWORD_LENGTH.min)
		expect(password({ length: 500 }).length).toBe(PASSWORD_LENGTH.max)
		expect(password({ length: 20.6 }).length).toBe(21)
		expect(password({ minDigits: -2, minSpecial: 40 })).toMatchObject({ minDigits: 0, minSpecial: 9 })
		expect(sanitize({ passphrase: { words: 1 } }).passphrase.words).toBe(PASSPHRASE_WORDS.min)
		expect(sanitize({ passphrase: { words: 99 } }).passphrase.words).toBe(PASSPHRASE_WORDS.max)
	})

	it('takes a field of the wrong type from the previous options', () => {
		const previous = defaultOptions()
		previous.password.length = 30
		expect(sanitize({ password: { length: 'long', includeDigits: 'yes' } }, previous).password).toMatchObject({ length: 30, includeDigits: true })
		expect(sanitize({ password: { length: Number.NaN } }, previous).password.length).toBe(30)
		expect(sanitize({ tab: 'pin', username: { type: 'phone', mode: 'other' } }, previous)).toMatchObject({ tab: 'password', username: { type: 'word', mode: 'random' } })
	})

	it('keeps a length shorter than the minimums, which apply at generation', () => {
		const options = password({ length: 8, includeSpecialCharacters: true, minDigits: 6, minSpecial: 6 })
		expect(options.length).toBe(8)
		expect(minimumLength(options)).toBe(14)
	})

	it('keeps the previous classes when the last one is turned off', () => {
		const previous = defaultOptions()
		previous.password = { ...previous.password, includeUppercase: false, includeLowercase: false, includeDigits: true }
		const off = { includeUppercase: false, includeLowercase: false, includeDigits: false, includeSpecialCharacters: false }
		expect(sanitize({ password: off }, previous).password).toMatchObject({ includeUppercase: false, includeLowercase: false, includeDigits: true, includeSpecialCharacters: false })
		expect(sanitize({ password: off }).password).toMatchObject({ includeUppercase: true, includeLowercase: true, includeDigits: true })
	})

	it('keeps one code point of the separator, also outside the BMP, and allows none', () => {
		expect(sanitize({ passphrase: { separator: '-_' } }).passphrase.separator).toBe('-')
		expect(sanitize({ passphrase: { separator: '🔑x' } }).passphrase.separator).toBe('🔑')
		expect(sanitize({ passphrase: { separator: '' } }).passphrase.separator).toBe('')
		expect(sanitize({ passphrase: { separator: 3 } }).passphrase.separator).toBe('-')
	})

	it('trims the email and domain and caps their length', () => {
		const { username } = sanitize({ username: { email: '  a@example.org ', domain: 'x'.repeat(300) } })
		expect(username.email).toBe('a@example.org')
		expect(username.domain).toHaveLength(254)
		expect(sanitize({ username: { email: 7 } }).username.email).toBe('')
	})

	it('never shares objects with the previous options', () => {
		const previous = defaultOptions()
		const result = sanitize(undefined, previous)
		result.password.length = 99
		expect(previous.password.length).toBe(14)
	})
})

describe('minimumLength', () => {
	it('is one character per enabled class plus what the minimums ask beyond that', () => {
		expect(minimumLength({ ...DEFAULT_PASSWORD, includeSpecialCharacters: true, minDigits: 6, minSpecial: 6 })).toBe(14)
		expect(minimumLength({ ...DEFAULT_PASSWORD, includeDigits: false, minDigits: 9 })).toBe(2)
	})

	it('counts a class with a minimum of 0 as one character', () => {
		expect(minimumLength({ ...DEFAULT_PASSWORD, includeSpecialCharacters: true, minDigits: 0, minSpecial: 0 })).toBe(4)
	})
})

describe('effective', () => {
	const policy = (overrides: Partial<GeneratorPolicy> = {}): GeneratorPolicy => ({
		minLength: 8, requireUpper: false, requireLower: false, requireDigit: false, requireSymbol: false, allowPassphrase: true, ...overrides,
	})

	it('leaves valid options alone without a policy', () => {
		expect(effective(DEFAULT_PASSWORD, null)).toEqual(DEFAULT_PASSWORD)
	})

	it('applies the floor and required classes, and raises the length to the minimums', () => {
		expect(effective({ ...DEFAULT_PASSWORD, length: 8, minDigits: 9 }, null).length).toBe(11)
		expect(effective({ ...DEFAULT_PASSWORD, minSpecial: 0 }, policy({ minLength: 200, requireSymbol: true })))
			.toMatchObject({ length: 128, includeSpecialCharacters: true, minSpecial: 1 })
	})

	it('is idempotent and never writes back', () => {
		const stored = { ...DEFAULT_PASSWORD, length: 9, includeDigits: false }
		const rule = policy({ minLength: 16, requireDigit: true, requireUpper: true })
		const once = effective(stored, rule)
		expect(effective(once, rule)).toEqual(once)
		expect(stored).toEqual({ ...DEFAULT_PASSWORD, length: 9, includeDigits: false })
	})

	it('reads the floor within the range', () => {
		expect([lengthFloor(null), lengthFloor(policy({ minLength: 4 })), lengthFloor(policy({ minLength: 300 }))]).toEqual([8, 8, 128])
	})
})
