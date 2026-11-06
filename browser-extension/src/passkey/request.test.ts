import { describe, expect, it } from 'vitest'
import { frameGate, readStart, rpIdAllowed, type StartInput } from './request'
import { MAX_URL } from './shape'

const NOW = 1_000_000
const b64u = (length: number) => 'A'.repeat(Math.ceil((length * 4) / 3))

const createOptions = (extra: Record<string, unknown> = {}) => ({
	rp: { name: 'Example' },
	user: { id: 'dXNlci03ZjNh', name: 'alice', displayName: 'Alice' },
	challenge: b64u(32),
	pubKeyCredParams: [{ type: 'public-key', alg: -257 }, { type: 'public-key', alg: -7 }],
	excludeCredentials: [],
	...extra,
})

const getOptions = (extra: Record<string, unknown> = {}) => ({ challenge: b64u(32), allowCredentials: [], ...extra })

const create = (extra: Record<string, unknown> = {}, mediation?: string) => ({ type: 'create', options: createOptions(extra), mediation })
const get = (extra: Record<string, unknown> = {}, mediation?: string) => ({ type: 'get', options: getOptions(extra), mediation })

const start = (overrides: Partial<StartInput> = {}, url = 'https://example.test/login'): StartInput => ({
	request: get(),
	crossOriginAncestor: false,
	sender: { url, origin: new URL(url).origin, tab: { id: 7 }, frameId: 3 },
	account: { askPasskeys: true, excludedDomains: [] },
	now: NOW,
	...overrides,
})

const fallback = (input: StartInput) => {
	const result = readStart(input)
	return 'fallback' in result ? result.fallback : null
}

const request = (input: StartInput) => {
	const result = readStart(input)
	if (!('request' in result)) throw new Error(`fell back: ${result.fallback}`)
	return result.request
}

