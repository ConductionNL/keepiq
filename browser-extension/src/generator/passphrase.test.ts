import { describe, expect, it } from 'vitest'
import { webWordlist } from '@/src/testing/vectors'
import { DEFAULT_PASSPHRASE, type GeneratorPolicy } from './options'
import { generatePassphrase } from './passphrase'
import { SPECIAL } from './password'

const words = webWordlist()
const known = new Set(words)
const policy = (overrides: Partial<GeneratorPolicy> = {}): GeneratorPolicy => ({
	minLength: 8, requireUpper: false, requireLower: false, requireDigit: false, requireSymbol: false, allowPassphrase: true, ...overrides,
})

function value(result: ReturnType<typeof generatePassphrase>): string {
	if (!result.ok) throw new Error(result.failure)
	return result.value
}

describe('generatePassphrase', () => {
	it('draws the chosen number of known words, joined by the separator', () => {
		for (let i = 0; i < 1000; i++) {
			const parts = value(generatePassphrase(DEFAULT_PASSPHRASE, null, words)).split('-')
			expect(parts).toHaveLength(5)
			for (const part of parts) expect(known.has(part)).toBe(true)
		}
	})

	it('capitalises every word and appends one digit to one word', () => {
		for (let i = 0; i < 1000; i++) {
			const parts = value(generatePassphrase({ ...DEFAULT_PASSPHRASE, capitalise: true, includeNumber: true, separator: ' ' }, null, words)).split(' ')
			expect(parts.every((part) => /^[A-Z]/.test(part))).toBe(true)
			expect(parts.filter((part) => /\d$/.test(part))).toHaveLength(1)
			expect(parts.join('').replace(/\D/g, '')).toHaveLength(1)
		}
	})

	it('accepts an empty separator', () => {
		expect(value(generatePassphrase({ ...DEFAULT_PASSPHRASE, separator: '' }, null, words))).not.toContain('-')
	})

	it('never draws a hyphenated word', () => {
		// A source that walks every index once.
		let next = 0
		const walk = () => next++
		const drawn = new Set<string>()
		for (let i = 0; i < 7772; i += 4) {
			next = i
			for (const part of value(generatePassphrase({ words: 4, separator: ' ' }, null, words, walk)).split(' ')) drawn.add(part)
		}
		expect(drawn.size).toBe(7772)
		expect([...drawn].some((word) => word.includes('-'))).toBe(false)
	})

	it('meets the floor and every required class under a policy', () => {
		const rule = policy({ minLength: 40, requireUpper: true, requireDigit: true, requireSymbol: true })
		for (let i = 0; i < 1000; i++) {
			const phrase = value(generatePassphrase({ ...DEFAULT_PASSPHRASE, words: 4, separator: ' ' }, rule, words))
			expect(phrase.length).toBeGreaterThanOrEqual(40)
			expect(phrase).toMatch(/[A-Z]/)
			expect(phrase).toMatch(/\d/)
			expect([...phrase].some((char) => SPECIAL.includes(char))).toBe(true)
		}
	})

	it('makes a required symbol the separator between every word', () => {
		const rule = policy({ minLength: 8, requireSymbol: true })
		for (let i = 0; i < 200; i++) {
			const phrase = value(generatePassphrase({ ...DEFAULT_PASSPHRASE, words: 5, separator: ' ' }, rule, words))
			const symbols = [...phrase].filter((char) => SPECIAL.includes(char))
			expect(new Set(symbols).size).toBe(1)
			expect(phrase.split(symbols[0]!).every((word) => /^[a-z]+$/.test(word))).toBe(true)
			expect(symbols.length).toBe(phrase.split(symbols[0]!).length - 1)
		}
		// A separator that is already a symbol stays.
		expect(value(generatePassphrase({ ...DEFAULT_PASSPHRASE, separator: '!' }, rule, words)).split('!')).toHaveLength(DEFAULT_PASSPHRASE.words)
	})

	it('refuses when the organisation turned passphrases off, or a word count out of range', () => {
		expect(generatePassphrase(DEFAULT_PASSPHRASE, policy({ allowPassphrase: false }), words)).toEqual({ ok: false, failure: 'passphrase_off' })
		expect(generatePassphrase({ words: 3 }, null, words)).toEqual({ ok: false, failure: 'words_out_of_range' })
		expect(generatePassphrase({ words: 13 }, null, words)).toEqual({ ok: false, failure: 'words_out_of_range' })
	})
})
