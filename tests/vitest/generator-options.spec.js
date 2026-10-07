/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Generator options for the extension's Generator tab: character classes,
 * minimum digits and symbols, ambiguous characters, and the username
 * generator.
 *
 * @spec openspec/specs/extension-generator/spec.md#requirement-password-options
 * @spec openspec/specs/extension-generator/spec.md#requirement-username-generator
 */

import { describe, expect, it } from 'vitest'
import { EFF_LARGE_WORDLIST } from '../../src/generator/eff-large-wordlist.js'
import { generateKey } from '../../src/generator/generator.js'
import {
	catchAllEmail,
	generateUsername,
	plusAddressedEmail,
	randomWordUsername,
} from '../../src/generator/username.js'

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

describe('password options', () => {
	it('uses only the classes that are on', () => {
		repeat(() => {
			expect(
				generateKey({
					length: 20,
					includeUppercase: false,
					includeDigits: false,
					includeSpecialCharacters: false,
				}),
			).toMatch(/^[a-z]{20}$/)
			expect(
				generateKey({
					length: 20,
					includeUppercase: false,
					includeLowercase: false,
					includeSpecialCharacters: false,
				}),
			).toMatch(/^[0-9]{20}$/)
		})
	})

	it('refuses when every class is off', () => {
		expect(() =>
			generateKey({
				includeUppercase: false,
				includeLowercase: false,
				includeDigits: false,
				includeSpecialCharacters: false,
			}),
		).toThrow('Choose at least one kind of character')
	})

	it('holds at least the minimum digits and symbols', () => {
		repeat(() => {
			const value = generateKey({
				length: 12,
				includeSpecialCharacters: true,
				minDigits: 4,
				minSpecial: 3,
			})
			expect(value.replace(/[^0-9]/g, '').length).toBeGreaterThanOrEqual(4)
			expect(value.replace(/[A-Za-z0-9]/g, '').length).toBeGreaterThanOrEqual(
				3,
			)
		})
	})

	it('grows the length to fit the minimums', () => {
		expect(
			generateKey({
				length: 8,
				includeSpecialCharacters: true,
				minDigits: 6,
				minSpecial: 5,
			}),
		).toHaveLength(11)
	})

	it('leaves out I, O, l, 0 and 1 when avoiding ambiguous characters', () => {
		repeat(() =>
			expect(generateKey({ length: 64, avoidAmbiguous: true })).not.toMatch(
				/[IOl01]/,
			),
		)
	})

	it('keeps the server defaults when no option is given', () => {
		repeat(() => expect(generateKey({ length: 16 })).toHaveLength(16))
	})
})

describe('username generator', () => {
	it('a random word is a capitalised list word with four digits', () => {
		repeat(() => {
			const name = randomWordUsername()
			const match = name.match(/^([A-Z][a-z-]*)(\d{4})$/)
			expect(match).not.toBeNull()
			expect(EFF_LARGE_WORDLIST).toContain(match[1].toLowerCase())
		})
		expect(
			randomWordUsername({ capitalize: false, includeNumber: false }, () => 0),
		).toBe(EFF_LARGE_WORDLIST[0])
	})

	it('a plus-addressed email adds eight random characters or the website', () => {
		repeat(() =>
			expect(plusAddressedEmail('user@example.com')).toMatch(
				/^user\+[a-z0-9]{8}@example\.com$/,
			),
		)
		expect(
			plusAddressedEmail('user@example.com', {
				mode: 'website',
				website: 'Login.Example.org',
			}),
		).toBe('user+login.example.org@example.com')
		expect(() => plusAddressedEmail('nope')).toThrow('Enter an email address')
	})

	it('a catch-all email uses the domain with a random or website local part', () => {
		repeat(() =>
			expect(catchAllEmail('example.com')).toMatch(
				/^[a-z0-9]{8}@example\.com$/,
			),
		)
		expect(
			catchAllEmail('@example.com', {
				mode: 'website',
				website: 'login.example.org',
			}),
		).toBe('login.example.org@example.com')
		expect(() => catchAllEmail('')).toThrow('Enter a domain')
		expect(() =>
			catchAllEmail('example.com', { mode: 'website', website: '' }),
		).toThrow('No website detected')
	})

	it('dispatches on the type', () => {
		expect(generateUsername({ type: 'plus', email: 'a@b.nl' })).toMatch(
			/^a\+[a-z0-9]{8}@b\.nl$/,
		)
		expect(generateUsername({ type: 'catchall', domain: 'b.nl' })).toMatch(
			/^[a-z0-9]{8}@b\.nl$/,
		)
		expect(generateUsername({ type: 'word' })).toMatch(/^[A-Z][a-z-]*\d{4}$/)
	})
})
