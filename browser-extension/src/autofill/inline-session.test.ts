import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import type { OpenSecretRow } from '@/src/api/types'
import { addAccount, markLoggedOut, setActive } from '@/src/accounts/store'
import { pemToPkcs8, rsaDecrypt, toBase64 } from '@/src/crypto'
import { lastUsedKey } from '@/src/last-used'
import type { BackgroundToContent, InlineListToBackground, PickInput } from '@/src/messages'
import { encrypt, secretRow, typeRows } from '@/src/testing/vault'
import { envelope, suiteRow } from '@/src/testing/vectors'
import { putKey } from '@/src/vault/key-store'
import { writeSnapshot } from '@/src/vault/store'
import type { StoredSecretRow } from '@/src/vault/types'
import { lock } from '@/src/vault/unlock'
import { closeAllSessions, closedByHost, handleInlineList, inlineSessionKey, openSession, registerInlineSessions } from './inline-session'

vi.mock('@/src/crypto', async (importOriginal) => {
	const actual = await importOriginal<typeof import('@/src/crypto')>()
	return { ...actual, rsaDecrypt: vi.fn(actual.rsaDecrypt) }
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
	const { key } = (await openSession(sender))!
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
		const first = await openSession(content())
		expect(first?.key).toMatch(/^[0-9a-f]{32}$/)
		expect(JSON.parse((await stored())!)).toEqual({
			key: first!.key, accountId, frameId: 0, frameUrl: pageUrl, listFrameId: null, itemIds: [], openedAt: expect.any(Number),
		})
		expect((await openSession(content()))!.key).not.toBe(first!.key)
	})

	it('opens on a locked vault, deciding nothing from the vault', async () => {
		await lock(accountId)
		expect(await openSession(content())).toEqual({ key: expect.any(String) })
	})

	it('opens nothing without a logged-in account', async () => {
		await markLoggedOut(accountId)
		expect(await openSession(content())).toBeNull()
		expect(await stored()).toBeUndefined()
	})

	it('follows the inlineSuggestions setting on every open', async () => {
		await browser.storage.local.set({ [`settings.${accountId}`]: { inlineSuggestions: false } })
		expect(await openSession(content())).toBeNull()
		await browser.storage.local.set({ [`settings.${accountId}`]: { inlineSuggestions: true } })
		expect(await openSession(content())).not.toBeNull()
	})

	it('opens on an excluded domain', async () => {
		await browser.storage.local.set({ [`settings.${accountId}`]: { excludedDomains: ['example.test'] } })
		expect(await openSession(content())).not.toBeNull()
	})

	it.each(['file:///home/alice/login.html', 'chrome-extension://abc/page.html', 'about:blank'])('opens nothing for a frame on %s', async (url) => {
		expect(await openSession(content({ url }))).toBeNull()
	})

	it('opens nothing for a sender without a tab or frame', async () => {
		expect(await openSession(content({ tab: undefined }))).toBeNull()
		expect(await openSession(content({ frameId: undefined }))).toBeNull()
	})
})

