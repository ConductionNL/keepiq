/**
 * @spec openspec/changes/clients-extension-gaps/specs/extension-pairing/spec.md
 *
 * Pairing and transport: the server address is https and stored clean,
 * requests carry no cookies, and a revoked app password signs the account
 * out until the user signs in again. On the REAL router and popup.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	isSecureServerUrl,
	normalizeServerUrl,
} from '../../browser-extension/src/lib/server-url.js'
import {
	installChrome,
	installServer,
	makeVault,
	POPUP,
} from './fixtures/fakeBrowser.js'

describe('server address', () => {
	it.each([
		['cloud.example.com', 'https://cloud.example.com'],
		['  https://cloud.example.com/  ', 'https://cloud.example.com'],
		[
			'https://cloud.example.com/index.php/apps/keepiq/vault?x=1#top',
			'https://cloud.example.com',
		],
		[
			'https://example.com/nextcloud/index.php/login',
			'https://example.com/nextcloud',
		],
		['https://example.com/nextcloud/', 'https://example.com/nextcloud'],
		[
			'https://cloud.example.com:8443/apps/files',
			'https://cloud.example.com:8443',
		],
		['http://localhost:8080', 'http://localhost:8080'],
		['http://127.0.0.1', 'http://127.0.0.1'],
		['http://nextcloud.test/', 'http://nextcloud.test'],
		['http://nc.local', 'http://nc.local'],
	])('stores %s as %s', (typed, stored) => {
		expect(normalizeServerUrl(typed)).toBe(stored)
	})

	it.each([
		['http://cloud.example.com', /needs an https address/],
		['https://ann:secret@cloud.example.com', /Leave the user name/],
		['ftp://cloud.example.com', /not a valid address/],
		['', /Enter the address/],
	])('refuses %s', (typed, message) => {
		expect(() => normalizeServerUrl(typed)).toThrow(message)
	})

	it('treats only https and local http as secure', () => {
		expect(isSecureServerUrl('https://a.example')).toBe(true)
		expect(isSecureServerUrl('http://localhost:8098')).toBe(true)
		expect(isSecureServerUrl('http://a.example')).toBe(false)
		expect(isSecureServerUrl('not a url')).toBe(false)
	})
})

const SERVER = 'https://one.example'
let router
let server
let browser
let state

/**
 * Send a message to the router as the popup.
 *
 * @param {string} type The message type.
 * @param {object} [payload] The payload.
 * @return {Promise<object>}
 */
const send = (type, payload = {}) => router.handleMessage({ type, payload }, POPUP)

/** The stored accounts. */
const accounts = () => browser.storage.get('keepiq.accounts') || []

beforeEach(async () => {
	vi.resetModules()
	browser = installChrome()
	const fixture = await makeVault('m', 'x')
	state = { ...fixture, types: [{ id: 't1', name: 'login' }], folders: [] }
	server = installServer({ [SERVER]: state })
	router = await import('../../browser-extension/src/background/router.js')
})

afterEach(async () => {
	await new Promise((r) => setTimeout(r, 300))
	document.body.innerHTML = ''
})

describe('pairing', () => {
	it('stores the server address without the page path it was pasted with', async () => {
		const res = await send('pair', {
			url: SERVER + '/index.php/apps/keepiq/vault',
			user: 'ann',
			appPassword: 'p',
		})
		expect(res.ok).toBe(true)
		expect(accounts()[0].url).toBe(SERVER)
	})

	it('refuses a plain http server before sending the app password', async () => {
		const res = await send('pair', {
			url: 'http://plain.example',
			user: 'ann',
			appPassword: 'p',
		})
		expect(res.error).toMatch(/needs an https address/)
		expect(server.calls).toHaveLength(0)
		expect(accounts()).toHaveLength(0)
	})

	it('sends every request without cookies', async () => {
		await send('pair', { url: SERVER, user: 'ann', appPassword: 'p' })
		await send('unlock', { masterPassword: 'm' })
		await send('match', { host: 'example.com' })
		expect(server.calls.length).toBeGreaterThan(2)
		for (const call of server.calls) expect(call.credentials).toBe('omit')
	})

	it('will not use an account paired over http before this rule', async () => {
		browser.storage.set('keepiq.accounts', [
			{
				id: 'old',
				url: 'http://legacy.example',
				user: 'ann',
				appPassword: 'p',
			},
		])
		browser.storage.set('keepiq.activeAccountId', 'old')
		const view = await send('get-state')
		expect(view.insecure).toBe(true)
		const unlock = await send('unlock', { masterPassword: 'm' })
		expect(unlock.error).toMatch(/needs an https address/)
		expect(server.calls.some((c) => c.url.startsWith('http://legacy'))).toBe(
			false,
		)
	})
})

