import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { answered, ASK_BACKGROUND, BACKGROUND_STATE, GECKO_ID, OPEN_TAB, openedExtensionPage, sessionCapabilities, smoke, type FirefoxSmokeOptions } from './firefox-smoke'

const UUID = '0b6f2a3c-1d4e-4f5a-8b6c-7d8e9f0a1b2c'
const options: FirefoxSmokeOptions = { driver: 'http://driver', extensionPath: '/abs/.output/firefox-mv2', uuid: UUID, upMs: 20, pollMs: 1, requestMs: 1_000 }

/** `hang`: never answers; only the request's own timeout ends it. */
type Route = (body: Record<string, unknown> | undefined) => { status?: number; value: unknown } | 'hang'

/** geckodriver as `fetch` sees it; `routes` overrides a `METHOD path`. */
function fakeDriver(routes: Record<string, Route> = {}) {
	let handlesCalls = 0
	const calls: string[] = []
	const bodies: Record<string, unknown>[] = []
	const defaults: Record<string, Route> = {
		'GET http://driver/status': () => ({ value: { ready: true } }),
		'POST http://driver/session': () => ({ value: { sessionId: 's1', capabilities: {} } }),
		'POST http://driver/session/s1/moz/addon/install': () => ({ value: GECKO_ID }),
		'POST http://driver/session/s1/moz/context': () => ({ value: null }),
		'POST http://driver/session/s1/execute/sync': (body) => ({ value: body?.script === BACKGROUND_STATE ? 'running' : `moz-extension://${UUID}/popup.html` }),
		'GET http://driver/session/s1/window/handles': () => ({ value: handlesCalls++ === 0 ? ['h1'] : ['h1', 'h2'] }),
		'POST http://driver/session/s1/window': () => ({ value: null }),
		'POST http://driver/session/s1/execute/async': () => ({ value: { ok: true, reply: { theme: 'system' } } }),
		'DELETE http://driver/session/s1': () => ({ value: null }),
	}
	vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
		const key = `${init?.method ?? 'GET'} ${url}`
		const body = init?.body ? JSON.parse(String(init.body)) as Record<string, unknown> : undefined
		calls.push(key)
		if (body) bodies.push(body)
		const route = routes[key] ?? defaults[key]
		if (!route) return new Response('{}', { status: 404 })
		const answer = route(body)
		if (answer === 'hang') {
			return new Promise<Response>((_, reject) => init?.signal?.addEventListener('abort', () => reject(init.signal!.reason)))
		}
		const { status = 200, value } = answer
		return new Response(JSON.stringify({ value }), { status })
	}))
	return { calls, bodies }
}

let log: { mock: { calls: unknown[][] } }

beforeEach(() => {
	log = vi.spyOn(console, 'log').mockImplementation(() => {})
})

afterEach(() => {
	vi.unstubAllGlobals()
})

const logged = () => log.mock.calls.map((call: unknown[]) => String(call[0]))

describe('sessionCapabilities', () => {
	it('asks for headless Firefox with the extension host fixed to the uuid', () => {
		const firefox = (sessionCapabilities(UUID) as { capabilities: { alwaysMatch: Record<string, { args: string[]; prefs: Record<string, string> }> } })
			.capabilities.alwaysMatch['moz:firefoxOptions']!
		expect(firefox.args).toEqual(['-headless'])
		expect(JSON.parse(firefox.prefs['extensions.webextensions.uuids']!)).toEqual({ [GECKO_ID]: UUID })
	})
})

describe('answered', () => {
	it.each([[{ ok: true, reply: {} }, true], [{ ok: true, reply: null }, true], [{ ok: false, error: 'no reply' }, false], [null, false], ['x', false]])('%j → %s', (value, expected) => {
		expect(answered(value)).toBe(expected)
	})
})

describe('openedExtensionPage', () => {
	it.each([
		[`moz-extension://${UUID}/popup.html`, true],
		['not installed', false],
		['about:blank', false],
		[true, false],
		[null, false],
	])('%j is an opened extension page: %s', (value, expected) => {
		expect(openedExtensionPage(value)).toBe(expected)
	})
})

