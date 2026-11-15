/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for `src/offline/service-worker.js`.
 *
 * The worker's globals (`self`, `caches`, `fetch`) are the browser boundary
 * and are stubbed; the module is imported fresh per test so its listeners
 * register against the stub. What is pinned:
 *
 *  - install precaches the shell and the same-origin keepiq JS/CSS it links,
 *    never an API URL, and survives a failing asset or being offline;
 *  - activate drops old keepiq AND pre-rename doriath shell caches only;
 *  - fetch never interposes while online, never on non-GET or API requests,
 *    and offline serves cache, then the shell for navigations, then network.
 *
 * @spec openspec/specs/offline-readonly-cache/spec.md#requirement-the-app-shell-loads-offline-via-a-service-worker
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const ORIGIN = 'https://cloud.example'
const SCOPE = ORIGIN + '/apps/keepiq/'

/**
 * A fake CacheStorage holding named Map caches.
 *
 * @return {object} The fake.
 */
function createCaches() {
	const store = new Map()
	const open = async (name) => {
		if (!store.has(name)) {
			store.set(name, new Map())
		}
		const cache = store.get(name)
		return {
			put: vi.fn(async (key, value) => {
				cache.set(String(key), value)
			}),
		}
	}
	return {
		store,
		open: vi.fn(open),
		keys: vi.fn(async () => [...store.keys()]),
		delete: vi.fn(async (name) => store.delete(name)),
		match: vi.fn(async (request) => {
			const key = typeof request === 'string' ? request : request.url
			for (const cache of store.values()) {
				if (cache.has(key)) {
					return cache.get(key)
				}
			}
			return undefined
		}),
	}
}

/**
 * A minimal Response stand-in.
 *
 * @param {string} body The text body.
 * @param {boolean} ok Whether the response is OK.
 * @return {object} The response.
 */
function response(body, ok = true) {
	const res = {
		ok,
		body,
		text: async () => body,
		clone: () => response(body, ok),
	}
	return res
}

let listeners
let caches
let fetchMock
let selfStub

/**
 * Import the worker afresh against the current stubs.
 *
 * @return {Promise<void>}
 */
async function loadWorker() {
	vi.resetModules()
	await import('../../src/offline/service-worker.js')
}

/**
 * Dispatch an extendable event and wait for what it was told to wait on.
 *
 * @param {string} type The event type.
 * @param {object} extra Extra event fields.
 * @return {Promise<object>} The event.
 */
async function dispatch(type, extra = {}) {
	const waits = []
	const event = {
		...extra,
		waitUntil: (p) => waits.push(p),
		respondWith: vi.fn((p) => waits.push(p)),
	}
	listeners[type](event)
	event.settled = await Promise.all(waits)
	return event
}

function get (url, extra = {}) {
  return {
	method: 'GET',
	url,
	mode: 'cors',
	destination: 'script',
	...extra,
}
}

