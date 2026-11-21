import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import type { OpenSecretRow } from '@/src/api/types'
import { addAccount, markLoggedOut, setActive } from '@/src/accounts/store'
import { broadcast } from '@/src/background/broadcast'
import { pemToPkcs8, rsaDecrypt, toBase64 } from '@/src/crypto'
import { fromBase64Url, toBase64Url } from '@/src/crypto/base64'
import { lastUsedKey } from '@/src/last-used'
import { inlineHeight, type BackgroundToContent, type FormConfirmation, type InlineListToBackground, type InlineStateReply, type PickInput } from '@/src/messages'
import { ceremonyKey, endForAccountChange, handlePasskeyContent, handlePasskeyWindow, registerPasskeyCeremonies, type Ceremony } from '@/src/passkey/ceremony'
import { forgetOfferedPasskeys } from '@/src/passkey/offered'
import { setLocale } from '@/src/testing/i18n'
import { blockedRow, encrypt, secretRow, typeRows } from '@/src/testing/vault'
import { envelope, suiteRow } from '@/src/testing/vectors'
import { getKey, hasKey, putKey, unlockedAtKey } from '@/src/vault/key-store'
import { readSnapshot, writeSnapshot } from '@/src/vault/store'
import type { StoredSecretRow } from '@/src/vault/types'
import { lock, unlock } from '@/src/vault/unlock'
import { savePasskeyCounter } from '@/src/vault/write'
import { forgetTab, recordFrame } from './frames'
import { closeAllSessions, closedByHost, handleInlineList, inlineSessionKey, openSession, registerInlineSessions } from './inline-session'

const OPEN = { kind: 'inline.open', mode: 'login' } as const

vi.mock('@/src/crypto', async (importOriginal) => {
	const actual = await importOriginal<typeof import('@/src/crypto')>()
	return { ...actual, rsaDecrypt: vi.fn(actual.rsaDecrypt) }
})
vi.mock('@/src/vault/unlock', async (importOriginal) => ({ ...await importOriginal<typeof import('@/src/vault/unlock')>(), unlock: vi.fn() }))
vi.mock('@/src/vault/key-store', async (importOriginal) => {
	const actual = await importOriginal<typeof import('@/src/vault/key-store')>()
	return { ...actual, getKey: vi.fn(actual.getKey) }
})
vi.mock('@/src/vault/store', async (importOriginal) => {
	const actual = await importOriginal<typeof import('@/src/vault/store')>()
	return { ...actual, readSnapshot: vi.fn(actual.readSnapshot) }
})
vi.mock('@/src/vault/write', async (importOriginal) => {
	const actual = await importOriginal<typeof import('@/src/vault/write')>()
	return { ...actual, savePasskeyCounter: vi.fn(actual.savePasskeyCounter) }
})

const TAB = 7
const LIST_FRAME = 9
let accountId: string
let pageUrl: string
let visible: boolean
let sent: Array<{ tabId: number; message: BackgroundToContent; frameId: number | undefined }>

const content = (overrides: object = {}) => ({ id: browser.runtime.id, url: pageUrl, tab: { id: TAB }, frameId: 0, ...overrides }) as never
const listPage = (overrides: object = {}) => ({ id: browser.runtime.id, url: browser.runtime.getURL('/inline-list.html'), tab: { id: TAB }, frameId: LIST_FRAME, ...overrides }) as never
const input: PickInput = { kind: 'pointer', at: 1, x: 20, y: 20 }

async function vault(secrets: StoredSecretRow[]) {
	const syncedAt = new Date().toISOString()
	await writeSnapshot(accountId, {
		suite: { id: suiteRow.id, unlockKeyEpoch: 1 }, secrets, folders: [], types: typeRows, syncedAt, listsSyncedAt: syncedAt, newestUpdatedAt: null, total: secrets.length,
	})
}

async function login(overrides: Partial<OpenSecretRow>): Promise<OpenSecretRow> {
	return secretRow({ url: 'https://example.test/', login: await encrypt(`${overrides.id}-user`), key: await encrypt('hunter2'), ...overrides })
}

async function twoLogins() {
	await vault([
		await login({ id: 'work', name: 'Work', login: await encrypt('alice@work.test') }),
		await login({ id: 'personal', name: 'Personal', login: await encrypt('alice') }),
	])
	await browser.storage.local.set({ [lastUsedKey(accountId)]: { personal: 5 } })
}

const list = (message: InlineListToBackground, sender = listPage()) => handleInlineList(message, sender)

/** Opens a list on the top frame and lets the list page load its state. */
async function openWithState(sender = content()) {
	const { key } = (await openSession(OPEN, sender))!
	const state = await list({ kind: 'inline.state', key })
	return { key, state }
}

const stored = async () => (await browser.storage.session.get(inlineSessionKey(TAB)))[inlineSessionKey(TAB)] as string | undefined
const kinds = () => sent.map(({ message, frameId }) => [message.kind, frameId])
const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

beforeEach(async () => {
	fakeBrowser.reset()
	vi.mocked(rsaDecrypt).mockClear()
	accountId = (await addAccount({
		serverUrl: 'https://cloud.example.org', uid: 'alice', loginName: 'alice', displayName: 'Alice', email: null, avatarDataUrl: null, appPassword: 'pw', keepiqVersion: '0.3.12',
	}, { ...suiteRow })).id
	await putKey(accountId, toBase64(pemToPkcs8(envelope.privateKeyPem)))
	pageUrl = 'https://example.test/login'
	visible = true
	sent = []
	vi.spyOn(browser.tabs, 'get').mockImplementation(async (id: number) => ({ id, url: pageUrl }) as never)
	vi.spyOn(browser.tabs, 'query').mockImplementation(async () => [{ id: TAB, url: pageUrl, active: true }] as never)
	vi.spyOn(browser.tabs, 'sendMessage').mockImplementation(async (tabId: number, message: unknown, options?: { frameId?: number }) => {
		sent.push({ tabId, message: structuredClone(message) as BackgroundToContent, frameId: options?.frameId })
		const m = message as BackgroundToContent
		if (m.kind === 'inline.verify') return visible as never
		if (m.kind === 'fill.execute') return { filled: 'both' } as never
		return undefined as never
	})
	registerInlineSessions()
})

describe('inline.open', () => {
	it('answers a random 128-bit key and keeps a session without plaintext in storage.session', async () => {
		const first = await openSession(OPEN, content())
		expect(first?.key).toMatch(/^[0-9a-f]{32}$/)
		expect(JSON.parse((await stored())!)).toEqual({
			key: first!.key, accountId, frameId: 0, frameUrl: pageUrl, listFrameId: null, mode: 'login', itemIds: [], openedAt: expect.any(Number),
		})
		expect((await openSession(OPEN, content()))!.key).not.toBe(first!.key)
	})

	it('opens on a locked vault at one row, the locked message, reading nothing from the vault', async () => {
		await twoLogins()
		await lock(accountId)
		expect(await openSession(OPEN, content())).toEqual({ key: expect.any(String), height: inlineHeight(1) })
	})

	it.each([1, 3, 5, 7])('sizes the list for %i matching logins, decrypting nothing', async (count) => {
		await vault(await Promise.all(Array.from({ length: count }, (_, i) => login({ id: `item${i}`, name: `Item ${i}` }))))
		vi.mocked(rsaDecrypt).mockClear()
		expect(await openSession(OPEN, content())).toEqual({ key: expect.any(String), height: inlineHeight(count) })
		expect(rsaDecrypt).not.toHaveBeenCalled()
	})

	it('sizes the no-match message as one row', async () => {
		await twoLogins()
		pageUrl = 'https://nothing.test/login'
		expect(await openSession(OPEN, content())).toEqual({ key: expect.any(String), height: inlineHeight(1) })
	})

	it('opens nothing without a logged-in account', async () => {
		await markLoggedOut(accountId)
		expect(await openSession(OPEN, content())).toBeNull()
		expect(await stored()).toBeUndefined()
	})

	it('follows the inlineSuggestions setting on every open', async () => {
		await browser.storage.local.set({ [`settings.${accountId}`]: { inlineSuggestions: false } })
		expect(await openSession(OPEN, content())).toBeNull()
		await browser.storage.local.set({ [`settings.${accountId}`]: { inlineSuggestions: true } })
		expect(await openSession(OPEN, content())).not.toBeNull()
	})

	it('opens on an excluded domain', async () => {
		await browser.storage.local.set({ [`settings.${accountId}`]: { excludedDomains: ['example.test'] } })
		expect(await openSession(OPEN, content())).not.toBeNull()
	})

	it.each(['file:///home/alice/login.html', 'chrome-extension://abc/page.html', 'about:blank'])('opens nothing for a frame on %s', async (url) => {
		expect(await openSession(OPEN, content({ url }))).toBeNull()
	})

	it('opens nothing for a sender without a tab or frame', async () => {
		expect(await openSession(OPEN, content({ tab: undefined }))).toBeNull()
		expect(await openSession(OPEN, content({ frameId: undefined }))).toBeNull()
	})
})

