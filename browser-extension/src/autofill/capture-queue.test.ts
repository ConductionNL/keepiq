import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import type { OpenSecretRow } from '@/src/api/types'
import { readSettings } from '@/src/accounts/settings'
import { addAccount, setActive } from '@/src/accounts/store'
import { importPrivateKey, pemToPkcs8, rsaDecrypt, toBase64 } from '@/src/crypto'
import type { BackgroundToContent, CaptureSubmitted, NotificationToBackground, PickInput } from '@/src/messages'
import { encrypt, folderRow, secretRow, typeRows } from '@/src/testing/vault'
import { envelope, suiteRow } from '@/src/testing/vectors'
import { clearKey, putKey } from '@/src/vault/key-store'
import { writeSnapshot } from '@/src/vault/store'
import { lock } from '@/src/vault/unlock'
import { createItem } from '@/src/vault/write'
import {
	CAPTURE_LIFESPAN_MS, captureSubmitted, dropAllCaptures, focusBar, handleNotification, registerCaptureQueue, reshow, SAVED_CLOSE_MS, SHOW_DELAY_MS, showPending,
} from './capture-queue'
import { fill, matchingLogins } from './fill'
import { rememberedItem, rememberItem } from './tab-items'

vi.mock('@/src/vault/write', async (importOriginal) => {
	const actual = await importOriginal<typeof import('@/src/vault/write')>()
	return { ...actual, createItem: vi.fn(actual.createItem) }
})

const TAB = 7
const BAR_FRAME = 3
const fetchMock = vi.fn<typeof fetch>()
const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status })

interface Sent { method: string; path: string; body: Record<string, unknown> | undefined }

let accountId: string
let privateKey: CryptoKey
let tab: { id: number; url: string; status: 'loading' | 'complete' }
let toContent: Array<{ message: BackgroundToContent; frameId: number | undefined }>
let secrets: OpenSecretRow[]
let routes: Record<string, (sent: Sent) => Response>
/** What the host's visibility guard answers `capture.verify`. */
let visible: boolean
/** The host's bar in the top frame, which a `capture.show` of its capture keeps while this is on. */
let hostBar: { key: string; capture: string } | null
/** Off, every show lands on a fresh page load, so the host makes a new bar. */
let keepsBar: boolean
/** Overrides the host's answer to `capture.show`. */
let showReply: ((message: { key: string; capture: string }) => unknown) | undefined
/** Holds the host's answers to `capture.show` until it resolves; the host has acted by then. */
let showGate: Promise<void> | undefined

const content = (overrides: object = {}) => ({ id: browser.runtime.id, url: tab.url, tab: { id: TAB, url: tab.url }, frameId: 0, ...overrides }) as never
const bar = (overrides: object = {}) => ({ id: browser.runtime.id, url: browser.runtime.getURL('/notification.html' as '/popup.html'), tab: { id: TAB }, frameId: BAR_FRAME, ...overrides }) as never
const submitted = (username: string | null, password: string, changed = false): CaptureSubmitted => ({ kind: 'capture.submitted', username, password, changed })
const press = (): PickInput => ({ kind: 'pointer', at: Date.now(), x: 10, y: 10 })

/** The key of the newest `capture.show` to the tab. */
let lastShownKey: string
/** The key the host's bar holds and posted to its frame, else that of the newest show. */
const shownKey = () => hostBar?.key ?? lastShownKey
type Unkeyed = NotificationToBackground extends infer M ? M extends unknown ? Omit<M, 'key'> : never : never
/** A message from the bar page, with the key its host posted to it unless one is given. */
const ask = (message: Unkeyed & { key?: string }, sender = bar()) =>
	handleNotification({ key: shownKey(), ...message } as NotificationToBackground, sender)
const state = (sender = bar()) => ask({ kind: 'capture.state' }, sender)
const kinds = () => toContent.map(({ message, frameId }) => [message.kind, frameId])
const decrypt = (ciphertext: unknown) => rsaDecrypt(ciphertext as string, privateKey)
const ticks = async () => {
	for (let i = 0; i < 20; i++) await Promise.resolve()
}
/** The show delay, and whatever the show awaits. */
const settle = async () => {
	await vi.advanceTimersByTimeAsync(SHOW_DELAY_MS)
	await ticks()
}
/** A submit, the bar shown, and its state read, which binds the bar frame. */
async function submit(message: CaptureSubmitted, sender = content()) {
	await captureSubmitted(message, sender)
	await settle()
}

/** Every request by `METHOD /path`; the suites and manifest answer with `secrets`, so the syncs after writes succeed. */
const requests = () => fetchMock.mock.calls.map(([input, init]) => ({
	method: init?.method ?? 'GET',
	path: new URL(String(input)).pathname.replace('/index.php/apps/keepiq', ''),
	body: init?.body ? JSON.parse(String(init.body)) as Record<string, unknown> : undefined,
}))
const writes = () => requests().filter(({ method }) => method !== 'GET').map(({ method, path }) => `${method} ${path}`)
const bodyOf = (line: string) => requests().find(({ method, path }) => `${method} ${path}` === line)?.body

async function vault(rows: OpenSecretRow[]) {
	secrets = rows
	const syncedAt = new Date().toISOString()
	await writeSnapshot(accountId, {
		suite: { id: suiteRow.id, unlockKeyEpoch: 1 }, secrets: rows, folders: [folderRow(), folderRow({ id: 'f2', name: 'Clients', parentId: 'f1' })], types: typeRows,
		syncedAt, listsSyncedAt: syncedAt, newestUpdatedAt: null, total: rows.length,
	})
}

async function item(id: string, username: string | null, password: string, overrides: Partial<OpenSecretRow> = {}) {
	return secretRow({ id, name: `Item ${id}`, url: 'https://example.test/', login: username === null ? null : await encrypt(username), key: await encrypt(password), ...overrides })
}

const settings = (patch: object) => browser.storage.local.set({ [`settings.${accountId}`]: patch })

beforeEach(async () => {
	fakeBrowser.reset()
	vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date'], shouldAdvanceTime: true })
	vi.stubGlobal('fetch', fetchMock)
	fetchMock.mockReset()
	// Back to the real write, dropping a once-answer a test left unused.
	vi.mocked(createItem).mockReset()
	vi.spyOn(console, 'error').mockImplementation(() => {})
	accountId = (await addAccount({
		serverUrl: 'https://cloud.example.org', uid: 'alice', loginName: 'alice', displayName: 'Alice', email: null, avatarDataUrl: null, appPassword: 'pw', keepiqVersion: '0.3.12',
	}, { ...suiteRow })).id
	const pkcs8 = pemToPkcs8(envelope.privateKeyPem)
	privateKey = await importPrivateKey(pkcs8)
	await putKey(accountId, toBase64(pkcs8))
	await vault([])
	tab = { id: TAB, url: 'https://example.test/login', status: 'complete' }
	toContent = []
	lastShownKey = 'none'
	routes = {}
	visible = true
	hostBar = null
	keepsBar = false
	showReply = undefined
	showGate = undefined
	vi.spyOn(browser.tabs, 'get').mockImplementation(async () => ({ ...tab }) as never)
	vi.spyOn(browser.tabs, 'query').mockImplementation(async () => [{ ...tab, active: true }] as never)
	vi.spyOn(browser.tabs, 'sendMessage').mockImplementation(async (_tabId: number, message: unknown, options?: { frameId?: number }) => {
		toContent.push({ message: structuredClone(message) as BackgroundToContent, frameId: options?.frameId })
		const kind = (message as BackgroundToContent).kind
		if (kind === 'capture.show') {
			const show = message as { key: string; capture: string }
			lastShownKey = show.key
			let reply: unknown
			if (showReply) reply = showReply(show)
			else {
				if (!keepsBar || hostBar?.capture !== show.capture) hostBar = { key: show.key, capture: show.capture }
				reply = hostBar.key
			}
			await showGate
			return reply as never
		}
		if (kind === 'capture.hide' && hostBar?.key === (message as { key: string }).key) hostBar = null
		if (kind === 'capture.verify') return visible as never
		return kind === 'fill.execute' ? { filled: 'both' } as never : undefined as never
	})
	fetchMock.mockImplementation(async (input, init) => {
		const url = new URL(String(input))
		const sent: Sent = { method: init?.method ?? 'GET', path: url.pathname.replace('/index.php/apps/keepiq', ''), body: init?.body ? JSON.parse(String(init.body)) : undefined }
		const route = routes[`${sent.method} ${sent.path}`]
		if (route) return route(sent)
		if (sent.path === '/api/v1/suites') return json([suiteRow])
		if (sent.path === '/api/v1/offline/manifest') return json({ suite: suiteRow, secrets, folders: [folderRow()], types: typeRows, syncedAt: '' })
		const own = /^\/api\/v1\/secrets\/([^/]+)(\/write-context|\/share-save)?$/.exec(sent.path)
		const row = own && secrets.find((secret) => secret.id === decodeURIComponent(own[1]!))
		if (row && own[2] === '/write-context') return json({ sourceSecretId: row.id, effectiveGrade: 'owner', ownerCertificate: envelope.certificatePem, sourceUpdatedAt: row.updatedAt })
		if (row && own[2] === '/share-save' && sent.method === 'PUT') return json({ secret: row, federated: { ownerOnly: false } })
		if (row && !own[2] && sent.method === 'GET') return json(row)
		return json({ message: 'Not found' }, 404)
	})
	registerCaptureQueue()
})

