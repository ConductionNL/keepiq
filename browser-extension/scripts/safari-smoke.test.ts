import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { extensionIdOf, smoke, type SmokeOptions } from './safari-smoke'

const zipFolder = vi.fn<(path: string) => Promise<string>>(async () => 'UEsDBA==')
const options: SmokeOptions = { driver: 'http://driver', site: 'http://site', extensionPath: '/abs/.output/safari-mv3', upMs: 20, scriptMs: 20, pollMs: 1, zipFolder }

type Route = (body: Record<string, unknown> | undefined) => { status?: number; value: unknown }

const EXTENSION_PAGE = 'safari-web-extension://ext-1/popup.html'

/** safaridriver and the test site, as `fetch` sees them; `routes` overrides a `METHOD path`. */
function fakeDriver(routes: Record<string, Route> = {}) {
	const calls: string[] = []
	const bodies: Record<string, unknown>[] = []
	const defaults: Record<string, Route> = {
		'GET http://driver/status': () => ({ value: { ready: true } }),
		'GET http://site/': () => ({ value: null }),
		'POST http://driver/session': () => ({ value: { sessionId: 's1', capabilities: {} } }),
		'POST http://driver/session/s1/webextension': () => ({ value: { extension: 'ext-1' } }),
		'POST http://driver/session/s1/timeouts': () => ({ value: null }),
		'POST http://driver/session/s1/url': () => ({ value: null }),
		'POST http://driver/session/s1/execute/async': () => ({ value: true }),
		'POST http://driver/session/s1/execute/sync': () => ({ value: false }),
		'DELETE http://driver/session/s1': () => ({ value: null }),
	}
	vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
		const key = `${init?.method ?? 'GET'} ${url}`
		const body = init?.body ? JSON.parse(String(init.body)) as Record<string, unknown> : undefined
		calls.push(key)
		if (body) bodies.push(body)
		const route = routes[key] ?? defaults[key]
		if (!route) return new Response('{}', { status: 404 })
		const { status = 200, value } = route(body)
		return new Response(JSON.stringify({ value }), { status })
	}))
	return { calls, bodies }
}

let log: { mock: { calls: unknown[][] } }

beforeEach(() => {
	log = vi.spyOn(console, 'log').mockImplementation(() => {})
	zipFolder.mockClear()
})

afterEach(() => {
	vi.unstubAllGlobals()
})

const logged = () => log.mock.calls.map((call: unknown[]) => String(call[0]))

describe('extensionIdOf', () => {
	it.each([['ext-1', 'ext-1'], [{ extension: 'ext-1' }, 'ext-1'], [null, undefined], [{}, undefined], [{ extension: 5 }, undefined]])('%j → %s', (value, id) => {
		expect(extensionIdOf(value)).toBe(id)
	})
})

describe('smoke', () => {
	it('installs the build by path, asks the extension\'s page about access, checks the page script and ends the session', async () => {
		const { calls, bodies } = fakeDriver()
		await smoke(options)
		expect(bodies).toContainEqual({ type: 'path', path: '/abs/.output/safari-mv3' })
		expect(bodies).toContainEqual({ pageLoad: 5_000 })
		expect(bodies).toContainEqual({ url: EXTENSION_PAGE })
		expect(bodies).toContainEqual(expect.objectContaining({ args: ['http://site/*'] }))
		expect(bodies).toContainEqual({ url: 'http://site/passkey.html' })
		expect(zipFolder).not.toHaveBeenCalled()
		expect(calls.at(-1)).toBe('DELETE http://driver/session/s1')
	})

	it('installs a base64 zip when the path is refused', async () => {
		fakeDriver({
			'POST http://driver/session/s1/webextension': (body) => body?.type === 'path'
				? { status: 400, value: { error: 'invalid argument', message: 'no path' } }
				: { value: 'ext-1' },
		})
		await smoke(options)
		expect(zipFolder).toHaveBeenCalledWith('/abs/.output/safari-mv3')
	})

	it('fails, naming the step, when neither install works, and still ends the session', async () => {
		const { calls } = fakeDriver({ 'POST http://driver/session/s1/webextension': () => ({ status: 500, value: { error: 'unknown error', message: 'not loadable' } }) })
		await expect(smoke(options)).rejects.toThrow('Safari smoke test failed at "install the extension": POST /session/s1/webextension: HTTP 500 unknown error not loadable')
		expect(calls).toContain('DELETE http://driver/session/s1')
	})

	it('fails when the install answers no id', async () => {
		fakeDriver({ 'POST http://driver/session/s1/webextension': () => ({ value: null }) })
		await expect(smoke(options)).rejects.toThrow('no extension id')
	})

	it('treats a page-load timeout on the extension\'s page as opened', async () => {
		const { calls } = fakeDriver({
			'POST http://driver/session/s1/url': (body) => body?.url === EXTENSION_PAGE ? { status: 500, value: { error: 'timeout', message: 'page load' } } : { value: null },
		})
		await smoke(options)
		expect(calls).toContain('POST http://driver/session/s1/execute/sync')
	})

	it.each<[string, Record<string, Route>]>([
		['the extension has no access', { 'POST http://driver/session/s1/execute/async': () => ({ value: false }) }],
		['its page cannot be opened', { 'POST http://driver/session/s1/url': () => ({ status: 400, value: { error: 'invalid argument', message: 'bad scheme' } }) }],
		['its page answers something else', { 'POST http://driver/session/s1/execute/async': () => ({ value: 'ReferenceError: browser' }) }],
	])('skips the page script check and passes when %s', async (_, routes) => {
		const { calls } = fakeDriver(routes)
		await smoke(options)
		expect(logged()).toContain('SKIP: no website access in an automation session')
		expect(calls).not.toContain('POST http://driver/session/s1/execute/sync')
		expect(calls.at(-1)).toBe('DELETE http://driver/session/s1')
	})

	it('waits until the content script replaced the native function', async () => {
		let polls = 0
		fakeDriver({ 'POST http://driver/session/s1/execute/sync': () => ({ value: ++polls < 3 }) })
		await smoke(options)
		expect(polls).toBe(3)
	})

	it('fails when navigator.credentials.get stays native', async () => {
		fakeDriver({ 'POST http://driver/session/s1/execute/sync': () => ({ value: true }) })
		await expect(smoke(options)).rejects.toThrow('at "passkey page script"')
	})

	it('fails when safaridriver does not answer', async () => {
		const { calls } = fakeDriver({ 'GET http://driver/status': () => ({ status: 503, value: null }) })
		await expect(smoke(options)).rejects.toThrow('at "safaridriver status"')
		expect(calls).not.toContain('POST http://driver/session')
	})
})
