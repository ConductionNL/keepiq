import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { addAccount, getAccount, markLoggedOut, setActive } from '@/src/accounts/store'
import { writeSettings } from '@/src/accounts/settings'
import { pemToPkcs8, rsaDecrypt, toBase64 } from '@/src/crypto'
import { fromBase64Url, toBase64Url } from '@/src/crypto/base64'
import { Failure } from '@/src/failure'
import type { BackgroundToContent, PasskeyAssertionJSON, PasskeyPopupRequest, PasskeyResult } from '@/src/messages'
import { encrypt, secretRow, typeRows } from '@/src/testing/vault'
import { envelope, suiteRow } from '@/src/testing/vectors'
import { hasKey, putKey } from '@/src/vault/key-store'
import { writeSnapshot } from '@/src/vault/store'
import { onPopupClosed, onPopupOpened } from '@/src/vault/timeout'
import { lock, unlock } from '@/src/vault/unlock'
import { ceremonyKey, endForAccountChange, HAND_BACK_MS, handlePasskeyContent, handlePasskeyWindow, registerPasskeyCeremonies, type Ceremony } from './ceremony'
import { FLAGS } from './webauthn'

vi.mock('@/src/crypto', async (importOriginal) => {
	const actual = await importOriginal<typeof import('@/src/crypto')>()
	return { ...actual, rsaDecrypt: vi.fn(actual.rsaDecrypt) }
})
vi.mock('@/src/vault/unlock', async (importOriginal) => ({ ...await importOriginal<typeof import('@/src/vault/unlock')>(), unlock: vi.fn() }))
vi.mock('@/src/vault/sync', async (importOriginal) => ({ ...await importOriginal<typeof import('@/src/vault/sync')>(), sync: vi.fn(async () => ({})) }))

const TAB = 7
const PAGE_WINDOW = 3
const ORIGIN = 'https://example.test'
const CHALLENGE = toBase64Url(new Uint8Array(32).fill(9))
let accountId: string
let privateKey: string
let results: Array<{ tabId: number; frameId: number | undefined; message: Extract<BackgroundToContent, { kind: 'passkey.result' }> }>
let created: Array<{ id: number; tabId: number; url: string; width?: number; height?: number }>
let removed: number[]
let updated: Array<{ tabId: number; url?: string }>

const content = (overrides: object = {}) => ({ id: browser.runtime.id, url: `${ORIGIN}/login`, origin: ORIGIN, tab: { id: TAB }, frameId: 0, ...overrides }) as never
const windowOf = (id: string, overrides: object = {}) => ({
	id: browser.runtime.id, url: browser.runtime.getURL(`/popup.html?passkey=${id}`), tab: { id: created.at(-1)?.tabId }, frameId: 0, ...overrides,
}) as never

const getRequest = (options: Record<string, unknown> = {}, extra: Record<string, unknown> = {}) =>
	({ type: 'get', options: { challenge: CHALLENGE, rpId: 'example.test', allowCredentials: [], ...options }, ...extra })
const createRequest = () => ({
	type: 'create',
	options: {
		rp: { name: 'Example' }, user: { id: toBase64Url(new Uint8Array([1, 2])), name: 'alice@example.test', displayName: 'Alice' }, challenge: CHALLENGE,
		pubKeyCredParams: [{ type: 'public-key', alg: -7 }], excludeCredentials: [],
	},
})

/** Lets the queued window work and the fake storage settle. */
const settle = async () => {
	for (let i = 0; i < 5; i++) await new Promise((resolve) => setTimeout(resolve, 0))
}

async function start(request: unknown = getRequest(), sender = content(), crossOriginAncestor: unknown = false) {
	const reply = await handlePasskeyContent({ kind: 'passkey.start', request, crossOriginAncestor } as never, sender)
	await settle()
	return reply as { ceremonyId?: string; fallback?: true }
}

const record = async (tabId = TAB) => (await browser.storage.session.get(ceremonyKey(tabId)))[ceremonyKey(tabId)] as Ceremony | undefined
const window = (message: Record<string, unknown>, sender: never) => handlePasskeyWindow(message as PasskeyPopupRequest, sender)
const delivered = () => results.map(({ message }) => [message.ceremonyId, message.result])

