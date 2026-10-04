/**
 * @spec openspec/changes/clients-extension-complete/specs/extension-vault-sync/spec.md
 *
 * The vault snapshot and its sync: one sync at a time, the cheap check, the
 * manifest and its fallback, suite changes, offline reads and writes, and
 * the triggers in the real worker router and popup.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { areaOrMemory } from '../../browser-extension/src/background/generator-handlers.js'
import {
	buildVaultSync,
	SYNC_INTERVAL_MINUTES,
} from '../../browser-extension/src/background/vault-sync.js'
import {
	installChrome,
	installServer,
	makeVault,
	POPUP,
} from './fixtures/fakeBrowser.js'

const ACCOUNT = { id: 'acc-1' }
const ROWS = [
	{ id: 's1', name: 'Mail', updatedAt: '2026-10-02T10:00:00+00:00', key: 'c1' },
	{ id: 's2', name: 'Bank', updatedAt: '2026-10-01T10:00:00+00:00', key: 'c2' },
]

/**
 * A sync on memory storage and a fake API.
 *
 * @param {object} [api] Replace parts of the fake API.
 * @param {object} [extra] Clock and suite overrides.
 * @return {object}
 */
function setup(api = {}, extra = {}) {
	const local = areaOrMemory(null)
	let clock = Date.parse('2026-10-03T08:00:00Z')
	const lock = vi.fn()
	const fakeApi = {
		fetchOfflineManifest: vi.fn(async () => ({
			suite: { id: 'suite-1' },
			secrets: ROWS,
			folders: [{ id: 'f1', name: 'Work' }],
			types: [{ id: 't1', name: 'login' }],
		})),
		latestSecret: vi.fn(async () => ({ items: [ROWS[0]], total: 2 })),
		listSecrets: vi.fn(async () => ROWS),
		listFolders: vi.fn(async () => []),
		listTypes: vi.fn(async () => []),
		fetchActiveSuite: vi.fn(async () => ({ id: 'suite-1' })),
		...api,
	}
	const sync = buildVaultSync({
		api: fakeApi,
		local,
		activeSuiteId: () => extra.suite ?? 'suite-1',
		lock,
		now: () => clock,
	})
	return { sync, api: fakeApi, local, lock, advance: (ms) => (clock += ms) }
}

describe('sync', () => {
	it('stores the snapshot in one write, with the time and the change check', async () => {
		const { sync, local } = setup()
		const set = vi.spyOn(local, 'set')
		const status = await sync.sync(ACCOUNT, { force: true })
		expect(set).toHaveBeenCalledTimes(1)
		const snapshot = await sync.snapshotOf('acc-1')
		expect(snapshot.secrets).toHaveLength(2)
		expect(snapshot.check).toEqual({
			top: '2026-10-02T10:00:00+00:00',
			total: 2,
		})
		expect(status).toMatchObject({
			syncing: false,
			offline: false,
			lastError: null,
		})
		expect(status.syncedAt).toBe('2026-10-03T08:00:00.000Z')
	})

	it('runs one sync at a time per account', async () => {
		const { sync, api } = setup()
		await Promise.all([
			sync.sync(ACCOUNT, { force: true }),
			sync.sync(ACCOUNT, { force: true }),
		])
		expect(api.fetchOfflineManifest).toHaveBeenCalledTimes(1)
	})

	it('costs one cheap request when nothing changed', async () => {
		const { sync, api, advance } = setup()
		await sync.sync(ACCOUNT, { force: true })
		advance(60_000)
		await sync.sync(ACCOUNT)
		expect(api.latestSecret).toHaveBeenCalledTimes(1)
		expect(api.fetchOfflineManifest).toHaveBeenCalledTimes(1)
		api.latestSecret.mockResolvedValue({
			items: [{ updatedAt: '2026-10-03T07:59:00+00:00' }],
			total: 2,
		})
		await sync.sync(ACCOUNT)
		expect(api.fetchOfflineManifest).toHaveBeenCalledTimes(2)
	})

	it('is stale after the interval', async () => {
		const { sync, advance } = setup()
		expect(await sync.isStale('acc-1')).toBe(true)
		await sync.sync(ACCOUNT, { force: true })
		expect(await sync.isStale('acc-1')).toBe(false)
		advance(SYNC_INTERVAL_MINUTES * 60_000)
		expect(await sync.isStale('acc-1')).toBe(true)
	})

	it('falls back to the paged lists when offline caching is off', async () => {
		const { sync, api } = setup({
			fetchOfflineManifest: vi.fn(async () => {
				throw Object.assign(new Error('off'), { status: 428 })
			}),
		})
		await sync.sync(ACCOUNT, { force: true })
		expect(api.listSecrets).toHaveBeenCalled()
		expect((await sync.snapshotOf('acc-1')).secrets).toHaveLength(2)
	})

	it('discards the snapshot and locks when the suite changed', async () => {
		const { sync, lock } = setup({}, { suite: 'old-suite' })
		const status = await sync.sync(ACCOUNT, { force: true })
		expect(lock).toHaveBeenCalledWith('acc-1')
		expect(await sync.snapshotOf('acc-1')).toBeNull()
		expect(status.lastError).toBe('suite-changed')
	})

	it('keeps the snapshot and says offline when the server cannot be reached', async () => {
		const { sync, api } = setup()
		await sync.sync(ACCOUNT, { force: true })
		api.fetchOfflineManifest.mockRejectedValue(new TypeError('Failed to fetch'))
		const status = await sync.sync(ACCOUNT, { force: true })
		expect(status.offline).toBe(true)
		expect((await sync.snapshotOf('acc-1')).secrets).toHaveLength(2)
	})

	it('locks on an authentication failure and keeps going on a migration lock', async () => {
		let { sync, lock } = setup({
			fetchOfflineManifest: vi.fn(async () => {
				throw Object.assign(new Error('x'), { status: 401 })
			}),
		})
		expect((await sync.sync(ACCOUNT, { force: true })).lastError).toBe('auth')
		expect(lock).toHaveBeenCalled()
		;({ sync, lock } = setup({
			fetchOfflineManifest: vi.fn(async () => {
				throw Object.assign(new Error('x'), { status: 423 })
			}),
		}))
		expect((await sync.sync(ACCOUNT, { force: true })).lastError).toBe(
			'locked-for-migration',
		)
		expect(lock).not.toHaveBeenCalled()
	})

	it('forgets an account', async () => {
		const { sync } = setup()
		await sync.sync(ACCOUNT, { force: true })
		await sync.forget('acc-1')
		expect(await sync.snapshotOf('acc-1')).toBeNull()
	})
})

