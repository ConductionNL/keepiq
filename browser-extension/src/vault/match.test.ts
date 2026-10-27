import { describe, expect, it } from 'vitest'
import { baseDomain } from './match'

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