afterEach(async () => {
	dropAllCaptures()
	// Also forgets a saved bar's close, which a later test's submit would otherwise hide.
	for (const id of [TAB, 8]) await fakeBrowser.tabs.onRemoved.trigger(id, { windowId: 1, isWindowClosing: false })
	vi.useRealTimers()
})

describe('capture.submitted', () => {
	it('keeps a new login and shows the save bar in the top frame only, with a fresh 128-bit key', async () => {
		await submit(submitted('alice', 'hunter2'))
		expect(kinds()).toEqual([['capture.show', 0]])
		expect(shownKey()).toMatch(/^[0-9a-f]{32}$/)
		expect(await state()).toEqual({
			mode: 'add', host: 'example.test', theme: 'system', folders: [{ id: 'f1', name: 'Work' }, { id: 'f2', name: 'Work / Clients' }],
		})
	})

	it('never gives the bar or the content script the password, and keeps it out of storage', async () => {
		await submit(submitted('alice', 'hunter2'))
		expect(JSON.stringify(await state())).not.toContain('hunter2')
		expect(JSON.stringify(toContent)).not.toContain('hunter2')
		expect(JSON.stringify(await browser.storage.local.get(null))).not.toContain('hunter2')
		expect(JSON.stringify(await browser.storage.session.get(null))).not.toContain('hunter2')
	})

	it.each([
		['an empty password', submitted('alice', '')],
		['no password', { kind: 'capture.submitted', username: 'alice', changed: false }],
		['a username that is no string', { kind: 'capture.submitted', username: 7, password: 'hunter2', changed: false }],
		['no changed flag', { kind: 'capture.submitted', username: 'alice', password: 'hunter2' }],
	])('drops %s', async (_, message) => {
		await submit(message as CaptureSubmitted)
		expect(toContent).toEqual([])
	})

	it('drops a submit while the vault is locked, showing no bar', async () => {
		await lock(accountId)
		toContent = []
		await submit(submitted('alice', 'hunter2'))
		expect(kinds()).toEqual([])
	})

	it.each(['file:///login.html', 'chrome-extension://abc/page.html', 'about:blank', 'example.test/login'])('drops a submit from a frame on %s', async (url) => {
		await submit(submitted('alice', 'hunter2'), content({ url }))
		expect(kinds()).toEqual([])
	})

	it('drops a submit from a sender without a tab or frame', async () => {
		await submit(submitted('alice', 'hunter2'), content({ tab: undefined }))
		await submit(submitted('alice', 'hunter2'), content({ frameId: undefined }))
		expect(toContent).toEqual([])
	})

	it('shows nothing for a username already saved with this password', async () => {
		await vault([await item('a', 'alice', 'hunter2')])
		await submit(submitted('alice', 'hunter2'))
		expect(kinds()).toEqual([])
	})

	it('drops a new login while "Ask to add login" is off', async () => {
		await settings({ askToAdd: false })
		await submit(submitted('alice', 'hunter2'))
		expect(kinds()).toEqual([])
	})

	it('shows no bar on an excluded domain, while its item stays fillable', async () => {
		await vault([await item('a', 'alice', 'old')])
		await settings({ excludedDomains: ['example.test'] })
		await submit(submitted('bob', 'hunter2'))
		await submit(submitted('alice', 'hunter2'))
		expect(kinds()).toEqual([])
		expect((await matchingLogins(accountId, tab.url)).rows.map((row) => row.id)).toEqual(['a'])
		expect(await fill({ itemId: 'a', tabId: TAB, ask: 'reply' })).toEqual({ filled: 'both' })
	})

	it('offers to update the one item with this username, naming the item and its username', async () => {
		await vault([await item('a', 'alice', 'old'), await item('b', 'bob', 'old')])
		await submit(submitted('alice', 'hunter2'))
		const reply = await state()
		expect(reply).toMatchObject({ mode: 'update', itemName: 'Item a', username: 'alice', host: 'example.test' })
		// The name and username, as the question and the item row show them; never the password, old or new.
		expect(Object.keys(reply as object).filter((name) => name !== 'shortcut').sort()).toEqual(['folders', 'host', 'itemName', 'mode', 'theme', 'username'])
		expect(JSON.stringify(reply)).not.toMatch(/hunter2|"old"/)
	})

	it('names no username for an item without one', async () => {
		await vault([await item('a', null, 'old')])
		await submit(submitted(null, 'hunter2'))
		const reply = await state()
		expect(reply).toMatchObject({ mode: 'update', itemName: 'Item a' })
		expect(reply).not.toHaveProperty('username')
		expect(Object.keys(reply as object).filter((name) => name !== 'shortcut').sort()).toEqual(['folders', 'host', 'itemName', 'mode', 'theme'])
	})

	it('drops a changed password while "Ask to update existing login" is off, and still offers a new login', async () => {
		await vault([await item('a', 'alice', 'old')])
		await settings({ askToUpdate: false })
		await submit(submitted('alice', 'hunter2'))
		expect(kinds()).toEqual([])
		await submit(submitted('bob', 'hunter2'))
		expect(await state()).toMatchObject({ mode: 'add' })
	})

	it('offers a new login when several items hold the username, since which one changed is unknown', async () => {
		await vault([await item('a', 'alice', 'one'), await item('b', 'alice', 'two')])
		await submit(submitted('alice', 'hunter2'))
		expect(await state()).toMatchObject({ mode: 'add' })
	})

	it('compares only items matching the submitting frame', async () => {
		await vault([await item('a', 'alice', 'old', { url: 'https://other.test/' })])
		await submit(submitted('alice', 'hunter2'))
		expect(await state()).toMatchObject({ mode: 'add' })
	})

	it.each([['use-only', { useOnly: true }], ['read-only', { readOnly: true }]])('offers a new login, not an update, for a new password on a %s copy, which may not be changed', async (_, copy) => {
		await vault([await item('a', 'alice', 'old', copy)])
		await submit(submitted('alice', 'hunter2'))
		expect(await state()).toMatchObject({ mode: 'add' })
	})

	it('updates the one editable login beside a read-only copy under the frame', async () => {
		await vault([await item('a', 'alice', 'old', { readOnly: true }), await item('b', 'alice', 'old')])
		await submit(submitted('alice', 'hunter2'))
		expect(await state()).toMatchObject({ mode: 'update', itemName: 'Item b' })
	})

	it('lets a newer submit replace an older capture, also when it needs no bar', async () => {
		await vault([await item('a', 'alice', 'hunter2')])
		await submit(submitted('alice', 'typo'))
		expect(await state()).toMatchObject({ mode: 'update' })
		const key = shownKey()
		await submit(submitted('alice', 'hunter2'))
		expect(await state()).toBeNull()
		expect(toContent.at(-1)).toEqual({ message: { kind: 'capture.hide', key }, frameId: 0 })
	})

	it('closes the bar of an older capture when a newer submit needs a bar too, and shows the new capture in a bar that acts on it', async () => {
		await submit(submitted('alice', 'typo'))
		expect(await state()).toMatchObject({ mode: 'add' })
		const first = toContent.at(-1)!.message as { key: string; capture: string }
		toContent = []
		await captureSubmitted(submitted('alice', 'hunter2'), content())
		await ticks()
		expect(toContent).toEqual([{ message: { kind: 'capture.hide', key: first.key }, frameId: 0 }])
		await settle()
		const second = toContent.at(-1)!.message as { kind: string; key: string; capture: string }
		expect(second).toMatchObject({ kind: 'capture.show' })
		expect(second.key).not.toBe(first.key)
		expect(second.capture).not.toBe(first.capture)
		expect(await ask({ kind: 'capture.state', key: first.key })).toBeNull()
		const newBar = bar({ frameId: BAR_FRAME + 1 })
		expect(await state(newBar)).toMatchObject({ mode: 'add' })
		routes['POST /api/v1/secrets'] = () => json(secretRow({ id: 'new' }), 201)
		expect(await ask({ kind: 'capture.save', folderId: null, input: press() }, newBar)).toEqual({ ok: true, value: null })
		expect(await decrypt(bodyOf('POST /api/v1/secrets')!.key)).toBe('hunter2')
	})

	it('closes the "Login saved" bar at once when a newer submit needs a bar, and shows the new capture', async () => {
		await submit(submitted('alice', 'hunter2'))
		await state()
		routes['POST /api/v1/secrets'] = () => json(secretRow({ id: 'new' }), 201)
		expect(await ask({ kind: 'capture.save', folderId: null, input: press() })).toEqual({ ok: true, value: null })
		const savedKey = shownKey()
		toContent = []
		await submit(submitted('bob', 's3cret'))
		expect(toContent[0]).toEqual({ message: { kind: 'capture.hide', key: savedKey }, frameId: 0 })
		expect(kinds()).toEqual([['capture.hide', 0], ['capture.show', 0]])
		const newBar = bar({ frameId: BAR_FRAME + 1 })
		expect(await state(newBar)).toMatchObject({ mode: 'add' })
		vi.advanceTimersByTime(SAVED_CLOSE_MS)
		expect(kinds()).toEqual([['capture.hide', 0], ['capture.show', 0]])
		expect(await ask({ kind: 'capture.save', folderId: null, input: press() }, newBar)).toEqual({ ok: true, value: null })
	})
})