async function vault() {
	const entry = {
		credentialId: 'QQ', rpId: 'example.test', rpName: 'Example', userName: 'alice', userDisplayName: 'Alice', userHandle: 'dXNlcg',
		privateKey, algorithm: -7, counter: 0, transports: ['internal'], createdAt: '2026-01-01T00:00:00.000Z',
	}
	const secrets = [secretRow({ id: 'work', name: 'Work', typeId: 't-login', url: 'https://intranet.test/', passkeys: await encrypt(JSON.stringify([entry])) })]
	const syncedAt = new Date().toISOString()
	await writeSnapshot(accountId, {
		suite: { id: suiteRow.id, unlockKeyEpoch: 1 }, secrets, folders: [], types: typeRows, syncedAt, listsSyncedAt: syncedAt, newestUpdatedAt: null, total: 1,
	})
}

const credentialOf = (result: PasskeyResult) => (result as Extract<PasskeyResult, { type: 'credential' }>).credential.response as PasskeyAssertionJSON

beforeAll(async () => {
	const pair = await crypto.subtle.generateKey({ name: 'ECDSA', namedCurve: 'P-256' }, true, ['sign', 'verify'])
	privateKey = toBase64Url(new Uint8Array(await crypto.subtle.exportKey('pkcs8', pair.privateKey)))
})

beforeEach(async () => {
	fakeBrowser.reset()
	vi.mocked(rsaDecrypt).mockClear()
	vi.mocked(unlock).mockReset()
	accountId = (await addAccount({
		serverUrl: 'https://cloud.example.org', uid: 'alice', loginName: 'alice', displayName: 'Alice', email: null, avatarDataUrl: null, appPassword: 'pw', keepiqVersion: '0.3.12',
	}, { ...suiteRow })).id
	await putKey(accountId, toBase64(pemToPkcs8(envelope.privateKeyPem)))
	await vault()
	results = []
	created = []
	removed = []
	updated = []
	vi.spyOn(browser.tabs, 'sendMessage').mockImplementation(async (tabId: number, message: unknown, options?: { frameId?: number }) => {
		const m = structuredClone(message) as BackgroundToContent
		if (m.kind === 'passkey.result') results.push({ tabId, frameId: options?.frameId, message: m })
		return undefined as never
	})
	vi.spyOn(browser.windows, 'create').mockImplementation(async (data?: { url?: string | string[]; width?: number; height?: number }) => {
		const id = 50 + created.length
		created.push({ id, tabId: 500 + id, url: data!.url as string, width: data!.width, height: data!.height })
		return { id, tabs: [{ id: 500 + id }] } as never
	})
	vi.spyOn(browser.windows, 'remove').mockImplementation(async (id: number) => {
		removed.push(id)
		await fakeBrowser.windows.onRemoved.trigger(id)
	})
	vi.spyOn(browser.windows, 'update').mockImplementation(async () => ({}) as never)
	vi.spyOn(browser.tabs, 'get').mockImplementation(async (tabId: number) => ({ id: tabId, windowId: PAGE_WINDOW }) as never)
	vi.spyOn(browser.tabs, 'update').mockImplementation(async (tabId: unknown, info: unknown) => {
		updated.push({ tabId: tabId as number, url: (info as { url?: string }).url })
		return {} as never
	})
	registerPasskeyCeremonies()
})

afterEach(() => {
	vi.useRealTimers()
})

