import { describe, expect, it } from 'vitest'
import { URI_MATCHES, type UriMatch } from '@/src/messages'
import { candidatesFor, matches } from './matcher'

describe('matches', () => {
	describe('base domain', () => {
		it('ignores subdomains but respects a multi-label public suffix', () => {
			expect(matches('https://accounts.example.co.uk/login', 'https://www.example.co.uk/', 'baseDomain')).toBe(true)
			expect(matches('https://other.co.uk/', 'https://www.example.co.uk/', 'baseDomain')).toBe(false)
		})

		it('treats a private suffix as a suffix', () => {
			expect(matches('https://a.github.io', 'https://b.github.io', 'baseDomain')).toBe(false)
			expect(matches('https://a.github.io/repo', 'https://a.github.io/', 'baseDomain')).toBe(true)
		})

		it('ignores the scheme and port', () => {
			expect(matches('example.test', 'http://login.example.test:8080/', 'baseDomain')).toBe(true)
		})
	})

	describe.each([
		['IPv4', 'http://192.168.1.10', 'http://192.168.1.11'],
		['IPv6', 'http://[2001:db8::1]', 'http://[2001:db8::2]'],
		['localhost', 'http://localhost', 'http://127.0.0.1'],
	])('an %s address', (_, address, otherAddress) => {
		it('matches only itself under Base domain, on any port', () => {
			expect(matches(`${address}:8080/login`, `${address}/`, 'baseDomain')).toBe(true)
			expect(matches(`${address}/`, `${otherAddress}/`, 'baseDomain')).toBe(false)
		})

		it('needs the same port under Host', () => {
			expect(matches(`${address}:8080/`, `${address}:8080/login`, 'host')).toBe(true)
			expect(matches(`${address}:8080/`, `${address}/`, 'host')).toBe(false)
			expect(matches(`${address}/`, `${otherAddress}/`, 'host')).toBe(false)
		})
	})

	it('does not read an IPv4 address as a domain under a suffix', () => {
		expect(matches('http://10.0.0.1/', 'http://192.168.0.1/', 'baseDomain')).toBe(false)
	})

	describe('host', () => {
		it('includes the port', () => {
			expect(matches('https://example.test:8443/', 'https://example.test/', 'host')).toBe(false)
			expect(matches('https://example.test:8443/', 'https://example.test:8443/login', 'host')).toBe(true)
		})

		it('does not match another subdomain', () => {
			expect(matches('https://example.test/', 'https://www.example.test/', 'host')).toBe(false)
		})

		it('reads a bare host as the item url', () => {
			expect(matches('example.test', 'https://example.test/login', 'host')).toBe(true)
		})
	})

	describe('starts with and exact', () => {
		it('matches a longer page under Starts with only', () => {
			expect(matches('https://example.test/app/', 'https://example.test/app/login', 'startsWith')).toBe(true)
			expect(matches('https://example.test/app/', 'https://example.test/app/login', 'exact')).toBe(false)
		})

		it('matches the same url under both', () => {
			expect(matches('https://example.test/app/', 'https://example.test/app/', 'startsWith')).toBe(true)
			expect(matches('https://example.test/app/', 'https://example.test/app/', 'exact')).toBe(true)
		})

		it('does not match a page outside the prefix', () => {
			expect(matches('https://example.test/app/', 'https://example.test/other', 'startsWith')).toBe(false)
		})
	})

	describe('regular expression', () => {
		it('tests the page url, ignoring case', () => {
			expect(matches('^https://([a-z]+\\.)?EXAMPLE\\.test/', 'https://login.example.test/x', 'regex')).toBe(true)
			expect(matches('^https://example\\.test/admin', 'https://example.test/login', 'regex')).toBe(false)
		})

		it.each([
			['https://bank.com', 'https://bank.com.evil.net/'],
			['https://bank.com', 'https://evil.net/?r=https://bank.com'],
			['bank.com', 'https://bankxcom.net/'],
		])('matches ordinary url %s by base domain, not as a pattern, so not %s', (item, page) => {
			expect(matches(item, page, 'regex')).toBe(false)
		})

		it('matches an ordinary url on its base domain, a query or dot included', () => {
			expect(matches('https://bank.com', 'https://login.bank.com/x', 'regex')).toBe(true)
			expect(matches('bank.com', 'https://bank.com/', 'regex')).toBe(true)
			expect(matches('https://bank.com/login?next=1', 'https://www.bank.com/', 'regex')).toBe(true)
		})

		it('runs an item url with regular expression syntax as the pattern the user wrote', () => {
			expect(matches('^https://([a-z]+\\.)?bank\\.com/', 'https://login.bank.com/x', 'regex')).toBe(true)
			expect(matches('^https://([a-z]+\\.)?bank\\.com/', 'https://bank.com.evil.net/', 'regex')).toBe(false)
			expect(matches('bank\\.com', 'https://evil.net/?r=bank.com', 'regex')).toBe(true)
		})

		it('ignores what reads as syntax in the query or fragment, so such a url is no pattern', () => {
			const item = 'https://login.example.com/oauth?scope=openid+profile'
			expect(matches(item, 'https://evil.net/https://login.example.com/oauthscope=openidprofile', 'regex')).toBe(false)
			expect(matches(item, 'https://login.example.com/other', 'regex')).toBe(true)
			expect(matches('https://example.com/app#/(home)', 'https://evil.net/', 'regex')).toBe(false)
		})

		it('counts an escaped ? before the query as syntax', () => {
			expect(matches('^https://bank\\.com/login\\?next', 'https://bank.com/login?next=1', 'regex')).toBe(true)
			expect(matches('^https://bank\\.com/login\\?next', 'https://www.bank.com/', 'regex')).toBe(false)
		})

		it('does not count an IPv6 host\'s brackets as syntax', () => {
			expect(matches('https://[::1]:8080/', 'http://[::1]/login', 'regex')).toBe(true)
			expect(matches('https://[::1]:8080/', 'https://evil.net/', 'regex')).toBe(false)
			// Other syntax still makes it a pattern, which by base domain would match.
			expect(matches('https://[::1]/(admin|root)', 'https://[::1]/other', 'regex')).toBe(false)
		})

		it('runs a url with a ? in its scheme as a pattern', () => {
			expect(matches('https?://bank.com/login', 'http://bank.com/login', 'regex')).toBe(true)
			expect(matches('https?://bank.com/login', 'https://bank.com/other', 'regex')).toBe(false)
			expect(matches('.*://bank.com/', 'https://bank.com/', 'regex')).toBe(true)
		})

		it('matches a url that is no web url with nothing, as under every other rule', () => {
			for (const item of ['ftp://files.bank.com', 'ssh://git.bank.com', 'androidapp://com.bank.app', 'mailto:support@bank.com', 'my_app://bank.com']) {
				expect(matches(item, 'https://files.bank.com/', 'regex')).toBe(false)
				expect(matches(item, 'https://bank.com/', 'regex')).toBe(false)
			}
		})

		// The guard for the rule that an ordinary url never matches a page that merely contains it.
		describe.each([
			['https://bank.com', 'bank.com'],
			['http://bank.com/login', 'bank.com'],
			['bank.com', 'bank.com'],
			['bank.com:8443', 'bank.com'],
			['https://192.168.1.10/', '192.168.1.10'],
			['https://[2001:db8::1]:8080/', '[2001:db8::1]'],
			['ftp://files.bank.com', 'files.bank.com'],
			['ssh://git.bank.com', 'git.bank.com'],
			['androidapp://com.bank.app', 'com.bank.app'],
			['mailto:support@bank.com', 'bank.com'],
			['my_app://bank.com', 'bank.com'],
			['https://bank.com/login?next=a+b(c)', 'bank.com'],
			['https://bank.com/app#/login(x)', 'bank.com'],
		])('the ordinary url %s', (item, host) => {
			const swapped = (text: string) => ['x', '-', '/'].map((c) => text.replaceAll('.', c))
			const hostile = [
				`https://evil.net/?r=${item}`,
				`https://evil.net/${item}`,
				`https://evil.net/${host}`,
				`https://${host}.evil.net/`,
				...swapped(host).map((h) => `https://${h}.net/`),
				...swapped(item).map((u) => `https://evil.net/?r=${u}`),
				`https://${host}@evil.net/`,
			]

			it.each(URI_MATCHES)('matches no page that merely contains it under %s', (rule) => {
				for (const page of hostile) expect(matches(item, page, rule), page).toBe(false)
			})
		})

		it('still matches a web url on its base domain, and a real pattern as written', () => {
			expect(matches('https://bank.com', 'https://login.bank.com/x', 'regex')).toBe(true)
			expect(matches('^https://([a-z]+\\.)?bank\\.com/', 'https://login.bank.com/x', 'regex')).toBe(true)
		})

		it('never matches an invalid expression, and never throws', () => {
			expect(() => matches('https://[', 'https://example.test/', 'regex')).not.toThrow()
			expect(matches('https://[', 'https://example.test/', 'regex')).toBe(false)
			expect(matches('(', 'https://example.test/', 'regex')).toBe(false)
		})
	})

	it('matches nothing under Never', () => {
		expect(matches('https://example.test/app/', 'https://example.test/app/', 'never')).toBe(false)
	})

	it.each(URI_MATCHES)('needs an item url under %s', (rule) => {
		expect(matches(null, 'https://example.test/', rule)).toBe(false)
		expect(matches('', 'https://example.test/', rule)).toBe(false)
		expect(matches('   ', 'https://example.test/', rule)).toBe(false)
	})

	it.each(URI_MATCHES.flatMap((rule) => [
		['chrome://settings/', rule],
		['about:blank', rule],
		['chrome-extension://abc/popup.html', rule],
		['moz-extension://abc/popup.html', rule],
		['file:///home/example.test/login.html', rule],
		['javascript:alert(1)', rule],
		['data:text/html,example.test', rule],
		['example.test', rule],
		['', rule],
	] as Array<[string, UriMatch]>))('never matches the page %s under %s', (page, rule) => {
		// A regex that would accept anything, so only the scheme check can refuse it.
		const item = rule === 'regex' ? '.*' : 'https://example.test/'
		expect(matches(item, page, rule)).toBe(false)
	})

	it('matches an http page', () => {
		expect(matches('https://example.test/', 'http://example.test/login', 'baseDomain')).toBe(true)
	})
})