describe('inline.state', () => {
	it('sends inline.ready to the host frame, then lists the matching logins last used first with decrypted usernames', async () => {
		await twoLogins()
		const { key, state } = await openWithState()
		expect(state).toEqual({
			state: 'unlocked', mode: 'login', host: 'example.test', theme: 'system',
			rows: [{ id: 'personal', name: 'Personal', username: 'alice' }, { id: 'work', name: 'Work', username: 'alice@work.test' }],
		})
		expect(sent).toEqual([{ tabId: TAB, message: { kind: 'inline.ready', key }, frameId: 0 }])
		expect(JSON.parse((await stored())!)).toMatchObject({ listFrameId: LIST_FRAME, itemIds: ['personal', 'work'] })
	})

	it('shows a login without a username by name only, and lists use-only copies', async () => {
		await vault([await login({ id: 'a', name: 'No user', login: null }), await login({ id: 'b', name: 'Shared', useOnly: true })])
		const { state } = await openWithState()
		expect(state).toMatchObject({ rows: [{ id: 'a', name: 'No user', username: null }, { id: 'b', name: 'Shared', username: 'b-user' }] })
	})

	it('lists only logins matching the frame’s own URL', async () => {
		await vault([await login({ id: 'top', name: 'Top' }), await login({ id: 'ads', name: 'Ads', url: 'https://ads.other.test/' })])
		const { state } = await openWithState(content({ frameId: 3, url: 'https://ads.other.test/frame' }))
		expect(state).toMatchObject({ host: 'ads.other.test', rows: [{ id: 'ads' }] })
		expect(sent[0]).toMatchObject({ message: { kind: 'inline.ready' }, frameId: 3 })
	})

	it('answers the no-match state with no rows', async () => {
		await vault([])
		pageUrl = 'https://nothing.test/login'
		expect((await openWithState()).state).toEqual({ state: 'unlocked', mode: 'login', host: 'nothing.test', rows: [], theme: 'system' })
	})

	it('answers locked without decrypting anything', async () => {
		await twoLogins()
		await lock(accountId)
		expect((await openWithState()).state).toEqual({ state: 'locked', mode: 'login', host: 'example.test', rows: [], theme: 'system' })
		expect(rsaDecrypt).not.toHaveBeenCalled()
	})

	it('carries the theme setting', async () => {
		await browser.storage.local.set({ 'settings.global': { theme: 'dark' } })
		await vault([])
		expect((await openWithState()).state).toMatchObject({ theme: 'dark' })
	})

	it('refuses a wrong key, another tab, a second list frame and a page other than the list', async () => {
		await twoLogins()
		const { key } = (await openSession(OPEN, content()))!
		expect(await list({ kind: 'inline.state', key: 'f'.repeat(32) })).toBeNull()
		expect(await list({ kind: 'inline.state', key }, listPage({ tab: { id: 8 } }))).toBeNull()
		expect(await list({ kind: 'inline.state', key })).not.toBeNull()
		expect(await list({ kind: 'inline.state', key }, listPage({ frameId: LIST_FRAME + 1 }))).toBeNull()
		// The bound frame keeps working.
		expect(await list({ kind: 'inline.state', key })).not.toBeNull()
		expect(sent.filter(({ message }) => message.kind === 'inline.ready')).toHaveLength(2)
	})

	it('binds the list frame only by inline.state, so a pick first is refused and binds nothing', async () => {
		await twoLogins()
		const { key } = (await openSession(OPEN, content()))!
		expect(await list({ kind: 'inline.pick', key, itemId: 'work', input }, listPage({ frameId: 12 }))).toBeNull()
		expect(await list({ kind: 'inline.state', key })).not.toBeNull()
	})

	it('refuses a session after the active account changed, and closes its list', async () => {
		const other = await addAccount({
			serverUrl: 'https://other.example.org', uid: 'bob', loginName: 'bob', displayName: 'Bob', email: null, avatarDataUrl: null, appPassword: 'pw', keepiqVersion: '0.3.12',
		}, { ...suiteRow })
		await setActive(accountId)
		const { key } = (await openSession(OPEN, content()))!
		await setActive(other.id)
		expect(await list({ kind: 'inline.state', key })).toBeNull()
		expect(await stored()).toBeUndefined()
		expect(kinds()).toEqual([['inline.ready', 0], ['inline.close', 0]])
	})
})

describe('inline.pick', () => {
	it('verifies visibility with the pick’s input, then fills only the list’s frame with the key, and closes the list', async () => {
		await twoLogins()
		const { key } = await openWithState()
		sent = []
		expect(await list({ kind: 'inline.pick', key, itemId: 'work', input })).toEqual({ filled: 'both', code: false })
		expect(sent).toEqual([
			{ tabId: TAB, message: { kind: 'inline.verify', key, input }, frameId: 0 },
			{ tabId: TAB, message: { kind: 'fill.execute', username: 'alice@work.test', password: 'hunter2', code: null, origin: 'https://example.test', anchorKey: key }, frameId: 0 },
			{ tabId: TAB, message: { kind: 'inline.close', key, refocus: true }, frameId: 0 },
		])
		expect((await browser.storage.local.get(lastUsedKey(accountId)))[lastUsedKey(accountId)]).toHaveProperty('work')
		expect(await stored()).toBeUndefined()
	})

	it('fills a subframe’s list in that frame only, even after the worker forgot the frames', async () => {
		await vault([await login({ id: 'i1', name: 'Login', url: 'https://login.example.test/' })])
		const { key } = await openWithState(content({ frameId: 3, url: 'https://login.example.test/frame' }))
		sent = []
		expect(await list({ kind: 'inline.pick', key, itemId: 'i1', input })).toEqual({ filled: 'both', code: false })
		expect(kinds()).toEqual([['inline.verify', 3], ['fill.execute', 3], ['inline.close', 3]])
	})

	it('decrypts nothing when the host does not confirm visibility, keeping the list open to say why', async () => {
		await twoLogins()
		const { key } = await openWithState()
		vi.mocked(rsaDecrypt).mockClear()
		sent = []
		visible = false
		expect(await list({ kind: 'inline.pick', key, itemId: 'work', input })).toEqual({ refused: 'not_visible' })
		expect(rsaDecrypt).not.toHaveBeenCalled()
		expect(sent.map(({ message }) => message)).toEqual([{ kind: 'inline.verify', key, input }])
		expect(await stored()).toBeDefined()
		// The user closes it from the explanation.
		await list({ kind: 'inline.close', key, refocus: true })
		expect(sent.at(-1)!.message).toEqual({ kind: 'inline.close', key, refocus: true })
		expect(await stored()).toBeUndefined()
	})

	it('does not count a refused pick as interaction', async () => {
		await twoLogins()
		const { key } = await openWithState()
		visible = false
		await list({ kind: 'inline.pick', key, itemId: 'work', input })
		expect((await browser.storage.session.get('lastInteractionAt')).lastInteractionAt).toBeUndefined()
	})

	it('treats a host that does not answer as not visible', async () => {
		await twoLogins()
		const { key } = await openWithState()
		vi.mocked(browser.tabs.sendMessage).mockRejectedValueOnce(new Error('no receiver'))
		expect(await list({ kind: 'inline.pick', key, itemId: 'work', input })).toEqual({ refused: 'not_visible' })
	})

	it('refuses an item that was not offered, or no longer matches the frame, as stale', async () => {
		await twoLogins()
		const { key } = await openWithState()
		await vault([await login({ id: 'work', name: 'Work' }), await login({ id: 'other', name: 'Other' })])
		expect(await list({ kind: 'inline.pick', key, itemId: 'other', input })).toEqual({ refused: 'stale' })
		await twoLogins()
		const second = (await openWithState()).key
		await vault([await login({ id: 'personal', name: 'Personal', url: 'https://elsewhere.test/' }), await login({ id: 'work', name: 'Work' })])
		expect(await list({ kind: 'inline.pick', key: second, itemId: 'personal', input })).toEqual({ refused: 'stale' })
		expect(sent.some(({ message }) => message.kind === 'inline.verify' || message.kind === 'fill.execute')).toBe(false)
	})

	it('refuses a pick after the vault locked', async () => {
		await twoLogins()
		const { key } = await openWithState()
		// Locks close every list; a session that somehow outlived one still refuses.
		await browser.storage.session.remove(`privateKeyPkcs8.${accountId}`)
		vi.mocked(rsaDecrypt).mockClear()
		expect(await list({ kind: 'inline.pick', key, itemId: 'work', input })).toEqual({ refused: 'locked' })
		expect(rsaDecrypt).not.toHaveBeenCalled()
	})

	it('asks for an https login on an http frame, keeping the list open, and fills once confirmed', async () => {
		await twoLogins()
		pageUrl = 'http://example.test/login'
		const { key } = await openWithState()
		vi.mocked(rsaDecrypt).mockClear()
		sent = []
		expect(await list({ kind: 'inline.pick', key, itemId: 'work', input })).toEqual({ needsInsecureConfirm: true })
		expect(rsaDecrypt).not.toHaveBeenCalled()
		expect(kinds()).toEqual([['inline.verify', 0]])
		expect(await list({ kind: 'inline.pick', key, itemId: 'work', input, confirmedInsecure: true })).toEqual({ filled: 'both', code: false })
	})

	it('ignores a malformed pick', async () => {
		await twoLogins()
		const { key } = await openWithState()
		for (const bad of [{ kind: 'pointer', at: 'now', x: 0, y: 0 }, { kind: 'mouse', at: 1, x: 0, y: 0 }, null, { kind: 'key', at: 1, x: Number.NaN, y: 0 }]) {
			expect(await list({ kind: 'inline.pick', key, itemId: 'work', input: bad as never })).toBeNull()
		}
		expect(await list({ kind: 'inline.pick', key, itemId: 7 as never, input })).toBeNull()
		expect(sent.filter(({ message }) => message.kind !== 'inline.ready')).toEqual([])
	})

	it('counts a pick as interaction for the timeout, and showing the list not', async () => {
		await twoLogins()
		const lastInteraction = async () => (await browser.storage.session.get('lastInteractionAt')).lastInteractionAt
		const { key } = await openWithState()
		expect(await lastInteraction()).toBeUndefined()
		await list({ kind: 'inline.pick', key, itemId: 'work', input })
		expect(await lastInteraction()).toBeDefined()
	})
})