describe('passkey.start', () => {
	it('answers a ceremony id, keeps the request without vault data and opens the window on it', async () => {
		const { ceremonyId } = await start()
		expect(ceremonyId).toMatch(/^[A-Za-z0-9_-]{22}$/)
		expect(await record()).toEqual({
			kind: 'get', tabId: TAB, frameId: 0, origin: ORIGIN, url: `${ORIGIN}/login`, rpId: 'example.test', conditional: false, challenge: CHALLENGE, userVerification: 'preferred',
			deadline: expect.any(Number), credentialIds: [], rpName: '', user: null, credProps: false,
			id: ceremonyId, accountId, window: { id: 50, tabId: 550 }, verified: false,
		})
		expect(created).toEqual([{ id: 50, tabId: 550, url: browser.runtime.getURL(`/popup.html?passkey=${ceremonyId}`), width: 380, height: 630 }])
		expect(rsaDecrypt).not.toHaveBeenCalled()
	})

	it('gives a locked and an unlocked vault the same reply and the same window, reading nothing', async () => {
		const unlocked = await start()
		await lock(accountId)
		await settle()
		const locked = await start(getRequest(), content({ tab: { id: TAB + 1 } }))
		expect(Object.keys(locked)).toEqual(Object.keys(unlocked))
		expect(created.map(({ url, width, height }) => [url.replace(/=.*/, ''), width, height])).toEqual([
			[browser.runtime.getURL('/popup.html?passkey'), 380, 630], [browser.runtime.getURL('/popup.html?passkey'), 380, 630],
		])
		expect(rsaDecrypt).not.toHaveBeenCalled()
		// The lock kept the first ceremony.
		expect((await record())?.id).toBe(unlocked.ceremonyId)
	})

	it.each([
		['a page over http', content({ url: 'http://example.test/', origin: 'http://example.test' }), getRequest()],
		['a frame with a cross-origin ancestor', content(), getRequest(), true],
		['a frame whose ancestor flag is not false', content(), getRequest(), 'no'],
		['another site’s RP ID', content(), getRequest({ rpId: 'bank.test' })],
		['a malformed request', content(), { type: 'get', options: { challenge: '+' } }],
		['an RSA-only create', content(), { ...createRequest(), options: { ...createRequest().options, pubKeyCredParams: [{ type: 'public-key', alg: -257 }] } }],
		['a sender without a frame', content({ frameId: undefined }), getRequest()],
	])('falls back for %s, opening and storing nothing', async (_, sender, request, ancestor = false) => {
		expect(await start(request, sender, ancestor)).toEqual({ fallback: true })
		expect(await browser.storage.session.get(null)).not.toHaveProperty([ceremonyKey(TAB)])
		expect(created).toEqual([])
	})

	it('falls back for an excluded domain, and without a logged-in account', async () => {
		await writeSettings(accountId, { excludedDomains: ['example.test'] })
		expect(await start()).toEqual({ fallback: true })
		await writeSettings(accountId, { excludedDomains: [] })
		await markLoggedOut(accountId)
		expect(await start()).toEqual({ fallback: true })
		expect(created).toEqual([])
	})

	it('sends the next request to the browser once Ask to save and use passkeys is off, without a reload, locked or not', async () => {
		expect(await start()).toHaveProperty('ceremonyId')
		await writeSettings(accountId, { askPasskeys: false })
		for (const request of [getRequest(), createRequest(), getRequest({}, { mediation: 'conditional' })]) {
			expect(await start(request, content({ tab: { id: TAB + 1 } }))).toEqual({ fallback: true })
		}
		await lock(accountId)
		expect(await start(getRequest(), content({ tab: { id: TAB + 1 } }))).toEqual({ fallback: true })
		expect(created).toHaveLength(1)
		expect(await record(TAB + 1)).toBeUndefined()
		expect(rsaDecrypt).not.toHaveBeenCalled()
		await writeSettings(accountId, { askPasskeys: true })
		expect(await start(getRequest(), content({ tab: { id: TAB + 1 } }))).toHaveProperty('ceremonyId')
	})

	it('sends a subdomain of an excluded base domain to the browser, for a create and a get', async () => {
		await writeSettings(accountId, { excludedDomains: ['example.test'] })
		const sender = content({ url: 'https://accounts.example.test/', origin: 'https://accounts.example.test' })
		expect(await start(getRequest({ rpId: 'accounts.example.test' }), sender)).toEqual({ fallback: true })
		expect(await start(createRequest(), sender)).toEqual({ fallback: true })
		expect(created).toEqual([])
		const other = content({ url: 'https://example.org/', origin: 'https://example.org' })
		expect(await start(getRequest({ rpId: 'example.org' }), other)).toHaveProperty('ceremonyId')
	})

	it('registers a conditional request without a window', async () => {
		const { ceremonyId } = await start(getRequest({}, { mediation: 'conditional' }))
		expect(await record()).toMatchObject({ id: ceremonyId, conditional: true, deadline: null, window: null })
		expect(created).toEqual([])
	})

	it('ends the first ceremony in a tab with NotAllowedError when a second starts, and shows the second in its window', async () => {
		const first = await start()
		const second = await start(createRequest())
		expect(delivered()).toEqual([[first.ceremonyId, { type: 'error', name: 'NotAllowedError' }]])
		expect(results[0]).toMatchObject({ tabId: TAB, frameId: 0, message: { origin: ORIGIN } })
		expect(created).toHaveLength(1)
		expect(updated).toEqual([{ tabId: 550, url: browser.runtime.getURL(`/popup.html?passkey=${second.ceremonyId}`) }])
		expect(await record()).toMatchObject({ id: second.ceremonyId, kind: 'create', window: { id: 50, tabId: 550 } })
		expect(removed).toEqual([])
		// The window now answers the second ceremony only.
		expect(await window({ kind: 'passkey.state', ceremonyId: first.ceremonyId }, windowOf(first.ceremonyId!))).toEqual({ ok: false, code: 'ended' })
	})

	it.each(['lock', 'logout'] as const)('keeps an "Immediately" vault open (%s) while the window reloads for the second ceremony', async (vaultTimeoutAction) => {
		await writeSettings(accountId, { vaultTimeout: 'immediately', vaultTimeoutAction })
		const open = async () => (vaultTimeoutAction === 'lock' ? hasKey(accountId) : (await getAccount(accountId))?.appPassword === 'pw')
		const first = await start()
		onPopupOpened()
		let closed: Promise<void> | undefined
		// The reload drops the old page's port before the new page connects.
		vi.mocked(browser.tabs.update).mockImplementation(async () => {
			closed = onPopupClosed()
			onPopupOpened()
			return {} as never
		})
		vi.useFakeTimers({ toFake: ['setTimeout'] })
		const { ceremonyId } = await handlePasskeyContent({ kind: 'passkey.start', request: getRequest(), crossOriginAncestor: false } as never, content()) as { ceremonyId: string }
		await vi.runAllTimersAsync()
		await closed
		vi.useRealTimers()
		await settle()
		expect(await open()).toBe(true)
		expect((await record())?.id).toBe(ceremonyId)
		expect(delivered()).toEqual([[first.ceremonyId, { type: 'error', name: 'NotAllowedError' }]])
		// The window closing for good still counts.
		await onPopupClosed()
		expect(await open()).toBe(false)
	})

	it('opens a new window when the old one’s tab is gone, giving a popup close no grace', async () => {
		await writeSettings(accountId, { vaultTimeout: 'immediately', vaultTimeoutAction: 'lock' })
		await start()
		onPopupOpened()
		vi.mocked(browser.tabs.update).mockRejectedValue(new Error('No tab with id: 550'))
		const { ceremonyId } = await start(createRequest())
		expect(created.map(({ id }) => id)).toEqual([50, 51])
		expect(await record()).toMatchObject({ id: ceremonyId, window: { id: 51, tabId: 551 } })
		expect(await window({ kind: 'passkey.state', ceremonyId }, windowOf(ceremonyId!))).toMatchObject({ ok: true })
		void onPopupClosed()
		await settle()
		expect(await hasKey(accountId)).toBe(false)
	})

	it('answers fallback only after a window without a tab is gone and the tab’s window was asked to take focus', async () => {
		vi.mocked(browser.windows.create).mockImplementation(async () => {
			created.push({ id: 50, tabId: 0, url: '' })
			return { id: 50, tabs: [] } as never
		})
		vi.spyOn(browser.windows, 'get').mockResolvedValue({ id: 50, tabs: [] } as never)
		const events: string[] = []
		vi.mocked(browser.windows.remove).mockImplementation(async (id: number) => {
			await new Promise((resolve) => setTimeout(resolve, 0))
			events.push('removed')
			await fakeBrowser.windows.onRemoved.trigger(id)
		})
		vi.mocked(browser.windows.update).mockImplementation(async (id: number) => {
			events.push(`focus ${id}`)
			return {} as never
		})
		vi.mocked(browser.tabs.sendMessage).mockImplementation(async (_tab: number, message: unknown) => {
			events.push(`result ${(message as { result: PasskeyResult }).result.type}`)
			return undefined as never
		})
		await start()
		await settle()
		expect(events).toEqual(['removed', `focus ${PAGE_WINDOW}`, 'result fallback'])
		expect(await record()).toBeUndefined()
	})

	it('closes the window when a conditional request replaces a modal one', async () => {
		await start()
		await start(getRequest({}, { mediation: 'conditional' }))
		expect(removed).toEqual([50])
		expect((await record())?.window).toBeNull()
	})

	it('leaves no window open and no record after an abort right after the start', async () => {
		const reply = await handlePasskeyContent({ kind: 'passkey.start', request: getRequest(), crossOriginAncestor: false } as never, content()) as { ceremonyId: string }
		await handlePasskeyContent({ kind: 'passkey.abort', ceremonyId: reply.ceremonyId }, content())
		await settle()
		expect(await record()).toBeUndefined()
		expect(removed).toEqual(created.map(({ id }) => id))
		expect(delivered()).toEqual([])
	})

	it('ignores an abort from another frame or for another ceremony', async () => {
		const { ceremonyId } = await start()
		await handlePasskeyContent({ kind: 'passkey.abort', ceremonyId: ceremonyId! }, content({ frameId: 3 }))
		await handlePasskeyContent({ kind: 'passkey.abort', ceremonyId: 'other' }, content())
		await handlePasskeyContent({ kind: 'passkey.abort', ceremonyId: ceremonyId! }, content({ tab: { id: TAB + 1 } }))
		expect((await record())?.id).toBe(ceremonyId)
	})
})