describe('a request Keepiq handles', () => {
	it('reads a get', () => {
		const allowCredentials = [{ type: 'public-key', id: 'AQID' }, { type: 'other', id: 'BAUG' }]
		expect(request(start({ request: get({ allowCredentials, userVerification: 'required', timeout: 60_000 }) }))).toEqual({
			kind: 'get',
			tabId: 7,
			frameId: 3,
			origin: 'https://example.test',
			url: 'https://example.test/login',
			rpId: 'example.test',
			conditional: false,
			challenge: b64u(32),
			userVerification: 'required',
			deadline: NOW + 60_000,
			credentialIds: ['AQID'],
			rpName: '',
			user: null,
			credProps: false,
		})
	})

	it('reads a create', () => {
		const options = {
			excludeCredentials: [{ type: 'public-key', id: 'AQID' }],
			extensions: { credProps: true, prf: {} },
			authenticatorSelection: { authenticatorAttachment: 'platform', userVerification: 'discouraged' },
		}
		expect(request(start({ request: create(options) }))).toMatchObject({
			kind: 'create',
			rpId: 'example.test',
			rpName: 'Example',
			user: { id: 'dXNlci03ZjNh', name: 'alice', displayName: 'Alice' },
			credentialIds: ['AQID'],
			credProps: true,
			userVerification: 'discouraged',
			deadline: NOW + 120_000,
		})
	})

	it('takes an empty pubKeyCredParams as WebAuthn does, ES256 included', () => {
		expect(request(start({ request: create({ pubKeyCredParams: [] }) }))).toMatchObject({ kind: 'create' })
	})

	it('reads a missing excludeCredentials or allowCredentials as none', () => {
		expect(request(start({ request: create({ excludeCredentials: undefined }) })).credentialIds).toEqual([])
		expect(request(start({ request: get({ allowCredentials: undefined }) })).credentialIds).toEqual([])
	})

	it('takes the origin from the sender', () => {
		// Firefox gives only `url`; Chrome's `origin` wins over an inherited `about:blank` url.
		expect(request(start({ sender: { url: 'https://example.test/a?b#c', tab: { id: 1 }, frameId: 0 } })).origin).toBe('https://example.test')
		expect(request(start({ sender: { url: 'about:blank', origin: 'https://example.test', tab: { id: 1 }, frameId: 2 } })).origin).toBe('https://example.test')
		expect(request(start({}, 'https://example.test:8443/x')).origin).toBe('https://example.test:8443')
	})

	it('keeps the frame’s URL without its fragment, or the origin’s root for a URL of another origin', () => {
		expect(request(start({ sender: { url: 'https://example.test/a?b#c', tab: { id: 1 }, frameId: 0 } })).url).toBe('https://example.test/a?b')
		expect(request(start({ sender: { url: 'about:blank', origin: 'https://example.test', tab: { id: 1 }, frameId: 2 } })).url).toBe('https://example.test/')
		expect(request(start({ sender: { origin: 'https://example.test', tab: { id: 1 }, frameId: 2 } })).url).toBe('https://example.test/')
	})

	it('keeps the origin’s root for a URL over the size cap, which a page could grow with pushState', () => {
		const at = (length: number) => `https://example.test/?${'a'.repeat(length - 'https://example.test/?'.length)}`
		expect(request(start({}, at(MAX_URL))).url).toBe(at(MAX_URL))
		expect(request(start({}, at(MAX_URL + 1))).url).toBe('https://example.test/')
	})

	it('lets login.bank.example use bank.example, with its own origin in the client data', () => {
		const signIn = request(start({ request: get({ rpId: 'bank.example' }) }, 'https://login.bank.example/'))
		expect([signIn.rpId, signIn.origin]).toEqual(['bank.example', 'https://login.bank.example'])
		expect(request(start({ request: create({ rp: { id: 'bank.example', name: 'Bank' } }) }, 'https://login.bank.example/')).rpId).toBe('bank.example')
	})

	it.each(['http://localhost:8100/', 'http://app.localhost/', 'https://localhost/'])('accepts %s', (url) => {
		expect(request(start({}, url)).rpId).toBe(new URL(url).hostname)
	})

	it('has no deadline for a conditional get', () => {
		expect(request(start({ request: get({}, 'conditional') }))).toMatchObject({ conditional: true, deadline: null })
	})

	it.each(['optional', 'required', undefined])('opens a modal get for mediation %s', (mediation) => {
		expect(request(start({ request: get({}, mediation) })).conditional).toBe(false)
	})

	it.each([
		[undefined, undefined, 300_000],
		['required', 9_999_999, 600_000],
		['preferred', 1_000, 30_000],
		['discouraged', undefined, 120_000],
		['discouraged', 9_999_999, 180_000],
		['whatever', undefined, 300_000],
	])('clamps the deadline for %s verification and timeout %s', (userVerification, timeout, ms) => {
		const result = request(start({ request: get({ userVerification, timeout }) }))
		expect([result.deadline, result.userVerification]).toEqual([NOW + ms, userVerification === 'required' || userVerification === 'discouraged' ? userVerification : 'preferred'])
	})
})