describe('code mode', () => {
	/** RFC 6238's SHA-1 key; at 59 s its code is `287082`, until 60 s. */
	const SEED = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'
	const CODE = { kind: 'inline.open', mode: 'code' } as const
	const withCode = async (overrides: Partial<OpenSecretRow>) =>
		login({ url: 'https://example.test', login: null, totp: await encrypt(SEED), ...overrides })

	async function openCodes(sender = content()) {
		const { key, height } = (await openSession(CODE, sender))!
		return { key, height, state: await list({ kind: 'inline.state', key }) }
	}

	beforeEach(async () => {
		vi.useFakeTimers({ toFake: ['Date'] })
		vi.setSystemTime(59_000)
		pageUrl = 'https://example.test/2fa'
		await vault([
			await withCode({ id: 'work', name: 'Work', login: await encrypt('alice@work.test') }),
			await withCode({ id: 'personal', name: 'Personal' }),
			await withCode({ id: 'broken', name: 'Broken', totp: await encrypt('not-a-seed') }),
			await withCode({ id: 'shared', name: 'Shared', useOnly: true }),
			await login({ id: 'login', name: 'Login' }),
			secretRow({ id: 'standalone', name: 'Standalone', url: 'https://example.test', typeId: 't-totp', key: await encrypt(SEED) }),
		])
		await browser.storage.local.set({ [lastUsedKey(accountId)]: { personal: 5 } })
	})
	afterEach(() => {
		vi.useRealTimers()
	})

	it('counts the matching logins with a code for the height and lists them with their codes, use-only copies too', async () => {
		const { height, state } = await openCodes()
		expect(height).toBe(inlineHeight(4))
		expect(state).toEqual({
			state: 'unlocked', mode: 'code', host: 'example.test', theme: 'system',
			rows: [
				{ id: 'personal', name: 'Personal', username: null, code: { digits: '287082', period: 30, validUntil: 60_000 } },
				{ id: 'broken', name: 'Broken', username: null, code: { digits: null, period: 30, validUntil: 60_000 } },
				{ id: 'shared', name: 'Shared', username: null, code: { digits: '287082', period: 30, validUntil: 60_000 } },
				{ id: 'work', name: 'Work', username: 'alice@work.test', code: { digits: '287082', period: 30, validUntil: 60_000 } },
			],
		})
		expect(JSON.parse((await stored())!)).toMatchObject({ mode: 'code', itemIds: ['personal', 'broken', 'shared', 'work'] })
		expect(await stored()).not.toContain('287082')
	})

	it('reads an unknown mode as login', async () => {
		const { key } = (await openSession({ kind: 'inline.open', mode: 'codes' } as never, content()))!
		const state = await list({ kind: 'inline.state', key }) as { mode: string; rows: Array<{ id: string; code?: unknown }> }
		expect(state.mode).toBe('login')
		expect(state.rows.map((row) => row.id)).toContain('login')
		expect(state.rows.some((row) => row.code !== undefined || row.id === 'standalone')).toBe(false)
	})

	it('answers a refresh from the bound list frame with the next codes', async () => {
		const { key } = await openCodes()
		vi.setSystemTime(61_000)
		expect(await list({ kind: 'inline.state', key })).toMatchObject({ rows: [{ code: { validUntil: 90_000 } }, {}, {}, {}] })
		expect(await list({ kind: 'inline.state', key }, listPage({ frameId: LIST_FRAME + 1 }))).toBeNull()
	})

	it('opens at one row locked and answers locked without decrypting anything', async () => {
		await lock(accountId)
		vi.mocked(rsaDecrypt).mockClear()
		const { height, state } = await openCodes()
		expect(height).toBe(inlineHeight(1))
		expect(state).toEqual({ state: 'locked', mode: 'code', host: 'example.test', rows: [], theme: 'system' })
		expect(rsaDecrypt).not.toHaveBeenCalled()
	})

	it('fills the code computed at the pick into the list\'s frame only, records last used and closes the list', async () => {
		const { key } = await openCodes()
		sent = []
		vi.mocked(browser.tabs.sendMessage).mockImplementation(async (tabId: number, message: unknown, options?: { frameId?: number }) => {
			sent.push({ tabId, message: structuredClone(message) as BackgroundToContent, frameId: options?.frameId })
			const m = message as BackgroundToContent
			return (m.kind === 'inline.verify' ? true : m.kind === 'fill.execute' ? { filled: 'none', code: true } : undefined) as never
		})
		vi.setSystemTime(61_000)
		expect(await list({ kind: 'inline.pick', key, itemId: 'work', input })).toEqual({ filled: 'none', code: true })
		expect(sent).toEqual([
			{ tabId: TAB, message: { kind: 'inline.verify', key, input }, frameId: 0 },
			{ tabId: TAB, message: { kind: 'fill.execute', username: null, password: null, code: expect.stringMatching(/^\d{6}$/), origin: 'https://example.test', anchorKey: key }, frameId: 0 },
			{ tabId: TAB, message: { kind: 'inline.close', key, refocus: true }, frameId: 0 },
		])
		expect((sent[1]!.message as { code: string }).code).not.toBe('287082')
		expect((await browser.storage.local.get(lastUsedKey(accountId)))[lastUsedKey(accountId)]).toHaveProperty('work')
		expect(await browser.storage.session.get('clipboardCode')).toEqual({})
	})

	it('refuses a login without a code, a standalone item, and a pick while locked, without filling', async () => {
		const { key } = await openCodes()
		expect(await list({ kind: 'inline.pick', key, itemId: 'login', input })).toEqual({ refused: 'stale' })
		const other = (await openCodes()).key
		expect(await list({ kind: 'inline.pick', key: other, itemId: 'standalone', input })).toEqual({ refused: 'stale' })
		const second = (await openCodes()).key
		await browser.storage.session.remove(`privateKeyPkcs8.${accountId}`)
		expect(await list({ kind: 'inline.pick', key: second, itemId: 'work', input })).toEqual({ refused: 'locked' })
		expect(sent.some(({ message }) => message.kind === 'fill.execute')).toBe(false)
	})

	it('refuses as not visible when the host does not confirm, keeping the list open', async () => {
		const { key } = await openCodes()
		visible = false
		expect(await list({ kind: 'inline.pick', key, itemId: 'work', input })).toEqual({ refused: 'not_visible' })
		expect(await stored()).toBeDefined()
	})

	it('asks for an https login on an http frame before filling', async () => {
		pageUrl = 'http://example.test/2fa'
		const { key } = await openCodes()
		sent = []
		expect(await list({ kind: 'inline.pick', key, itemId: 'work', input })).toEqual({ needsInsecureConfirm: true })
		expect(kinds()).toEqual([['inline.verify', 0]])
		await list({ kind: 'inline.pick', key, itemId: 'work', input, confirmedInsecure: true })
		expect(kinds()).toEqual([['inline.verify', 0], ['inline.verify', 0], ['fill.execute', 0], ['inline.close', 0]])
	})
})

