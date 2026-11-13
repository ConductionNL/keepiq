import { describe, expect, it } from 'vitest'
import { isLocalhost, webUrl } from './url'

describe('isLocalhost', () => {
	it.each([['localhost', true], ['shop.localhost', true], ['localhost.shop.test', false], ['notlocalhost', false], ['127.0.0.1', false]])('%s: %s', (host, local) => {
		expect(isLocalhost(host)).toBe(local)
	})
})

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
