/**
 * @spec openspec/specs/extension-account-switching/spec.md
 * @spec openspec/specs/browser-extension-autofill/spec.md#requirement-user-chosen-idle-lock-period-with-an-administrator-maximum
 *
 * Several accounts in one extension (keepiq#784), driven through the worker's
 * REAL message router with real crypto, real storage code and a fake server:
 * the old single pairing becomes the first account, a sixth pairing is
 * refused, each account locks on its own timer, a fill is refused unless it
 * comes from the active account's own match, and a page cannot ask the worker
 * for anything but the four content-script messages.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	installChrome,
	installServer,
	makeVault,
	pageSender,
	POPUP,
} from './fixtures/fakeBrowser.js'

const WORK = 'https://cloud.work.example'
const HOME = 'https://cloud.home.example'

let browser
let server
let router
let api
let vault
let vaults

/**
 * Send one message through the worker's router.
 *
 * @param {string} type The message type.
 * @param {object} payload The payload.
 * @param {object} sender The sender record (the popup by default).
 * @return {Promise<object>} The answer.
 */
function send(type, payload = {}, sender = POPUP) {
	return router.handleMessage({ type, payload }, sender)
}

beforeEach(async () => {
	vi.resetModules()
	browser = installChrome()
	if (!vaults) {
		vaults = {
			[WORK]: await makeVault('work-master', 'work'),
			[HOME]: await makeVault('home-master', 'home'),
		}
	}
	server = installServer({
		[WORK]: { ...vaults[WORK], maxIdleMinutes: 240 },
		[HOME]: { ...vaults[HOME], maxIdleMinutes: 240 },
	})
	api = await import('../../browser-extension/src/lib/api.js')
	vault = await import('../../browser-extension/src/lib/vault.js')
	router = await import('../../browser-extension/src/background/router.js')
})

afterEach(() => {
	vi.useRealTimers()
	vault.lockAll()
})

async function pairBoth() {
	await send('pair', { url: WORK, user: 'alice', appPassword: 'a1' })
	await send('pair', { url: HOME, user: 'alice', appPassword: 'a2' })
	const state = await send('get-state')
	const [work, home] = state.accounts
	return { work, home }
}

describe('account storage', () => {
	it('upgrades the old single pairing into the first account and removes the old key', async () => {
		browser.storage.set('keepiq.config', {
			url: WORK,
			user: 'alice',
			appPassword: 'a1',
		})
		await api.migrateLegacyConfig()

		expect(browser.storage.has('keepiq.config')).toBe(false)
		const accounts = await api.loadAccounts()
		expect(accounts).toHaveLength(1)
		expect(accounts[0]).toMatchObject({
			url: WORK,
			user: 'alice',
			appPassword: 'a1',
			idleMinutes: 15,
		})
		expect(await api.activeAccountId()).toBe(accounts[0].id)
	})

	it('refuses a sixth pairing', async () => {
		server = installServer(
			Object.fromEntries(
				[1, 2, 3, 4, 5, 6].map((i) => ['https://cloud' + i + '.example', vaults[WORK]]),
			),
		)
		for (let i = 1; i <= 5; i++) {
			const res = await send('pair', {
				url: 'https://cloud' + i + '.example',
				user: 'u',
				appPassword: 'p',
			})
			expect(res.ok).toBe(true)
		}
		const sixth = await send('pair', {
			url: 'https://cloud6.example',
			user: 'u',
			appPassword: 'p',
		})
		expect(sixth.error).toContain('up to 5 accounts')
		expect(await api.loadAccounts()).toHaveLength(5)
		expect(
			server.calls.some((c) => c.url.startsWith('https://cloud6.example')),
		).toBe(false)
	})

	it('pairs a second server next to the first and keeps the first one unlocked', async () => {
		await send('pair', { url: WORK, user: 'alice', appPassword: 'a1' })
		await send('unlock', { masterPassword: 'work-master' })
		await send('pair', { url: HOME, user: 'alice', appPassword: 'a2' })

		const state = await send('get-state')
		expect(state.accounts.map((a) => a.host)).toEqual([
			'cloud.work.example',
			'cloud.home.example',
		])
		expect(state.accounts[0].unlocked).toBe(true)
		expect(state.accounts[1].unlocked).toBe(false)
	})
})