describe('card and identity lists', () => {
	const CARD = { kind: 'inline.open', mode: 'card' } as const
	const IDENTITY = { kind: 'inline.open', mode: 'identity' } as const
	const VISA = { cardholder: 'Anna de Vries', number: '4111 1111 1111 1111', expiry: '03/27', cvv: '123', pin: '9876' }
	/** Each subframe's URL, and each frame's roles in its `fill.probe` reply. */
	let frames: Record<number, string>
	let probes: Record<number, string[]>

	const card = async (id: string, name: string, payload: object = VISA, overrides: Partial<OpenSecretRow> = {}) =>
		secretRow({ id, name, url: 'https://bank.test/', typeId: 't-card', key: await encrypt(JSON.stringify(payload)), ...overrides })
	const identity = async (id: string, name: string, payload: object) =>
		secretRow({ id, name, url: null, typeId: 't-identity', key: await encrypt(JSON.stringify(payload)) })
	const cards = (count: number) => Promise.all(Array.from({ length: count }, (_, i) => card(`c${i}`, `Card ${i}`)))
	const forms = () => sent.flatMap(({ frameId, message }) => (message.kind === 'fill.executeForm' ? [[frameId, message.values]] : []))
	const frame = (frameId: number, url: string, roles: string[]) => {
		recordFrame(TAB, frameId, url)
		frames[frameId] = url
		probes[frameId] = roles
	}

	async function openCard() {
		const { key } = (await openSession(CARD, content()))!
		await list({ kind: 'inline.state', key })
		return key
	}
	const pickWork = (key: string, confirmed?: FormConfirmation) => list({ kind: 'inline.pick', key, itemId: 'work', input, ...(confirmed && { confirmed }) })

	beforeEach(() => {
		pageUrl = 'https://shop.test/checkout'
		frames = {}
		probes = { 0: ['number', 'expiry', 'securityCode'] }
		vi.mocked(browser.tabs.sendMessage).mockImplementation(async (tabId: number, message: unknown, options?: { frameId?: number }) => {
			const frameId = options?.frameId ?? 0
			sent.push({ tabId, message: structuredClone(message) as BackgroundToContent, frameId })
			const m = message as BackgroundToContent
			if (m.kind === 'inline.verify') return visible as never
			if (m.kind === 'fill.probe') return { roles: probes[frameId] ?? [], origin: new URL(frames[frameId] ?? pageUrl).origin } as never
			if (m.kind === 'fill.executeForm') return { filled: Object.keys(m.values).length } as never
			return undefined as never
		})
	})

	afterEach(() => forgetTab(TAB))

	it.each([[0, 1], [1, 1], [3, 3], [8, 5]])('opens the card list with %i cards %i rows tall, decrypting nothing', async (count, rows) => {
		await vault(await cards(count))
		vi.mocked(rsaDecrypt).mockClear()
		expect(await openSession(CARD, content())).toEqual({ key: expect.any(String), height: inlineHeight(rows) })
		expect(rsaDecrypt).not.toHaveBeenCalled()
	})

	it.each([[0, 1], [1, 1], [3, 3], [8, 5]])('opens the identity list with %i identities %i rows tall, decrypting nothing', async (count, rows) => {
		await vault(await Promise.all(Array.from({ length: count }, (_, i) => identity(`i${i}`, `Identity ${i}`, { firstName: 'Anna' }))))
		vi.mocked(rsaDecrypt).mockClear()
		expect(await openSession(IDENTITY, content())).toEqual({ key: expect.any(String), height: inlineHeight(rows) })
		expect(rsaDecrypt).not.toHaveBeenCalled()
	})

	it('counts a use-only copy as a row at open, and neither a blocked row nor another kind', async () => {
		await vault([
			await card('work', 'Work Visa'),
			await card('shared', 'Shared', VISA, { useOnly: true }),
			blockedRow({ id: 'gone', typeId: 't-card' }),
			await login({ id: 'site', name: 'Shop', url: 'https://shop.test/' }),
			await identity('me', 'Me', { firstName: 'Anna' }),
		])
		const { key, height } = (await openSession(CARD, content()))!
		expect(height).toBe(inlineHeight(2))
		expect((await list({ kind: 'inline.state', key }) as { rows: unknown[] }).rows).toHaveLength(2)
		expect((await openSession(IDENTITY, content()))!.height).toBe(inlineHeight(1))
	})

	it.each([0, 3])('opens one row tall while locked with %i cards, and answers locked without listing, counting or decrypting', async (count) => {
		await vault(await cards(count))
		await lock(accountId)
		vi.mocked(rsaDecrypt).mockClear()
		vi.mocked(readSnapshot).mockClear()
		const { key, height } = (await openSession(CARD, content()))!
		expect(height).toBe(inlineHeight(1))
		expect(await list({ kind: 'inline.state', key })).toEqual({ state: 'locked', mode: 'card', host: 'shop.test', rows: [], theme: 'system' })
		expect(rsaDecrypt).not.toHaveBeenCalled()
		expect(readSnapshot).not.toHaveBeenCalled()
		expect(JSON.parse((await stored())!).itemIds).toEqual([])
	})

	it('keeps the kind in the session beside the offered ids, without plaintext', async () => {
		await vault([await card('work', 'Work Visa')])
		const key = await openCard()
		// The whole shape, so no member, brand or last four can sit in an extra field.
		expect(JSON.parse((await stored())!)).toEqual({
			key, accountId, frameId: 0, frameUrl: pageUrl, listFrameId: expect.any(Number), mode: 'card', itemIds: ['work'], openedAt: expect.any(Number),
		})
	})

	it('opens each kind only with inline suggestions and its own setting on, whether or not the vault holds any', async () => {
		const opens = async () => [await openSession(CARD, content()) !== null, await openSession(IDENTITY, content()) !== null]
		expect(await opens()).toEqual([true, true])
		await browser.storage.local.set({ [`settings.${accountId}`]: { inlineCards: false } })
		expect(await opens()).toEqual([false, true])
		await browser.storage.local.set({ [`settings.${accountId}`]: { inlineIdentities: false } })
		expect(await opens()).toEqual([true, false])
		await browser.storage.local.set({ [`settings.${accountId}`]: { inlineSuggestions: false } })
		expect(await opens()).toEqual([false, false])
	})

	it('opens nothing in a frame that is not http(s)', async () => {
		pageUrl = 'file:///home/alice/checkout.html'
		expect(await openSession(CARD, content())).toBeNull()
	})

	it('lists every card whatever the site, last used first, by brand and last four; a use-only copy by name, never decrypted', async () => {
		await vault([
			await card('work', 'Work Visa'),
			await card('personal', 'Personal', { number: '5555 5555 5555 4444' }),
			await card('store', 'Store card', { number: '9000 1234' }),
			await card('shared', 'Shared', VISA, { useOnly: true }),
			await card('empty', 'Empty', { cardholder: 'Anna de Vries' }),
			blockedRow({ id: 'gone', typeId: 't-card' }),
			await login({ id: 'site', name: 'Shop', url: 'https://shop.test/' }),
			await identity('me', 'Me', { firstName: 'Anna' }),
		])
		await browser.storage.local.set({ [lastUsedKey(accountId)]: { personal: 5 } })
		vi.mocked(rsaDecrypt).mockClear()
		const { key } = (await openSession(CARD, content()))!
		expect(await list({ kind: 'inline.state', key })).toEqual({
			state: 'unlocked', mode: 'card', host: 'shop.test', theme: 'system', rows: [
				{ id: 'personal', name: 'Personal', username: null, card: { brand: 'Mastercard', last4: '4444' } },
				{ id: 'empty', name: 'Empty', username: null },
				{ id: 'shared', name: 'Shared', username: null },
				{ id: 'store', name: 'Store card', username: null, card: { brand: null, last4: '1234' } },
				{ id: 'work', name: 'Work Visa', username: null, card: { brand: 'Visa', last4: '1111' } },
			],
		})
		expect(rsaDecrypt).toHaveBeenCalledTimes(4)
	})

	it('sends a card no wording, only its brand and last four, and nothing for a number under four digits', async () => {
		setLocale('nl')
		await vault([await card('store', 'Store card', { number: '9000 1234' }), await card('short', 'Short', { number: '123' })])
		const { key } = (await openSession(CARD, content()))!
		const { rows } = (await list({ kind: 'inline.state', key })) as NonNullable<InlineStateReply>
		expect(rows.find((row) => row.id === 'store')).toEqual({ id: 'store', name: 'Store card', username: null, card: { brand: null, last4: '1234' } })
		expect(Object.keys(rows.find((row) => row.id === 'short')!)).toEqual(['id', 'name', 'username'])
	})

	it('lists identities by full name, or by email without a name', async () => {
		await vault([
			await identity('me', 'Me', { firstName: 'Anna', lastName: 'de Vries', email: 'anna@example.test', bsn: '123456782' }),
			await identity('work', 'Work', { email: 'anna@work.test' }),
			await identity('blank', 'Blank', {}),
			await card('work-card', 'Work Visa'),
		])
		const { key } = (await openSession(IDENTITY, content()))!
		expect(await list({ kind: 'inline.state', key })).toMatchObject({ mode: 'identity', rows: [
			{ id: 'blank', name: 'Blank', username: null, detail: null },
			{ id: 'me', name: 'Me', username: null, detail: 'Anna de Vries' },
			{ id: 'work', name: 'Work', username: null, detail: 'anna@work.test' },
		] })
	})

	it('fills a pick into the list’s group, records last used, counts as interaction and closes the list', async () => {
		await vault([await card('work', 'Work Visa')])
		const key = await openCard()
		sent = []
		expect(await pickWork(key)).toEqual({ filled: 'form', code: false })
		expect(kinds()).toEqual([['inline.verify', 0], ['fill.probe', 0], ['fill.executeForm', 0], ['inline.close', 0]])
		expect(sent[1]!.message).toEqual({ kind: 'fill.probe', form: 'card', anchorKey: key })
		expect(sent[2]!.message).toMatchObject({ form: 'card', anchorKey: key, origin: 'https://shop.test' })
		expect(forms()).toEqual([[0, { number: '4111111111111111', expMonth: '03', expYear: '2027', cvv: '123' }]])
		expect(sent[3]!.message).toEqual({ kind: 'inline.close', key, refocus: true })
		expect((await browser.storage.local.get(lastUsedKey(accountId)))[lastUsedKey(accountId)]).toHaveProperty('work')
		expect((await browser.storage.session.get('lastInteractionAt')).lastInteractionAt).toBeDefined()
	})

	it('asks before a card reaches an http page, decrypting nothing and keeping the list open, and fills on the answer for that origin only', async () => {
		pageUrl = 'http://shop.test/checkout'
		await vault([await card('work', 'Work Visa')])
		const key = await openCard()
		vi.mocked(rsaDecrypt).mockClear()
		sent = []
		const asked = { needsFormConfirm: { insecure: true, host: 'shop.test', origin: 'http://shop.test', frameHosts: [] } }
		expect(await pickWork(key)).toEqual(asked)
		expect(rsaDecrypt).not.toHaveBeenCalled()
		expect(kinds()).toEqual([['inline.verify', 0], ['fill.probe', 0]])
		expect(await pickWork(key, { insecure: true, frames: 'all', frameHosts: [], origin: 'https://shop.test' })).toEqual(asked)
		expect(forms()).toEqual([])
		expect(await pickWork(key, { insecure: true, frames: 'all', frameHosts: [], origin: 'http://shop.test' })).toEqual({ filled: 'form', code: false })
	})

	it('asks about a cross-site frame, asks again about one added after the answer, and fills only the page for "Only {host}"', async () => {
		await vault([await card('work', 'Work Visa')])
		probes[0] = ['cardholder']
		frame(3, 'https://js.stripe.com/card', ['number'])
		const key = await openCard()
		const asked = (frameHosts: string[]) => ({ needsFormConfirm: { insecure: false, host: 'shop.test', origin: 'https://shop.test', frameHosts } })
		expect(await pickWork(key)).toEqual(asked(['js.stripe.com']))
		frame(5, 'https://ads.test/frame', ['number'])
		const fillAll: FormConfirmation = { frames: 'all', frameHosts: ['js.stripe.com'], origin: 'https://shop.test' }
		expect(await pickWork(key, fillAll)).toEqual(asked(['js.stripe.com', 'ads.test']))
		expect(forms()).toEqual([])
		expect(await pickWork(key, { ...fillAll, frames: 'sameSite' })).toEqual({ filled: 'form', code: false })
		expect(forms()).toEqual([[0, { cardholder: 'Anna de Vries' }]])
	})

	it('closes the list when the vault locks, after which its pick decrypts nothing', async () => {
		await vault([await card('work', 'Work Visa')])
		const key = await openCard()
		sent = []
		await lock(accountId)
		await vi.waitFor(async () => expect(await stored()).toBeUndefined())
		expect(sent.map(({ message }) => message)).toEqual([{ kind: 'inline.close', key, refocus: false }])
		vi.mocked(rsaDecrypt).mockClear()
		expect(await pickWork(key)).toBeNull()
		expect(rsaDecrypt).not.toHaveBeenCalled()
	})

	it('refuses an item of another kind as stale, filling nothing', async () => {
		await vault([await card('work', 'Work Visa'), await identity('me', 'Me', { firstName: 'Anna' }), await login({ id: 'site', name: 'Shop', url: 'https://shop.test/' })])
		const { key: identities } = (await openSession(IDENTITY, content()))!
		await list({ kind: 'inline.state', key: identities })
		expect(await pickWork(identities)).toEqual({ refused: 'stale' })
		const cardList = await openCard()
		expect(await list({ kind: 'inline.pick', key: cardList, itemId: 'site', input })).toEqual({ refused: 'stale' })
		expect(forms()).toEqual([])
	})
})

