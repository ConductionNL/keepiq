import { describe, expect, it } from 'vitest'
import { DEFAULT_PASSWORD, type GeneratorPolicy, type PasswordOptions } from './options'
import { AMBIGUOUS, generatePassword, SPECIAL } from './password'

const policy = (overrides: Partial<GeneratorPolicy> = {}): GeneratorPolicy => ({
	minLength: 8, requireUpper: false, requireLower: false, requireDigit: false, requireSymbol: false, allowPassphrase: true, ...overrides,
})

const count = (value: string, set: string) => [...value].filter((char) => set.includes(char)).length
const UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'
const LOWER = 'abcdefghijklmnopqrstuvwxyz'
const DIGITS = '0123456789'

function generated(options: Partial<PasswordOptions>, rule: GeneratorPolicy | null = null): string {
	const result = generatePassword({ ...DEFAULT_PASSWORD, ...options }, rule)
	if (!result.ok) throw new Error(result.failure)
	return result.value
}

describe('generatePassword', () => {
	it.each([
		['the defaults', {}],
		['every class with minimums', { length: 20, includeSpecialCharacters: true, minDigits: 4, minSpecial: 3 }],
		['digits only', { length: 12, includeUppercase: false, includeLowercase: false }],
		['minimums filling the length', { length: 20, includeSpecialCharacters: true, minDigits: 9, minSpecial: 9, avoidAmbiguous: false }],
		['ambiguous allowed', { length: 64, includeSpecialCharacters: true, avoidAmbiguous: false }],
	] as const)('keeps every rule over 1000 values: %s', (_, options) => {
		const settings = { ...DEFAULT_PASSWORD, ...options }
		for (let i = 0; i < 1000; i++) {
			const value = generated(settings)
			expect(value).toHaveLength(settings.length)
			expect(count(value, UPPER) > 0).toBe(settings.includeUppercase)
			expect(count(value, LOWER) > 0).toBe(settings.includeLowercase)
			if (settings.includeDigits) expect(count(value, DIGITS)).toBeGreaterThanOrEqual(Math.max(1, settings.minDigits))
			else expect(count(value, DIGITS)).toBe(0)
			if (settings.includeSpecialCharacters) expect(count(value, SPECIAL)).toBeGreaterThanOrEqual(Math.max(1, settings.minSpecial))
			else expect(count(value, SPECIAL)).toBe(0)
			if (settings.avoidAmbiguous) expect(count(value, AMBIGUOUS)).toBe(0)
		}
	})

	it('grows the length to hold every minimum', () => {
		expect(generated({ length: 8, includeSpecialCharacters: true, minDigits: 6, minSpecial: 6 })).toHaveLength(14)
	})

	it('meets the policy floor and every required class', () => {
		const rule = policy({ minLength: 20, requireUpper: true, requireLower: true, requireDigit: true, requireSymbol: true })
		for (let i = 0; i < 1000; i++) {
			const value = generated({ length: 8, includeUppercase: false, includeDigits: false }, rule)
			expect(value).toHaveLength(20)
			for (const set of [UPPER, LOWER, DIGITS, SPECIAL]) expect(count(value, set)).toBeGreaterThan(0)
		}
	})

	it('puts the one upper-case letter among seven digits at every position about equally often', () => {
		const positions = new Array<number>(8).fill(0)
		for (let i = 0; i < 16_000; i++) {
			const value = generated({ length: 8, includeLowercase: false, includeSpecialCharacters: false, minDigits: 7 })
			positions[[...value].findIndex((char) => UPPER.includes(char))]!++
		}
		for (const hits of positions) expect(Math.abs(hits - 2000)).toBeLessThan(250)
	})

	it.each([
		['length_too_short', { length: 6 }],
		['length_too_long', { length: 129 }],
		['no_kind', { includeUppercase: false, includeLowercase: false, includeDigits: false }],
	] as const)('refuses with %s', (failure, options) => {
		expect(generatePassword({ ...DEFAULT_PASSWORD, ...options }, null)).toEqual({ ok: false, failure })
	})

	it('refuses an empty or single-character set', () => {
		const letters = UPPER + LOWER
		expect(generatePassword({ length: 8, includeSpecialCharacters: false, excludedCharacters: letters + DIGITS }, null)).toEqual({ ok: false, failure: 'charset_empty' })
		expect(generatePassword({ length: 8, includeSpecialCharacters: false, excludedCharacters: letters.slice(1) + DIGITS }, null)).toEqual({ ok: false, failure: 'charset_too_small' })
	})
})