describe('per-account lock state and idle timers', () => {
	it('locks only the account whose timer expired; an OS lock locks both', async () => {
		const { work, home } = await pairBoth()
		await send('switch-account', { accountId: work.id })
		expect((await send('unlock', { masterPassword: 'work-master' })).ok).toBe(true)
		await send('switch-account', { accountId: home.id })
		expect((await send('unlock', { masterPassword: 'home-master' })).ok).toBe(true)

		vi.useFakeTimers()
		await send('set-idle', { accountId: work.id, idleMinutes: 5 })
		await send('set-idle', { accountId: home.id, idleMinutes: 60 })
		vi.advanceTimersByTime(5 * 60 * 1000)

		expect(vault.isUnlocked(work.id)).toBe(false)
		expect(vault.isUnlocked(home.id)).toBe(true)

		router.onIdleState('locked')
		expect(vault.unlockedAccounts()).toEqual([])
	})

	it('uses the administrator maximum when it is lower than the choice', async () => {
		server = installServer({ [WORK]: { ...vaults[WORK], maxIdleMinutes: 30 } })
		await send('pair', { url: WORK, user: 'alice', appPassword: 'a1' })
		await send('unlock', { masterPassword: 'work-master' })
		const { activeAccountId } = await send('get-state')

		vi.useFakeTimers()
		const res = await send('set-idle', { accountId: activeAccountId, idleMinutes: 240 })
		expect(res.effectiveIdleMinutes).toBe(30)
		const stored = await api.loadAccount(activeAccountId)
		expect(stored.idleMinutes).toBe(240)
		expect((await send('get-state')).maxIdleMinutes).toBe(30)

		vi.advanceTimersByTime(30 * 60 * 1000 - 1)
		expect(vault.isUnlocked(activeAccountId)).toBe(true)
		vi.advanceTimersByTime(1)
		expect(vault.isUnlocked(activeAccountId)).toBe(false)
	})

	it('refuses an idle delay that is not offered', async () => {
		await send('pair', { url: WORK, user: 'alice', appPassword: 'a1' })
		const res = await send('set-idle', { idleMinutes: 0 })
		expect(res.error).toBeTruthy()
		expect((await send('get-state')).idleMinutes).toBe(15)
	})
})

