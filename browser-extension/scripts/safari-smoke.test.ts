import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { extensionIdOf, smoke, type SmokeOptions } from './safari-smoke'

const zipFolder = vi.fn<(path: string) => Promise<string>>(async () => 'UEsDBA==')
const options: SmokeOptions = { driver: 'http://driver', extensionPath: '/abs/.output/safari-mv3', upMs: 20, pollMs: 1, requestMs: 1_000, zipFolder }

/** `hang`: never answers, as a frozen Safari did on the runner; only the request's own timeout ends it. */
type Route = (body: Record<string, unknown> | undefined) => { status?: number; value: unknown } | 'hang'

/** safaridriver as `fetch` sees it; `routes` overrides a `METHOD path`. */
function fakeDriver(routes: Record<string, Route> = {}) {
	const calls: string[] = []
	const bodies: Record<string, unknown>[] = []
	const defaults: Record<string, Route> = {
		'GET http://driver/status': () => ({ value: { ready: true } }),
		'POST http://driver/session': () => ({ value: { sessionId: 's1', capabilities: {} } }),
		'POST http://driver/session/s1/webextension': () => ({ value: { extension: 'ext-1' } }),
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
	it('installs the build by path, logs its id and ends the session', async () => {
		const { calls, bodies } = fakeDriver()
		await smoke(options)
		expect(bodies).toContainEqual({ type: 'path', path: '/abs/.output/safari-mv3' })
		expect(zipFolder).not.toHaveBeenCalled()
		expect(logged()).toContain('Installed /abs/.output/safari-mv3 as ext-1')
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

	it('cuts off a request Safari never answers', async () => {
		vi.spyOn(console, 'warn').mockImplementation(() => {})
		fakeDriver({ 'POST http://driver/session/s1/webextension': () => 'hang', 'DELETE http://driver/session/s1': () => 'hang' })
		await expect(smoke({ ...options, requestMs: 20 })).rejects.toThrow('POST /session/s1/webextension: no answer within 20 ms')
	})

	it('fails when safaridriver does not answer', async () => {
		const { calls } = fakeDriver({ 'GET http://driver/status': () => ({ status: 503, value: null }) })
		await expect(smoke(options)).rejects.toThrow('at "safaridriver status"')
		expect(calls).not.toContain('POST http://driver/session')
	})
})
