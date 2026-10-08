import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { addAccount, markLoggedOut } from '@/src/accounts/store'
import { settingsKey, writeSettings } from '@/src/accounts/settings'
import { CLIPBOARD_ALARM } from '@/src/clipboard'
import { pemToPkcs8, toBase64 } from '@/src/crypto'
import type { PopupReplies, PopupRequest } from '@/src/messages'
import { blockedRow, encrypt, folderRow, secretRow, typeRows } from '@/src/testing/vault'
import { envelope, suiteRow } from '@/src/testing/vectors'
import { hasKey, putKey } from '@/src/vault/key-store'
import { writeSnapshot } from '@/src/vault/store'
import type { VaultSnapshot } from '@/src/vault/types'
import { onPopupClosed, onPopupOpened } from '@/src/vault/timeout'
import { lock } from '@/src/vault/unlock'
import { handlePopupRequest, readLastTab } from './requests'

const fetchMock = vi.fn<typeof fetch>()

function send<K extends PopupRequest['kind']>(message: Extract<PopupRequest, { kind: K }>): Promise<PopupReplies[K]> {
	return handlePopupRequest(message) as Promise<PopupReplies[K]>
}

let accountId: string

async function snapshot(secrets: VaultSnapshot['secrets'], syncedAt = new Date().toISOString()) {
	await writeSnapshot(accountId, {
		suite: { id: suiteRow.id, unlockKeyEpoch: 1 }, secrets, folders: [folderRow()], types: typeRows,
		syncedAt, listsSyncedAt: syncedAt, newestUpdatedAt: null, total: secrets.length,
	})
}

beforeEach(async () => {
	fakeBrowser.reset()
	vi.stubGlobal('fetch', fetchMock)
	fetchMock.mockReset()
	fetchMock.mockResolvedValue(new Response(JSON.stringify({ message: 'Down' }), { status: 503 }))
	accountId = (await addAccount({
		serverUrl: 'https://cloud.example.org', uid: 'alice', loginName: 'alice', displayName: 'Alice', email: null, avatarDataUrl: null, appPassword: 'pw',
	}, { ...suiteRow })).id
	await putKey(accountId, toBase64(pemToPkcs8(envelope.privateKeyPem)))
})

describe('vault.snapshot', () => {
	it('answers locked and logged out without rows', async () => {
		await lock(accountId)
		expect(await send({ kind: 'vault.snapshot' })).toEqual({ state: 'locked' })
		await markLoggedOut(accountId)
		expect(await send({ kind: 'vault.snapshot' })).toEqual({ state: 'logged_out' })
	})

	it('sends metadata only, plus the ids matching the tab', async () => {
		const login = await encrypt('alice')
		await snapshot([
			secretRow({ id: 'a', url: 'https://login.example.co.uk', login, additionalFields: await encrypt('{}') }),
			secretRow({ id: 'b', url: 'https://other.test' }),
			blockedRow({ id: 'c', url: 'example.co.uk' }),
		])
		const reply = await send({ kind: 'vault.snapshot', tabUrl: 'https://www.example.co.uk/' })
		if (reply.state !== 'unlocked') throw new Error(reply.state)
		expect(reply.suggestionIds).toEqual(['a', 'c'])
		expect(reply.items?.map((item) => [item.id, item.hasLogin, item.blocked])).toEqual([['a', true, false], ['b', false, false], ['c', false, true]])
		expect(reply.items?.[2]).toMatchObject({ blockedReason: 'suite revoked' })
		expect(reply.folders).toEqual([{ id: 'f1', name: 'Work', parentId: null }])
		expect(reply.types[0]).toEqual({ id: 't-login', name: 'login', label: 'Login' })
		expect(reply.webAppUrl).toBe('https://cloud.example.org/index.php/apps/keepiq/')
		for (const item of reply.items!) expect(Object.keys(item)).not.toEqual(expect.arrayContaining([expect.stringMatching(/^(key|login|additionalFields)$/)]))
		expect(JSON.stringify(reply)).not.toContain(login)
		expect(JSON.stringify(reply)).not.toContain('ciphertext')
	})

	it('starts a first sync and says so when there is no snapshot', async () => {
		const reply = await send({ kind: 'vault.snapshot', opened: true })
		expect(reply).toMatchObject({ state: 'unlocked', items: null, sync: { syncing: true } })
		expect(fetchMock).toHaveBeenCalled()
	})

	it('does not retry from a re-read, so a failing server is not hammered', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		await send({ kind: 'vault.snapshot', opened: true })
		await vi.waitFor(async () => expect((await send({ kind: 'vault.snapshot' }) as { sync: { syncing: boolean } }).sync.syncing).toBe(false))
		fetchMock.mockClear()
		expect(await send({ kind: 'vault.snapshot' })).toMatchObject({ items: null, sync: { syncing: false, offline: true } })
		expect(fetchMock).not.toHaveBeenCalled()
	})

	it('does not count a re-read as interaction', async () => {
		await snapshot([])
		await send({ kind: 'vault.snapshot', opened: true })
		const opened = await browser.storage.session.get('lastInteractionAt')
		await new Promise((resolve) => setTimeout(resolve, 5))
		await send({ kind: 'vault.snapshot' })
		expect(await browser.storage.session.get('lastInteractionAt')).toEqual(opened)
	})

	it('does not count a passive re-decrypt as interaction', async () => {
		await snapshot([secretRow({ id: 'a', key: await encrypt('pw') })])
		await send({ kind: 'item.decrypt', ids: ['a'], fields: ['key'] })
		const before = await browser.storage.session.get('lastInteractionAt')
		await new Promise((resolve) => setTimeout(resolve, 5))
		await send({ kind: 'item.decrypt', ids: ['a'], fields: ['key'], passive: true })
		expect(await browser.storage.session.get('lastInteractionAt')).toEqual(before)
	})

	it('does not sync a fresh snapshot', async () => {
		await snapshot([])
		expect(await send({ kind: 'vault.snapshot' })).toMatchObject({ sync: { syncing: false } })
		expect(fetchMock).not.toHaveBeenCalled()
	})

	it('probes a stale snapshot and serves the cache meanwhile', async () => {
		await snapshot([secretRow()], new Date(Date.now() - 20 * 60_000).toISOString())
		const reply = await send({ kind: 'vault.snapshot', opened: true })
		expect(reply).toMatchObject({ items: [{ id: 's1' }], sync: { syncing: true } })
		expect(String(fetchMock.mock.calls[0]![0])).toContain('sort=updated_at')
	})
})

