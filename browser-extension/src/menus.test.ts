import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import type { OpenSecretRow } from '@/src/api/types'
import { addAccount, markLoggedOut, removeAccount } from '@/src/accounts/store'
import { writeSettings } from '@/src/accounts/settings'
import { forgetTab } from '@/src/autofill/frames'
import { broadcast } from '@/src/background/broadcast'
import { CLIPBOARD_ALARM } from '@/src/clipboard'
import { pemToPkcs8, toBase64 } from '@/src/crypto'
import { listHistory, sealHistory } from '@/src/generator/history'
import { saveOptions } from '@/src/generator/store'
import { defaultOptions } from '@/src/generator/options'
import { lastUsedKey } from '@/src/last-used'
import { installMenuFakes } from '@/src/testing/menus'
import { blockedRow, encrypt, folderRow, secretRow, typeRows } from '@/src/testing/vault'
import { envelope, suiteRow } from '@/src/testing/vectors'
import { provenCertificateKey } from '@/src/vault/certificate'
import { generatorSealedKey, putKey } from '@/src/vault/key-store'
import { writeSnapshot } from '@/src/vault/store'
import type { StoredSecretRow } from '@/src/vault/types'
import { lock } from '@/src/vault/unlock'
import { MENU_LIMIT, rebuildMenus, registerMenus } from './menus'

const TAB = 7
let accountId: string
let tabUrl: string
let menus: ReturnType<typeof installMenuFakes>
/** Clipboard writes through the (Chrome) offscreen document, and fills sent to the tab. */
let clipboard: string[]
let toTab: unknown[]
let openPopup: ReturnType<typeof vi.fn>

const tab = () => ({ id: TAB, url: tabUrl, active: true }) as never
const ids = () => menus.entries.map((entry) => entry.id)
const entry = (id: string) => menus.entries.find((e) => e.id === id)

async function vault(secrets: StoredSecretRow[]) {
	const syncedAt = new Date().toISOString()
	await writeSnapshot(accountId, {
		suite: { id: suiteRow.id, unlockKeyEpoch: 1 }, secrets, folders: [folderRow()], types: typeRows,
		syncedAt, listsSyncedAt: syncedAt, newestUpdatedAt: null, total: secrets.length,
	})
}

async function login(id: string, overrides: Partial<OpenSecretRow> = {}): Promise<OpenSecretRow> {
	return secretRow({ id, name: id, url: 'https://example.test/', login: await encrypt(`user-${id}`), key: await encrypt(`pass-${id}`), ...overrides })
}

