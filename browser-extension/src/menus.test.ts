import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import type { OpenSecretRow } from '@/src/api/types'
import { addAccount, markLoggedOut, removeAccount } from '@/src/accounts/store'
import { takeIntent } from '@/src/add-intent'
import { writeSettings } from '@/src/accounts/settings'
import { forgetTab } from '@/src/autofill/frames'
import { broadcast } from '@/src/background/broadcast'
import { CLIPBOARD_ALARM } from '@/src/clipboard'
import { pemToPkcs8, toBase64 } from '@/src/crypto'
import { listHistory, sealHistory } from '@/src/generator/history'
import { saveOptions } from '@/src/generator/store'
import { defaultOptions } from '@/src/generator/options'
import { lastUsedKey } from '@/src/last-used'
import { setLocale } from '@/src/testing/i18n'
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
/** The content script's replies; anything else answers `true`, as a confirm the user accepted. */
const TAB_REPLIES: Record<string, () => unknown> = {
	'fill.execute': () => ({ filled: 'both' }),
	'fill.probe': () => ({ roles: ['cardholder', 'number', 'firstName'], origin: new URL(tabUrl).origin }),
	'fill.executeForm': () => ({ filled: 2 }),
}
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

async function card(id: string, overrides: Partial<OpenSecretRow> = {}): Promise<OpenSecretRow> {
	const key = await encrypt(JSON.stringify({ number: '4111 1111 1111 1111', expiry: '03/27', cvv: '123', pin: '9876', cardholder: `Holder ${id}` }))
	return secretRow({ id, name: id, url: 'https://bank.test/', typeId: 't-card', key, ...overrides })
}