describe('the save or update lookup, whatever the fill rule', () => {
	/** A submit from the top frame of a tab on `url`. */
	async function submitOn(url: string, username: string, password: string) {
		tab.url = url
		await submit(submitted(username, password))
	}

	it('offers a new login, not an update, for a new password on a sibling app under the same base domain', async () => {
		await vault([await item('a', 'u', 'old', { url: 'https://app1.corp.example.com/' })])
		await submitOn('https://app2.corp.example.com/login', 'u', 'new')
		expect(await state()).toMatchObject({ mode: 'add' })
	})

	it('shows nothing under Exact for a login saved from the bar, submitted again on the login page', async () => {
		await settings({ uriMatch: 'exact' })
		await vault([await item('a', 'u', 'pw', { url: 'https://site.test' })])
		await submitOn('https://site.test/login', 'u', 'pw')
		expect(kinds()).toEqual([])
	})

	it('shows nothing under Never for the same username and password on the site', async () => {
		await settings({ uriMatch: 'never' })
		await vault([await item('a', 'u', 'pw', { url: 'https://site.test/' })])
		await submitOn('https://login.site.test/sign-in', 'u', 'pw')
		expect(kinds()).toEqual([])
	})

	it('offers a new login for a new password on a path the saved url is no prefix of', async () => {
		await vault([await item('a', 'u', 'old', { url: 'https://example.com/app1' })])
		await submitOn('https://example.com/app2/login', 'u', 'new')
		expect(await state()).toMatchObject({ mode: 'add' })
	})

	it('offers a new login for a new password on http for a login saved on https', async () => {
		await vault([await item('a', 'u', 'old', { url: 'https://site.test' })])
		await submitOn('http://site.test/login', 'u', 'new')
		expect(await state()).toMatchObject({ mode: 'add' })
	})

	it('offers to update the login whose url is a prefix of the frame\'s', async () => {
		await vault([await item('a', 'u', 'old', { url: 'https://site.test' })])
		await submitOn('https://site.test/login', 'u', 'new')
		expect(await state()).toMatchObject({ mode: 'update', itemName: 'Item a', username: 'u' })
	})

	it('offers a new login when two editable logins on the site hold the username', async () => {
		await vault([await item('a', 'u', 'one', { url: 'https://site.test/' }), await item('b', 'u', 'two', { url: 'https://site.test/' })])
		await submitOn('https://site.test/login', 'u', 'new')
		expect(await state()).toMatchObject({ mode: 'add' })
	})

	it('compares usernames exactly, without folding case', async () => {
		await vault([await item('a', 'Alice', 'pw', { url: 'https://site.test/' })])
		await submitOn('https://site.test/login', 'alice', 'pw')
		expect(await state()).toMatchObject({ mode: 'add' })
	})

	it.each([
		['http://localhost:8080/', 'http://localhost:9090/login'],
		['http://intranet:8080/', 'http://intranet:9090/login'],
	])('treats %s as the whole host: same credentials on %s are saved, a new password there is a new login', async (saved, page) => {
		await vault([await item('a', 'u', 'pw', { url: saved })])
		await submitOn(page, 'u', 'pw')
		expect(kinds()).toEqual([])
		await submitOn(page, 'u', 'new')
		expect(await state()).toMatchObject({ mode: 'add' })
		await submitOn(`${saved}login`, 'u', 'new')
		expect(await state()).toMatchObject({ mode: 'update', itemName: 'Item a' })
	})

	it.each([
		['https://corp.example.com/jira', 'https://corp.example.com/jira-test/login', 'add'],
		['https://corp.example.com/jira', 'https://corp.example.com/jira/login', 'update'],
		['https://corp.example.com/jira', 'https://corp.example.com/jira', 'update'],
		['https://corp.example.com/jira', 'https://corp.example.com/jira?next=1', 'update'],
		['https://corp.example.com/jira', 'https://corp.example.com/jira#login', 'update'],
		['https://corp.example.com/jira/', 'https://corp.example.com/jira/login', 'update'],
		['https://corp.example.com', 'https://corp.example.com/login', 'update'],
	])('takes a login for %s as under %s only at a path boundary', async (saved, page, mode) => {
		await vault([await item('a', 'u', 'old', { url: saved })])
		await submitOn(page, 'u', 'new')
		expect(await state()).toMatchObject({ mode })
	})

	it.each([
		['https://site.test/', 'https://login.site.test/sign-in', 'update'],
		['site.test', 'https://accounts.site.test/sign-in', 'update'],
		['https://site.test/app', 'https://login.site.test/sign-in', 'add'],
		['https://site.test/', 'http://login.site.test/sign-in', 'add'],
		['https://app1.site.test/', 'https://app2.site.test/sign-in', 'add'],
	])('takes a login for %s as the parent of %s only for a bare host', async (saved, page, mode) => {
		await vault([await item('a', 'u', 'old', { url: saved })])
		await submitOn(page, 'u', 'new')
		expect(await state()).toMatchObject({ mode })
	})

	it('prefers a login for the frame\'s own host over one for its parent host', async () => {
		await vault([await item('a', 'u', 'one', { url: 'https://site.test/' }), await item('b', 'u', 'two', { url: 'https://login.site.test/' })])
		await submitOn('https://login.site.test/sign-in', 'u', 'new')
		expect(await state()).toMatchObject({ mode: 'update', itemName: 'Item b' })
	})

	it('treats another IP address as another site', async () => {
		await vault([await item('a', 'u', 'pw', { url: 'http://10.0.0.5/' })])
		await submitOn('http://10.0.0.6/login', 'u', 'pw')
		expect(await state()).toMatchObject({ mode: 'add' })
	})
})