describe('item.decrypt', () => {
	it('decrypts the asked fields of a batch without writing storage', async () => {
		await snapshot([
			secretRow({ id: 'a', login: await encrypt('alice@example.com'), key: await encrypt('hunter2') }),
			secretRow({ id: 'b', login: null }),
		])
		const set = vi.spyOn(browser.storage.local, 'set')
		const sessionSet = vi.spyOn(browser.storage.session, 'set')
		expect(await send({ kind: 'item.decrypt', ids: ['a', 'b'], fields: ['login'] })).toEqual({
			ok: true, items: { a: { login: 'alice@example.com' }, b: {} },
		})
		expect(set).not.toHaveBeenCalled()
		// `touch` writes the interaction time and nothing else.
		expect(sessionSet.mock.calls.every(([values]) => Object.keys(values).every((k) => k === 'lastInteractionAt'))).toBe(true)
	})

	it('leaves bad rows out of a batch instead of failing it', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		await snapshot([blockedRow({ id: 'x' }), secretRow({ id: 'bad', login: 'AAAA' }), secretRow({ id: 'good', login: await encrypt('alice') })])
		expect(await send({ kind: 'item.decrypt', ids: ['x', 'bad', 'good'], fields: ['login'] })).toEqual({ ok: true, items: { good: { login: 'alice' } } })
	})

	it('refuses a blocked row', async () => {
		await snapshot([blockedRow({ id: 'x' })])
		expect(await send({ kind: 'item.decrypt', ids: ['x'], fields: ['key'] })).toEqual({ ok: false, error: 'blocked' })
	})

	it('refuses while locked', async () => {
		await snapshot([secretRow({ id: 'a', key: await encrypt('hunter2') })])
		await lock(accountId)
		expect(await send({ kind: 'item.decrypt', ids: ['a'], fields: ['key'] })).toEqual({ ok: false, error: 'locked' })
	})

	it('reports ciphertext that does not open', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		await snapshot([secretRow({ id: 'a', key: 'AAAA' })])
		expect(await send({ kind: 'item.decrypt', ids: ['a'], fields: ['key'] })).toEqual({ ok: false, error: 'decrypt_failed' })
	})
})

describe('last tab', () => {
	it('defaults to Vault, remembers a tab and forgets it on lock', async () => {
		expect(await readLastTab()).toBe('vault')
		await send({ kind: 'popup.lastTab.set', tab: 'settings' })
		expect(await readLastTab()).toBe('settings')
		await lock(accountId)
		expect(await readLastTab()).toBe('vault')
	})

	it('ignores a tab that does not exist', async () => {
		await send({ kind: 'popup.lastTab.set', tab: 'nope' as never })
		expect(await readLastTab()).toBe('vault')
	})
})

describe('clipboard.copied', () => {
	it('schedules nothing without a clear delay', async () => {
		await send({ kind: 'clipboard.copied' })
		expect(await browser.alarms.get(CLIPBOARD_ALARM)).toBeUndefined()
	})

	it('schedules the clear when a delay is set', async () => {
		await browser.storage.local.set({ [settingsKey(accountId)]: { vaultTimeout: 15, vaultTimeoutAction: 'lock', clearClipboardMs: 20_000 } })
		await send({ kind: 'clipboard.copied' })
		expect(await browser.alarms.get(CLIPBOARD_ALARM)).toBeDefined()
	})
})

describe('popup.popout', () => {
	it('opens the popup page in a window with the tab it came from', async () => {
		const create = vi.spyOn(browser.windows, 'create').mockResolvedValue({} as never)
		await send({ kind: 'popup.popout', tabId: 42 })
		expect(create).toHaveBeenCalledWith(expect.objectContaining({ type: 'popup', width: 380, height: 630 }))
		expect(create.mock.calls[0]![0]!.url).toMatch(/\/popup\.html\?popout=1&tabId=42$/)
	})

	it('gives a later close no grace when the window did not open', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		vi.spyOn(browser.windows, 'create').mockRejectedValue(new Error('No window'))
		await writeSettings(accountId, { vaultTimeout: 'immediately', vaultTimeoutAction: 'lock' })
		onPopupOpened()
		expect(await send({ kind: 'popup.popout', tabId: 42 })).toBeUndefined()
		// With the grace still set, this close would wait on a timer that never fires.
		vi.useFakeTimers({ toFake: ['setTimeout'] })
		try {
			await onPopupClosed()
			expect(await hasKey(accountId)).toBe(false)
		} finally {
			vi.useRealTimers()
		}
	})
})
