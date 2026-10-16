import { describe, expect, it } from 'vitest'
import { baseDomain, matchesBaseDomain, suggestionIds } from './match'

describe('baseDomain', () => {
	it.each([
		['https://login.example.co.uk/x', 'example.co.uk'],
		['https://example.co.uk', 'example.co.uk'],
		['https://a.b.example.com:8443/path', 'example.com'],
		['github.com', 'github.com'],
		['http://192.168.1.10/admin', '192.168.1.10'],
		['http://localhost:8100/', 'localhost'],
		['https://alice.github.io', 'alice.github.io'],
	])('%s → %s', (url, domain) => {
		expect(baseDomain(url)).toBe(domain)
	})

	it.each(['chrome://newtab', 'file:///etc/passwd', 'javascript:alert(1)', 'https://', ''])('has none for %s', (url) => {
		expect(baseDomain(url)).toBeNull()
	})
})

describe('matchesBaseDomain', () => {
	it('matches across subdomains of a multi-label suffix', () => {
		expect(matchesBaseDomain('https://www.example.co.uk', 'https://login.example.co.uk/x')).toBe(true)
	})

	it('does not match a different registrable domain under the same suffix', () => {
		expect(matchesBaseDomain('https://other.co.uk', 'https://example.co.uk')).toBe(false)
		expect(matchesBaseDomain('https://bob.github.io', 'https://alice.github.io')).toBe(false)
	})

	it('needs an item url', () => {
		expect(matchesBaseDomain(null, 'https://example.com')).toBe(false)
	})
})

describe('suggestionIds', () => {
	const rows = [
		{ id: 'a', url: 'https://example.co.uk/login' },
		{ id: 'b', url: 'example.co.uk' },
		{ id: 'c', url: 'https://example.com' },
		{ id: 'd', url: null },
	]

	it('returns the rows on the tab’s base domain', () => {
		expect(suggestionIds(rows, 'https://login.example.co.uk/x')).toEqual(['a', 'b'])
	})

	it('suggests nothing on a tab without an http(s) url', () => {
		expect(suggestionIds(rows, 'chrome://newtab/')).toEqual([])
		expect(suggestionIds(rows, undefined)).toEqual([])
	})
})
