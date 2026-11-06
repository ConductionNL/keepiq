import { describe, expect, it } from 'vitest'
import { excludedDomain } from './domains'

describe('excludedDomain', () => {
	it.each([
		['HTTPS://Accounts.Example.test/path', 'example.test'],
		['login.example.co.uk', 'example.co.uk'],
		['  example.org  ', 'example.org'],
		['https://login.example.co.uk/signin', 'example.co.uk'],
	])('reduces %j to its base domain', (input, domain) => {
		expect(excludedDomain([], input)).toEqual({ ok: true, domain })
	})

	it.each([
		['', 'empty'],
		['   ', 'empty'],
		['exa mple', 'unreadable'],
		['ftp://example.org', 'unreadable'],
	])('refuses %j as %s', (input, error) => {
		expect(excludedDomain([], input)).toEqual({ ok: false, error })
	})

	it('refuses a domain already listed, whatever host names it', () => {
		expect(excludedDomain(['example.test'], 'www.example.test')).toEqual({ ok: false, error: 'duplicate' })
	})
})