describe('goes to the browser untouched', () => {
	it('with the setting off', () => {
		expect(fallback(start({ account: { askPasskeys: false, excludedDomains: [] } }))).toBe('setting_off')
	})

	it('without a logged-in active account', () => {
		expect(fallback(start({ account: null }))).toBe('account')
	})

	it('on an excluded domain', () => {
		expect(fallback(start({ account: { askPasskeys: true, excludedDomains: ['example.test'] } }, 'https://accounts.example.test/'))).toBe('excluded')
	})

	it.each(['http://example.test/', 'http://localhost.example/', 'ftp://example.test/', 'data:text/html,x', 'about:blank', 'file:///tmp/x.html'])(
		'on the insecure or opaque origin %s',
		(url) => {
			expect(fallback(start({ sender: { url, tab: { id: 1 }, frameId: 0 } }))).toBe('insecure')
		},
	)

	it('on an opaque sender origin', () => {
		expect(fallback(start({ sender: { origin: 'null', url: 'https://example.test/', tab: { id: 1 }, frameId: 0 } }))).toBe('insecure')
	})

	it.each([
		['https://evil.example/', 'bank.example'],
		['https://bank.example/', 'login.bank.example'],
		['https://bank.example/', 'ank.example'],
		['https://login.bank.example/', 'example'],
		['https://bank.co.uk/', 'co.uk'],
		['https://alice.github.io/', 'github.io'],
		['https://github.io/', 'github.io'],
		['https://bank.example/', 'BANK.example'],
		['https://bank.example/', 'bank.example.'],
		['https://bank.example/', ''],
		['https://app.localhost/', 'localhost'],
		['https://192.168.1.10/', '192.168.1.10'],
		['https://192.168.1.10/', '168.1.10'],
		['https://[::1]/', '[::1]'],
	])('on %s asking RP ID %j', (url, rpId) => {
		expect(fallback(start({ request: get({ rpId }) }, url))).toBe('rp_id')
		expect(fallback(start({ request: create({ rp: { id: rpId, name: 'x' } }) }, url))).toBe('rp_id')
	})

	it.each(['https://192.168.1.10/', 'https://[::1]:8443/', 'https://com/'])('on %s without an RP ID', (url) => {
		expect(fallback(start({}, url))).toBe('rp_id')
	})

	it('in a frame with a cross-origin ancestor', () => {
		expect(fallback(start({ crossOriginAncestor: true }, 'https://idp.example/'))).toBe('cross_origin')
	})

	it.each([[[{ type: 'public-key', alg: -257 }]], [[{ type: 'other', alg: -7 }]]])('for a create without ES256: %j', (pubKeyCredParams) => {
		expect(fallback(start({ request: create({ pubKeyCredParams }) }))).toBe('algorithm')
	})

	it('for a create asking a cross-platform authenticator', () => {
		expect(fallback(start({ request: create({ authenticatorSelection: { authenticatorAttachment: 'cross-platform' } }) }))).toBe('attachment')
	})

	it('checks a create’s RP ID first, then its algorithm, then its attachment', () => {
		const pubKeyCredParams = [{ type: 'public-key', alg: -257 }]
		const authenticatorSelection = { authenticatorAttachment: 'cross-platform' }
		expect(fallback(start({ request: create({ rp: { id: 'evil.example', name: 'x' }, pubKeyCredParams, authenticatorSelection }) }))).toBe('rp_id')
		expect(fallback(start({ request: create({ pubKeyCredParams, authenticatorSelection }) }))).toBe('algorithm')
	})

	it.each([
		['a conditional create', create({}, 'conditional')],
		['a silent get', get({}, 'silent')],
		['a user.id over 64 bytes', create({ user: { id: b64u(65), name: 'a', displayName: 'a' } })],
		['a challenge over 1024 bytes', get({ challenge: b64u(1025) })],
		['a string over 1024 characters', get({ rpId: 'x'.repeat(1025) })],
		['no request', undefined],
	])('for %s', (_name, request) => {
		expect(fallback(start({ request }))).toBe('malformed')
	})

	it('without a tab or frame', () => {
		expect(fallback(start({ sender: { url: 'https://example.test/', frameId: 0 } }))).toBe('sender')
		expect(fallback(start({ sender: { url: 'https://example.test/', tab: { id: 1 } } }))).toBe('sender')
	})
})

describe('frameGate', () => {
	it('answers availability without a request', () => {
		expect(frameGate(start())).toMatchObject({ tabId: 7, frameId: 3 })
		expect(frameGate(start({ account: { askPasskeys: false, excludedDomains: [] } }))).toEqual({ fallback: 'setting_off' })
		expect(frameGate(start({ crossOriginAncestor: true }))).toEqual({ fallback: 'cross_origin' })
		expect(frameGate(start({}, 'http://example.test/'))).toEqual({ fallback: 'insecure' })
	})
})

describe('rpIdAllowed', () => {
	it.each([
		['bank.example', 'login.bank.example', true],
		['login.bank.example', 'a.login.bank.example', true],
		['bank.example', 'bank.example', true],
		['example.co.uk', 'www.example.co.uk', true],
		['alice.github.io', 'alice.github.io', true],
		['localhost', 'localhost', true],
		['intranet', 'intranet', true],
		['bank.example', 'evil.example', false],
		['co.uk', 'example.co.uk', false],
		['github.io', 'alice.github.io', false],
		['bob.github.io', 'alice.github.io', false],
		['example', 'bank.example', false],
		['localhost', 'app.localhost', false],
		['10.0.0.1', '10.0.0.1', false],
	])('%s for %s: %s', (rpId, host, allowed) => {
		expect(rpIdAllowed(rpId, host)).toBe(allowed)
	})
})