async function identity(id: string): Promise<OpenSecretRow> {
	return secretRow({ id, name: id, url: null, typeId: 't-identity', key: await encrypt(JSON.stringify({ firstName: 'Anna', lastName: 'de Vries', bsn: '123456782' })) })
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
		return (TAB_REPLIES[(message as { kind: string }).kind]?.() ?? true) as never
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

/** The children of a parent, in order. */
const childrenOf = (parentId: string) => menus.entries.filter((e) => e.parentId === parentId).map((e) => e.id)
const EMPTY_FORMS = [
	'separator-1',
	'autofill-identity', 'identity-none', 'identity-separator', 'add:identity', 'open:identity',
	'autofill-card', 'card-none', 'card-separator', 'add:card', 'open:card',
	'separator-2', 'generate',
]

describe('the context menu', () => {
	it('lists matching logins under Autofill login, then Add login and Open Keepiq, the copy submenus, the card and identity submenus and generate', async () => {
		await vault([await login('a'), await login('b'), await login('other', { url: 'https://other.test/' })])
		await rebuildMenus()
		expect(ids()).toEqual([
			'keepiq', 'autofill', 'autofill:a', 'autofill:b', 'autofill-separator', 'add:login', 'open:login',
			'copy-user', 'copy-user:a', 'copy-user:b', 'copy-pass', 'copy-pass:a', 'copy-pass:b', 'copy-code', 'copy-code-none',
			...EMPTY_FORMS,
		])
		expect(entry('keepiq')!.title).toBe('Keepiq')
		expect(entry('autofill')).toEqual(expect.objectContaining({ parentId: 'keepiq', title: 'Autofill login' }))
		expect(entry('autofill:a')).toMatchObject({ parentId: 'autofill', title: 'a' })
		expect(entry('add:login')).toMatchObject({ parentId: 'autofill', title: 'Add login' })
		expect(entry('open:login')).toMatchObject({ parentId: 'autofill', title: 'Open Keepiq' })
		expect(entry('separator-1')).toMatchObject({ parentId: 'keepiq', type: 'separator' })
	})

	it('shows on the page, frames, selections, links, fields, images, video and audio, never as `all`', async () => {
		await vault([await login('a')])
		await rebuildMenus()
		const contexts = ['page', 'frame', 'selection', 'link', 'editable', 'image', 'video', 'audio']
		expect(menus.entries.every((e) => e.contexts?.join() === contexts.join())).toBe(true)
	})

	it('lists the first ten of twelve by last used then name, then Add login and Open Keepiq', async () => {
		const names = Array.from({ length: 12 }, (_, i) => `item${String(i).padStart(2, '0')}`)
		await vault(await Promise.all(names.map((name) => login(name))))
		await browser.storage.local.set({ [lastUsedKey(accountId)]: { item11: 2, item10: 1 } })
		await rebuildMenus()
		const first = ['item11', 'item10', ...names.slice(0, 8)]
		expect(childrenOf('autofill')).toEqual([...first.map((name) => `autofill:${name}`), 'autofill-separator', 'add:login', 'open:login'])
		expect(MENU_LIMIT).toBe(10)
		for (const copy of ['copy-user', 'copy-pass']) expect(childrenOf(copy)).toEqual(first.map((name) => `${copy}:${name}`))
	})

	it('makes Copy username a submenu for a single match too, listing it by name', async () => {
		await vault([await login('a')])
		await rebuildMenus()
		expect(entry('copy-user')).toEqual(expect.objectContaining({ parentId: 'keepiq', title: 'Copy username' }))
		expect(entry('copy-user')!.enabled).toBeUndefined()
		expect(entry('copy-user:a')).toMatchObject({ parentId: 'copy-user', title: 'a' })
		expect(entry('copy-pass:a')).toMatchObject({ parentId: 'copy-pass', title: 'a' })
	})

	it('holds one disabled line in each submenu without a match, its parent enabled', async () => {
		await vault([await login('a', { url: 'https://other.test/' })])
		await rebuildMenus()
		expect(ids()).toEqual([
			'keepiq', 'autofill', 'autofill-none', 'autofill-separator', 'add:login', 'open:login',
			'copy-user', 'copy-user-none', 'copy-pass', 'copy-pass-none', 'copy-code', 'copy-code-none',
			...EMPTY_FORMS,
		])
		expect(menus.entries.filter((e) => e.enabled === false).map((e) => [e.id, e.title])).toEqual([
			['autofill-none', 'No logins available'],
			['copy-user-none', 'No usernames available'],
			['copy-pass-none', 'No passwords available'],
			['copy-code-none', 'No verification codes available'],
			['identity-none', 'No identities available'],
			['card-none', 'No cards available'],
		])
	})

	it('words every entry in Dutch', async () => {
		setLocale('nl')
		try {
			await vault([await login('a', { url: 'https://other.test/' })])
			await rebuildMenus()
			expect(['autofill', 'autofill-none', 'add:login', 'autofill-identity', 'add:identity', 'autofill-card', 'add:card'].map((id) => entry(id)!.title)).toEqual([
				'Login invullen', 'Geen logins beschikbaar', 'Login toevoegen', 'Identiteit invullen', 'Identiteit toevoegen', 'Kaart invullen', 'Kaart toevoegen',
			])
		} finally {
			setLocale('en')
		}
	})

	it('offers no Copy password for a use-only copy and no Copy username without a login', async () => {
		await vault([await login('a', { useOnly: true }), await login('b', { login: null })])
		await rebuildMenus()
		expect(ids()).toEqual(expect.arrayContaining(['autofill:a', 'autofill:b', 'copy-user:a', 'copy-pass:b']))
		expect(ids()).not.toContain('copy-pass:a')
		expect(ids()).not.toContain('copy-user:b')
	})

	it('puts no username or other decrypted value in any title', async () => {
		await vault([await login('a', { name: 'GitHub', login: await encrypt('alice') }), await withCode('b'), await card('Visa'), await identity('Me')])
		await rebuildMenus()
		expect(JSON.stringify(menus.entries)).not.toMatch(/alice|user-b|pass-|287082|4111|Holder|Anna/)
	})

	describe('Copy verification code', () => {
		it('lists the one matching login with a code in its submenu', async () => {
			await vault([await login('a'), await withCode('b')])
			await rebuildMenus()
			expect(entry('copy-code')).toEqual(expect.objectContaining({ parentId: 'keepiq', title: 'Copy verification code' }))
			expect(childrenOf('copy-code')).toEqual(['copy-code:b'])
		})

		it('lists at most ten, use-only copies included', async () => {
			await vault(await Promise.all(Array.from({ length: 12 }, (_, i) => withCode(`t${String(i).padStart(2, '0')}`, { useOnly: i === 0 }))))
			await rebuildMenus()
			expect(childrenOf('copy-code')).toHaveLength(MENU_LIMIT)
			expect(ids()).toContain('copy-code:t00')
		})

		it('holds only its empty line without a match, and never lists a login without a code, a blocked row or a standalone authenticator item', async () => {
			await vault([
				await withCode('t1', { url: 'https://other.test/' }), await login('t2', { totp: '' }), blockedRow({ id: 't3', url: 'https://example.test/' }),
				secretRow({ id: 't4', name: 't4', url: 'https://example.test/', typeId: 't-totp', key: await encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ') }),
			])
			await rebuildMenus()
			expect(childrenOf('copy-code')).toEqual(['copy-code-none'])
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

	describe('Autofill identity and Autofill card', () => {
		it('list every identity and every card whatever their url, after a separator, each then Add and Open Keepiq', async () => {
			await vault([await login('a'), await card('Visa'), await identity('Me'), await card('Amex')])
			await rebuildMenus()
			expect(ids().slice(ids().indexOf('separator-1'))).toEqual([
				'separator-1',
				'autofill-identity', 'identity:Me', 'identity-separator', 'add:identity', 'open:identity',
				'autofill-card', 'card:Amex', 'card:Visa', 'card-separator', 'add:card', 'open:card',
				'separator-2', 'generate',
			])
			expect(entry('autofill-card')).toEqual(expect.objectContaining({ parentId: 'keepiq', title: 'Autofill card' }))
			expect(entry('autofill-identity')).toEqual(expect.objectContaining({ parentId: 'keepiq', title: 'Autofill identity' }))
			expect(entry('card:Visa')).toMatchObject({ parentId: 'autofill-card', title: 'Visa' })
			expect(entry('add:card')).toMatchObject({ parentId: 'autofill-card', title: 'Add card' })
			expect(entry('open:identity')).toMatchObject({ parentId: 'autofill-identity', title: 'Open Keepiq' })
		})

		it('list the first ten of twelve cards by last used then name', async () => {
			const names = Array.from({ length: 12 }, (_, i) => `card${String(i).padStart(2, '0')}`)
			await vault(await Promise.all(names.map((name) => card(name))))
			await browser.storage.local.set({ [lastUsedKey(accountId)]: { card11: 2, card10: 1 } })
			await rebuildMenus()
			expect(childrenOf('autofill-card'))
				.toEqual([...['card11', 'card10', ...names.slice(0, 8)].map((name) => `card:${name}`), 'card-separator', 'add:card', 'open:card'])
		})

		it('show their empty lines and Add when the vault has none of the kind, a blocked card counting as none', async () => {
			await vault([await login('a'), blockedRow({ id: 'gone', typeId: 't-card' }), secretRow({ id: 'mine', typeId: 't-note', name: 'card' })])
			await rebuildMenus()
			expect(ids().slice(ids().indexOf('separator-1'))).toEqual(EMPTY_FORMS)
		})

		it('list nothing on a page that is not on the web', async () => {
			tabUrl = 'about:blank'
			await vault([await card('Visa'), await identity('Me')])
			await rebuildMenus()
			expect(childrenOf('autofill-card')).toEqual(['card-none', 'card-separator', 'add:card', 'open:card'])
			expect(childrenOf('autofill-identity')).toEqual(['identity-none', 'identity-separator', 'add:identity', 'open:identity'])
		})

		it('give way to Unlock vault while locked', async () => {
			await vault([await card('Visa'), await identity('Me')])
			await lock(accountId)
			await rebuildMenus()
			expect(ids()).toEqual(['keepiq', 'unlock'])
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
			await vi.waitFor(() => expect(ids()).toContain('autofill-none'))
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

		it('fill a picked card with only the members its fields ask for, and an identity likewise', async () => {
			await vault([await card('Visa'), await identity('Me')])
			menus.click('card:Visa', tab())
			await vi.waitFor(() => expect(toTab).toHaveLength(2))
			menus.click('identity:Me', tab())
			await vi.waitFor(() => expect(toTab).toHaveLength(4))
			const origin = 'https://example.test'
			expect(toTab).toEqual([
				{ kind: 'fill.probe', form: 'card' },
				{ kind: 'fill.executeForm', form: 'card', values: { cardholder: 'Holder Visa', number: '4111111111111111' }, origin },
				{ kind: 'fill.probe', form: 'identity' },
				{ kind: 'fill.executeForm', form: 'identity', values: { firstName: 'Anna' }, origin },
			])
			expect(JSON.stringify(toTab)).not.toMatch(/9876|123456782/)
			expect(await browser.storage.local.get(lastUsedKey(accountId))).toEqual({ [lastUsedKey(accountId)]: { Visa: expect.any(Number), Me: expect.any(Number) } })
		})

		it('ask a card\'s insecure question in the page first, and fill nothing on Cancel', async () => {
			tabUrl = 'http://shop.test/checkout'
			await vault([await card('Visa')])
			menus.click('card:Visa', tab())
			await vi.waitFor(() => expect(toTab.map((m) => (m as { kind: string }).kind)).toEqual(['fill.probe', 'fill.confirmForm', 'fill.probe', 'fill.executeForm']))
			expect(toTab[1]).toEqual({ kind: 'fill.confirmForm', insecure: true, host: 'shop.test', origin: 'http://shop.test', frameHosts: [] })
			toTab = []
			TAB_REPLIES['fill.confirmForm'] = () => false
			try {
				menus.click('card:Visa', tab())
				await vi.waitFor(() => expect(toTab).toHaveLength(2))
				await new Promise((resolve) => setTimeout(resolve, 20))
				expect(toTab.map((m) => (m as { kind: string }).kind)).toEqual(['fill.probe', 'fill.confirmForm'])
			} finally {
				delete TAB_REPLIES['fill.confirmForm']
			}
		})

		it.each([['add:login', 'login'], ['add:card', 'card'], ['add:identity', 'identity']])('open the popup at once from %s, leaving its intent in memory', (id, add) => {
			menus.click(id, tab())
			expect(openPopup).toHaveBeenCalledOnce()
			expect(takeIntent()).toEqual({ add })
			expect(takeIntent()).toBeNull()
		})

		it('clear the intent on a lock', async () => {
			await vault([await login('a')])
			menus.click('add:card', tab())
			await lock(accountId)
			expect(takeIntent()).toBeNull()
		})

		describe('a fill without a form', () => {
			const notices = () => toTab.filter((m) => (m as { kind: string }).kind === 'notice.show')

			it('shows the login notice in the top frame', async () => {
				const sendMessage = vi.mocked(browser.tabs.sendMessage)
				TAB_REPLIES['fill.execute'] = () => ({ filled: 'none', code: false })
				try {
					await vault([await login('a')])
					menus.click('autofill:a', tab())
					await vi.waitFor(() => expect(notices()).toEqual([{ kind: 'notice.show', notice: 'no_login_form' }]))
					expect(sendMessage).toHaveBeenLastCalledWith(TAB, { kind: 'notice.show', notice: 'no_login_form' }, { frameId: 0 })
				} finally {
					delete TAB_REPLIES['fill.execute']
				}
			})

			it.each([['card', 'no_card_form'], ['identity', 'no_identity_form']])('shows the %s notice, decrypting nothing', async (kind, notice) => {
				TAB_REPLIES['fill.probe'] = () => ({ roles: [], origin: new URL(tabUrl).origin })
				try {
					await vault([await card('Visa'), await identity('Me')])
					menus.click(kind === 'card' ? 'card:Visa' : 'identity:Me', tab())
					await vi.waitFor(() => expect(notices()).toEqual([{ kind: 'notice.show', notice }]))
					expect(toTab.map((m) => (m as { kind: string }).kind)).toEqual(['fill.probe', 'notice.show'])
				} finally {
					TAB_REPLIES['fill.probe'] = () => ({ roles: ['cardholder', 'number', 'firstName'], origin: new URL(tabUrl).origin })
				}
			})

			it('shows none after a declined insecure question', async () => {
				tabUrl = 'http://example.test/login'
				TAB_REPLIES['fill.confirmInsecure'] = () => false
				try {
					await vault([await login('a')])
					menus.click('autofill:a', tab())
					await vi.waitFor(() => expect(toTab).toHaveLength(1))
					await new Promise((resolve) => setTimeout(resolve, 20))
					expect(toTab.map((m) => (m as { kind: string }).kind)).toEqual(['fill.confirmInsecure'])
				} finally {
					delete TAB_REPLIES['fill.confirmInsecure']
				}
			})

			it('shows none after a fill that filled something', async () => {
				await vault([await login('a')])
				menus.click('autofill:a', tab())
				await vi.waitFor(() => expect(toTab).toHaveLength(1))
				await new Promise((resolve) => setTimeout(resolve, 20))
				expect(notices()).toEqual([])
			})
		})

		it.each(['open:login', 'open:card', 'open:identity'])('open the popup from %s', (id) => {
			menus.click(id, tab())
			expect(openPopup).toHaveBeenCalledOnce()
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
			menus.click('open:login', tab())
			await vi.waitFor(() => expect(create).toHaveBeenCalledWith(expect.objectContaining({ url: expect.stringContaining('tabId=7') })))
		})
	})
})