describe('showing', () => {
	it('waits the show delay after a submit, so a single-page login gets the bar', async () => {
		await captureSubmitted(submitted('alice', 'hunter2'), content())
		await vi.advanceTimersByTimeAsync(SHOW_DELAY_MS - 50)
		expect(kinds()).toEqual([])
		await settle()
		expect(kinds()).toEqual([['capture.show', 0]])
	})

	it('shows nothing on a page that starts loading within the delay, then shows once it completes', async () => {
		await captureSubmitted(submitted('alice', 'hunter2'), content())
		tab.status = 'loading'
		await fakeBrowser.tabs.onUpdated.trigger(TAB, { status: 'loading' }, { ...tab } as never)
		await settle()
		expect(kinds()).toEqual([])
		tab.status = 'complete'
		await fakeBrowser.tabs.onUpdated.trigger(TAB, { status: 'complete' }, { ...tab } as never)
		await vi.waitFor(() => expect(kinds()).toEqual([['capture.show', 0]]))
	})

	it('shows once for a page that loads and completes within the delay', async () => {
		await captureSubmitted(submitted('alice', 'hunter2'), content())
		await fakeBrowser.tabs.onUpdated.trigger(TAB, { status: 'loading' }, { ...tab } as never)
		await fakeBrowser.tabs.onUpdated.trigger(TAB, { status: 'complete' }, { ...tab } as never)
		await settle()
		await vi.advanceTimersByTimeAsync(SHOW_DELAY_MS)
		expect(kinds()).toEqual([['capture.show', 0]])
	})

	it('waits for the tab to finish loading before showing the bar', async () => {
		tab.status = 'loading'
		await submit(submitted('alice', 'hunter2'))
		expect(kinds()).toEqual([])
		tab.status = 'complete'
		await showPending(TAB)
		expect(kinds()).toEqual([['capture.show', 0]])
	})

	it('drops the capture instead of showing it on a tab now on another site', async () => {
		tab.status = 'loading'
		await submit(submitted('alice', 'hunter2'))
		// As after an MV3 wake, with no `onUpdated` seen for the navigation.
		tab = { id: TAB, url: 'https://other.test/', status: 'complete' }
		await showPending(TAB)
		expect(kinds()).toEqual([])
		tab.url = 'https://example.test/'
		await showPending(TAB)
		expect(kinds()).toEqual([])
	})

	it('shows the bar once on the final page of a redirect chain within the site', async () => {
		await captureSubmitted(submitted('alice', 'hunter2'), content())
		for (const url of ['https://example.test/session', 'https://www.example.test/home']) {
			tab = { id: TAB, url, status: 'loading' }
			await fakeBrowser.tabs.onUpdated.trigger(TAB, { status: 'loading', url }, { ...tab } as never)
		}
		await settle()
		tab.status = 'complete'
		await fakeBrowser.tabs.onUpdated.trigger(TAB, { status: 'complete' }, { ...tab } as never)
		await vi.waitFor(() => expect(kinds()).toEqual([['capture.show', 0]]))
	})

	it('dismisses the bar when the tab leaves the site', async () => {
		await submit(submitted('alice', 'hunter2'))
		tab.url = 'https://other.test/'
		await fakeBrowser.tabs.onUpdated.trigger(TAB, { status: 'loading', url: tab.url }, { ...tab } as never)
		expect(kinds()).toEqual([['capture.show', 0], ['capture.hide', 0]])
		expect(await state()).toBeNull()
	})

	it('sends the focus command to the top frame only while a capture is pending', () => {
		focusBar(TAB)
		expect(kinds()).toEqual([])
	})

	it('shows again on the host\'s capture.reshow from the top frame only', async () => {
		await submit(submitted('alice', 'hunter2'))
		await reshow(content({ frameId: 2 }))
		expect(kinds()).toEqual([['capture.show', 0]])
		const first = shownKey()
		await reshow(content())
		expect(kinds()).toEqual([['capture.show', 0], ['capture.show', 0]])
		expect(shownKey()).not.toBe(first)
	})

	it('relays the focus command to the bar', async () => {
		await submit(submitted('alice', 'hunter2'))
		focusBar(TAB)
		await ticks()
		expect(kinds().at(-1)).toEqual(['capture.focus', 0])
	})
})