const SERVER = 'https://one.example'

describe('in the worker and the popup', () => {
	let router
	let browser
	let state

	beforeEach(async () => {
		vi.resetModules()
		browser = installChrome({ tabUrl: 'https://example.com/login' })
		const fixture = await makeVault('m', 'x')
		state = { ...fixture, types: [{ id: 't1', name: 'login' }], folders: [] }
		installServer({ [SERVER]: state })
		router = await import('../../browser-extension/src/background/router.js')
		await router.handleMessage(
			{
				type: 'pair',
				payload: { url: SERVER, user: 'ann', appPassword: 'p' },
			},
			POPUP,
		)
	})

	afterEach(async () => {
		await new Promise((r) => setTimeout(r, 300))
		document.body.innerHTML = ''
	})

	it('syncs on unlock, schedules a sync while unlocked and stops on lock', async () => {
		await router.handleMessage(
			{ type: 'unlock', payload: { masterPassword: 'm' } },
			POPUP,
		)
		await vi.waitFor(async () => {
			const stored = await globalThis.chrome.storage.local.get(null)
			expect(
				Object.keys(stored).some((k) => k.startsWith('vault-snapshot:')),
			).toBe(true)
		})
		expect(browser.alarms.create).toHaveBeenCalledWith(
			expect.stringMatching(/^keepiq-sync:/),
			{ periodInMinutes: SYNC_INTERVAL_MINUTES },
		)
		await router.handleMessage({ type: 'lock', payload: {} }, POPUP)
		expect(browser.alarms.clear).toHaveBeenCalledWith(
			expect.stringMatching(/^keepiq-sync:/),
		)
	})

	it('offers logins from the snapshot while offline, and the Vault tab reads it', async () => {
		await router.handleMessage(
			{ type: 'unlock', payload: { masterPassword: 'm' } },
			POPUP,
		)
		await vi.waitFor(async () => {
			const stored = await globalThis.chrome.storage.local.get(null)
			expect(
				Object.keys(stored).some((k) => k.startsWith('vault-snapshot:')),
			).toBe(true)
		})
		// The server goes away.
		const online = globalThis.fetch
		globalThis.fetch = vi.fn(async () => {
			throw new TypeError('Failed to fetch')
		})
		const offered = await router.handleMessage(
			{ type: 'match', payload: { host: 'example.com' } },
			POPUP,
		)
		expect(offered.map((o) => o.name)).toEqual(['Example'])

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
		await vi.waitFor(() =>
			expect(document.getElementById('view-unlocked').hidden).toBe(false),
		)
		document.getElementById('vault-sync-now').click()
		document.getElementById('tab-vault').click()
		await vi.waitFor(() =>
			expect(document.getElementById('vault-list').textContent).toContain(
				'Example',
			),
		)
		await vi.waitFor(() =>
			expect(document.getElementById('vault-offline').hidden).toBe(false),
		)
		expect(document.getElementById('vault-new').disabled).toBe(true)
		globalThis.fetch = online
	})
})
