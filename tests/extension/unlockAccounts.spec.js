/**
 * @spec openspec/changes/clients-extension-gaps/specs/extension-unlock-and-accounts/spec.md
 *
 * Unlocking and accounts on the REAL router and popup: clear messages,
 * offline unlock from the snapshot, show and hide, log out of one account
 * or all, lock all, and initials in the account bar.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	installChrome,
	installServer,
	makeVault,
	POPUP,
} from './fixtures/fakeBrowser.js'

const ONE = 'https://one.example'
const TWO = 'https://two.example'
let router
let browser
let server

/**
 * Send a message to the router as the popup.
 *
 * @param {string} type The type.
 * @param {object} [payload] The payload.
 * @return {Promise<object>}
 */
const send = (type, payload = {}) => router.handleMessage({ type, payload }, POPUP)

/**
 * Element by id.
 *
 * @param {string} id The id.
 * @return {HTMLElement}
 */
const $ = (id) => document.getElementById(id)

/** Open the real popup. */
async function openPopup() {
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
}

beforeEach(async () => {
	vi.resetModules()
	browser = installChrome()
	const one = await makeVault('m', 'x')
	const two = await makeVault('n', 'y')
	server = installServer({
		[ONE]: { ...one, types: [{ id: 't1', name: 'login' }], folders: [] },
		[TWO]: { ...two, types: [{ id: 't1', name: 'login' }], folders: [] },
	})
	router = await import('../../browser-extension/src/background/router.js')
})

afterEach(async () => {
	await new Promise((r) => setTimeout(r, 300))
	document.body.innerHTML = ''
})

describe('messages', () => {
	it('says the master password is wrong, not what the crypto library said', async () => {
		await send('pair', { url: ONE, user: 'ann', appPassword: 'p' })
		const res = await send('unlock', { masterPassword: 'wrong' })
		expect(res.error).toBe('Invalid master password')
	})

	it.each([
		[401, 'Nextcloud did not accept this user name and app password.'],
		[403, 'This Nextcloud account may not use Keepiq.'],
		[404, 'Keepiq is not installed on this server, or the address is wrong.'],
		[502, 'The server could not answer. Try again later.'],
		[undefined, 'Cannot reach this server. Check the address.'],
	])('explains a failed pairing (%s)', (status, message) => {
		expect(router.pairingProblem({ status })).toBe(message)
	})

	it('explains a server that does not run Keepiq when pairing', async () => {
		const res = await send('pair', {
			url: 'https://elsewhere.example',
			user: 'ann',
			appPassword: 'p',
		})
		expect(res.error).toBe(
			'Keepiq is not installed on this server, or the address is wrong.',
		)
	})
})

describe('offline unlock', () => {
	it('unlocks from the vault snapshot when the server cannot be reached', async () => {
		await send('pair', { url: ONE, user: 'ann', appPassword: 'p' })
		await send('unlock', { masterPassword: 'm' })
		await vi.waitFor(() =>
			expect(
				[...browser.storage.keys()].some((k) =>
					k.startsWith('vault-snapshot:'),
				),
			).toBe(true),
		)
		await send('lock', {})
		const online = globalThis.fetch
		globalThis.fetch = vi.fn(async () => {
			throw new TypeError('Failed to fetch')
		})
		const res = await send('unlock', { masterPassword: 'm' })
		expect(res).toMatchObject({ ok: true, offline: true })
		expect((await send('get-state')).unlocked).toBe(true)
		const wrong = await send('lock', {}).then(() =>
			send('unlock', { masterPassword: 'wrong' }),
		)
		expect(wrong.error).toBe('Invalid master password')
		globalThis.fetch = online
	})

	it('cannot unlock offline without a snapshot', async () => {
		await send('pair', { url: ONE, user: 'ann', appPassword: 'p' })
		globalThis.fetch = vi.fn(async () => {
			throw new TypeError('Failed to fetch')
		})
		const res = await send('unlock', { masterPassword: 'm' })
		expect(res.error).toBeTruthy()
		expect((await send('get-state')).unlocked).toBe(false)
	})
})

describe('log out and lock', () => {
	beforeEach(async () => {
		await send('pair', { url: ONE, user: 'ann', appPassword: 'p' })
		await send('unlock', { masterPassword: 'm' })
		await send('pair', { url: TWO, user: 'bob', appPassword: 'q' })
		await send('unlock', { masterPassword: 'n' })
	})

	it('logs out of one account: its app password is deleted in Nextcloud and the account kept', async () => {
		const view = await send('get-state')
		await send('logout', { accountId: view.activeAccountId })
		const after = await send('get-state')
		expect(after).toMatchObject({ loggedOut: true, loggedOutReason: 'logout' })
		expect(after.accounts.map((a) => [a.user, a.loggedOut])).toEqual([
			['ann', false],
			['bob', true],
		])
		const revokes = server.calls.filter(
			(c) =>
				c.method === 'DELETE'
				&& c.url.endsWith('/ocs/v2.php/core/apppassword'),
		)
		expect(revokes.map((c) => c.url.startsWith(TWO))).toEqual([true])
	})

	it('logs out of all accounts', async () => {
		await send('logout', { all: true })
		const after = await send('get-state')
		expect(after.accounts.every((a) => a.loggedOut)).toBe(true)
	})

	it('locks all accounts, logs out from Settings after asking, and shows why', async () => {
		await openPopup()
		await vi.waitFor(() => expect($('view-unlocked').hidden).toBe(false))
		expect($('account-initials').textContent).toBe('BO')
		$('tab-settings').click()
		await vi.waitFor(() => expect($('view-settings').hidden).toBe(false))
		$('settings-lock-all').click()
		await vi.waitFor(async () =>
			expect((await send('get-state')).accounts.some((a) => a.unlocked)).toBe(
				false,
			),
		)
		await vi.waitFor(() => expect($('view-locked').hidden).toBe(false))
		$('tab-settings').click()
		window.confirm = vi.fn(() => true)
		$('settings-logout').click()
		await vi.waitFor(() => expect($('view-signed-out').hidden).toBe(false))
		expect(window.confirm.mock.calls[0][0]).toMatch(/^Log out of this account\?/)
		expect($('signed-out-text').textContent).toMatch(/^You logged out/)
	})
})

describe('the unlock screen', () => {
	it('focuses the master password, shows and hides it, and unlocks on Enter', async () => {
		await send('pair', { url: ONE, user: 'ann', appPassword: 'p' })
		await openPopup()
		await vi.waitFor(() => expect($('view-locked').hidden).toBe(false))
		expect(document.activeElement).toBe($('unlock-master'))
		$('unlock-show').click()
		expect($('unlock-master').type).toBe('text')
		expect($('unlock-show').getAttribute('aria-pressed')).toBe('true')
		$('unlock-show').click()
		expect($('unlock-master').type).toBe('password')
		$('unlock-master').value = 'm'
		$('unlock-master').dispatchEvent(
			new KeyboardEvent('keydown', { key: 'Enter' }),
		)
		await vi.waitFor(() => expect($('view-unlocked').hidden).toBe(false))
	})
})