/** A login with a code: RFC 6238's SHA-1 key, whose code at 59 s is `287082`. */
async function withCode(id: string, overrides: Partial<OpenSecretRow> = {}): Promise<OpenSecretRow> {
	return login(id, { totp: await encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'), ...overrides })
}

beforeEach(async () => {
	fakeBrowser.reset()
	forgetTab(TAB)
	menus = installMenuFakes()
	accountId = (await addAccount({
		serverUrl: 'https://cloud.example.org', uid: 'alice', loginName: 'alice', displayName: 'Alice', email: null, avatarDataUrl: null, appPassword: 'pw', keepiqVersion: '0.3.12',
	}, { ...suiteRow })).id
	await putKey(accountId, toBase64(pemToPkcs8(envelope.privateKeyPem)))
	tabUrl = 'https://example.test/login'
	clipboard = []
	toTab = []
	vi.spyOn(browser.tabs, 'query').mockImplementation(async () => [tab()])
	vi.spyOn(browser.tabs, 'get').mockImplementation(async () => tab())
	vi.spyOn(browser.tabs, 'sendMessage').mockImplementation(async (_tabId: number, message: unknown) => {
		toTab.push(structuredClone(message))
		return ((message as { kind: string }).kind === 'fill.execute' ? { filled: 'both' } : true) as never
	})
	Object.assign(browser, { offscreen: { hasDocument: async () => false, createDocument: async () => {}, closeDocument: async () => {} } })
	vi.spyOn(browser.runtime, 'sendMessage').mockImplementation(async (message: unknown) => {
		const m = message as { kind: string; text?: string }
		if (m.kind === 'offscreen.writeClipboard') clipboard.push(m.text!)
		return true as never
	})
	openPopup = vi.fn(async () => {})
	Object.assign(browser.action, { openPopup })
})

afterEach(() => {
	Object.assign(browser, { offscreen: undefined })
})

describe('the context menu', () => {
	it('lists matching logins under Autofill, then Open Keepiq, and the copy and generate entries', async () => {
		await vault([await login('a'), await login('b'), await login('other', { url: 'https://other.test/' })])
		await rebuildMenus()
		expect(ids()).toEqual([
			'keepiq', 'autofill', 'autofill:a', 'autofill:b', 'autofill-separator', 'open',
			'copy-user', 'copy-user:a', 'copy-user:b', 'copy-pass', 'copy-pass:a', 'copy-pass:b', 'copy-code', 'generate',
		])
		expect(menus.entries.every((e) => e.contexts?.join() === 'editable')).toBe(true)
		expect(entry('keepiq')!.title).toBe('Keepiq')
		expect(entry('autofill:a')).toMatchObject({ parentId: 'autofill', title: 'a' })
	})

	it('lists the first ten of twelve by last used then name, then Open Keepiq', async () => {
		const names = Array.from({ length: 12 }, (_, i) => `item${String(i).padStart(2, '0')}`)
		await vault(await Promise.all(names.map((name) => login(name))))
		await browser.storage.local.set({ [lastUsedKey(accountId)]: { item11: 2, item10: 1 } })
		await rebuildMenus()
		const autofill = menus.entries.filter((e) => e.parentId === 'autofill').map((e) => e.id)
		expect(autofill).toEqual([...['item11', 'item10', ...names.slice(0, 8)].map((name) => `autofill:${name}`), 'autofill-separator', 'open'])
		expect(MENU_LIMIT).toBe(10)
		for (const copy of ['copy-user', 'copy-pass']) {
			expect(menus.entries.filter((e) => e.parentId === copy).map((e) => e.id)).toEqual(['item11', 'item10', ...names.slice(0, 8)].map((name) => `${copy}:${name}`))
		}
	})

	it('names a single match’s copy entries directly', async () => {
		await vault([await login('a')])
		await rebuildMenus()
		expect(entry('copy-user:a')).toMatchObject({ parentId: 'keepiq', title: 'Copy username' })
		expect(entry('copy-pass:a')).toMatchObject({ parentId: 'keepiq', title: 'Copy password' })
	})

	it('reads "No matching logins" and disables copying without a match', async () => {
		await vault([await login('a', { url: 'https://other.test/' })])
		await rebuildMenus()
		expect(ids()).toEqual(['keepiq', 'autofill', 'copy-user', 'copy-pass', 'copy-code', 'generate'])
		expect(entry('autofill')).toMatchObject({ title: 'No matching logins', enabled: false })
		expect(entry('copy-pass')).toMatchObject({ enabled: false })
	})

	it('offers no Copy password for a use-only copy and no Copy username without a login', async () => {
		await vault([await login('a', { useOnly: true }), await login('b', { login: null })])
		await rebuildMenus()
		expect(ids()).toEqual(expect.arrayContaining(['autofill:a', 'autofill:b', 'copy-user:a', 'copy-pass:b']))
		expect(ids()).not.toContain('copy-pass:a')
		expect(ids()).not.toContain('copy-user:b')
	})

	describe('Copy verification code', () => {
		it('names the one matching login with a code directly, after Copy password', async () => {
			await vault([await login('a'), await withCode('b')])
			await rebuildMenus()
			expect(ids().slice(-3)).toEqual(['copy-pass:b', 'copy-code:b', 'generate'])
			expect(entry('copy-code:b')).toMatchObject({ parentId: 'keepiq', title: 'Copy verification code' })
		})

		it('lists several in a submenu of at most ten, use-only copies included', async () => {
			await vault(await Promise.all(Array.from({ length: 12 }, (_, i) => withCode(`t${String(i).padStart(2, '0')}`, { useOnly: i === 0 }))))
			await rebuildMenus()
			expect(entry('copy-code')).toMatchObject({ parentId: 'keepiq', title: 'Copy verification code' })
			expect(menus.entries.filter((e) => e.parentId === 'copy-code').map((e) => e.id)).toHaveLength(MENU_LIMIT)
			expect(ids()).toContain('copy-code:t00')
		})

		it('is disabled without a match, and never lists a login without a code, a blocked row or a standalone authenticator item', async () => {
			await vault([
				await withCode('t1', { url: 'https://other.test/' }), await login('t2', { totp: '' }), blockedRow({ id: 't3', url: 'https://example.test/' }),
				secretRow({ id: 't4', name: 't4', url: 'https://example.test/', typeId: 't-totp', key: await encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ') }),
			])
			await rebuildMenus()
			expect(entry('copy-code')).toMatchObject({ enabled: false })
			expect(ids().filter((id) => id?.startsWith('copy-code:'))).toEqual([])
		})

		it('copies the current code, which a lock clears at once', async () => {
			vi.useFakeTimers({ toFake: ['Date'] })
			vi.setSystemTime(59_000)
			try {
				await vault([await withCode('t1')])
				registerMenus()
				menus.click('copy-code:t1', tab())
				await vi.waitFor(() => expect(clipboard).toEqual(['287082']))
				expect(await browser.storage.session.get('clipboardCode')).toEqual({ clipboardCode: accountId })
			} finally {
				vi.useRealTimers()
			}
		})

		it('copies nothing for an item that no longer matches', async () => {
			await vault([await withCode('t1', { url: 'https://other.test/' })])
			registerMenus()
			menus.click('copy-code:t1', tab())
			await new Promise((resolve) => setTimeout(resolve, 20))
			expect(clipboard).toEqual([])
		})
	})

	it('keeps an & in an item name literal', async () => {
		await vault([await login('a', { name: 'Tom & Jerry' })])
		await rebuildMenus()
		expect(entry('autofill:a')!.title).toBe('Tom && Jerry')
	})

	it('offers only Unlock vault while locked, which opens the popup', async () => {
		await vault([await login('a')])
		await lock(accountId)
		await rebuildMenus()
		expect(menus.entries).toEqual([
			expect.objectContaining({ id: 'keepiq' }),
			expect.objectContaining({ id: 'unlock', parentId: 'keepiq', title: 'Unlock vault' }),
		])
		registerMenus()
		menus.click('unlock', tab())
		expect(openPopup).toHaveBeenCalledOnce()
	})

	it('is hidden for a logged-out account', async () => {
		await vault([await login('a')])
		await markLoggedOut(accountId)
		await rebuildMenus()
		expect(menus.entries).toEqual([])
	})

	it('is hidden with the setting off, and without an account', async () => {
		await vault([await login('a')])
		await writeSettings(accountId, { contextMenu: false })
		await rebuildMenus()
		expect(menus.entries).toEqual([])
		await writeSettings(accountId, { contextMenu: true })
		await rebuildMenus()
		expect(menus.entries).not.toEqual([])
		await removeAccount(accountId)
		await rebuildMenus()
		expect(menus.entries).toEqual([])
	})

	describe('rebuilds', () => {
		beforeEach(async () => {
			await vault([await login('a')])
			registerMenus()
			await rebuildMenus()
		})

		it('on a lock', async () => {
			await lock(accountId)
			await vi.waitFor(() => expect(ids()).toEqual(['keepiq', 'unlock']))
		})

		it('on tab activation and a URL change of the active tab', async () => {
			tabUrl = 'https://other.test/'
			await fakeBrowser.tabs.onActivated.trigger({ tabId: TAB, windowId: 1 })
			await vi.waitFor(() => expect(entry('autofill')!.enabled).toBe(false))
			tabUrl = 'https://example.test/'
			await fakeBrowser.tabs.onUpdated.trigger(TAB, { url: tabUrl }, tab())
			await vi.waitFor(() => expect(ids()).toContain('autofill:a'))
		})

		it('on a window gaining focus, not on focus leaving the browser', async () => {
			tabUrl = 'https://other.test/'
			await fakeBrowser.windows.onFocusChanged.trigger(-1)
			await new Promise((resolve) => setTimeout(resolve, 20))
			expect(ids()).toContain('autofill:a')
			await fakeBrowser.windows.onFocusChanged.trigger(2)
			await vi.waitFor(() => expect(ids()).not.toContain('autofill:a'))
		})

		it('on a sync', async () => {
			await vault([await login('a'), await login('b')])
			broadcast({ kind: 'vault.changed', sync: { syncing: false, syncedAt: null, offline: false, lastError: null } })
			await vi.waitFor(() => expect(ids()).toContain('autofill:b'))
		})
	})

	describe('clicks', () => {
		beforeEach(() => registerMenus())

		it('fill the picked item into the tab', async () => {
			await vault([await login('a'), await login('b')])
			menus.click('autofill:b', tab())
			await vi.waitFor(() => expect(toTab).toEqual([{ kind: 'fill.execute', username: 'user-b', password: 'pass-b', code: null, origin: 'https://example.test' }]))
		})

		it('ask in the page first on an insecure page, and fill once accepted', async () => {
			tabUrl = 'http://example.test/login'
			await vault([await login('a')])
			menus.click('autofill:a', tab())
			await vi.waitFor(() => expect(toTab.map((m) => (m as { kind: string }).kind)).toEqual(['fill.confirmInsecure', 'fill.execute']))
		})

		it('copy the password and clear it after the delay', async () => {
			await vault([await login('a')])
			await writeSettings(accountId, { clearClipboardMs: 10_000 })
			const before = Date.now()
			menus.click('copy-pass:a', tab())
			await vi.waitFor(() => expect(clipboard).toEqual(['pass-a']))
			const alarm = await browser.alarms.get(CLIPBOARD_ALARM)
			expect(alarm!.scheduledTime).toBeGreaterThanOrEqual(before + 10_000)
			expect(alarm!.scheduledTime).toBeLessThan(Date.now() + 10_001)
		})

		it('copy the username', async () => {
			await vault([await login('a')])
			menus.click('copy-user:a', tab())
			await vi.waitFor(() => expect(clipboard).toEqual(['user-a']))
		})

		it('never copy a use-only copy’s password, nor an item that no longer matches', async () => {
			await vault([await login('a', { useOnly: true }), await login('b', { url: 'https://other.test/' })])
			menus.click('copy-pass:a', tab())
			menus.click('copy-pass:b', tab())
			menus.click('copy-user:a', tab())
			await vi.waitFor(() => expect(clipboard).toEqual(['user-a']))
		})

		it('generate a password with the saved options, copy it and keep it in the history', async () => {
			await saveOptions(accountId, { ...defaultOptions(), password: { ...defaultOptions().password, length: 33 } })
			menus.click('generate', tab())
			await vi.waitFor(() => expect(clipboard).toHaveLength(1))
			expect(clipboard[0]).toHaveLength(33)
			expect((await listHistory(accountId))[0]).toMatchObject({ value: clipboard[0], kind: 'password' })
			expect(await browser.alarms.get(CLIPBOARD_ALARM)).toBeDefined()
		})

		it('generate moves what the lock screen sealed into the history first, like the popup', async () => {
			await browser.storage.local.set({ [provenCertificateKey(accountId)]: suiteRow.certificate })
			await lock(accountId)
			await sealHistory(accountId, { value: 'locked', kind: 'password' })
			await putKey(accountId, toBase64(pemToPkcs8(envelope.privateKeyPem)))
			menus.click('generate', tab())
			await vi.waitFor(async () => expect((await listHistory(accountId)).map((e) => e.value)).toEqual([clipboard[0], 'locked']))
			expect(await browser.storage.session.get(generatorSealedKey(accountId))).toEqual({})
		})

		it('do nothing with an item while locked', async () => {
			await vault([await login('a')])
			await lock(accountId)
			menus.click('copy-pass:a', tab())
			menus.click('autofill:a', tab())
			await new Promise((resolve) => setTimeout(resolve, 20))
			expect([clipboard, toTab]).toEqual([[], []])
		})

		it('open the popup from Open Keepiq, falling back to the popout', async () => {
			openPopup.mockRejectedValueOnce(new Error('not from a user action'))
			const create = vi.spyOn(browser.windows, 'create').mockResolvedValue({} as never)
			menus.click('open', tab())
			await vi.waitFor(() => expect(create).toHaveBeenCalledWith(expect.objectContaining({ url: expect.stringContaining('tabId=7') })))
		})
	})
})