describe('a revoked app password', () => {
	beforeEach(async () => {
		await send('pair', { url: SERVER, user: 'ann', appPassword: 'p' })
		await send('unlock', { masterPassword: 'm' })
		await vi.waitFor(() =>
			expect(
				[...browser.storage.keys()].some((k) =>
					k.startsWith('vault-snapshot:'),
				),
			).toBe(true),
		)
	})

	it('signs the account out: locked, password and snapshot gone, account kept', async () => {
		state.revokedPasswords = ['p']
		await send('vault-sync-now')
		await vi.waitFor(() => expect(accounts()[0].loggedOut).toBe(true))
		expect(accounts()[0].appPassword).toBe('')
		expect(
			[...browser.storage.keys()].some((k) => k.startsWith('vault-snapshot:')),
		).toBe(false)
		const view = await send('get-state')
		expect(view).toMatchObject({ loggedOut: true, unlocked: false })
		expect(view.accounts[0].loggedOut).toBe(true)
		const unlock = await send('unlock', { masterPassword: 'm' })
		expect(unlock.error).toMatch(/signed out/)
	})

	it('signs in again with a new app password, verified first', async () => {
		state.revokedPasswords = ['p']
		await send('vault-sync-now')
		await vi.waitFor(() => expect(accounts()[0].loggedOut).toBe(true))
		state.revokedPasswords = ['p', 'still-wrong']
		const wrong = await send('relogin', {
			accountId: accounts()[0].id,
			appPassword: 'still-wrong',
		})
		expect(wrong.error).toBeTruthy()
		expect(accounts()[0].loggedOut).toBe(true)
		const ok = await send('relogin', {
			accountId: accounts()[0].id,
			appPassword: 'p2',
		})
		expect(ok.ok).toBe(true)
		expect(accounts()[0]).toMatchObject({ loggedOut: false, appPassword: 'p2' })
		expect((await send('unlock', { masterPassword: 'm' })).ok).toBe(true)
	})

	it('shows the signed-out view in the popup and signs in from there', async () => {
		state.revokedPasswords = ['p']
		await send('vault-sync-now')
		await vi.waitFor(() => expect(accounts()[0].loggedOut).toBe(true))
		const html = readFileSync(
			resolve(__dirname, '../../browser-extension/src/popup/popup.html'),
			'utf8',
		)
		document.body.innerHTML = html
			.replace(/^[\s\S]*<body>/, '')
			.replace(/<\/body>[\s\S]*$/, '')
		globalThis.chrome.runtime.sendMessage = (msg, cb) => {
			const pending = router.handleMessage(msg, POPUP)
			if (pending) pending.then(cb)
		}
		vi.resetModules()
		await import('../../browser-extension/src/popup/popup.js')
		const $ = (id) => document.getElementById(id)
		await vi.waitFor(() => expect($('view-signed-out').hidden).toBe(false))
		expect($('signed-out-text').textContent).toMatch(/revoked or changed/)
		expect($('account-select').textContent).toContain('(signed out)')
		$('relogin-app-password').value = 'p2'
		$('relogin-form').dispatchEvent(new Event('submit', { cancelable: true }))
		await vi.waitFor(() => expect($('view-locked').hidden).toBe(false))
	})
})