describe('inline.state', () => {
	it('sends inline.ready to the host frame, then lists the matching logins last used first with decrypted usernames', async () => {
		await twoLogins()
		const { key, state } = await openWithState()
		expect(state).toEqual({
			state: 'unlocked', host: 'example.test', theme: 'system',
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
		expect((await openWithState()).state).toEqual({ state: 'unlocked', host: 'nothing.test', rows: [], theme: 'system' })
	})

	it('answers locked without decrypting anything', async () => {
		await twoLogins()
		await lock(accountId)
		expect((await openWithState()).state).toEqual({ state: 'locked', host: 'example.test', rows: [], theme: 'system' })
		expect(rsaDecrypt).not.toHaveBeenCalled()
	})

	it('carries the theme setting', async () => {
		await browser.storage.local.set({ 'settings.global': { theme: 'dark' } })
		await vault([])
		expect((await openWithState()).state).toMatchObject({ theme: 'dark' })
	})

	it('refuses a wrong key, another tab, a second list frame and a page other than the list', async () => {
		await twoLogins()
		const { key } = (await openSession(content()))!
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
		const { key } = (await openSession(content()))!
		expect(await list({ kind: 'inline.pick', key, itemId: 'work', input }, listPage({ frameId: 12 }))).toBeNull()
		expect(await list({ kind: 'inline.state', key })).not.toBeNull()
	})

	it('refuses a session after the active account changed, and closes its list', async () => {
		const other = await addAccount({
			serverUrl: 'https://other.example.org', uid: 'bob', loginName: 'bob', displayName: 'Bob', email: null, avatarDataUrl: null, appPassword: 'pw', keepiqVersion: '0.3.12',
		}, { ...suiteRow })
		await setActive(accountId)
		const { key } = (await openSession(content()))!
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
		expect(await list({ kind: 'inline.pick', key, itemId: 'work', input })).toEqual({ filled: 'both' })
		expect(sent).toEqual([
			{ tabId: TAB, message: { kind: 'inline.verify', key, input }, frameId: 0 },
			{ tabId: TAB, message: { kind: 'fill.execute', username: 'alice@work.test', password: 'hunter2', origin: 'https://example.test', anchorKey: key }, frameId: 0 },
			{ tabId: TAB, message: { kind: 'inline.close', key, refocus: true }, frameId: 0 },
		])
		expect((await browser.storage.local.get(lastUsedKey(accountId)))[lastUsedKey(accountId)]).toHaveProperty('work')
		expect(await stored()).toBeUndefined()
	})

	it('fills a subframe’s list in that frame only, even after the worker forgot the frames', async () => {
		await vault([await login({ id: 'i1', name: 'Login', url: 'https://login.example.test/' })])
		const { key } = await openWithState(content({ frameId: 3, url: 'https://login.example.test/frame' }))
		sent = []
		expect(await list({ kind: 'inline.pick', key, itemId: 'i1', input })).toEqual({ filled: 'both' })
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
		expect(await list({ kind: 'inline.pick', key, itemId: 'work', input, confirmedInsecure: true })).toEqual({ filled: 'both' })
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

describe('the list page’s other messages', () => {
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

	it('ignores every kind with a wrong key', async () => {
		const { key } = await openWithState()
		sent = []
		const wrong = 'f'.repeat(32)
		await list({ kind: 'inline.refocus', key: wrong })
		await list({ kind: 'inline.close', key: wrong, refocus: true })
		await list({ kind: 'inline.unlock', key: wrong })
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
		const first = (await openSession(content({ frameId: 3, url: 'https://login.example.test/' })))!.key
		const second = (await openSession(content()))!.key
		expect(sent).toEqual([{ tabId: TAB, message: { kind: 'inline.close', key: first, refocus: false }, frameId: 3 }])
		expect(JSON.parse((await stored())!)).toMatchObject({ key: second })
	})

	it('on two opens from different frames at once, closing the first instead of orphaning it', async () => {
		const [first, second] = await Promise.all([
			openSession(content({ frameId: 3, url: 'https://login.example.test/' })),
			openSession(content()),
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
		await openSession(content())
		await browser.storage.local.set({ [`settings.${accountId}`]: { inlineSuggestions: false } })
		expect(await openSession(content())).toBeNull()
		expect(await stored()).toBeUndefined()
	})

	it('on tab close, sending nothing', async () => {
		await openSession(content())
		await fakeBrowser.tabs.onRemoved.trigger(TAB, { windowId: 1, isWindowClosing: false })
		await flush()
		expect(await stored()).toBeUndefined()
		expect(sent).toEqual([])
	})

	it('on the tab starting to load', async () => {
		const { key } = (await openSession(content()))!
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
			expect(await list({ kind: 'inline.pick', key, itemId: 'work', input })).toEqual({ filled: 'both' })
		} finally {
			Object.assign(browser.storage, { session })
		}
	})
})

it('sends a content script no name, username, item id or row count, and plaintext only in the one fill', async () => {
	await twoLogins()
	const { key } = await openWithState()
	await list({ kind: 'inline.refocus', key })
	await list({ kind: 'inline.pick', key, itemId: 'work', input: { ...input, kind: 'key' } })
	await openWithState()
	await list({ kind: 'inline.close', key: (await openWithState()).key, refocus: false })
	const toContent = sent.filter(({ message }) => message.kind !== 'fill.execute').map(({ message }) => JSON.stringify(message))
	expect(toContent.length).toBeGreaterThan(5)
	for (const message of toContent) {
		for (const secret of ['Work', 'Personal', 'alice', 'work', 'personal', '"rows"', 'count']) expect(message).not.toContain(secret)
		expect(Object.keys(JSON.parse(message) as object).sort()).toEqual(expect.arrayContaining(['key', 'kind']))
	}
	expect(sent.filter(({ message }) => message.kind === 'fill.execute')).toHaveLength(1)
	const session = (await stored()) ?? ''
	for (const secret of ['Work', 'Personal', 'alice', 'hunter2']) expect(session).not.toContain(secret)
})
