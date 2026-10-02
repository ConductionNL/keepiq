/**
 * A fake `chrome` global and a fake Keepiq server for driving the extension
 * worker's real router in vitest. Storage is an in-memory map; the server
 * answers the API paths the worker calls, per server URL.
 */
import { vi } from 'vitest'
import {
	encryptPrivateKey,
	importPublicKey,
	rsaEncrypt,
} from '../../../browser-extension/src/crypto/index.js'
import {
	RSA4096_PRIVATE_KEY_PKCS8_PEM,
	RSA4096_PUBLIC_KEY_SPKI_PEM,
} from '../../vitest/fixtures/rsa-fixtures.js'

export const EXTENSION_ID = 'abcdefghijklmnopabcdefghijklmnop'
export const EXTENSION_BASE = 'chrome-extension://' + EXTENSION_ID + '/'

/** The sender record of the popup (an extension page). */
export const POPUP = Object.freeze({
	id: EXTENSION_ID,
	url: EXTENSION_BASE + 'popup.html',
	origin: 'chrome-extension://' + EXTENSION_ID,
})

/**
 * The sender record of a content script in a web page.
 *
 * @param {string} url The page URL.
 * @return {object} The sender.
 */
export function pageSender(url = 'https://evil.example/') {
	return {
		id: EXTENSION_ID,
		url,
		origin: new URL(url).origin,
		tab: { id: 9, url },
	}
}

/**
 * Install a fake `chrome` global.
 *
 * @param {{tabUrl?: string}} options The active tab URL.
 * @return {object} The fake, with `storage` (the map), `filled` (fill messages) and `setTab`.
 */
export function installChrome({ tabUrl = 'https://example.com/login' } = {}) {
	const storage = new Map()
	const filled = []
	let tab = { id: 1, url: tabUrl }
	const fake = {
		storage,
		filled,
		setTab: (url) => {
			tab = { id: 1, url }
		},
		runtime: {
			id: EXTENSION_ID,
			getURL: (path) => EXTENSION_BASE + path,
			onMessage: { addListener: () => {}, removeListener: () => {} },
		},
		tabs: {
			query: vi.fn(async () => [tab]),
			sendMessage: vi.fn(async (tabId, msg) => {
				filled.push(msg)
				return { filled: true }
			}),
		},
		windows: { create: vi.fn() },
	}
	globalThis.chrome = {
		...fake,
		storage: {
			local: {
				get: async (keys) => {
					const list = Array.isArray(keys) ? keys : [keys]
					const out = {}
					for (const k of list)
						if (storage.has(k)) out[k] = structuredClone(storage.get(k))
					return out
				},
				set: async (items) => {
					for (const [k, v] of Object.entries(items))
						storage.set(k, structuredClone(v))
				},
				remove: async (keys) => {
					for (const k of Array.isArray(keys) ? keys : [keys])
						storage.delete(k)
				},
			},
		},
	}
	return fake
}

/**
 * A vault on one fake server: the suite envelope locked with a master
 * password and two encrypted login rows.
 *
 * @param {string} masterPassword The master password.
 * @param {string} prefix Prefix for ids and plaintexts, so accounts differ.
 * @return {Promise<object>} { suite, rows }
 */
export async function makeVault(masterPassword, prefix) {
	const publicKey = await importPublicKey(RSA4096_PUBLIC_KEY_SPKI_PEM)
	const enc = (v) => rsaEncrypt(v, publicKey)
	return {
		suite: {
			id: prefix + '-suite',
			status: 'active',
			privateKey: await encryptPrivateKey(
				RSA4096_PRIVATE_KEY_PKCS8_PEM,
				masterPassword,
			),
			certificate: RSA4096_PUBLIC_KEY_SPKI_PEM,
		},
		rows: [
			{
				id: prefix + '-s1',
				name: 'Example',
				url: 'https://example.com',
				login: await enc(prefix + '-user'),
				key: await enc(prefix + '-password'),
			},
		],
	}
}

/**
 * Install a fake `fetch` that answers per server URL.
 *
 * @param {object} servers Map of base URL → { suite, rows, maxIdleMinutes, passkeyOptions }.
 * @return {object} { calls } every request made, as { method, url, body }.
 */
export function installServer(servers) {
	const calls = []
	globalThis.fetch = vi.fn(async (url, init = {}) => {
		const method = init.method || 'GET'
		const body = init.body ? JSON.parse(init.body) : undefined
		calls.push({ method, url, body })
		const server = Object.entries(servers).find(([base]) => url.startsWith(base))
		const respond = (status, data) => ({
			ok: status < 400,
			status,
			json: async () => data,
			text: async () => JSON.stringify(data),
		})
		if (!server) return respond(404, {})
		const [base, s] = server
		const path = url.slice((base + '/index.php/apps/keepiq').length)
		if (path === '/api/v1/extension/pair') {
			return respond(200, {
				ok: true,
				serverVersion:
					'serverVersion' in s
						? s.serverVersion
						: '0.3.4-unstable.20261002180000',
			})
		}
		if (path === '/api/v1/extension/unpair') return respond(200, { ok: true })
		if (path === '/api/v1/suites') return respond(200, [s.suite])
		if (path === '/api/v1/extension/policy') {
			return respond(200, { maxIdleMinutes: s.maxIdleMinutes ?? 240 })
		}
		if (path.startsWith('/api/v1/extension/match'))
			return respond(200, { items: s.rows })
		if (path.startsWith('/api/v1/extension/used/'))
			return respond(200, { recorded: true })
		if (path === '/api/v1/secret-types') return respond(200, [])
		if (path === '/api/settings/policy') return respond(200, null)
		if (path === '/api/v1/secrets' && method === 'POST')
			return respond(201, { id: 'new' })
		if (path.startsWith('/api/v1/secrets/')) return respond(200, s.rows[0])
		if (path === '/api/v1/passkeys/challenge')
			return respond(200, {
				challenge: 'Y2hhbGxlbmdlY2hhbGxlbmdlY2hhbGxlbmdlMTIz',
			})
		if (path.startsWith('/api/v1/passkeys/login-options')) {
			return respond(
				200,
				s.passkeyOptions ?? { credentials: [], challenge: 'Y2hhbGxlbmdl' },
			)
		}
		if (path === '/api/v1/passkeys' && method === 'POST')
			return respond(201, { id: 'pk1', ...body })
		if (path.startsWith('/api/v1/passkeys/'))
			return respond(200, { recorded: true })
		return respond(404, {})
	})
	return { calls }
}