describe('passkey.available', () => {
	const available = (sender = content(), crossOriginAncestor: unknown = false) =>
		handlePasskeyContent({ kind: 'passkey.available', crossOriginAncestor } as never, sender)

	it('answers true while the provider would handle the frame, locked or not', async () => {
		expect(await available()).toBe(true)
		await lock(accountId)
		expect(await available()).toBe(true)
	})

	it('answers false for a cross-origin ancestor, an insecure page, an excluded site, the setting off or no logged-in account', async () => {
		await writeSettings(accountId, { askPasskeys: false })
		expect(await available()).toBe(false)
		await writeSettings(accountId, { askPasskeys: true })
		expect(await available(content(), true)).toBe(false)
		expect(await available(content({ url: 'http://example.test/', origin: 'http://example.test' }))).toBe(false)
		await writeSettings(accountId, { excludedDomains: ['example.test'] })
		expect(await available()).toBe(false)
		await writeSettings(accountId, { excludedDomains: [] })
		await markLoggedOut(accountId)
		expect(await available()).toBe(false)
	})
})

describe('ending a ceremony', () => {
	it.each([
		['the tab closes', () => fakeBrowser.tabs.onRemoved.trigger(TAB, { windowId: 0, isWindowClosing: false })],
		['the tab navigates to another origin', () => fakeBrowser.tabs.onUpdated.trigger(TAB, { status: 'loading', url: 'https://other.test/' }, { id: TAB } as never)],
		['the tab navigates to a URL without an origin', () => fakeBrowser.tabs.onUpdated.trigger(TAB, { url: 'about:blank' }, { id: TAB } as never)],
	])('clears the record and closes the window, sending nothing, when %s', async (_, happen) => {
		await start()
		await happen()
		await settle()
		expect(await record()).toBeUndefined()
		expect(removed).toEqual([50])
		expect(delivered()).toEqual([])
	})

	it.each([
		['a same-origin URL change, as pushState', { url: `${ORIGIN}/next#step` }],
		['a loading status alone, as a late frame load', { status: 'loading' }],
		['a title change', { title: 'Sign in' }],
	])('keeps the ceremony on %s', async (_, change) => {
		const { ceremonyId } = await start()
		await fakeBrowser.tabs.onUpdated.trigger(TAB, change as never, { id: TAB } as never)
		await settle()
		expect((await record())?.id).toBe(ceremonyId)
		expect(removed).toEqual([])
	})

	it('leaves another tab’s ceremony alone when a tab navigates away', async () => {
		const { ceremonyId } = await start()
		await fakeBrowser.tabs.onUpdated.trigger(TAB + 1, { url: 'https://other.test/' }, { id: TAB + 1 } as never)
		await settle()
		expect((await record())?.id).toBe(ceremonyId)
	})

	it('ends with NotAllowedError when the user closes the window', async () => {
		const { ceremonyId } = await start()
		await fakeBrowser.windows.onRemoved.trigger(50)
		await settle()
		expect(await record()).toBeUndefined()
		expect(delivered()).toEqual([[ceremonyId, { type: 'error', name: 'NotAllowedError' }]])
	})

	it('ends with NotAllowedError on logout, closing the window', async () => {
		const { ceremonyId } = await start()
		await markLoggedOut(accountId)
		await settle()
		expect(await record()).toBeUndefined()
		expect(removed).toEqual([50])
		expect(delivered()).toEqual([[ceremonyId, { type: 'error', name: 'NotAllowedError' }]])
	})

	it('ends with NotAllowedError on an account switch', async () => {
		const { ceremonyId } = await start()
		const other = await addAccount({
			serverUrl: 'https://cloud.example.org', uid: 'bob', loginName: 'bob', displayName: 'Bob', email: null, avatarDataUrl: null, appPassword: 'pw', keepiqVersion: '0.3.12',
		}, { ...suiteRow })
		await setActive(other.id)
		await endForAccountChange()
		await settle()
		expect(await record()).toBeUndefined()
		expect(delivered()).toEqual([[ceremonyId, { type: 'error', name: 'NotAllowedError' }]])
	})

	it('keeps the ceremony through a lock', async () => {
		const { ceremonyId } = await start()
		await lock(accountId)
		await endForAccountChange()
		await settle()
		expect((await record())?.id).toBe(ceremonyId)
		expect(delivered()).toEqual([])
	})

	it('ends with NotAllowedError on the first window message past the deadline', async () => {
		const { ceremonyId } = await start(getRequest({ timeout: 30_000 }))
		vi.useFakeTimers({ now: Date.now() + 31_000, toFake: ['Date'] })
		expect(await window({ kind: 'passkey.state', ceremonyId }, windowOf(ceremonyId!))).toEqual({ ok: false, code: 'ended' })
		await settle()
		expect(await record()).toBeUndefined()
		expect(delivered()).toEqual([[ceremonyId, { type: 'error', name: 'NotAllowedError' }]])
	})

	it('keeps ceremonies in memory where storage.session is missing', async () => {
		const session = browser.storage.session
		Object.assign(browser.storage, { session: undefined })
		try {
			const { ceremonyId } = await start()
			expect(await window({ kind: 'passkey.cancel', ceremonyId }, windowOf(ceremonyId!))).toEqual({ ok: true })
			expect(delivered()).toEqual([[ceremonyId, { type: 'error', name: 'NotAllowedError' }]])
			expect(await window({ kind: 'passkey.state', ceremonyId }, windowOf(ceremonyId!))).toEqual({ ok: false, code: 'ended' })
		} finally {
			Object.assign(browser.storage, { session })
		}
	})
})

