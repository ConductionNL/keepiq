/**
 * @spec openspec/specs/extension-lock/spec.md
 *
 * Locking: a changed master password locks, the popup forgets the vault the
 * moment the worker locks, Lock locks the account on screen only, and
 * Disconnect asks first. On the REAL router and popup.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { areaOrMemory } from '../../browser-extension/src/background/generator-handlers.js'
import { buildVaultSync } from '../../browser-extension/src/background/vault-sync.js'
import {
	installChrome,
	installServer,
	makeVault,
	POPUP,
} from './fixtures/fakeBrowser.js'

describe('the unlock-key epoch', () => {
	/**
	 * A sync whose manifest has the given suite epoch, unlocked at epoch 1.
	 *
	 * @param {number} epoch The epoch the server reports.
	 * @return {object}
	 */
	function setup(epoch) {
		const local = areaOrMemory(null)
		const lock = vi.fn()
		const sync = buildVaultSync({
			api: {
				fetchOfflineManifest: vi.fn(async () => ({
					suite: { id: 'suite-1', unlockKeyEpoch: epoch },
					secrets: [],
					folders: [],
					types: [],
				})),
			},
			local,
			activeSuiteId: () => 'suite-1',
			activeSuiteEpoch: () => 1,
			lock,
		})
		return { sync, lock }
	}

	it('locks and drops the snapshot when the master password changed', async () => {
		const { sync, lock } = setup(2)
		const status = await sync.sync({ id: 'a' }, { force: true })
		expect(status.lastError).toBe('master-password-changed')
		expect(lock).toHaveBeenCalledWith('a')
		expect(await sync.snapshotOf('a')).toBeNull()
	})

	it('keeps going when the epoch is unchanged', async () => {
		const { sync, lock } = setup(1)
		const status = await sync.sync({ id: 'a' }, { force: true })
		expect(status.lastError).toBeNull()
		expect(lock).not.toHaveBeenCalled()
		expect(await sync.snapshotOf('a')).not.toBeNull()
	})
})

const ONE = 'https://one.example'
const TWO = 'https://two.example'
let router
let browser
let one
let two

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

/** Open the real popup, its messages going to the router and back. */
async function openPopup() {
	const html = readFileSync(
		resolve(__dirname, '../../browser-extension/src/popup/popup.html'),
		'utf8',
	)
	document.body.innerHTML = html
		.replace(/^[\s\S]*<body>/, '')
		.replace(/<\/body>[\s\S]*$/, '')
	browser.runtime.onMessage.listeners = []
	globalThis.chrome.runtime.onMessage = browser.runtime.onMessage
	globalThis.chrome.runtime.sendMessage = (msg, cb) => {
		// The worker's broadcast reaches the page's listeners.
		if (msg.type === 'keepiq-locked') {
			for (const listener of browser.runtime.onMessage.listeners) listener(msg)
			return Promise.resolve()
		}
		const pending = router.handleMessage(msg, POPUP)
		if (pending) pending.then(cb)
		return undefined
	}
	vi.resetModules()
	await import('../../browser-extension/src/popup/popup.js')
}

beforeEach(async () => {
	vi.resetModules()
	browser = installChrome()
	one = await makeVault('m', 'x')
	two = await makeVault('n', 'y')
	one = {
		...one,
		suite: { ...one.suite, unlockKeyEpoch: 1 },
		types: [{ id: 't1', name: 'login' }],
		folders: [],
	}
	two = { ...two, types: [{ id: 't1', name: 'login' }], folders: [] }
	installServer({ [ONE]: one, [TWO]: two })
	router = await import('../../browser-extension/src/background/router.js')
})

afterEach(async () => {
	await new Promise((r) => setTimeout(r, 300))
	document.body.innerHTML = ''
})

describe('locking', () => {
	it('locks the extension when the server reports a new unlock-key epoch', async () => {
		await send('pair', { url: ONE, user: 'ann', appPassword: 'p' })
		await send('unlock', { masterPassword: 'm' })
		await vi.waitFor(async () =>
			expect((await send('get-state')).unlocked).toBe(true),
		)
		one.suite.unlockKeyEpoch = 2
		const status = await send('vault-sync-now')
		expect(status.lastError).toBe('master-password-changed')
		expect((await send('get-state')).unlocked).toBe(false)
	})

	it('drops the open item from the popup the moment the worker locks', async () => {
		await send('pair', { url: ONE, user: 'ann', appPassword: 'p' })
		await send('unlock', { masterPassword: 'm' })
		await openPopup()
		await vi.waitFor(() => expect($('view-unlocked').hidden).toBe(false))
		$('tab-vault').click()
		await vi.waitFor(() =>
			expect($('vault-list').querySelector('button')).toBeTruthy(),
		)
		$('vault-list').querySelector('button').click()
		await vi.waitFor(() => expect($('vault-detail').hidden).toBe(false))
		await vi.waitFor(() =>
			expect($('detail-sections').textContent.length).toBeGreaterThan(0),
		)
		// The idle timer fires in the worker.
		await send('lock', { accountId: (await send('get-state')).activeAccountId })
		await vi.waitFor(() => expect($('view-locked').hidden).toBe(false))
		expect($('detail-sections').children).toHaveLength(0)
		expect($('vault-list').children).toHaveLength(0)
		expect($('view-unlocked').hidden).toBe(true)
	})

	it('locks only the account on screen with the Lock button', async () => {
		await send('pair', { url: ONE, user: 'ann', appPassword: 'p' })
		await send('unlock', { masterPassword: 'm' })
		await send('pair', { url: TWO, user: 'bob', appPassword: 'q' })
		await send('unlock', { masterPassword: 'n' })
		await openPopup()
		await vi.waitFor(() => expect($('view-unlocked').hidden).toBe(false))
		$('lock-btn').click()
		await vi.waitFor(() => expect($('view-locked').hidden).toBe(false))
		const view = await send('get-state')
		const byUser = Object.fromEntries(
			view.accounts.map((a) => [a.user, a.unlocked]),
		)
		expect(byUser).toEqual({ ann: true, bob: false })
	})

	it('asks before disconnecting, and does nothing when the answer is no', async () => {
		await send('pair', { url: ONE, user: 'ann', appPassword: 'p' })
		await openPopup()
		await vi.waitFor(() => expect($('view-locked').hidden).toBe(false))
		window.confirm = vi.fn(() => false)
		$('unlock-unpair').click()
		await new Promise((r) => setTimeout(r, 100))
		expect(window.confirm).toHaveBeenCalledWith(
			expect.stringMatching(
				/^Disconnect ann@one\.example\? Its app password is deleted in Nextcloud/,
			),
		)
		expect((await send('get-state')).paired).toBe(true)
		window.confirm = vi.fn(() => true)
		$('unlock-unpair').click()
		await vi.waitFor(async () =>
			expect((await send('get-state')).paired).toBe(false),
		)
	})
})