describe('the list page’s other messages', () => {
	it.each(['inline.bogus', 'constructor', '__proto__'])('answers %s, a kind it does not know, with nothing and sends nothing', async (kind) => {
		const { key } = await openWithState()
		sent = []
		expect(await list({ kind, key } as unknown as InlineListToBackground)).toBeUndefined()
		expect(sent).toEqual([])
	})

	it('forwards inline.refocus to the host with the key', async () => {
		const { key } = await openWithState()
		sent = []
		expect(await list({ kind: 'inline.refocus', key })).toBeUndefined()
		expect(sent).toEqual([{ tabId: TAB, message: { kind: 'inline.refocus', key }, frameId: 0 }])
	})

	it('closes on inline.close, passing refocus on', async () => {
		const { key } = await openWithState()
		sent = []
		await list({ kind: 'inline.close', key, refocus: true })
		expect(sent.map(({ message }) => message)).toEqual([{ kind: 'inline.close', key, refocus: true }])
		expect(await stored()).toBeUndefined()
		expect(await list({ kind: 'inline.refocus', key })).toBeUndefined()
		expect(sent).toHaveLength(1)
	})

	it('opens the pop-out for inline.unlock and closes the list', async () => {
		await lock(accountId)
		const create = vi.spyOn(browser.windows, 'create').mockResolvedValue({} as never)
		const { key } = await openWithState()
		sent = []
		await list({ kind: 'inline.unlock', key })
		expect(create).toHaveBeenCalledWith(expect.objectContaining({ type: 'popup', url: expect.stringContaining(`tabId=${TAB}`) }))
		expect(sent.map(({ message }) => message)).toEqual([{ kind: 'inline.close', key, refocus: false }])
	})

	it('forwards inline.resize to the host with the key and a height within the list\'s bounds', async () => {
		const { key } = await openWithState()
		sent = []
		expect(await list({ kind: 'inline.resize', key, height: 130.4 })).toBeUndefined()
		await list({ kind: 'inline.resize', key, height: 5000 })
		await list({ kind: 'inline.resize', key, height: '130' as never })
		await list({ kind: 'inline.resize', key, height: Number.NaN })
		expect(sent).toEqual([
			{ tabId: TAB, message: { kind: 'inline.resize', key, height: 131 }, frameId: 0 },
			{ tabId: TAB, message: { kind: 'inline.resize', key, height: inlineHeight(5) }, frameId: 0 },
		])
	})

	it('takes inline.resize only from the bound list frame', async () => {
		const { key } = (await openSession(OPEN, content()))!
		sent = []
		await list({ kind: 'inline.resize', key, height: 130 })
		expect(sent).toEqual([])
		await list({ kind: 'inline.state', key })
		sent = []
		await list({ kind: 'inline.resize', key, height: 130 }, listPage({ frameId: LIST_FRAME + 1 }))
		expect(sent).toEqual([])
	})

	it('ignores every kind with a wrong key', async () => {
		const { key } = await openWithState()
		sent = []
		const wrong = 'f'.repeat(32)
		await list({ kind: 'inline.refocus', key: wrong })
		await list({ kind: 'inline.close', key: wrong, refocus: true })
		await list({ kind: 'inline.unlock', key: wrong })
		await list({ kind: 'inline.resize', key: wrong, height: 130 })
		expect(sent).toEqual([])
		expect(JSON.parse((await stored())!)).toMatchObject({ key })
	})
})

describe('dropping the session', () => {
	it('on inline.closed from the hosting frame with its key only', async () => {
		const { key } = await openWithState()
		await closedByHost({ kind: 'inline.closed', key: 'f'.repeat(32) }, content())
		await closedByHost({ kind: 'inline.closed', key }, content({ frameId: 3 }))
		expect(await stored()).toBeDefined()
		await closedByHost({ kind: 'inline.closed', key }, content())
		expect(await stored()).toBeUndefined()
		// The host closed it itself.
		expect(kinds()).toEqual([['inline.ready', 0]])
	})

	it('on the next inline.open in the tab, closing the earlier list by its key', async () => {
		const first = (await openSession(OPEN, content({ frameId: 3, url: 'https://login.example.test/' })))!.key
		const second = (await openSession(OPEN, content()))!.key
		expect(sent).toEqual([{ tabId: TAB, message: { kind: 'inline.close', key: first, refocus: false }, frameId: 3 }])
		expect(JSON.parse((await stored())!)).toMatchObject({ key: second })
	})

	it('on two opens from different frames at once, closing the first instead of orphaning it', async () => {
		const [first, second] = await Promise.all([
			openSession(OPEN, content({ frameId: 3, url: 'https://login.example.test/' })),
			openSession(OPEN, content()),
		])
		expect(first).not.toBeNull()
		expect(second).not.toBeNull()
		const closed = sent.filter(({ message }) => message.kind === 'inline.close')
		const kept = JSON.parse((await stored())!) as { key: string }
		expect(closed).toHaveLength(1)
		expect([first!.key, second!.key]).toContain(kept.key)
		expect(closed[0]!.message).toMatchObject({ key: kept.key === first!.key ? second!.key : first!.key })
	})

	it('on the next inline.open even when that one opens nothing', async () => {
		await openSession(OPEN, content())
		await browser.storage.local.set({ [`settings.${accountId}`]: { inlineSuggestions: false } })
		expect(await openSession(OPEN, content())).toBeNull()
		expect(await stored()).toBeUndefined()
	})

	it('on tab close, sending nothing', async () => {
		await openSession(OPEN, content())
		await fakeBrowser.tabs.onRemoved.trigger(TAB, { windowId: 1, isWindowClosing: false })
		await flush()
		expect(await stored()).toBeUndefined()
		expect(sent).toEqual([])
	})

	it('on the tab starting to load', async () => {
		const { key } = (await openSession(OPEN, content()))!
		await fakeBrowser.tabs.onUpdated.trigger(TAB, { status: 'loading' }, { id: TAB } as never)
		await vi.waitFor(async () => expect(await stored()).toBeUndefined())
		expect(sent.map(({ message }) => message)).toEqual([{ kind: 'inline.close', key, refocus: false }])
	})

	it.each([
		['lock', () => lock(accountId)],
		['logout', () => markLoggedOut(accountId)],
		['account switch', () => closeAllSessions()],
	])('on %s, closing the open list', async (_, act) => {
		await twoLogins()
		const { key } = await openWithState()
		sent = []
		await act()
		await vi.waitFor(async () => expect(await stored()).toBeUndefined())
		expect(sent.map(({ message }) => message)).toEqual([{ kind: 'inline.close', key, refocus: false }])
		expect(await list({ kind: 'inline.pick', key, itemId: 'work', input })).toBeNull()
	})

	it('keeps the session in background memory where storage.session is missing (Firefox below 115)', async () => {
		const session = browser.storage.session
		Object.assign(browser.storage, { session: undefined })
		try {
			await putKey(accountId, toBase64(pemToPkcs8(envelope.privateKeyPem)))
			await twoLogins()
			const { key, state } = await openWithState()
			expect(state).toMatchObject({ state: 'unlocked', rows: [{ id: 'personal' }, { id: 'work' }] })
			expect(await list({ kind: 'inline.pick', key, itemId: 'work', input })).toEqual({ filled: 'both', code: false })
		} finally {
			Object.assign(browser.storage, { session })
		}
	})
})