describe('matching and filling use the active account only', () => {
	async function bothUnlocked() {
		const accounts = await pairBoth()
		await send('switch-account', { accountId: accounts.work.id })
		await send('unlock', { masterPassword: 'work-master' })
		await send('switch-account', { accountId: accounts.home.id })
		await send('unlock', { masterPassword: 'home-master' })
		return accounts
	}

	it('switching the active account changes the candidates', async () => {
		const { work, home } = await bothUnlocked()
		const homeCandidates = await send('match', { host: 'example.com' })
		expect(homeCandidates.map((c) => c.id)).toEqual(['home-s1'])
		expect(homeCandidates[0].accountId).toBe(home.id)

		await send('switch-account', { accountId: work.id })
		const workCandidates = await send('match', { host: 'example.com' })
		expect(workCandidates.map((c) => c.id)).toEqual(['work-s1'])
	})

	it('fills a login from the active account match', async () => {
		const { home } = await bothUnlocked()
		await send('match', { host: 'example.com' })
		const res = await send('fill', { id: 'home-s1', accountId: home.id })
		expect(res.filled).toBe(true)
		expect(browser.filled[0]).toEqual({
			type: 'fill-credential',
			payload: { login: 'home-user', secret: 'home-password' },
		})
	})

	it('refuses a fill for a match of account A while account B is active, and decrypts nothing', async () => {
		const { work, home } = await bothUnlocked()
		await send('switch-account', { accountId: work.id })
		await send('match', { host: 'example.com' })
		await send('switch-account', { accountId: home.id })
		const decrypt = vi.spyOn(vault, 'decryptSecret')

		const named = await send('fill', { id: 'work-s1', accountId: work.id })
		expect(named.error).toContain('another account')
		const relabelled = await send('fill', { id: 'work-s1', accountId: home.id })
		expect(relabelled.error).toContain('not offered')

		expect(browser.filled).toEqual([])
		expect(decrypt).not.toHaveBeenCalled()
	})

	it('refuses a fill for an id that no match offered, without fetching it', async () => {
		const { home } = await bothUnlocked()
		await send('match', { host: 'example.com' })
		const res = await send('fill', { id: 'some-other-secret', accountId: home.id })
		expect(res.error).toContain('not offered')
		expect(server.calls.some((c) => c.url.includes('/api/v1/secrets/'))).toBe(false)
		expect(browser.filled).toEqual([])
	})

	it('refuses a fill when the tab moved to another site after the match', async () => {
		const { home } = await bothUnlocked()
		await send('match', { host: 'example.com' })
		browser.setTab('https://evil.example/login')
		const res = await send('fill', { id: 'home-s1', accountId: home.id })
		expect(res.error).toContain('page changed')
		expect(browser.filled).toEqual([])
	})

	it('saves a captured login to the account that was active at submit', async () => {
		const { home } = await bothUnlocked()
		const offer = await router.doCapture({
			host: 'new.example',
			url: 'https://new.example',
			login: 'alice',
			secret: 'correct horse battery staple violin 7%Q',
		})
		expect(offer.action).toBe('save')
		const pending = await send('pending-capture')
		expect(pending.capture.account).toBe('alice@cloud.home.example')
		expect(pending.capture.accountId).toBe(home.id)

		await send('capture-decision', { choice: 'save' }, pageSender('https://new.example/'))
		const posts = server.calls.filter(
			(c) => c.method === 'POST' && c.url.endsWith('/api/v1/secrets'),
		)
		expect(posts).toHaveLength(1)
		expect(posts[0].url.startsWith(HOME)).toBe(true)
		expect(posts[0].body.encryptionSuiteId).toBe('home-suite')
	})
})

describe('a web page cannot use the popup messages', () => {
	it.each([
		['get-state', {}],
		['match', { host: 'example.com' }],
		['fill', { id: 'home-s1' }],
		['unlock', { masterPassword: 'x' }],
		['unlock-raw', { accountId: 'a', rawKey: [] }],
		['save-capture', { host: 'x', secret: 'y' }],
		['pending-capture', {}],
		['totp-for-host', { host: 'example.com' }],
		['switch-account', { accountId: 'a' }],
		['set-idle', { idleMinutes: 240 }],
		['pair', { url: 'https://attacker.example', user: 'x', appPassword: 'y' }],
		['unpair', {}],
		['lock', {}],
		['biometric-options', { accountId: 'a' }],
		['biometric-enrol-context', { accountId: 'a' }],
		['biometric-enrol', { accountId: 'a', body: {} }],
	])('refuses %s from a content script', async (type, payload) => {
		await send('pair', { url: WORK, user: 'alice', appPassword: 'a1' })
		await send('unlock', { masterPassword: 'work-master' })
		await send('match', { host: 'example.com' })
		const before = server.calls.length

		const res = await send(type, payload, pageSender('https://example.com/'))

		expect(res).toEqual({ error: 'not allowed from a web page' })
		expect(server.calls.length).toBe(before)
		expect(browser.filled).toEqual([])
		expect(await api.loadAccounts()).toHaveLength(1)
	})

	it('refuses a message from another extension', async () => {
		const res = await send('get-state', {}, { ...POPUP, id: 'someone-else' })
		expect(res.error).toBe('not allowed from a web page')
	})

	it('still accepts the content-script messages from a page', async () => {
		await send('pair', { url: WORK, user: 'alice', appPassword: 'a1' })
		const res = await send(
			'capture-decision',
			{ choice: 'dismiss' },
			pageSender('https://example.com/'),
		)
		expect(res).toEqual({ ok: false })
	})
})