describe('the passkey window', () => {
	const KINDS: Array<Record<string, unknown>> = [
		{ kind: 'passkey.state' }, { kind: 'passkey.pick', itemId: 'work', credentialId: 'QQ' }, { kind: 'passkey.verify', method: { type: 'pin', pin: '123456' } },
		{ kind: 'passkey.fallback' }, { kind: 'passkey.cancel' }, { kind: 'passkey.save', target: { itemId: 'work' } }, { kind: 'passkey.retry' },
	]

	describe.each(KINDS)('$kind', (message) => {
		it.each([
			['the toolbar popup', (id: string) => windowOf(id, { tab: undefined })],
			['another tab', (id: string) => windowOf(id, { tab: { id: 999 } })],
			['the page’s own tab', (id: string) => windowOf(id, { tab: { id: TAB } })],
			['the window under another ceremony id', () => windowOf('other')],
		])('is refused from %s, changing nothing', async (_, sender) => {
			const { ceremonyId } = await start()
			const id = sender.length === 0 ? 'other' : ceremonyId!
			expect(await window({ ...message, ceremonyId: id }, sender(ceremonyId!))).toEqual({ ok: false, code: 'ended' })
			await settle()
			expect(await record()).toMatchObject({ id: ceremonyId, verified: false })
			expect(delivered()).toEqual([])
			expect(unlock).not.toHaveBeenCalled()
		})
	})

	it('shows a locked vault no passkey, decrypting nothing, and an unlocked one the passkeys offered', async () => {
		const { ceremonyId } = await start()
		await lock(accountId)
		vi.mocked(rsaDecrypt).mockClear()
		const view = { kind: 'get', conditional: false, rpId: 'example.test', host: 'example.test', rpName: '', userName: '', verify: false, targets: [], folders: [], alreadySaved: false, failure: null, pending: null }
		expect(await window({ kind: 'passkey.state', ceremonyId }, windowOf(ceremonyId!))).toEqual({ ok: true, view: { ...view, locked: true, rows: [] } })
		expect(rsaDecrypt).not.toHaveBeenCalled()
		await putKey(accountId, toBase64(pemToPkcs8(envelope.privateKeyPem)))
		expect(await window({ kind: 'passkey.state', ceremonyId }, windowOf(ceremonyId!))).toEqual({
			ok: true, view: { ...view, locked: false, rows: [{ itemId: 'work', credentialId: 'QQ', name: 'Work', userName: 'alice' }] },
		})
	})

	it('shows a create the page’s RP name and user name', async () => {
		const { ceremonyId } = await start(createRequest())
		expect(await window({ kind: 'passkey.state', ceremonyId }, windowOf(ceremonyId!))).toMatchObject({ ok: true, view: { kind: 'create', rpName: 'Example', userName: 'alice@example.test', rows: [] } })
	})

	it('signs a pick for the frame, closes the window and records the use', async () => {
		const { ceremonyId } = await start()
		expect(await window({ kind: 'passkey.pick', ceremonyId, itemId: 'work', credentialId: 'QQ' }, windowOf(ceremonyId!))).toEqual({ ok: true })
		await settle()
		expect(results).toHaveLength(1)
		expect(results[0]).toMatchObject({ tabId: TAB, frameId: 0, message: { ceremonyId, origin: ORIGIN, result: { type: 'credential', credential: { id: 'QQ' } } } })
		expect(fromBase64Url(credentialOf(results[0]!.message.result).authenticatorData)[32]! & FLAGS.UV).toBe(0)
		expect(removed).toEqual([50])
		expect(await record()).toBeUndefined()
		expect(await browser.storage.local.get(`lastUsed.${accountId}`)).toEqual({ [`lastUsed.${accountId}`]: { work: expect.any(Number) } })
	})

	it('refuses a pick while locked, before verification where required, and of a passkey not offered', async () => {
		const required = await start(getRequest({ userVerification: 'required' }))
		const id = required.ceremonyId!
		expect(await window({ kind: 'passkey.pick', ceremonyId: id, itemId: 'work', credentialId: 'QQ' }, windowOf(id))).toEqual({ ok: false, code: 'verify_required' })
		expect(await window({ kind: 'passkey.state', ceremonyId: id }, windowOf(id))).toMatchObject({ view: { verify: true } })
		const allowed = await start(getRequest({ allowCredentials: [{ type: 'public-key', id: 'Qg' }] }))
		const other = allowed.ceremonyId!
		expect(await window({ kind: 'passkey.pick', ceremonyId: other, itemId: 'work', credentialId: 'QQ' }, windowOf(other))).toEqual({ ok: false, code: 'stale' })
		await lock(accountId)
		expect(await window({ kind: 'passkey.pick', ceremonyId: other, itemId: 'work', credentialId: 'QQ' }, windowOf(other))).toEqual({ ok: false, code: 'locked' })
		expect(delivered()).toEqual([[id, { type: 'error', name: 'NotAllowedError' }]])
	})

	it('asks to confirm, then signs with the user-verified flag', async () => {
		vi.mocked(unlock).mockResolvedValue()
		const { ceremonyId } = await start(getRequest({ userVerification: 'required' }))
		const reply = await window({ kind: 'passkey.verify', ceremonyId, method: { type: 'pin', pin: '123456' } }, windowOf(ceremonyId!))
		expect(reply).toMatchObject({ ok: true, view: { verify: false, locked: false, rows: [{ itemId: 'work' }] } })
		expect(unlock).toHaveBeenCalledWith(accountId, { type: 'pin', pin: '123456' })
		expect(await window({ kind: 'passkey.pick', ceremonyId, itemId: 'work', credentialId: 'QQ' }, windowOf(ceremonyId!))).toEqual({ ok: true })
		expect(fromBase64Url(credentialOf(results[0]!.message.result).authenticatorData)[32]! & FLAGS.UV).toBe(FLAGS.UV)
	})

	it('counts unlocking in the window as verification, and lists the passkey only after it', async () => {
		await lock(accountId)
		vi.mocked(unlock).mockImplementation(async (id) => putKey(id, toBase64(pemToPkcs8(envelope.privateKeyPem))))
		const { ceremonyId } = await start(getRequest({ userVerification: 'required' }))
		expect(await window({ kind: 'passkey.state', ceremonyId }, windowOf(ceremonyId!))).toMatchObject({ view: { locked: true, rows: [] } })
		expect(await window({ kind: 'passkey.verify', ceremonyId, method: { type: 'masterPassword', masterPassword: 'pw' } }, windowOf(ceremonyId!)))
			.toMatchObject({ ok: true, view: { locked: false, verify: false, rows: [{ itemId: 'work', credentialId: 'QQ' }] } })
		expect(await window({ kind: 'passkey.pick', ceremonyId, itemId: 'work', credentialId: 'QQ' }, windowOf(ceremonyId!))).toEqual({ ok: true })
		expect(fromBase64Url(credentialOf(results[0]!.message.result).authenticatorData)[32]! & FLAGS.UV).toBe(FLAGS.UV)
	})

	it('passes on a wrong PIN with the tries left and does not count it as verified', async () => {
		vi.mocked(unlock).mockRejectedValue(new Failure('invalid_pin', 'Wrong PIN', 4))
		const { ceremonyId } = await start(getRequest({ userVerification: 'required' }))
		expect(await window({ kind: 'passkey.verify', ceremonyId, method: { type: 'pin', pin: '000000' } }, windowOf(ceremonyId!)))
			.toEqual({ ok: false, code: 'invalid_pin', attemptsLeft: 4 })
		expect((await record())?.verified).toBe(false)
		vi.mocked(unlock).mockRejectedValue(new Failure('pin_forgotten'))
		expect(await window({ kind: 'passkey.verify', ceremonyId, method: { type: 'pin', pin: '000000' } }, windowOf(ceremonyId!)))
			.toEqual({ ok: false, code: 'pin_forgotten' })
	})

	it.each([null, { type: 'pin' }, { type: 'pin', pin: 7 }, { type: 'masterPassword' }, { type: 'biometric', pin: 'x' }])('refuses the method %j without unlocking', async (method) => {
		const { ceremonyId } = await start()
		expect(await window({ kind: 'passkey.verify', ceremonyId, method }, windowOf(ceremonyId!))).toEqual({ ok: false, code: 'unknown' })
		expect(unlock).not.toHaveBeenCalled()
	})

	it.each([
		['passkey.fallback', { type: 'fallback' }],
		['passkey.cancel', { type: 'error', name: 'NotAllowedError' }],
	])('%s ends the ceremony with %j and closes the window, also while locked', async (kind, result) => {
		const { ceremonyId } = await start()
		await lock(accountId)
		expect(await window({ kind, ceremonyId }, windowOf(ceremonyId!))).toEqual({ ok: true })
		await settle()
		expect(delivered()).toEqual([[ceremonyId, result]])
		expect(removed).toEqual([50])
		expect(await record()).toBeUndefined()
	})

	describe('passkey.fallback hands the page back its focus first', () => {
		const focused = () => vi.mocked(browser.windows.update).mock.calls.filter(([id]) => id === PAGE_WINDOW)

		it('sends fallback only after the window is gone and the tab’s window was asked to take focus', async () => {
			const { ceremonyId } = await start()
			let close = () => {}
			vi.mocked(browser.windows.remove).mockImplementation(async (id: number) => {
				removed.push(id)
				close = () => void fakeBrowser.windows.onRemoved.trigger(id)
			})
			let deliveredAtFocus: number | undefined
			vi.mocked(browser.windows.update).mockImplementation(async () => {
				deliveredAtFocus = results.length
				return {} as never
			})
			expect(await window({ kind: 'passkey.fallback', ceremonyId }, windowOf(ceremonyId!))).toEqual({ ok: true })
			await settle()
			expect(removed).toEqual([50])
			expect(focused()).toEqual([])
			expect(delivered()).toEqual([])
			close()
			await settle()
			expect(browser.tabs.get).toHaveBeenCalledWith(TAB)
			expect(focused()).toEqual([[PAGE_WINDOW, { focused: true }]])
			expect(deliveredAtFocus).toBe(0)
			expect(delivered()).toEqual([[ceremonyId, { type: 'fallback' }]])
		})

		it('still sends fallback, after asking for focus, when the window never reports its removal', async () => {
			const { ceremonyId } = await start()
			vi.mocked(browser.windows.remove).mockImplementation(async (id: number) => void removed.push(id))
			vi.useFakeTimers({ toFake: ['setTimeout'] })
			const reply = window({ kind: 'passkey.fallback', ceremonyId }, windowOf(ceremonyId!))
			await vi.advanceTimersByTimeAsync(HAND_BACK_MS - 1)
			expect(delivered()).toEqual([])
			await vi.advanceTimersByTimeAsync(1)
			expect(await reply).toEqual({ ok: true })
			expect(focused()).toEqual([[PAGE_WINDOW, { focused: true }]])
			expect(delivered()).toEqual([[ceremonyId, { type: 'fallback' }]])
		})

		it.each([
			['windows.update rejects', () => vi.mocked(browser.windows.update).mockRejectedValue(new Error('No window with id: 3'))],
			['tabs.get rejects', () => vi.mocked(browser.tabs.get).mockRejectedValue(new Error('No tab with id: 7'))],
			['windows.remove rejects', () => vi.mocked(browser.windows.remove).mockRejectedValue(new Error('No window with id: 50'))],
		])('still sends fallback at once when %s', async (_, fail) => {
			const { ceremonyId } = await start()
			fail()
			expect(await window({ kind: 'passkey.fallback', ceremonyId }, windowOf(ceremonyId!))).toEqual({ ok: true })
			await settle()
			expect(browser.tabs.get).toHaveBeenCalledWith(TAB)
			expect(delivered()).toEqual([[ceremonyId, { type: 'fallback' }]])
		})
	})

	it('leaves no decrypted value, key or challenge in storage.local or storage.session after a sign-in', async () => {
		const { ceremonyId } = await start()
		await window({ kind: 'passkey.pick', ceremonyId, itemId: 'work', credentialId: 'QQ' }, windowOf(ceremonyId!))
		await settle()
		expect(results).toHaveLength(1)
		const stored = JSON.stringify([await browser.storage.local.get(null), await browser.storage.session.get(null)])
		for (const secret of [privateKey, CHALLENGE, '"rpId":"example.test"', 'dXNlcg']) expect(stored).not.toContain(secret)
	})
})