describe('passkeys during a conditional request', () => {
	const CHALLENGE = toBase64Url(new Uint8Array(32).fill(9))
	const WEBAUTHN = { kind: 'inline.open', mode: 'login', webauthn: true } as const
	let privateKey: string
	let windows: string[]

	const entry = (credentialId: string, overrides: object = {}) => ({
		credentialId, rpId: 'example.test', rpName: 'Example', userName: 'alice', userDisplayName: 'Alice', userHandle: 'dXNlcg',
		privateKey, algorithm: -7, counter: 0, transports: ['internal'], createdAt: '2026-01-01T00:00:00.000Z', ...overrides,
	})
	let passkeysCipher: string
	/** `Example` holds a passkey for this site under another URL, and one for another site; `Work` is a plain login for this site. */
	async function passkeyVault(name = 'Example') {
		passkeysCipher = await encrypt(JSON.stringify([entry('QQ'), entry('Qg', { rpId: 'other.test' })]))
		await vault([
			await login({ id: 'example', name, url: 'https://elsewhere.test/', passkeys: passkeysCipher }),
			await login({ id: 'work', name: 'Work', login: await encrypt('alice@work.test') }),
		])
	}
	const passkeyDecrypts = () => vi.mocked(rsaDecrypt).mock.calls.filter(([cipher]) => cipher === passkeysCipher).length
	async function conditional(options: object = {}, sender = content()) {
		const request = { type: 'get', options: { challenge: CHALLENGE, rpId: 'example.test', allowCredentials: [], ...options }, mediation: 'conditional' }
		return ((await handlePasskeyContent({ kind: 'passkey.start', request, crossOriginAncestor: false } as never, sender)) as { ceremonyId: string }).ceremonyId
	}
	const ceremony = async () => (await browser.storage.session.get(ceremonyKey(TAB)))[ceremonyKey(TAB)] as Ceremony | undefined
	const results = () => sent.filter(({ message }) => message.kind === 'passkey.result')
	const resultsOnly = () => results().map(({ message }) => (message as Extract<BackgroundToContent, { kind: 'passkey.result' }>).result)
	const offered = { itemId: 'example', credentialId: 'QQ', name: 'Example', userName: 'alice' }
	const pickPasskey = (key: string, itemId = 'example', credentialId: unknown = 'QQ') => list({ kind: 'inline.pick', key, itemId, credentialId, input } as never)

	beforeAll(async () => {
		const pair = await crypto.subtle.generateKey({ name: 'ECDSA', namedCurve: 'P-256' }, true, ['sign', 'verify'])
		privateKey = toBase64Url(new Uint8Array(await crypto.subtle.exportKey('pkcs8', pair.privateKey)))
	})

	beforeEach(async () => {
		forgetOfferedPasskeys()
		await passkeyVault()
		windows = []
		vi.spyOn(browser.windows, 'create').mockImplementation(async (data?: { url?: string | string[] }) => {
			windows.push(data!.url as string)
			return { id: 50, tabs: [{ id: 550 }] } as never
		})
		vi.spyOn(browser.windows, 'remove').mockImplementation(async () => {})
		vi.mocked(savePasskeyCounter).mockClear()
		registerPasskeyCeremonies()
	})

	it('shows no passkey row and decrypts no passkey without a pending conditional request', async () => {
		vi.mocked(rsaDecrypt).mockClear()
		expect(await openSession(OPEN, content())).toEqual({ key: expect.any(String), height: inlineHeight(1) })
		const { state } = await openWithState()
		expect(state).toEqual({ state: 'unlocked', mode: 'login', host: 'example.test', theme: 'system', rows: [{ id: 'work', name: 'Work', username: 'alice@work.test' }] })
		expect(rsaDecrypt).toHaveBeenCalledTimes(1)
		expect(JSON.parse((await stored())!)).not.toHaveProperty('passkeys')
	})

	it('opens on a webauthn field only while the frame has a conditional request pending', async () => {
		expect(await openSession(WEBAUTHN, content())).toBeNull()
		await handlePasskeyContent({ kind: 'passkey.start', request: { type: 'get', options: { challenge: CHALLENGE, rpId: 'example.test', allowCredentials: [] } }, crossOriginAncestor: false } as never, content())
		expect(await openSession(WEBAUTHN, content())).toBeNull()
		await conditional({}, content({ frameId: 3 }))
		expect(await openSession(WEBAUTHN, content())).toBeNull()
		await conditional()
		expect(await openSession(WEBAUTHN, content())).toEqual({ key: expect.any(String), height: inlineHeight(2) })
		expect(await openSession({ kind: 'inline.open', mode: 'code', webauthn: true }, content())).toBeNull()
	})

	it('lists the offered passkeys above the logins, as ids and names only, and counts them in the height', async () => {
		const ceremonyId = await conditional()
		expect(await openSession(OPEN, content())).toEqual({ key: expect.any(String), height: inlineHeight(2) })
		const { state } = await openWithState()
		expect(state).toEqual({
			state: 'unlocked', mode: 'login', host: 'example.test', theme: 'system', rows: [{ id: 'work', name: 'Work', username: 'alice@work.test' }], passkeys: [offered],
		})
		expect(JSON.parse((await stored())!)).toMatchObject({ itemIds: ['work'], passkeys: { ceremonyId, offered: [['example', 'QQ']] } })
		for (const secret of [privateKey, 'Alice', 'dXNlcg', CHALLENGE]) expect(JSON.stringify(state)).not.toContain(secret)
	})

	it('locks a vault past its timeout before counting passkeys, answering the locked height', async () => {
		await conditional()
		await browser.storage.local.set({ [`settings.${accountId}`]: { vaultTimeout: 1, vaultTimeoutAction: 'lock' } })
		const overdue = String(Date.now() - 2 * 60_000)
		await browser.storage.session.set({ lastInteractionAt: overdue, [unlockedAtKey(accountId)]: overdue })
		vi.mocked(rsaDecrypt).mockClear()
		expect(await openSession(OPEN, content())).toEqual({ key: expect.any(String), height: inlineHeight(1) })
		expect(rsaDecrypt).not.toHaveBeenCalled()
		expect(await hasKey(accountId)).toBe(false)
	})

	it('decrypts the passkeys once per vault version, not again on each open, state or new request', async () => {
		await conditional()
		vi.mocked(rsaDecrypt).mockClear()
		await openSession(OPEN, content())
		expect(passkeyDecrypts()).toBe(1)
		await openSession(OPEN, content())
		expect((await openWithState()).state).toMatchObject({ passkeys: [offered] })
		await list({ kind: 'inline.state', key: (await openSession(OPEN, content()))!.key })
		// A page's loop: abort, a new conditional request with another allow list, focus again.
		for (const allowCredentials of [[], [{ type: 'public-key', id: 'QQ' }], [{ type: 'public-key', id: 'Zm9v' }]]) {
			const ceremonyId = await conditional({ allowCredentials })
			await openWithState()
			await handlePasskeyContent({ kind: 'passkey.abort', ceremonyId } as never, content())
		}
		expect(passkeyDecrypts()).toBe(1)
	})

	it('matches again after an account switch or a logout', async () => {
		const other = (await addAccount({
			serverUrl: 'https://cloud.example.org', uid: 'bob', loginName: 'bob', displayName: 'Bob', email: null, avatarDataUrl: null, appPassword: 'pw', keepiqVersion: '0.3.12',
		}, { ...suiteRow })).id
		await setActive(accountId)
		await conditional()
		await openWithState()
		vi.mocked(rsaDecrypt).mockClear()
		await setActive(other)
		await endForAccountChange()
		await setActive(accountId)
		await endForAccountChange()
		await conditional()
		expect((await openWithState()).state).toMatchObject({ passkeys: [offered] })
		expect(passkeyDecrypts()).toBe(1)
		await markLoggedOut(other)
		await openWithState()
		expect(passkeyDecrypts()).toBe(2)
	})

	it('shows rows of before a change it was not told of yet, but signs only what the vault holds now', async () => {
		await conditional()
		await openWithState()
		await vault([await login({ id: 'work', name: 'Work', login: await encrypt('alice@work.test') })])
		const { key, state } = await openWithState()
		expect(state).toMatchObject({ passkeys: [offered] })
		expect(await pickPasskey(key)).toEqual({ refused: 'stale' })
		expect(results()).toEqual([])
	})

	it('matches again after the vault changes or locks, never showing rows of before', async () => {
		await conditional()
		expect((await openWithState()).state).toMatchObject({ passkeys: [offered] })
		await passkeyVault('Renamed')
		broadcast({ kind: 'vault.changed', sync: {} as never })
		expect((await openWithState()).state).toMatchObject({ passkeys: [{ ...offered, name: 'Renamed' }] })
		vi.mocked(rsaDecrypt).mockClear()
		await lock(accountId)
		await putKey(accountId, toBase64(pemToPkcs8(envelope.privateKeyPem)))
		expect((await openWithState()).state).toMatchObject({ passkeys: [{ ...offered, name: 'Renamed' }] })
		expect(passkeyDecrypts()).toBe(1)
	})

	it.each([
		['an empty allow list', { allowCredentials: [] }],
		['no allow list', { allowCredentials: undefined }],
	])('offers every passkey for the RP ID on %s', async (_, options) => {
		await conditional(options)
		expect(await openSession(OPEN, content())).toEqual({ key: expect.any(String), height: inlineHeight(2) })
		expect((await openWithState()).state).toMatchObject({ rows: [{ id: 'work' }], passkeys: [offered] })
	})

	it('shows the same rows and height whatever credentials the allow list names, decrypting no passkey', async () => {
		const id = (credentialId: string) => ({ type: 'public-key', id: credentialId })
		const allowLists = [
			[id('QQ')], [id('Zm9v')], [id('QQ'), id('Zm9v')], [id('Zm9v'), id('QQ')], [id('QQ'), id('QQ')], [id('Qg')], [id('QR')], [id('')],
			[{ type: 'other', id: 'Zm9v' }, id('QQ')], [id('Q')], [id('QQ==')], [{ type: 'public-key' }], 'QQ',
		]
		vi.mocked(rsaDecrypt).mockClear()
		const shown: object[] = []
		for (const allowCredentials of allowLists) {
			const ceremonyId = await conditional({ allowCredentials })
			// A malformed request is refused at the start, so a webauthn field has no request to open on.
			if (ceremonyId) expect(await openSession(WEBAUTHN, content())).toEqual({ key: expect.any(String), height: inlineHeight(1) })
			const height = (await openSession(OPEN, content()))?.height
			const { key, state } = await openWithState()
			const { rows, passkeys = [] } = state as { rows: unknown[]; passkeys?: unknown[] }
			shown.push({ height, rows, passkeys })
			expect(await pickPasskey(key)).toEqual({ refused: 'stale' })
			if (ceremonyId) await handlePasskeyContent({ kind: 'passkey.abort', ceremonyId } as never, content())
		}
		expect(shown).toEqual(allowLists.map(() => shown[0]))
		expect(shown[0]).toMatchObject({ height: inlineHeight(1), rows: [{ id: 'work' }], passkeys: [] })
		expect(passkeyDecrypts()).toBe(0)
		expect(results()).toEqual([])
	})

	it('answers an empty passkey list where none matches, for "No logins or passkeys"', async () => {
		await conditional({ rpId: 'example.test', allowCredentials: [{ type: 'public-key', id: 'Zm9v' }] })
		pageUrl = 'https://example.test/other'
		await vault([])
		expect((await openWithState()).state).toMatchObject({ rows: [], passkeys: [] })
	})

	it('shows nothing of passkeys while locked, the same whether the vault holds them or not, and lists them after unlocking', async () => {
		await conditional()
		await lock(accountId)
		vi.mocked(rsaDecrypt).mockClear()
		const withPasskeys = [await openSession(OPEN, content()), await openSession(WEBAUTHN, content())]
		const { key, state } = await openWithState()
		expect(state).toEqual({ state: 'locked', mode: 'login', host: 'example.test', rows: [], theme: 'system' })
		expect(await pickPasskey(key)).toEqual({ refused: 'locked' })
		await vault([])
		const without = [await openSession(OPEN, content()), await openSession(WEBAUTHN, content())]
		expect(withPasskeys.map((reply) => reply?.height)).toEqual(without.map((reply) => reply?.height))
		expect((await openWithState()).state).toEqual(state)
		expect(rsaDecrypt).not.toHaveBeenCalled()
		expect(results()).toEqual([])
		await passkeyVault()
		await putKey(accountId, toBase64(pemToPkcs8(envelope.privateKeyPem)))
		expect((await openWithState()).state).toMatchObject({ passkeys: [offered] })
	})

	it('signs a picked passkey after the visibility check, sends the assertion to the requesting frame and closes the list', async () => {
		const ceremonyId = await conditional()
		const opened = await openSession(OPEN, content())
		const { key } = await openWithState()
		const toHost = [JSON.stringify(opened), ...sent.map(({ message }) => JSON.stringify(message))]
		sent = []
		expect(await pickPasskey(key)).toEqual({ passkey: 'signed' })
		toHost.push(...sent.filter(({ message }) => message.kind !== 'passkey.result').map(({ message }) => JSON.stringify(message)))
		for (const message of toHost) {
			for (const secret of ['Example', 'example"', 'alice', 'QQ', 'passkeys']) expect(message).not.toContain(secret)
		}
		expect(sent[0]).toEqual({ tabId: TAB, message: { kind: 'inline.verify', key, input }, frameId: 0 })
		expect(results()).toEqual([{ tabId: TAB, frameId: 0, message: { kind: 'passkey.result', ceremonyId, origin: 'https://example.test', result: { type: 'credential', credential: expect.objectContaining({ id: 'QQ' }) } } }])
		expect(sent.at(-1)).toEqual({ tabId: TAB, message: { kind: 'inline.close', key, refocus: true }, frameId: 0 })
		expect(sent.some(({ message }) => message.kind === 'fill.execute')).toBe(false)
		expect(await stored()).toBeUndefined()
		expect(await ceremony()).toBeUndefined()
		expect(windows).toEqual([])
	})

	it.each([
		['a login that offers no passkey', 'work', 'QQ'],
		['a passkey of the login for another site', 'example', 'Qg'],
		['a credential id the page made up', 'example', 'AAAA'],
	])('refuses a pick of %s as stale, signing nothing', async (_, itemId, credentialId) => {
		await conditional()
		const { key } = await openWithState()
		vi.mocked(rsaDecrypt).mockClear()
		expect(await pickPasskey(key, itemId, credentialId)).toEqual({ refused: 'stale' })
		expect(rsaDecrypt).not.toHaveBeenCalled()
		expect(results()).toEqual([])
		expect(sent.some(({ message }) => message.kind === 'inline.verify' || message.kind === 'fill.execute')).toBe(false)
		expect(await ceremony()).toBeDefined()
	})

	it('refuses an offered passkey once its request ended or another replaced it', async () => {
		const ceremonyId = await conditional()
		let { key } = await openWithState()
		await handlePasskeyContent({ kind: 'passkey.abort', ceremonyId } as never, content())
		expect(await pickPasskey(key)).toEqual({ refused: 'stale' })
		await conditional()
		key = (await openWithState()).key
		await conditional()
		expect(await pickPasskey(key)).toEqual({ refused: 'stale' })
		expect(resultsOnly()).toEqual([{ type: 'error', name: 'NotAllowedError' }])
	})

	it('ignores a malformed credential id, and takes no passkey pick before the list showed its state', async () => {
		await conditional()
		const { key } = (await openSession(OPEN, content()))!
		expect(await pickPasskey(key)).toBeNull()
		await list({ kind: 'inline.state', key })
		expect(await pickPasskey(key, 'example', 7)).toBeNull()
		expect(results()).toEqual([])
	})

	it('signs nothing when the host does not confirm visibility, keeping the list open', async () => {
		await conditional()
		const { key } = await openWithState()
		visible = false
		vi.mocked(rsaDecrypt).mockClear()
		expect(await pickPasskey(key)).toEqual({ refused: 'not_visible' })
		expect(rsaDecrypt).not.toHaveBeenCalled()
		expect(results()).toEqual([])
		expect(await stored()).toBeDefined()
	})

	it('opens the passkey window when verification is required, and keeps the request when the user closes it', async () => {
		const ceremonyId = await conditional({ userVerification: 'required' })
		const { key } = await openWithState()
		expect(await pickPasskey(key)).toEqual({ passkey: 'window' })
		expect(windows).toEqual([browser.runtime.getURL(`/popup.html?passkey=${ceremonyId}`)])
		expect(await ceremony()).toMatchObject({ id: ceremonyId, window: { id: 50, tabId: 550 }, verified: false })
		expect(results()).toEqual([])
		expect(await stored()).toBeUndefined()
		await fakeBrowser.windows.onRemoved.trigger(50)
		await flush()
		expect(await ceremony()).toMatchObject({ id: ceremonyId, window: null })
		expect(results()).toEqual([])
	})

	it('keeps the request on Cancel in that window, and on a fallback the window does not offer', async () => {
		const ceremonyId = await conditional({ userVerification: 'required' })
		const fromWindow = { id: browser.runtime.id, url: browser.runtime.getURL(`/popup.html?passkey=${ceremonyId}`), tab: { id: 550 }, frameId: 0 } as never
		expect(await pickPasskey((await openWithState()).key)).toEqual({ passkey: 'window' })
		expect(await handlePasskeyWindow({ kind: 'passkey.state', ceremonyId }, fromWindow)).toMatchObject({ ok: true, view: { conditional: true } })
		expect(await handlePasskeyWindow({ kind: 'passkey.cancel', ceremonyId }, fromWindow)).toEqual({ ok: true })
		expect(await ceremony()).toMatchObject({ id: ceremonyId, window: null })
		expect(await pickPasskey((await openWithState()).key)).toEqual({ passkey: 'window' })
		expect(await handlePasskeyWindow({ kind: 'passkey.fallback', ceremonyId }, fromWindow)).toEqual({ ok: true })
		expect(await ceremony()).toMatchObject({ id: ceremonyId, window: null })
		expect(results()).toEqual([])
	})

	it.each([
		['"Ask to save and use passkeys" is off', { askPasskeys: false }],
		['the site is excluded', { excludedDomains: ['example.test'] }],
	])('offers and signs no passkey of a pending request once %s', async (_, settings) => {
		await conditional()
		const { key } = await openWithState()
		await browser.storage.local.set({ [`settings.${accountId}`]: settings })
		vi.mocked(rsaDecrypt).mockClear()
		expect(await pickPasskey(key)).toEqual({ refused: 'stale' })
		expect(await openSession(OPEN, content())).toEqual({ key: expect.any(String), height: inlineHeight(1) })
		expect((await openWithState()).state).not.toHaveProperty('passkeys')
		expect(passkeyDecrypts()).toBe(0)
		expect(results()).toEqual([])
	})

	it('does not sign a held pick once the setting is off', async () => {
		vi.mocked(unlock).mockReset()
		const ceremonyId = await conditional({ userVerification: 'required' })
		expect(await pickPasskey((await openWithState()).key)).toEqual({ passkey: 'window' })
		await browser.storage.local.set({ [`settings.${accountId}`]: { askPasskeys: false } })
		const fromWindow = { id: browser.runtime.id, url: browser.runtime.getURL(`/popup.html?passkey=${ceremonyId}`), tab: { id: 550 }, frameId: 0 } as never
		expect(await handlePasskeyWindow({ kind: 'passkey.verify', ceremonyId, method: { type: 'pin', pin: '123456' } }, fromWindow)).toEqual({ ok: false, code: 'stale' })
		expect(results()).toEqual([])
	})

	it('holds no pick and opens no window when the vault locks after the pick was checked', async () => {
		vi.mocked(unlock).mockReset()
		await conditional({ userVerification: 'required' })
		const { key } = await openWithState()
		const { getKey: unmocked } = await vi.importActual<typeof import('@/src/vault/key-store')>('@/src/vault/key-store')
		vi.mocked(getKey).mockImplementationOnce(async (id) => {
			const checked = await unmocked(id)
			await lock(id)
			return checked
		})
		expect(await pickPasskey(key)).toEqual({ refused: 'locked' })
		await flush()
		expect(await ceremony()).toBeDefined()
		expect((await ceremony())?.pending).toBeUndefined()
		expect(windows).toEqual([])
		expect(results()).toEqual([])
	})

	describe('a pick that waits on "Confirm it\'s you"', () => {
		const PIN = { type: 'pin', pin: '123456' } as const
		let ceremonyId: string
		const fromWindow = () => ({ id: browser.runtime.id, url: browser.runtime.getURL(`/popup.html?passkey=${ceremonyId}`), tab: { id: 550 }, frameId: 0 }) as never
		const passkeyWindow = (message: object) => handlePasskeyWindow({ ceremonyId, ...message } as never, fromWindow())

		beforeEach(async () => {
			vi.mocked(unlock).mockReset()
			ceremonyId = await conditional({ userVerification: 'required' })
			expect(await pickPasskey((await openWithState()).key)).toEqual({ passkey: 'window' })
		})

		it('is held by ids only, and the window opens on it', async () => {
			expect((await ceremony())?.pending).toEqual({ itemId: 'example', credentialId: 'QQ' })
			expect(await passkeyWindow({ kind: 'passkey.state' })).toMatchObject({ ok: true, view: { verify: true, pending: offered, rows: [offered] } })
		})

		it('is signed after "Confirm it\'s you", with the user-verified flag', async () => {
			expect(await passkeyWindow({ kind: 'passkey.verify', method: PIN })).toEqual({ ok: true })
			expect(unlock).toHaveBeenCalledWith(accountId, PIN)
			const [result] = resultsOnly()
			expect(result).toEqual({ type: 'credential', credential: expect.objectContaining({ id: 'QQ' }) })
			const { authenticatorData } = (result as Extract<typeof result, { type: 'credential' }>).credential.response
			expect(fromBase64Url(authenticatorData)[32]! & 0x04).toBe(0x04)
			expect(await ceremony()).toBeUndefined()
		})

		it('is refused once no longer offered, signing nothing and showing the list', async () => {
			await vault([await login({ id: 'work', name: 'Work' })])
			broadcast({ kind: 'vault.changed', sync: {} as never })
			expect(await passkeyWindow({ kind: 'passkey.verify', method: PIN })).toEqual({ ok: false, code: 'stale' })
			expect(results()).toEqual([])
			expect((await ceremony())?.pending).toBeUndefined()
			expect(await passkeyWindow({ kind: 'passkey.state' })).toMatchObject({ ok: true, view: { verify: false, pending: null, rows: [] } })
		})

		it('is dropped from the view once no longer offered', async () => {
			await vault([await login({ id: 'work', name: 'Work' })])
			expect(await passkeyWindow({ kind: 'passkey.state' })).toMatchObject({ ok: true, view: { verify: true, pending: null, rows: [] } })
			expect((await ceremony())?.pending).toBeUndefined()
		})

		it('is replaced by a pick in the window, so confirming signs nothing the user moved away from', async () => {
			expect(await passkeyWindow({ kind: 'passkey.pick', itemId: 'example', credentialId: 'QQ' })).toEqual({ ok: false, code: 'verify_required' })
			expect((await ceremony())?.pending).toBeUndefined()
			expect(await passkeyWindow({ kind: 'passkey.verify', method: PIN })).toMatchObject({ ok: true, view: { verify: false, pending: null } })
			expect(results()).toEqual([])
		})

		it('is forgotten on a lock, and not signed after unlocking in the window', async () => {
			await lock(accountId)
			await flush()
			expect((await ceremony())?.pending).toBeUndefined()
			vi.mocked(unlock).mockImplementation(async (id) => putKey(id, toBase64(pemToPkcs8(envelope.privateKeyPem))))
			expect(await passkeyWindow({ kind: 'passkey.verify', method: { type: 'masterPassword', masterPassword: 'pw' } })).toMatchObject({ ok: true, view: { locked: false, pending: null } })
			expect(results()).toEqual([])
		})

		it.each([
			['Cancel in the window', false, () => passkeyWindow({ kind: 'passkey.cancel' })],
			['closing the window', false, async () => {
				await fakeBrowser.windows.onRemoved.trigger(50)
				await flush()
			}],
			['a lock and an unlock outside the window', false, async () => {
				await lock(accountId)
				await flush()
				await putKey(accountId, toBase64(pemToPkcs8(envelope.privateKeyPem)))
			}],
			['a failed counter save', true, async () => {}],
		])('asks again on a list pick after confirming, then %s', async (_, failSave, then) => {
			if (failSave) vi.mocked(savePasskeyCounter).mockResolvedValueOnce({ ok: false, error: { code: 'offline' } })
			else expect(await passkeyWindow({ kind: 'passkey.pick', itemId: 'example', credentialId: 'QQ' })).toEqual({ ok: false, code: 'verify_required' })
			expect(await passkeyWindow({ kind: 'passkey.verify', method: PIN })).toMatchObject(failSave ? { ok: false, code: 'write_failed' } : { ok: true, view: { verify: false } })
			expect(await ceremony()).toMatchObject({ verified: true })
			await then()
			// Only the failed save leaves the window it confirmed in open, for Retry.
			expect(await ceremony()).toMatchObject({ verified: failSave })
			expect(await pickPasskey((await openWithState()).key)).toEqual({ passkey: 'window' })
			expect(await ceremony()).toMatchObject({ verified: false, pending: { itemId: 'example', credentialId: 'QQ' } })
			expect(await passkeyWindow({ kind: 'passkey.state' })).toMatchObject({ ok: true, view: { verify: true, pending: offered } })
			expect(await passkeyWindow({ kind: 'passkey.pick', itemId: 'example', credentialId: 'QQ' })).toEqual({ ok: false, code: 'verify_required' })
			expect(results()).toEqual([])
		})

		it('signs with the user-verified flag on Retry in the window it confirmed in', async () => {
			vi.mocked(savePasskeyCounter).mockResolvedValueOnce({ ok: false, error: { code: 'offline' } })
			expect(await passkeyWindow({ kind: 'passkey.verify', method: PIN })).toMatchObject({ ok: false, code: 'write_failed' })
			expect(await passkeyWindow({ kind: 'passkey.retry' })).toEqual({ ok: true })
			const [result] = resultsOnly()
			expect(fromBase64Url((result as Extract<typeof result, { type: 'credential' }>).credential.response.authenticatorData)[32]! & 0x04).toBe(0x04)
		})

		it('is forgotten on Cancel, and gone with the request', async () => {
			expect(await passkeyWindow({ kind: 'passkey.cancel' })).toEqual({ ok: true })
			expect(await ceremony()).toMatchObject({ id: ceremonyId, window: null })
			expect((await ceremony())?.pending).toBeUndefined()
			expect(await pickPasskey((await openWithState()).key)).toEqual({ passkey: 'window' })
			await handlePasskeyContent({ kind: 'passkey.abort', ceremonyId } as never, content())
			expect(await ceremony()).toBeUndefined()
			expect(results()).toEqual([])
		})
	})

	it('opens the passkey window on Retry when the counter save fails, sending the page nothing', async () => {
		vi.mocked(savePasskeyCounter).mockResolvedValueOnce({ ok: false, error: { code: 'offline' } })
		const ceremonyId = await conditional()
		const { key } = await openWithState()
		expect(await pickPasskey(key)).toEqual({ passkey: 'window' })
		expect(windows).toEqual([browser.runtime.getURL(`/popup.html?passkey=${ceremonyId}`)])
		expect(await ceremony()).toMatchObject({ failed: { action: 'pick', itemId: 'example', credentialId: 'QQ', error: { code: 'offline' } } })
		expect(results()).toEqual([])
	})

	it('signs a later list pick with the user-verified flag clear, though the user confirmed in a window since closed', async () => {
		vi.mocked(unlock).mockReset()
		vi.mocked(savePasskeyCounter).mockResolvedValueOnce({ ok: false, error: { code: 'offline' } })
		const ceremonyId = await conditional()
		expect(await pickPasskey((await openWithState()).key)).toEqual({ passkey: 'window' })
		const fromWindow = { id: browser.runtime.id, url: browser.runtime.getURL(`/popup.html?passkey=${ceremonyId}`), tab: { id: 550 }, frameId: 0 } as never
		expect(await handlePasskeyWindow({ kind: 'passkey.verify', ceremonyId, method: { type: 'pin', pin: '123456' } }, fromWindow)).toMatchObject({ ok: true })
		expect(await handlePasskeyWindow({ kind: 'passkey.cancel', ceremonyId }, fromWindow)).toEqual({ ok: true })
		expect(await pickPasskey((await openWithState()).key)).toEqual({ passkey: 'signed' })
		const [result] = resultsOnly()
		expect(fromBase64Url((result as Extract<typeof result, { type: 'credential' }>).credential.response.authenticatorData)[32]! & 0x04).toBe(0)
	})

	it('offers no passkey in a code list or in another frame’s list', async () => {
		await conditional()
		const { key } = (await openSession({ kind: 'inline.open', mode: 'code' }, content()))!
		expect(await list({ kind: 'inline.state', key })).not.toHaveProperty('passkeys')
		pageUrl = 'https://example.test/frame'
		expect((await openWithState(content({ frameId: 2 }))).state).not.toHaveProperty('passkeys')
	})

	it('opens nothing on a webauthn field with suggestions off, leaving the request pending', async () => {
		const ceremonyId = await conditional()
		await browser.storage.local.set({ [`settings.${accountId}`]: { inlineSuggestions: false } })
		expect(await openSession(WEBAUTHN, content())).toBeNull()
		expect(await ceremony()).toMatchObject({ id: ceremonyId })
	})
})

