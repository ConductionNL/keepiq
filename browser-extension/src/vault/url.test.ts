import { describe, expect, it } from 'vitest'
import { webUrl } from './url'

describe('webUrl', () => {
	it.each([
		['github.com', 'https://github.com/'],
		['http://intranet.local/x', 'http://intranet.local/x'],
		['localhost:8080', 'https://localhost:8080/'],
		['  example.org ', 'https://example.org/'],
	])('reads %j as %s', (raw, href) => {
		expect(webUrl(raw)?.href).toBe(href)
	})

	it.each([null, undefined, '', 'javascript:alert(1)', 'chrome://newtab/', 'file:///etc/passwd', 'about:blank', 'https://'])('is no web url: %j', (raw) => {
		expect(webUrl(raw)).toBeNull()
	})
})