describe('the bar key', () => {
	beforeEach(async () => {
		await submit(submitted('alice', 'hunter2'))
	})

	it('answers nothing without the key, or with another', async () => {
		expect(await handleNotification({ kind: 'capture.state' } as never, bar())).toBeNull()
		expect(await ask({ kind: 'capture.state', key: 'f'.repeat(32) })).toBeNull()
		expect(await state()).not.toBeNull()
	})

	it('binds the first frame to present the key, and answers no other frame, a framed copy included', async () => {
		expect(await state()).not.toBeNull()
		expect(await state(bar({ frameId: 9 }))).toBeNull()
		expect(await ask({ kind: 'capture.save', folderId: null, input: press() }, bar({ frameId: 9 }))).toBeNull()
		expect(await ask({ kind: 'capture.dismiss', never: true, input: press() }, bar({ frameId: 9 }))).toBeNull()
		expect(writes()).toEqual([])
		expect((await readSettings(accountId)).excludedDomains).toEqual([])
	})

	it('binds only through capture.state, so no decision comes from a frame that never asked', async () => {
		expect(await ask({ kind: 'capture.save', folderId: null, input: press() })).toBeNull()
		expect(await ask({ kind: 'capture.dismiss', never: false })).toBeNull()
		expect(writes()).toEqual([])
	})

	it('answers nothing for the key of another tab', async () => {
		expect(await state(bar({ tab: { id: 99 } }))).toBeNull()
		expect(await state(bar({ tab: undefined }))).toBeNull()
	})

	it('relays capture.expand to the host in the top frame, from the bound frame only', async () => {
		expect(await ask({ kind: 'capture.expand' })).toBeNull()
		expect(kinds()).toEqual([['capture.show', 0]])
		await state()
		expect(await ask({ kind: 'capture.expand' }, bar({ frameId: 9 }))).toBeNull()
		expect(await ask({ kind: 'capture.expand' })).toBeNull()
		expect(toContent.at(-1)).toEqual({ message: { kind: 'capture.expand', key: shownKey() }, frameId: 0 })
		expect(kinds()).toHaveLength(2)
	})

	it('names the assigned focus_notification_bar shortcut, and none once the user unassigned it', async () => {
		const getAll = vi.fn(async () => [{ name: 'autofill_login', shortcut: 'Ctrl+Shift+L' }, { name: 'focus_notification_bar', shortcut: 'Alt+Shift+K' }])
		Object.assign(browser, { commands: { getAll } })
		expect(await state()).toMatchObject({ shortcut: 'Alt+Shift+K' })
		getAll.mockResolvedValue([{ name: 'focus_notification_bar', shortcut: '' }])
		expect(await state()).not.toHaveProperty('shortcut')
		getAll.mockRejectedValue(new Error('no commands'))
		expect(await state()).not.toHaveProperty('shortcut')
	})

	it('keeps an earlier show\'s key, for the bar a slower load still shows', async () => {
		const first = shownKey()
		await showPending(TAB)
		expect(shownKey()).not.toBe(first)
		expect(await ask({ kind: 'capture.state', key: first })).not.toBeNull()
	})

	it('drops the oldest keys once more than four shows each made a new bar', async () => {
		const first = shownKey()
		for (let i = 0; i < 4; i++) await showPending(TAB)
		expect(await ask({ kind: 'capture.state', key: first })).toBeNull()
		expect(await state()).not.toBeNull()
	})

	describe('on a bar the host keeps through many shows of its capture, as a single-page app\'s route changes bring', () => {
		let first: string

		beforeEach(async () => {
			first = shownKey()
			keepsBar = true
			for (let i = 0; i < 6; i++) {
				await fakeBrowser.tabs.onUpdated.trigger(TAB, { status: 'complete' }, { ...tab } as never)
				await ticks()
			}
			expect(kinds().filter(([kind]) => kind === 'capture.show')).toHaveLength(7)
			// Bound only now, so the key also survived while its frame was still loading.
			expect(await ask({ kind: 'capture.state', key: first })).toMatchObject({ mode: 'add' })
		})

		it('saves from it', async () => {
			routes['POST /api/v1/secrets'] = () => json(secretRow({ id: 'new' }), 201)
			expect(await ask({ kind: 'capture.save', key: first, folderId: null, input: press() })).toEqual({ ok: true, value: null })
			expect(await decrypt(bodyOf('POST /api/v1/secrets')!.key)).toBe('hunter2')
		})

		it('closes it', async () => {
			expect(await ask({ kind: 'capture.dismiss', key: first, never: false })).toBeNull()
			expect(toContent.at(-1)).toEqual({ message: { kind: 'capture.hide', key: first }, frameId: 0 })
			expect(await ask({ kind: 'capture.state', key: first })).toBeNull()
		})

		it('hides it on a lock', async () => {
			await lock(accountId)
			await vi.waitFor(() => expect(toContent).toContainEqual({ message: { kind: 'capture.hide', key: first }, frameId: 0 }))
		})

		it('hides only keys a bar may hold', () => {
			dropAllCaptures()
			expect(toContent.filter(({ message }) => message.kind === 'capture.hide')).toEqual([{ message: { kind: 'capture.hide', key: first }, frameId: 0 }])
		})
	})

	describe('on a burst of shows sent before the first answer, with no bar on screen', () => {
		beforeEach(async () => {
			// The host closed the bar, as when the page removed it.
			hostBar = null
			keepsBar = true
			let release!: () => void
			showGate = new Promise((resolve) => (release = resolve))
			toContent = []
			for (let i = 0; i < 6; i++) void fakeBrowser.tabs.onUpdated.trigger(TAB, { status: 'complete' }, { ...tab } as never)
			await vi.waitFor(() => expect(kinds()).toEqual(Array.from({ length: 6 }, () => ['capture.show', 0])))
			expect(shownKey()).toBe((toContent[0]!.message as { key: string }).key)
			release()
			await ticks()
			expect(await state()).toMatchObject({ mode: 'add' })
		})

		it('saves from the bar the first show made', async () => {
			routes['POST /api/v1/secrets'] = () => json(secretRow({ id: 'new' }), 201)
			expect(await ask({ kind: 'capture.save', folderId: null, input: press() })).toEqual({ ok: true, value: null })
			expect(await decrypt(bodyOf('POST /api/v1/secrets')!.key)).toBe('hunter2')
		})

		it('closes it', async () => {
			const key = shownKey()
			expect(await ask({ kind: 'capture.dismiss', never: false })).toBeNull()
			expect(toContent.at(-1)).toEqual({ message: { kind: 'capture.hide', key }, frameId: 0 })
		})

		it('keeps none of the unused keys', async () => {
			for (const { message } of toContent.slice(1)) expect(await ask({ kind: 'capture.state', key: (message as { key: string }).key })).toBeNull()
		})
	})

	it('replaces a bar back from the back/forward cache whose key was dropped since, with one that acts', async () => {
		const restored = { ...hostBar! }
		// Four page loads, each with a bar of its own.
		for (let i = 0; i < 4; i++) await showPending(TAB)
		expect(await ask({ kind: 'capture.state', key: restored.key })).toBeNull()
		hostBar = restored
		keepsBar = true
		toContent = []
		await showPending(TAB)
		const unused = (toContent[0]!.message as { key: string }).key
		expect(toContent.slice(1)).toEqual([
			{ message: { kind: 'capture.hide', key: restored.key }, frameId: 0 },
			{ message: { kind: 'capture.show', key: shownKey(), capture: restored.capture }, frameId: 0 },
		])
		expect(shownKey()).not.toBe(unused)
		expect(await ask({ kind: 'capture.state', key: unused })).toBeNull()
		expect(await state()).toMatchObject({ mode: 'add' })
		routes['POST /api/v1/secrets'] = () => json(secretRow({ id: 'new' }), 201)
		expect(await ask({ kind: 'capture.save', folderId: null, input: press() })).toEqual({ ok: true, value: null })
	})

	it.each([
		['a key no show issued', async () => 'f'.repeat(32)],
		['the key of an earlier capture', async () => {
			const earlier = shownKey()
			await submit(submitted('bob', 's3cret'))
			return earlier
		}],
		['the key of a saved bar', async () => {
			await state()
			routes['POST /api/v1/secrets'] = () => json(secretRow({ id: 'new' }), 201)
			await ask({ kind: 'capture.save', folderId: null, input: press() })
			const saved = shownKey()
			await submit(submitted('bob', 's3cret'))
			return saved
		}],
	])('keeps nothing alive for an answer naming %s, and stops after one more show', async (_, named) => {
		const claimed = await named()
		const current = shownKey()
		showReply = () => claimed
		toContent = []
		await showPending(TAB)
		expect(kinds()).toEqual([['capture.show', 0], ['capture.hide', 0], ['capture.show', 0], ['capture.hide', 0]])
		expect(toContent.filter(({ message }) => message.kind === 'capture.hide').map(({ message }) => message)).toEqual([
			{ kind: 'capture.hide', key: claimed }, { kind: 'capture.hide', key: claimed },
		])
		for (const key of [claimed, ...toContent.filter(({ message }) => message.kind === 'capture.show').map(({ message }) => (message as { key: string }).key)]) {
			expect(await ask({ kind: 'capture.state', key })).toBeNull()
		}
		// The bar the background did show still acts.
		expect(await ask({ kind: 'capture.state', key: current })).not.toBeNull()
	})

	it('changes nothing for the worse on a show without an answer, as on a page without the content script yet', async () => {
		const first = shownKey()
		showReply = () => undefined
		await showPending(TAB)
		expect(await ask({ kind: 'capture.state', key: first })).not.toBeNull()
		expect(await ask({ kind: 'capture.state', key: lastShownKey }, bar({ frameId: BAR_FRAME + 1 }))).not.toBeNull()
	})
})