it('sends a content script no name, username or item id, only a height, and plaintext only in the one fill', async () => {
	await twoLogins()
	const opened = await openSession(OPEN, content())
	const { key } = opened!
	await list({ kind: 'inline.state', key })
	await list({ kind: 'inline.refocus', key })
	await list({ kind: 'inline.resize', key, height: 130 })
	await list({ kind: 'inline.pick', key, itemId: 'work', input: { ...input, kind: 'key' } })
	await openWithState()
	await list({ kind: 'inline.close', key: (await openWithState()).key, refocus: false })
	expect(opened).toEqual({ key, height: inlineHeight(2) })
	const toContent = sent.filter(({ message }) => message.kind !== 'fill.execute').map(({ message }) => JSON.stringify(message))
	expect(toContent.length).toBeGreaterThan(5)
	for (const message of [JSON.stringify(opened), ...toContent]) {
		for (const secret of ['Work', 'Personal', 'alice', 'work', 'personal', '"rows"', 'count', 'name', 'itemId']) expect(message).not.toContain(secret)
		expect(Object.keys(JSON.parse(message) as object).every((field) => ['key', 'kind', 'height', 'refocus', 'input'].includes(field))).toBe(true)
	}
	expect(sent.filter(({ message }) => message.kind === 'fill.execute')).toHaveLength(1)
	const session = (await stored()) ?? ''
	for (const secret of ['Work', 'Personal', 'alice', 'hunter2']) expect(session).not.toContain(secret)
})