describe('offline service worker', () => {
	beforeEach(async () => {
		listeners = {}
		caches = createCaches()
		fetchMock = vi.fn()
		selfStub = {
			registration: { scope: SCOPE },
			location: { origin: ORIGIN },
			navigator: { onLine: true },
			addEventListener: (type, fn) => {
				listeners[type] = fn
			},
			skipWaiting: vi.fn(),
			clients: { claim: vi.fn(async () => {}) },
		}
		vi.stubGlobal('self', selfStub)
		vi.stubGlobal('caches', caches)
		vi.stubGlobal('fetch', fetchMock)
		await loadWorker()
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	describe('install', () => {
		it('precaches the shell and the keepiq scripts and styles it links', async () => {
			const html = [
				'<script src="/apps/keepiq/js/keepiq-main.js?v=1&amp;x=2"></script>',
				'<link href="/apps/keepiq/css/app.css" rel="stylesheet">',
				'<script src="/core/js/core.js"></script>',
				'<script src="/apps/keepiq/api/v1/boot.js"></script>',
			].join('')
			fetchMock.mockImplementation(async (url) =>
				url === SCOPE ? response(html) : response('asset:' + url),
			)

			await dispatch('install')

			expect(selfStub.skipWaiting).toHaveBeenCalledTimes(1)
			const [name] = caches.store.keys()
			expect(name).toBe('keepiq-shell-dev')
			expect([...caches.store.get(name).keys()].sort()).toEqual([
				ORIGIN + '/apps/keepiq/css/app.css',
				SCOPE,
				ORIGIN + '/apps/keepiq/js/keepiq-main.js?v=1&x=2',
			].sort())
			expect(fetchMock).toHaveBeenCalledWith(SCOPE, {
				credentials: 'same-origin',
			})
		})

		it('keeps the rest of the precache when one asset fails', async () => {
			const html =
				'<script src="/apps/keepiq/js/a.js"></script><script src="/apps/keepiq/js/b.js"></script>'
			fetchMock.mockImplementation(async (url) => {
				if (url === SCOPE) return response(html)
				if (url.endsWith('a.js')) throw new Error('network')
				return response('b')
			})

			await dispatch('install')

			const cached = [...caches.store.get('keepiq-shell-dev').keys()]
			expect(cached).toContain(ORIGIN + '/apps/keepiq/js/b.js')
			expect(cached).not.toContain(ORIGIN + '/apps/keepiq/js/a.js')
		})

		it('caches nothing when the shell is not OK or the network is down', async () => {
			fetchMock.mockResolvedValueOnce(response('', false))
			await dispatch('install')
			fetchMock.mockRejectedValueOnce(new Error('offline'))
			await dispatch('install')

			expect(caches.store.size).toBe(0)
		})
	})

	describe('activate', () => {
		it('drops old keepiq and doriath shells, keeps the current and unrelated caches', async () => {
			for (const name of [
				'keepiq-shell-dev',
				'keepiq-shell-0.9.0',
				'doriath-shell-1.0',
				'other-app-cache',
			]) {
				caches.store.set(name, new Map())
			}

			await dispatch('activate')

			expect([...caches.store.keys()]).toEqual([
				'keepiq-shell-dev',
				'other-app-cache',
			])
			expect(selfStub.clients.claim).toHaveBeenCalledTimes(1)
		})
	})

	describe('fetch', () => {
		const shellDoc = response('<html>shell</html>')

		beforeEach(() => {
			caches.store.set(
				'keepiq-shell-dev',
				new Map([
					[SCOPE, shellDoc],
					[ORIGIN + '/apps/keepiq/js/main.js', response('cached-js')],
				]),
			)
		})

		it('does not interpose at all while online', async () => {
			const event = await dispatch('fetch', {
				request: get(ORIGIN + '/apps/keepiq/js/main.js'),
			})

			expect(event.respondWith).not.toHaveBeenCalled()
		})

		it('treats an unknown connectivity state as online', async () => {
			delete selfStub.navigator

			const event = await dispatch('fetch', {
				request: get(ORIGIN + '/apps/keepiq/js/main.js'),
			})

			expect(event.respondWith).not.toHaveBeenCalled()
		})

		describe('offline', () => {
			beforeEach(() => {
				selfStub.navigator.onLine = false
			})

			it.each([
				['a POST', { method: 'POST', url: ORIGIN + '/apps/keepiq/js/main.js' }],
				['an API call', get(ORIGIN + '/apps/keepiq/api/v1/secrets')],
				['another origin', get('https://cdn.example/apps/keepiq/x.js')],
				['a non-asset path', get(ORIGIN + '/ocs/v2.php/cloud/user')],
			])('leaves %s alone', async (_label, request) => {
				const event = await dispatch('fetch', { request })

				expect(event.respondWith).not.toHaveBeenCalled()
			})

			it('serves a cached asset', async () => {
				const event = await dispatch('fetch', {
					request: get(ORIGIN + '/apps/keepiq/js/main.js'),
				})

				expect(event.respondWith).toHaveBeenCalledTimes(1)
				expect(event.settled[0].body).toBe('cached-js')
				expect(fetchMock).not.toHaveBeenCalled()
			})

			it('serves the shell document for any in-app navigation', async () => {
				const event = await dispatch('fetch', {
					request: get(SCOPE + 'secrets/42', { mode: 'navigate' }),
				})

				expect(event.settled[0]).toBe(shellDoc)
			})

			it('falls through to the network on a miss', async () => {
				const live = response('live')
				fetchMock.mockResolvedValue(live)
				const request = get(ORIGIN + '/apps/keepiq/img/logo.svg')

				const event = await dispatch('fetch', { request })

				expect(event.settled[0]).toBe(live)
				expect(fetchMock).toHaveBeenCalledWith(request)
			})

			it('falls through to the network when the cache throws', async () => {
				caches.match.mockRejectedValue(new Error('broken'))
				const live = response('live')
				fetchMock.mockResolvedValue(live)

				const event = await dispatch('fetch', {
					request: get(SCOPE + 'secrets', { destination: 'document' }),
				})

				expect(event.settled[0]).toBe(live)
			})
		})
	})
})