describe('lifetime', () => {
	it('expires after five minutes, hiding the bar and forgetting the credential', async () => {
		await submit(submitted('alice', 'hunter2'))
		expect(await state()).not.toBeNull()
		vi.advanceTimersByTime(CAPTURE_LIFESPAN_MS - 1000)
		expect(await state()).not.toBeNull()
		vi.advanceTimersByTime(1000)
		expect(kinds().at(-1)).toEqual(['capture.hide', 0])
		expect(await state()).toBeNull()
	})

	it('expires on use even when its timer has not run yet', async () => {
		await submit(submitted('alice', 'hunter2'))
		expect(await state()).not.toBeNull()
		vi.setSystemTime(Date.now() + CAPTURE_LIFESPAN_MS)
		expect(await state()).toBeNull()
	})

	it('drops the capture when the tab closes', async () => {
		await submit(submitted('alice', 'hunter2'))
		await fakeBrowser.tabs.onRemoved.trigger(TAB, { windowId: 1, isWindowClosing: false })
		expect(await state()).toBeNull()
	})

	it('drops the capture and hides the bar when the vault locks, forgetting what each tab used', async () => {
		await submit(submitted('alice', 'hunter2'))
		rememberItem(8, 'a')
		await lock(accountId)
		await vi.waitFor(async () => expect(await state()).toBeNull())
		expect(kinds()).toContainEqual(['capture.hide', 0])
		expect(rememberedItem(8)).toBeUndefined()
	})

	it('drops every capture and forgets what each tab used on an account switch', async () => {
		await submit(submitted('alice', 'hunter2'))
		rememberItem(8, 'a')
		dropAllCaptures()
		expect(await state()).toBeNull()
		expect(rememberedItem(8)).toBeUndefined()
	})
})

describe('password change forms', () => {
	it('updates the item holding the username', async () => {
		await vault([await item('a', 'alice', 'old'), await item('b', 'bob', 'old')])
		await submit(submitted('alice', 'n3w', true))
		expect(await state()).toMatchObject({ mode: 'update', itemName: 'Item a' })
	})

	it('without a username, updates the item this tab filled, else the only match', async () => {
		await vault([await item('a', 'alice', 'old'), await item('b', 'bob', 'old')])
		await submit(submitted(null, 'n3w', true))
		expect(kinds()).toEqual([])
		await fill({ itemId: 'b', tabId: TAB, ask: 'reply' })
		await submit(submitted(null, 'n3w', true))
		expect(await state()).toMatchObject({ mode: 'update', itemName: 'Item b', username: 'bob' })
		await vault([await item('c', 'carol', 'old')])
		await submit(submitted(null, 'n3w', true), content({ tab: { id: 8, url: tab.url } }))
		expect(await state(bar({ tab: { id: 8 } }))).toMatchObject({ mode: 'update', itemName: 'Item c' })
	})

	it('updates the item this tab captured before', async () => {
		await vault([await item('a', 'alice', 'hunter2'), await item('b', 'bob', 'old')])
		await submit(submitted('alice', 'hunter2'))
		await submit(submitted(null, 'n3w', true))
		expect(await state()).toMatchObject({ mode: 'update', itemName: 'Item a' })
	})

	it('offers a new login for a username no item holds', async () => {
		await vault([await item('a', 'alice', 'old')])
		await submit(submitted('bob', 'n3w', true))
		expect(await state()).toMatchObject({ mode: 'add' })
	})

	describe('on another path than the login\'s, with nothing remembered for the tab', () => {
		beforeEach(() => {
			tab.url = 'https://github.com/settings/security'
		})

		it.each([['with', 'alice'], ['without', null]])('updates the one login on the frame\'s host, %s a username', async (_, username) => {
			await vault([await item('a', 'alice', 'old', { url: 'https://github.com/login' })])
			await submit(submitted(username, 'n3w', true))
			expect(await state()).toMatchObject({ mode: 'update', itemName: 'Item a', username: 'alice' })
		})

		it('does not reach a login on a sibling subdomain, and offers a new login for the username instead', async () => {
			await vault([await item('a', 'alice', 'old', { url: 'https://gist.github.com/login' })])
			await submit(submitted('alice', 'n3w', true))
			expect(await state()).toMatchObject({ mode: 'add' })
			await submit(submitted(null, 'n3w', true))
			expect(kinds().at(-1)).toEqual(['capture.hide', 0])
			expect(await state()).toBeNull()
		})

		it('offers a new login when two logins on the host hold the username, and nothing without a username', async () => {
			await vault([await item('a', 'alice', 'one', { url: 'https://github.com/login' }), await item('b', 'alice', 'two', { url: 'https://github.com/session' })])
			await submit(submitted('alice', 'n3w', true))
			expect(await state()).toMatchObject({ mode: 'add' })
			await submit(submitted(null, 'n3w', true))
			expect(await state()).toBeNull()
		})

		it('shows nothing for a new password the login already holds', async () => {
			await vault([await item('a', 'alice', 'n3w', { url: 'https://github.com/login' })])
			await submit(submitted('alice', 'n3w', true))
			await submit(submitted(null, 'n3w', true))
			expect(kinds()).toEqual([])
		})
	})

	it.each([['use-only', { useOnly: true }], ['read-only', { readOnly: true }]])('offers a new login for a %s copy, which may not be changed', async (_, copy) => {
		await vault([await item('a', 'alice', 'old', copy)])
		await submit(submitted('alice', 'n3w', true))
		expect(await state()).toMatchObject({ mode: 'add' })
		await fill({ itemId: 'a', tabId: TAB, ask: 'reply' })
		await submit(submitted(null, 'n3w', true))
		expect(await state()).toMatchObject({ mode: 'add' })
	})

	describe('without a username', () => {
		const saveFromBar = async () => {
			routes['POST /api/v1/secrets'] = () => json(secretRow({ id: 'new' }), 201)
			expect(await ask({ kind: 'capture.save', folderId: null, input: press() })).toEqual({ ok: true, value: null })
			const body = bodyOf('POST /api/v1/secrets')!
			return { login: await decrypt(body.login), key: await decrypt(body.key) }
		}

		it('offers nothing beside a read-only team login, rather than the other login on the site', async () => {
			await vault([await item('a', 'alice', 'old'), await item('t', 'team', 'shared', { readOnly: true })])
			await submit(submitted(null, 'n3w', true))
			expect(kinds()).toEqual([])
		})

		it.each([['use-only', { useOnly: true }], ['read-only', { readOnly: true }]])('saves a new password for the only login, a %s one, under its username', async (_, copy) => {
			await vault([await item('t', 'team', 'shared', copy)])
			await submit(submitted(null, 'n3w', true))
			expect(await state()).toMatchObject({ mode: 'add' })
			expect(await saveFromBar()).toEqual({ login: 'team', key: 'n3w' })
		})

		it('saves a new password for the use-only login this tab filled under its username', async () => {
			await vault([await item('a', 'alice', 'old'), await item('t', 'team', 'shared', { useOnly: true })])
			await fill({ itemId: 't', tabId: TAB, ask: 'reply' })
			await submit(submitted(null, 'n3w', true))
			expect(await state()).toMatchObject({ mode: 'add' })
			expect(await saveFromBar()).toEqual({ login: 'team', key: 'n3w' })
		})

		it('saves the new login under the username of a login deleted since', async () => {
			await vault([await item('a', 'alice', 'old')])
			await submit(submitted(null, 'n3w', true))
			expect(await state()).toMatchObject({ mode: 'update', itemName: 'Item a' })
			await vault([])
			expect(await state()).toMatchObject({ mode: 'add' })
			expect(await saveFromBar()).toEqual({ login: 'alice', key: 'n3w' })
		})
	})

	describe('with a username, deciding by its own login before the others holding it', () => {
		it('offers to update the login this tab filled, for a password another login with the username holds', async () => {
			await vault([await item('a', 'alice', 'old'), await item('b', 'alice', 'reused')])
			await fill({ itemId: 'a', tabId: TAB, ask: 'reply' })
			await submit(submitted('alice', 'reused', true))
			expect(await state()).toMatchObject({ mode: 'update', itemName: 'Item a', username: 'alice' })
		})

		it('offers to update the one login under the frame, for a password a login elsewhere on the site holds', async () => {
			await vault([await item('a', 'alice', 'old'), await item('b', 'alice', 'reused', { url: 'https://other.example.test/' })])
			await submit(submitted('alice', 'reused', true))
			expect(await state()).toMatchObject({ mode: 'update', itemName: 'Item a' })
		})

		it('shows nothing when its own login holds the password already', async () => {
			await vault([await item('a', 'alice', 'reused'), await item('b', 'alice', 'other', { url: 'https://other.example.test/' })])
			await submit(submitted('alice', 'reused', true))
			expect(kinds()).toEqual([])
		})

		it('without a login of its own, shows nothing when any login with the username holds the password', async () => {
			await vault([await item('a', 'alice', 'one'), await item('b', 'alice', 'reused')])
			await submit(submitted('alice', 'reused', true))
			expect(kinds()).toEqual([])
		})
	})
})