describe('ASK_BACKGROUND', () => {
	it('sends the read-only settings.get and hands back the reply', async () => {
		const sendMessage = vi.fn(async () => ({ theme: 'system' }))
		const result = await new Promise((resolve) => new Function('browser', ASK_BACKGROUND).call(null, { runtime: { sendMessage } }, resolve))
		expect(sendMessage).toHaveBeenCalledWith({ kind: 'settings.get' })
		expect(result).toEqual({ ok: true, reply: { theme: 'system' }, error: undefined })
	})

	it('reads the page\'s browser through wrappedJSObject, and fails without one', async () => {
		const sendMessage = vi.fn(async () => ({}))
		vi.stubGlobal('window', { wrappedJSObject: { browser: { runtime: { sendMessage } } } })
		const result = await new Promise((resolve) => new Function(ASK_BACKGROUND).call(null, resolve))
		expect(result).toEqual({ ok: true, reply: {}, error: undefined })
		vi.stubGlobal('window', { wrappedJSObject: {} })
		expect(await new Promise((resolve) => new Function(ASK_BACKGROUND).call(null, resolve))).toEqual({ ok: false, error: 'no browser API on the page' })
		vi.stubGlobal('location', { href: 'about:blank' })
		expect(await new Promise((resolve) => new Function(ASK_BACKGROUND).call(null, resolve))).toEqual({ ok: false, error: 'no browser API on about:blank' })
		vi.unstubAllGlobals()
	})

	it.each([
		['no listener answered', async () => undefined, { ok: false, reply: undefined, error: 'no reply' }],
		['the message failed', async () => { throw new Error('Could not establish connection. Receiving end does not exist.') }, { ok: false, error: 'Error: Could not establish connection. Receiving end does not exist.' }],
	])('reports failure when %s', async (_, sendMessage, expected) => {
		const result = await new Promise((resolve) => new Function('browser', ASK_BACKGROUND).call(null, { runtime: { sendMessage } }, resolve))
		expect(result).toEqual(expected)
	})
})