describe('candidatesFor', () => {
	const types = [{ id: 't-login', name: 'login' }, { id: 't-note', name: 'note' }]
	const row = (id: string, name: string, overrides: Partial<{ url: string | null; typeId: string | null; blocked: boolean }> = {}) => ({
		id, name, url: 'https://example.test/', typeId: 't-login', blocked: false, ...overrides,
	})
	const page = 'https://login.example.test/'

	it('keeps login rows matching the page under the rule', () => {
		const secrets = [row('a', 'A'), row('b', 'B', { url: 'https://other.test/' })]
		expect(candidatesFor(page, { secrets, types }, 'baseDomain', {}).map((r) => r.id)).toEqual(['a'])
		expect(candidatesFor(page, { secrets, types }, 'host', {})).toEqual([])
	})

	it('leaves out rows with an empty url', () => {
		const secrets = [row('a', 'A', { url: null }), row('b', 'B', { url: '' }), row('c', 'C')]
		expect(candidatesFor(page, { secrets, types }, 'baseDomain', {}).map((r) => r.id)).toEqual(['c'])
	})

	it('leaves out blocked rows', () => {
		const secrets = [row('a', 'A', { blocked: true }), row('b', 'B')]
		expect(candidatesFor(page, { secrets, types }, 'baseDomain', {}).map((r) => r.id)).toEqual(['b'])
	})

	it('leaves out other types, unknown types and rows without a type', () => {
		const secrets = [row('a', 'A', { typeId: 't-note' }), row('b', 'B', { typeId: 't-gone' }), row('c', 'C', { typeId: null }), row('d', 'D')]
		expect(candidatesFor(page, { secrets, types }, 'baseDomain', {}).map((r) => r.id)).toEqual(['d'])
	})

	it('offers nothing on a page that is not http(s), or without a page', () => {
		const secrets = [row('a', 'A', { url: 'chrome://settings' })]
		expect(candidatesFor('chrome://settings/', { secrets, types }, 'regex', {})).toEqual([])
		expect(candidatesFor(undefined, { secrets: [row('a', 'A')], types }, 'baseDomain', {})).toEqual([])
	})

	it('offers nothing under Never', () => {
		expect(candidatesFor(page, { secrets: [row('a', 'A')], types }, 'never', {})).toEqual([])
	})

	it('sorts by last used, newest first, then by name', () => {
		const secrets = [row('w', 'Work'), row('p', 'personal'), row('o', 'Old'), row('a', 'Alpha')]
		const sorted = candidatesFor(page, { secrets, types }, 'baseDomain', { o: 1_000, p: 2_000 })
		expect(sorted.map((r) => r.id)).toEqual(['p', 'o', 'a', 'w'])
	})

	it('puts the item filled last first, whatever its name', () => {
		const secrets = [row('w', 'Work'), row('p', 'Personal')]
		expect(candidatesFor(page, { secrets, types }, 'baseDomain', { p: 5 }).map((r) => r.name)).toEqual(['Personal', 'Work'])
		expect(candidatesFor(page, { secrets, types }, 'baseDomain', { p: 5, w: 6 }).map((r) => r.name)).toEqual(['Work', 'Personal'])
	})

	it('reads only the map’s own entries', () => {
		const secrets = [row('constructor', 'A'), row('b', 'B')]
		expect(candidatesFor(page, { secrets, types }, 'baseDomain', { b: 5 }).map((r) => r.id)).toEqual(['b', 'constructor'])
	})

	it('returns the rows themselves', () => {
		const a = { ...row('a', 'A'), useOnly: true }
		expect(candidatesFor(page, { secrets: [a], types }, 'baseDomain', {})[0]).toBe(a)
	})
})