describe('a login in a cross-site iframe', () => {
	const frame = () => content({ url: 'https://auth.provider.test/login', frameId: 5, tab: { id: TAB, url: 'https://shop.test/' } })

	beforeEach(() => {
		tab.url = 'https://shop.test/'
	})

	it('is checked and saved under the iframe, shown in the top frame only and named with the top frame\'s host', async () => {
		await vault([await item('s', 'alice', 'old', { url: 'https://shop.test/' })])
		await submit(submitted('alice', 'hunter2'), frame())
		expect(kinds()).toEqual([['capture.show', 0]])
		expect(await state()).toMatchObject({ mode: 'add', host: 'auth.provider.test', tabHost: 'shop.test' })
		routes['POST /api/v1/secrets'] = () => json(secretRow({ id: 'new' }), 201)
		expect(await ask({ kind: 'capture.save', folderId: null, input: press() })).toEqual({ ok: true, value: null })
		expect(bodyOf('POST /api/v1/secrets')).toMatchObject({ name: 'auth.provider.test', url: 'https://auth.provider.test' })
	})

	it('updates the iframe\'s login, not the page\'s, naming both hosts', async () => {
		await vault([await item('s', 'alice', 'old', { url: 'https://shop.test/' }), await item('p', 'alice', 'old', { url: 'https://auth.provider.test/' })])
		await submit(submitted('alice', 'hunter2'), frame())
		expect(kinds()).toEqual([['capture.show', 0]])
		expect(await state()).toMatchObject({ mode: 'update', itemName: 'Item p', host: 'auth.provider.test', tabHost: 'shop.test' })
		expect(await ask({ kind: 'capture.update', input: press() })).toEqual({ ok: true, value: null })
		expect(writes()).toEqual(['PUT /api/v1/secrets/p/share-save'])
		expect(await decrypt((bodyOf('PUT /api/v1/secrets/p/share-save')!.patch as { key: string }).key)).toBe('hunter2')
	})

	it('names no tab host when the hosts agree', async () => {
		tab.url = 'https://example.test/'
		await submit(submitted('alice', 'hunter2'))
		expect(await state()).not.toHaveProperty('tabHost')
	})

	it('stays while the tab remains on the top frame\'s site, and goes when it leaves', async () => {
		await submit(submitted('alice', 'hunter2'), frame())
		tab.url = 'https://www.shop.test/account'
		await fakeBrowser.tabs.onUpdated.trigger(TAB, { status: 'complete', url: tab.url }, { ...tab } as never)
		await vi.waitFor(() => expect(kinds()).toEqual([['capture.show', 0], ['capture.show', 0]]))
		tab.url = 'https://auth.provider.test/'
		await fakeBrowser.tabs.onUpdated.trigger(TAB, { status: 'loading', url: tab.url }, { ...tab } as never)
		expect(await state()).toBeNull()
	})

	it('excludes by the iframe\'s base domain', async () => {
		await settings({ excludedDomains: ['provider.test'] })
		await submit(submitted('alice', 'hunter2'), frame())
		expect(kinds()).toEqual([])
	})

	it('adds the iframe\'s base domain, not the page\'s, on Never for this site', async () => {
		await submit(submitted('alice', 'hunter2'), frame())
		await state()
		expect(await ask({ kind: 'capture.dismiss', never: true, input: press() })).toBeNull()
		expect((await readSettings(accountId)).excludedDomains).toEqual(['provider.test'])
	})
})

describe('capture.save', () => {
	beforeEach(async () => {
		await submit(submitted('alice', 'hunter2'), content({ url: 'https://login.example.test/sign-in' }))
		await state()
	})

	const save = (folderId: unknown = null, input: unknown = press()) => ask({ kind: 'capture.save', folderId, input } as never)

	it('checks the press with the host, then encrypts and saves under the host, origin, login type and folder, syncs and closes the bar after three seconds', async () => {
		routes['POST /api/v1/secrets'] = () => json(secretRow({ id: 'new' }), 201)
		const input = press()
		toContent = []
		expect(await save('f1', input)).toEqual({ ok: true, value: null })
		expect(toContent[0]).toEqual({ message: { kind: 'capture.verify', key: shownKey(), input }, frameId: 0 })
		const body = bodyOf('POST /api/v1/secrets')!
		expect(body).toMatchObject({ name: 'login.example.test', url: 'https://login.example.test', typeId: 't-login', folderId: 'f1' })
		expect(await decrypt(body.login)).toBe('alice')
		expect(await decrypt(body.key)).toBe('hunter2')
		expect(JSON.stringify(body)).not.toContain('hunter2')
		expect(requests().filter(({ path }) => path === '/api/v1/offline/manifest').length).toBeGreaterThan(0)
		expect(await state()).toBeNull()
		expect(kinds()).toEqual([['capture.verify', 0]])
		vi.advanceTimersByTime(SAVED_CLOSE_MS)
		expect(toContent.at(-1)).toEqual({ message: { kind: 'capture.hide', key: expect.any(String), done: true }, frameId: 0 })
	})

	it('does nothing for a press the host could not confirm, keeping the capture', async () => {
		visible = false
		expect(await save()).toEqual({ refused: 'not_visible' })
		expect(vi.mocked(createItem)).not.toHaveBeenCalled()
		expect(writes()).toEqual([])
		expect(await state()).toMatchObject({ mode: 'add' })
	})

	it.each([null, { kind: 'pointer', at: 1 }, { kind: 'mouse', at: 1, x: 1, y: 1 }])('does nothing for press %j, asking the host nothing', async (input) => {
		toContent = []
		expect(await save(null, input)).toEqual({ refused: 'not_visible' })
		expect(kinds()).toEqual([])
		expect(writes()).toEqual([])
	})

	it('keeps the capture until expiry when the server refuses, with the code for the bar to word', async () => {
		routes['POST /api/v1/secrets'] = () => json({ message: 'Vault is locked for writes' }, 423)
		expect(await save()).toEqual({ ok: false, error: { code: 'write_locked' } })
		expect(await state()).toMatchObject({ mode: 'add' })
		routes['POST /api/v1/secrets'] = () => json(secretRow({ id: 'new' }), 201)
		expect(await save()).toEqual({ ok: true, value: null })
	})

	it('writes once for a double click', async () => {
		routes['POST /api/v1/secrets'] = () => json(secretRow({ id: 'new' }), 201)
		expect(await Promise.all([save(), save()])).toEqual([{ ok: true, value: null }, { ok: true, value: null }])
		expect(writes()).toEqual(['POST /api/v1/secrets'])
	})

	it.each([7, { id: 'f1' }])('refuses folder %j without a write', async (folderId) => {
		expect(await save(folderId)).toEqual({ ok: false, error: { code: 'invalid' } })
		expect(writes()).toEqual([])
	})

	it('refuses Update on a capture of a new login', async () => {
		expect(await ask({ kind: 'capture.update', input: press() })).toEqual({ ok: false, error: { code: 'invalid' } })
		expect(writes()).toEqual([])
	})

	it('saves nothing after a lock between the bar showing and Save, even where a write would succeed', async () => {
		vi.mocked(createItem).mockResolvedValueOnce({ ok: true, value: { id: 'new' } })
		// The key goes without the lock broadcast, as when another window's lock is still on its way.
		await clearKey(accountId)
		expect(await save()).toEqual({ ok: false, error: { code: 'locked' } })
		expect(vi.mocked(createItem)).not.toHaveBeenCalled()
	})

	it('saves into nothing once the account is no longer the active one', async () => {
		const other = await addAccount({
			serverUrl: 'https://cloud.other.org', uid: 'bob', loginName: 'bob', displayName: 'Bob', email: null, avatarDataUrl: null, appPassword: 'pw', keepiqVersion: '0.3.12',
		}, { ...suiteRow })
		await setActive(other.id)
		expect(await save()).toMatchObject({ ok: false })
		expect(writes()).toEqual([])
	})

	it('closes a saved bar at once on Close, before the background would', async () => {
		routes['POST /api/v1/secrets'] = () => json(secretRow({ id: 'new' }), 201)
		await save()
		toContent = []
		expect(await ask({ kind: 'capture.dismiss', never: false })).toBeNull()
		expect(toContent).toEqual([{ message: { kind: 'capture.hide', key: shownKey() }, frameId: 0 }])
		vi.advanceTimersByTime(SAVED_CLOSE_MS)
		expect(toContent).toHaveLength(1)
	})
})

