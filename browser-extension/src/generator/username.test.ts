import { describe, expect, it } from 'vitest'
import { webWordlist } from '@/src/testing/vectors'
import { DEFAULT_USERNAME } from './options'
import { catchAllEmail, generateUsername, plusAddressedEmail, randomWordUsername } from './username'

const words = webWordlist()
const known = new Set(words)

describe('randomWordUsername', () => {
	it('is a capitalised list word with four digits by default', () => {
		for (let i = 0; i < 1000; i++) {
			const match = /^(.+?)(\d{4})$/.exec(randomWordUsername(DEFAULT_USERNAME, words))!
			expect(match).not.toBeNull()
			expect(match[1]![0]).toMatch(/[A-Z]/)
			expect(known.has(match[1]![0]!.toLowerCase() + match[1]!.slice(1))).toBe(true)
		}
	})

	it('can leave out the capital and the number', () => {
		const value = randomWordUsername({ capitalize: false, includeNumber: false }, words)
		expect(known.has(value)).toBe(true)
	})
})

describe('plusAddressedEmail', () => {
	it('puts a random 8-character tag or the website before the @', () => {
		expect(plusAddressedEmail('alice@example.org', { mode: 'random' })).toMatchObject({ ok: true, value: expect.stringMatching(/^alice\+[a-z0-9]{8}@example\.org$/) })
		expect(plusAddressedEmail('alice@example.org', { mode: 'website', website: ' GitHub.com ' })).toEqual({ ok: true, value: 'alice+github.com@example.org' })
	})

	it.each(['', 'alice', '@example.org', 'alice@'])('needs an email address: %j', (email) => {
		expect(plusAddressedEmail(email, { mode: 'random' })).toEqual({ ok: false, failure: 'needs_email' })
	})

	it('needs a website in website mode', () => {
		expect(plusAddressedEmail('alice@example.org', { mode: 'website', website: null })).toEqual({ ok: false, failure: 'no_website' })
	})
})

describe('catchAllEmail', () => {
	it('puts a tag or the website at the domain, without a leading @', () => {
		expect(catchAllEmail('@example.org', { mode: 'random' })).toMatchObject({ ok: true, value: expect.stringMatching(/^[a-z0-9]{8}@example\.org$/) })
		expect(catchAllEmail('example.org', { mode: 'website', website: 'shop.test' })).toEqual({ ok: true, value: 'shop.test@example.org' })
	})

	it.each(['', '  ', 'localhost', '@'])('needs a domain: %j', (domain) => {
		expect(catchAllEmail(domain, { mode: 'random' })).toEqual({ ok: false, failure: 'needs_domain' })
	})

	it('needs a website in website mode', () => {
		expect(catchAllEmail('example.org', { mode: 'website', website: '' })).toEqual({ ok: false, failure: 'no_website' })
	})
})

describe('generateUsername', () => {
	it('dispatches on the type', () => {
		expect(generateUsername({ ...DEFAULT_USERNAME, type: 'plus', email: 'a@b.c' }, words)).toMatchObject({ ok: true, value: expect.stringMatching(/^a\+[a-z0-9]{8}@b\.c$/) })
		expect(generateUsername({ ...DEFAULT_USERNAME, type: 'catchall', domain: 'b.c' }, words)).toMatchObject({ ok: true, value: expect.stringMatching(/@b\.c$/) })
		expect(generateUsername(DEFAULT_USERNAME, words)).toMatchObject({ ok: true, value: expect.stringMatching(/^[A-Z].*\d{4}$/) })
	})
})