describe('smoke', () => {
	it('installs the build as a temporary add-on, needs the background running, opens its popup in a new tab, needs its answer and ends the session', async () => {
		const { calls, bodies } = fakeDriver()
		await smoke(options)
		expect(bodies).toContainEqual({ path: '/abs/.output/firefox-mv2', temporary: true })
		expect(bodies).toContainEqual({ script: BACKGROUND_STATE, args: [GECKO_ID] })
		expect(bodies).toContainEqual({ script: OPEN_TAB, args: [GECKO_ID, 'popup.html'] })
		expect(bodies).toContainEqual({ handle: 'h2' })
		expect(calls.indexOf('POST http://driver/session/s1/window')).toBeLessThan(calls.indexOf('POST http://driver/session/s1/execute/async'))
		expect(logged()).toEqual(expect.arrayContaining([`Installed /abs/.output/firefox-mv2 as ${GECKO_ID}`, 'The background is running', `Opened moz-extension://${UUID}/popup.html`, 'The background answered settings.get: {"theme":"system"}']))
		expect(calls.at(-1)).toBe('DELETE http://driver/session/s1')
	})

	it('waits while the background starts, and fails naming its last state when it never runs', async () => {
		let state = 0
		fakeDriver({ 'POST http://driver/session/s1/execute/sync': (body) => ({ value: body?.script === BACKGROUND_STATE ? (++state < 3 ? 'starting' : 'running') : `moz-extension://${UUID}/popup.html` }) })
		await smoke({ ...options, upMs: 1_000 })
		expect(state).toBe(3)
		const { calls } = fakeDriver({ 'POST http://driver/session/s1/execute/sync': () => ({ value: 'stopped' }) })
		await expect(smoke(options)).rejects.toThrow('Firefox smoke test failed at "start the background": the background is not running within 20 ms: "stopped"')
		expect(calls).not.toContain('POST http://driver/session/s1/execute/async')
	})

	it('fails, without asking, when the chrome context finds no popup page to open', async () => {
		const { calls } = fakeDriver({ 'POST http://driver/session/s1/execute/sync': (body) => ({ value: body?.script === BACKGROUND_STATE ? 'running' : 'not installed' }) })
		await expect(smoke(options)).rejects.toThrow('at "open the popup page": no popup page to open: "not installed"')
		expect(calls).not.toContain('POST http://driver/session/s1/execute/async')
		expect(calls).toContain('DELETE http://driver/session/s1')
	})

	it('fails when the popup tab never appears', async () => {
		fakeDriver({ 'GET http://driver/session/s1/window/handles': () => ({ value: ['h1'] }) })
		await expect(smoke(options)).rejects.toThrow('at "open the popup page": no new tab opened within 20 ms: ["h1"]')
	})

	it('asks again when the page is not ready yet', async () => {
		let asked = 0
		fakeDriver({ 'POST http://driver/session/s1/execute/async': () => (++asked < 2 ? { status: 500, value: { error: 'javascript error', message: 'browser is not defined' } } : { value: { ok: true, reply: {} } }) })
		await smoke({ ...options, upMs: 1_000 })
		expect(asked).toBe(2)
	})

	it('asks again while the background is starting', async () => {
		let asked = 0
		const { calls } = fakeDriver({
			'POST http://driver/session/s1/execute/async': () => (++asked < 3 ? { value: { ok: false, error: 'Receiving end does not exist' } } : { value: { ok: true, reply: {} } }),
		})
		await smoke({ ...options, upMs: 1_000 })
		expect(calls.filter((call) => call.endsWith('/execute/async'))).toHaveLength(3)
	})

	it('fails, naming the step and the last reason, when the background never answers, and still ends the session', async () => {
		const { calls } = fakeDriver({ 'POST http://driver/session/s1/execute/async': () => ({ value: { ok: false, error: 'no reply' } }) })
		await expect(smoke(options)).rejects.toThrow('Firefox smoke test failed at "ask the background": the background did not answer within 20 ms: {"ok":false,"error":"no reply"}')
		expect(calls).toContain('DELETE http://driver/session/s1')
	})

	it('fails when the add-on installs under another id', async () => {
		const { calls } = fakeDriver({ 'POST http://driver/session/s1/moz/addon/install': () => ({ value: 'other@example.org' }) })
		await expect(smoke(options)).rejects.toThrow(`installed as "other@example.org", not ${GECKO_ID}`)
		expect(calls).not.toContain('POST http://driver/session/s1/moz/context')
		expect(calls).toContain('DELETE http://driver/session/s1')
	})

	it('fails, naming the step, when Firefox refuses the add-on', async () => {
		fakeDriver({ 'POST http://driver/session/s1/moz/addon/install': () => ({ status: 500, value: { error: 'unknown error', message: 'Could not install add-on' } }) })
		await expect(smoke(options)).rejects.toThrow('Firefox smoke test failed at "install the extension": POST /session/s1/moz/addon/install: HTTP 500 unknown error Could not install add-on')
	})

	it('fails when the session answers no id', async () => {
		fakeDriver({ 'POST http://driver/session': () => ({ value: {} }) })
		await expect(smoke(options)).rejects.toThrow('at "new session": no sessionId')
	})

	it('cuts off a request Firefox never answers', async () => {
		vi.spyOn(console, 'warn').mockImplementation(() => {})
		fakeDriver({ 'POST http://driver/session/s1/moz/addon/install': () => 'hang', 'DELETE http://driver/session/s1': () => 'hang' })
		await expect(smoke({ ...options, requestMs: 20 })).rejects.toThrow('POST /session/s1/moz/addon/install: no answer within 20 ms')
	})

	it('fails when geckodriver does not answer', async () => {
		const { calls } = fakeDriver({ 'GET http://driver/status': () => ({ status: 503, value: null }) })
		await expect(smoke(options)).rejects.toThrow('at "geckodriver status"')
		expect(calls).not.toContain('POST http://driver/session')
	})
})
