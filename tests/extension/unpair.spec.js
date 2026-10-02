/**
 * @spec openspec/specs/browser-extension-autofill/spec.md#requirement-pairing-against-the-nextcloud-session
 *
 * Disconnect deletes the app password the extension signs in with (#748).
 * Clearing local settings alone left that password valid. The worker case
 * runs the real message handler with a stubbed browser and network.
 */
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'
import { revokeAppPassword } from '../../browser-extension/src/lib/api.js'

const CONFIG = { url: 'https://cloud.example/', user: 'ann', appPassword: 'app-pw' }

describe('revokeAppPassword', () => {
	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('deletes the app password through Nextcloud with its own credential', async () => {
		const fetch = vi.fn().mockResolvedValue({ ok: true })
		vi.stubGlobal('fetch', fetch)

		await expect(revokeAppPassword(CONFIG)).resolves.toBe(true)

		const [url, init] = fetch.mock.calls[0]
		expect(url).toBe('https://cloud.example/ocs/v2.php/core/apppassword')
		expect(init.method).toBe('DELETE')
		expect(init.headers.Authorization).toBe('Basic ' + btoa('ann:app-pw'))
		expect(init.headers['OCS-APIRequest']).toBe('true')
	})

	it('reports a refusal', async () => {
		vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, status: 403 }))

		await expect(revokeAppPassword(CONFIG)).resolves.toBe(false)
	})
})

describe('the worker unpair handler', () => {
	let listener
	const store = {}

	beforeAll(async () => {
		globalThis.chrome = {
			runtime: {
				onMessage: {
					addListener: (fn) => {
						listener = fn
					},
				},
				id: 'ext',
				getURL: (p) => 'chrome-extension://ext/' + p,
			},
			storage: {
				local: {
					get: async (keys) => {
						const out = {}
						for (const k of Array.isArray(keys) ? keys : [keys]) {
							if (k in store) out[k] = store[k]
						}
						return out
					},
					set: async (obj) => Object.assign(store, obj),
					remove: async (keys) => {
						for (const k of Array.isArray(keys) ? keys : [keys])
							delete store[k]
					},
				},
				session: {
					get: async () => ({}),
					set: async () => {},
					remove: async () => {},
				},
			},
		}
		await import('../../browser-extension/src/background/service-worker.js')
	})

	it('deletes the app password and clears the pairing', async () => {
		// One paired account (extension-account-switching).
		store['keepiq.accounts'] = [{ id: 'a1', ...CONFIG, idleMinutes: 15 }]
		store['keepiq.activeAccountId'] = 'a1'
		const fetch = vi
			.fn()
			.mockResolvedValue({ ok: true, status: 200, json: async () => ({}) })
		vi.stubGlobal('fetch', fetch)

		const result = await new Promise((resolve) => {
			// Disconnect comes from the popup, an extension page.
			listener(
				{ type: 'unpair' },
				{ id: 'ext', url: 'chrome-extension://ext/popup.html' },
				resolve,
			)
		})

		expect(
			fetch.mock.calls.map(([url, init]) => `${init.method} ${url}`),
		).toContain('DELETE https://cloud.example/ocs/v2.php/core/apppassword')
		expect(result).toEqual({ ok: true, revoked: true })
		expect(store['keepiq.accounts']).toEqual([])
		vi.unstubAllGlobals()
	})
})