describe('capture.update', () => {
	let row: OpenSecretRow

	beforeEach(async () => {
		row = await item('a', 'alice', 'old', { additionalFields: await encrypt('{"notes":"keep"}') })
		await vault([row])
		await submit(submitted('alice', 'hunter2'))
		await state()
		toContent = []
	})

	const update = () => ask({ kind: 'capture.update', input: press() })

	it('re-fetches the item and saves a share-save patch of only key, beside baseUpdatedAt, then closes the bar after three seconds', async () => {
		expect(await update()).toEqual({ ok: true, value: null })
		expect(requests().map(({ method, path }) => `${method} ${path}`)).toContain('GET /api/v1/secrets/a')
		const body = bodyOf('PUT /api/v1/secrets/a/share-save')!
		expect(body.baseUpdatedAt).toBe(row.updatedAt)
		expect(Object.keys(body.patch as object)).toEqual(['key'])
		expect(await decrypt((body.patch as { key: string }).key)).toBe('hunter2')
		expect(writes()).toEqual(['PUT /api/v1/secrets/a/share-save'])
		vi.advanceTimersByTime(SAVED_CLOSE_MS)
		expect(kinds()).toEqual([['capture.verify', 0], ['capture.hide', 0]])
		expect(await state()).toBeNull()
	})

	it('does nothing for a press the host could not confirm', async () => {
		visible = false
		expect(await update()).toEqual({ refused: 'not_visible' })
		expect(requests().map(({ path }) => path)).not.toContain('/api/v1/secrets/a')
		expect(await state()).toMatchObject({ mode: 'update' })
	})

	it('saves nothing for an item with a partner share, failing as unverified', async () => {
		routes['PUT /api/v1/secrets/a/share-save'] = () => json({ error: 'incomplete', copies: { required: [], skipRefused: [] }, federated: { required: [{ shareId: 'fs-bob', recipientCloudId: 'bob@cloud.partner.example' }] } }, 409)
		expect(await update()).toEqual({ ok: false, error: { code: 'unverified' } })
		expect(requests().map(({ path }) => path)).not.toContain('/api/v1/federation/recipient-certificate')
		expect(await state()).toMatchObject({ mode: 'update' })
	})

	it('fails a view-only copy as view_only', async () => {
		routes['GET /api/v1/secrets/a/write-context'] = () => json({ sourceSecretId: 'source', effectiveGrade: 'read', ownerCertificate: null, sourceUpdatedAt: null })
		expect(await update()).toEqual({ ok: false, error: { code: 'view_only' } })
		expect(writes()).toEqual([])
	})

	it('turns into a new login when the item was deleted meanwhile, which Save then adds without another state', async () => {
		routes['GET /api/v1/secrets/a'] = () => json({ message: 'Not found' }, 404)
		expect(await update()).toEqual({ ok: false, error: { code: 'not_found' } })
		expect(writes()).toEqual([])
		routes['POST /api/v1/secrets'] = () => json(secretRow({ id: 'new' }), 201)
		expect(await ask({ kind: 'capture.save', folderId: null, input: press() })).toEqual({ ok: true, value: null })
		expect(await decrypt(bodyOf('POST /api/v1/secrets')!.key)).toBe('hunter2')
	})

	it('turns into a new login when a sync dropped the item before the bar asked, always naming the item in update mode', async () => {
		await vault([])
		const reply = await state()
		expect(reply).toMatchObject({ mode: 'add' })
		expect(reply).not.toHaveProperty('itemName')
		expect(reply).not.toHaveProperty('username')
		routes['POST /api/v1/secrets'] = () => json(secretRow({ id: 'new' }), 201)
		expect(await ask({ kind: 'capture.save', folderId: null, input: press() })).toEqual({ ok: true, value: null })
	})

	it('refuses Save while the capture is an update', async () => {
		expect(await ask({ kind: 'capture.save', folderId: null, input: press() })).toEqual({ ok: false, error: { code: 'invalid' } })
		expect(writes()).toEqual([])
	})
})

describe('capture.dismiss', () => {
	beforeEach(async () => {
		await submit(submitted('alice', 'hunter2'), content({ url: 'https://login.example.test/' }))
		await state()
		toContent = []
	})

	it('closes the bar and drops the capture, asking the host nothing', async () => {
		expect(await ask({ kind: 'capture.dismiss', never: false })).toBeNull()
		expect(kinds()).toEqual([['capture.hide', 0]])
		expect(await state()).toBeNull()
		expect((await readSettings(accountId)).excludedDomains).toEqual([])
	})

	it('with never, checks the press and appends the base domain to the excluded domains once', async () => {
		await settings({ excludedDomains: ['github.com'] })
		expect(await ask({ kind: 'capture.dismiss', never: true, input: press() })).toBeNull()
		expect(kinds()).toEqual([['capture.verify', 0], ['capture.hide', 0]])
		expect((await readSettings(accountId)).excludedDomains).toEqual(['github.com', 'example.test'])
		await submit(submitted('alice', 'hunter2'), content({ url: 'https://example.test/' }))
		expect(kinds()).toEqual([['capture.verify', 0], ['capture.hide', 0]])
	})

	it('with never, does nothing for a press the host could not confirm, or without one', async () => {
		visible = false
		expect(await ask({ kind: 'capture.dismiss', never: true, input: press() })).toEqual({ refused: 'not_visible' })
		visible = true
		expect(await ask({ kind: 'capture.dismiss', never: true })).toEqual({ refused: 'not_visible' })
		expect((await readSettings(accountId)).excludedDomains).toEqual([])
		expect(await state()).not.toBeNull()
	})

	it('answers a bar whose capture is gone with nothing, adding nothing', async () => {
		dropAllCaptures()
		toContent = []
		expect(await ask({ kind: 'capture.dismiss', never: true, input: press() })).toBeNull()
		expect(toContent).toEqual([])
		expect((await readSettings(accountId)).excludedDomains).toEqual([])
	})
})
