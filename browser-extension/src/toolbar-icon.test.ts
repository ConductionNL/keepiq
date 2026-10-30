import { readdirSync, readFileSync } from 'node:fs'
import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import type { OpenSecretRow } from '@/src/api/types'
import { writeSettings } from '@/src/accounts/settings'
import { addAccount, markLoggedOut, setActive } from '@/src/accounts/store'
import { GLOBAL_SETTINGS_KEY, updateGlobalSettings } from '@/src/settings/global'
import { setLocale } from '@/src/testing/i18n'
import { blockedRow, secretRow, typeRows } from '@/src/testing/vault'
import { suiteRow } from '@/src/testing/vectors'
import { clearKey, putKey } from '@/src/vault/key-store'
import { vaultCacheKey, writeSnapshot } from '@/src/vault/store'
import { lock } from '@/src/vault/unlock'
import { action } from './browser-action'
import { badgeText, refreshToolbar, registerToolbarListeners } from './toolbar-icon'

interface FakeTab { id: number; url: string; active: boolean; windowId: number }

const SITE = 'https://www.youtube.com/watch'
let tabs: FakeTab[]
let setIcon: MockInstance<typeof action.setIcon>
let onStorageChanged: Parameters<typeof browser.storage.onChanged.addListener>[0]
let accountId: string
const setBadgeTextColor = action.setBadgeTextColor

const badge = (tabId?: number) => action.getBadgeText(tabId === undefined ? {} : { tabId })
const title = (tabId?: number) => action.getTitle(tabId === undefined ? {} : { tabId })
const folderOf = (details: unknown) => (details as { path: Record<number, string> } | undefined)?.path[16]?.split('/')[2]
const iconFolder = () => folderOf(setIcon.mock.lastCall?.[0])

/** Runs the coalescing timers and the paints they queue, until nothing is pending. */
async function settle() {
	do {
		await vi.runAllTimersAsync()
		await new Promise<void>((resolve) => setImmediate(resolve))
	} while (vi.getTimerCount() > 0)
}

async function newAccount(uid: string): Promise<string> {
	return (await addAccount({
		serverUrl: 'https://cloud.example.org', uid, loginName: uid, displayName: uid, email: null, avatarDataUrl: null, appPassword: 'pw', keepiqVersion: '0.3.12',
	}, { ...suiteRow })).id
}

/** Only whether a key is held matters here, not what it opens. */
const unlock = (id: string) => putKey(id, 'a2V5')

async function vault(id: string, secrets: Array<OpenSecretRow | ReturnType<typeof blockedRow>>) {
	const at = new Date().toISOString()
	await writeSnapshot(id, { suite: { id: suiteRow.id, unlockKeyEpoch: 1 }, secrets, folders: [], types: typeRows, syncedAt: at, listsSyncedAt: at, newestUpdatedAt: null, total: secrets.length })
}

const logins = (count: number, url = 'https://youtube.com') =>
	Array.from({ length: count }, (_, i) => secretRow({ id: `s${i}`, name: `Login ${i}`, url }))

function tab(id: number, url: string, active = true, windowId = 1): FakeTab {
	return { id, url, active, windowId }
}

const flush = () => new Promise<void>((resolve) => setImmediate(resolve))

interface Read { area: 'local' | 'session'; key: string }

/** Holds the first read of `hold` after one of `after`, with what it read, until `release`. */
function holdRead(hold: Read, after: Read): { reached: Promise<void>; release: () => void } {
	let release = () => {}
	const held = new Promise<void>((resolve) => (release = resolve))
	let reach = () => {}
	const reached = new Promise<void>((resolve) => (reach = resolve))
	let armed = false
	for (const area of new Set([hold.area, after.area])) {
		const storage = browser.storage[area]
		const get = storage.get.bind(storage)
		vi.spyOn(storage, 'get').mockImplementation(async (keys) => {
			const values = await get(keys as never)
			if (area === after.area && keys === after.key) armed = true
			else if (armed && area === hold.area && keys === hold.key) {
				armed = false
				reach()
				await held
			}
			return values
		})
	}
	return { reached, release: () => release() }
}

/** The count's read of the match rule; `enforce()` reads it too, before the view. */
const holdCount = () => holdRead({ area: 'local', key: `settings.${accountId}` }, { area: 'local', key: GLOBAL_SETTINGS_KEY })

/** Runs a single-tab refresh up to the held read. */
async function refreshUntil(hold: { reached: Promise<void> }) {
	refreshToolbar(7)
	await vi.advanceTimersByTimeAsync(100)
	await hold.reached
}

beforeEach(async () => {
	fakeBrowser.reset()
	vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
	setIcon = vi.spyOn(action, 'setIcon').mockImplementation(async () => {})
	vi.spyOn(browser.runtime, 'getManifest').mockReturnValue({ name: 'Keepiq' } as never)
	tabs = [tab(7, SITE)]
	vi.spyOn(browser.tabs, 'query').mockImplementation(async (info) => tabs.filter((t) =>
		(info.active === undefined || t.active === info.active) && (info.windowId === undefined || t.windowId === info.windowId)) as never)
	vi.spyOn(browser.tabs, 'get').mockImplementation(async (id) => {
		const found = tabs.find((t) => t.id === id)
		if (!found) throw new Error(`No tab with id: ${id}`)
		return found as never
	})
	const storageListener = vi.spyOn(browser.storage.onChanged, 'addListener')
	registerToolbarListeners()
	onStorageChanged = storageListener.mock.calls[0]![0]
	accountId = await newAccount('alice')
	await unlock(accountId)
	await vault(accountId, logins(3))
	await settle()
})

afterEach(async () => {
	await settle()
	vi.useRealTimers()
	Object.assign(action, { setBadgeTextColor })
})

describe('badgeText', () => {
	it.each([[0, ''], [1, '1'], [9, '9'], [10, '9+'], [12, '9+']])('shows %i as "%s"', (count, text) => {
		expect(badgeText(count)).toBe(text)
	})
})

describe('the icon', () => {
	it('is grey and says logged out without an account', async () => {
		await markLoggedOut(accountId)
		await browser.storage.local.clear()
		await fakeBrowser.runtime.onStartup.trigger()
		await settle()
		expect(iconFolder()).toBe('toolbar-logged-out')
		expect(await title()).toBe('Keepiq: Logged out')
	})

	it('shows the lock and says locked for a locked account', async () => {
		await lock(accountId)
		await settle()
		expect(iconFolder()).toBe('toolbar-locked')
		expect(await title()).toBe('Keepiq: Locked')
	})

	it('is coloured and carries the name alone when unlocked', async () => {
		refreshToolbar('state')
		await settle()
		expect(iconFolder()).toBe('toolbar')
		expect(await title()).toBe('Keepiq')
	})

	it('turns grey when a 401 logs the account out', async () => {
		await markLoggedOut(accountId, true)
		await settle()
		expect(iconFolder()).toBe('toolbar-logged-out')
		expect(await title(7)).toBe('Keepiq: Logged out')
		expect(await badge(7)).toBe('')
	})

	it.each([
		['the browser starts', () => fakeBrowser.runtime.onStartup.trigger()],
		['the extension is installed or updated', () => fakeBrowser.runtime.onInstalled.trigger({ reason: 'update' } as never)],
		['the extension is re-enabled, which empties storage.session', async () => {
			await browser.storage.session.clear()
			registerToolbarListeners()
		}],
	])('paints the state when %s, with no storage change to follow', async (_, start) => {
		// No broadcast and no `storage.local` change, so only the start itself repaints.
		await clearKey(accountId)
		setIcon.mockClear()
		await start()
		await settle()
		expect(iconFolder()).toBe('toolbar-locked')
	})

	it('repaints nothing when the worker wakes after its last paint', async () => {
		setIcon.mockClear()
		vi.mocked(browser.tabs.query).mockClear()
		registerToolbarListeners()
		await settle()
		expect(setIcon).not.toHaveBeenCalled()
		expect(browser.tabs.query).not.toHaveBeenCalled()
	})

	it('leaves the next wake to paint while a refresh is pending, as when the worker stops before it', async () => {
		const painted = async () => (await browser.storage.session.get('toolbar:painted'))['toolbar:painted']
		expect(await painted()).toBe('true')
		refreshToolbar('state')
		await flush()
		expect(await painted()).toBeUndefined()
		await settle()
		expect(await painted()).toBe('true')
	})

	it('keeps the development name', async () => {
		vi.mocked(browser.runtime.getManifest).mockReturnValue({ name: 'Keepiq (DEV)' } as never)
		await lock(accountId)
		await settle()
		expect(await title()).toBe('Keepiq (DEV): Locked')
	})

	it('words the tooltips in Dutch in a Dutch browser', async () => {
		setLocale('nl')
		refreshToolbar('state')
		await settle()
		expect(await title(7)).toBe('Keepiq: 3 logins voor deze site')
		await lock(accountId)
		await settle()
		expect(await title()).toBe('Keepiq: vergrendeld')
	})

	it('sets the badge colours, also where the text colour is not supported', async () => {
		expect(await action.getBadgeBackgroundColor({})).toEqual([0x21, 0x46, 0x8b, 255])
		expect(await action.getBadgeTextColor({})).toBe('#FFFFFF')
		Object.assign(action, { setBadgeTextColor: undefined })
		expect(() => registerToolbarListeners()).not.toThrow()
	})
})

describe('the badge', () => {
	it('counts the logins matching the active tab, with the count in the tooltip', async () => {
		refreshToolbar('state')
		await settle()
		expect(await badge(7)).toBe('3')
		expect(await title(7)).toBe('Keepiq: 3 logins for this site')
	})

	it('uses the singular for one login', async () => {
		await vault(accountId, logins(1))
		await settle()
		expect(await badge(7)).toBe('1')
		expect(await title(7)).toBe('Keepiq: 1 login for this site')
	})

	it('shows 9+ above nine', async () => {
		await vault(accountId, logins(12))
		await settle()
		expect(await badge(7)).toBe('9+')
	})

	it('is empty without a match, with the name alone in the tooltip', async () => {
		tabs = [tab(7, 'https://elsewhere.example/')]
		refreshToolbar('state')
		await settle()
		expect(await badge(7)).toBe('')
		expect(await title(7)).toBe('Keepiq')
	})

	it('leaves out blocked rows and rows that are not logins', async () => {
		await vault(accountId, [
			blockedRow({ url: 'https://youtube.com' }),
			secretRow({ id: 'note', name: 'Note', url: 'https://youtube.com', typeId: 't-note' }),
			secretRow({ id: 'login', name: 'Login', url: 'https://youtube.com' }),
		])
		await settle()
		expect(await badge(7)).toBe('1')
	})

	it.each(['chrome://settings/', 'about:blank', 'file:///home/alice/login.html', 'chrome-extension://test-extension-id/popup.html', 'moz-extension://abc/popup.html'])(
		'is empty on %s, which cannot be filled',
		async (url) => {
			await vault(accountId, logins(3, url))
			tabs = [tab(7, url)]
			refreshToolbar('state')
			await settle()
			expect(await badge(7)).toBe('')
		},
	)

	it('is empty when the match rule is Never', async () => {
		await writeSettings(accountId, { uriMatch: 'never' })
		await settle()
		expect(await badge(7)).toBe('')
	})

	it('recomputes only the active tab of each window on a state change', async () => {
		tabs = [tab(7, SITE), tab(8, SITE, false), tab(9, SITE, true, 2)]
		refreshToolbar('state')
		await settle()
		expect([await badge(7), await badge(8), await badge(9)]).toEqual(['3', '', '3'])
	})

	it('counts a tab when it is activated', async () => {
		tabs = [tab(7, SITE, false), tab(8, SITE)]
		await fakeBrowser.tabs.onActivated.trigger({ tabId: 8, windowId: 1 })
		await settle()
		expect(await badge(8)).toBe('3')
	})

	it('follows a navigation in the tab', async () => {
		refreshToolbar('state')
		await settle()
		tabs = [tab(7, 'https://b.example/')]
		await fakeBrowser.tabs.onUpdated.trigger(7, { url: 'https://b.example/' }, tabs[0] as never)
		await settle()
		expect(await badge(7)).toBe('')
	})

	it('counts the active tab of a window that gains focus', async () => {
		tabs = [tab(7, SITE, true, 1), tab(9, SITE, true, 2)]
		const get = vi.mocked(browser.tabs.get)
		get.mockClear()
		await fakeBrowser.windows.onFocusChanged.trigger(browser.windows.WINDOW_ID_NONE)
		await fakeBrowser.windows.onFocusChanged.trigger(2)
		await settle()
		expect(get.mock.calls).toEqual([[9]])
		expect(await badge(9)).toBe('3')
	})

	it('computes once for the several updates of one page load', async () => {
		const get = vi.mocked(browser.tabs.get)
		get.mockClear()
		await fakeBrowser.tabs.onUpdated.trigger(7, { status: 'loading' }, tabs[0] as never)
		await fakeBrowser.tabs.onUpdated.trigger(7, { url: SITE }, tabs[0] as never)
		await fakeBrowser.tabs.onUpdated.trigger(7, { title: 'YouTube' }, tabs[0] as never)
		await fakeBrowser.tabs.onUpdated.trigger(7, { status: 'complete' }, tabs[0] as never)
		await settle()
		expect(get).toHaveBeenCalledOnce()
		expect(await badge(7)).toBe('3')
	})

	it('leaves a background tab\'s load to its activation', async () => {
		tabs = [tab(7, SITE), tab(8, SITE, false)]
		const get = vi.mocked(browser.tabs.get)
		get.mockClear()
		await fakeBrowser.tabs.onUpdated.trigger(8, { status: 'complete' }, tabs[1] as never)
		await settle()
		expect(get).not.toHaveBeenCalled()
	})

	it('follows the snapshot after a sync or a save', async () => {
		refreshToolbar('state')
		await settle()
		await vault(accountId, logins(4))
		await settle()
		expect(await badge(7)).toBe('4')
	})

	it('writes no count to storage, only that the state is painted', async () => {
		const local = vi.spyOn(browser.storage.local, 'set')
		const session = vi.spyOn(browser.storage.session, 'set')
		refreshToolbar('state')
		refreshToolbar(7)
		await settle()
		expect(await badge(7)).toBe('3')
		expect(local).not.toHaveBeenCalled()
		expect(session.mock.calls).toEqual([[{ 'toolbar:painted': 'true' }]])
	})

	it('drops another account\'s count from a background tab, also once that account locks', async () => {
		const bob = await newAccount('bob')
		await unlock(bob)
		await setActive(accountId)
		tabs = [tab(7, SITE, false), tab(8, SITE)]
		refreshToolbar(8)
		await settle()
		expect(await badge(8)).toBe('3')
		tabs = [tab(7, SITE), tab(8, SITE, false)]
		await setActive(bob)
		await settle()
		expect([await badge(8), await title(8)]).toEqual(['', 'Keepiq'])
		await lock(accountId)
		await settle()
		expect([await badge(8), await title(8)]).toEqual(['', 'Keepiq'])
	})

	it('still resets the tabs when the icon cannot be set', async () => {
		tabs = [tab(7, SITE), tab(8, SITE, false)]
		for (const id of [7, 8]) refreshToolbar(id)
		await settle()
		const logged = vi.spyOn(console, 'error').mockImplementation(() => {})
		setIcon.mockRejectedValue(new Error('icon'))
		await updateGlobalSettings({ showBadgeCounter: false })
		await settle()
		expect([await badge(7), await badge(8)]).toEqual(['', ''])
		expect(logged).toHaveBeenCalledWith('[keepiq] toolbar icon', expect.any(Error))
	})

	it('paints the other tabs when one closes during the paint', async () => {
		tabs = [tab(7, SITE), tab(9, SITE, true, 2)]
		refreshToolbar('state')
		await settle()
		const set = action.setBadgeText.bind(action)
		vi.spyOn(action, 'setBadgeText').mockImplementation(async (details) => {
			if (details.tabId === 7) throw new Error('No tab with id: 7')
			return set(details)
		})
		await vault(accountId, logins(5))
		await settle()
		expect(await badge(9)).toBe('5')
	})
})

describe('the badge is empty unless unlocked', () => {
	it('clears every tab on lock, the background ones too', async () => {
		tabs = [tab(7, SITE), tab(8, SITE, false), tab(9, SITE, true, 2)]
		for (const id of [7, 8, 9]) refreshToolbar(id)
		await settle()
		expect([await badge(7), await badge(8), await badge(9)]).toEqual(['3', '3', '3'])
		await lock(accountId)
		await settle()
		expect([await badge(), await badge(7), await badge(8), await badge(9)]).toEqual(['', '', '', ''])
		expect([await title(7), await title(8), await title(9)]).toEqual(Array(3).fill('Keepiq: Locked'))
	})

	it('paints no count from a refresh asked for before a lock that runs after it', async () => {
		refreshToolbar(7)
		await lock(accountId)
		await settle()
		expect(await badge(7)).toBe('')
		expect(await title(7)).toBe('Keepiq: Locked')
	})

	it('drops every count from the badges and tooltips as soon as the lock is announced, before the coalesced repaint', async () => {
		tabs = [tab(7, SITE), tab(8, SITE, false)]
		for (const id of [7, 8]) refreshToolbar(id)
		await settle()
		setIcon.mockClear()
		await lock(accountId)
		await flush()
		expect(vi.getTimerCount()).toBe(1)
		expect(setIcon).not.toHaveBeenCalled()
		expect([await badge(7), await badge(8)]).toEqual(['', ''])
		expect([await title(7), await title(8)]).toEqual(['Keepiq: Locked', 'Keepiq: Locked'])
	})

	it('paints no coloured icon read before a lock, not even for a moment', async () => {
		const hold = holdRead({ area: 'local', key: GLOBAL_SETTINGS_KEY }, { area: 'local', key: 'activeAccountId' })
		refreshToolbar('state')
		await vi.advanceTimersByTimeAsync(100)
		await hold.reached
		setIcon.mockClear()
		await lock(accountId)
		hold.release()
		await settle()
		expect(setIcon.mock.calls.map(([details]) => folderOf(details))).toEqual(['toolbar-locked'])
	})

	it('locks a vault past its timeout before counting, as after the computer slept', async () => {
		tabs = [tab(7, SITE, false), tab(8, SITE)]
		vi.spyOn(Date, 'now').mockReturnValue(Date.now() + 16 * 60_000)
		const painted = vi.spyOn(action, 'setBadgeText')
		await fakeBrowser.tabs.onActivated.trigger({ tabId: 8, windowId: 1 })
		await settle()
		expect(painted.mock.calls.map(([details]) => details.text)).toEqual(expect.not.arrayContaining(['3']))
		expect(await badge(8)).toBe('')
		expect(iconFolder()).toBe('toolbar-locked')
	})

	it('paints no count computed while the vault locked', async () => {
		const hold = holdCount()
		await refreshUntil(hold)
		await lock(accountId)
		hold.release()
		await settle()
		expect(await badge(7)).toBe('')
		expect(iconFolder()).toBe('toolbar-locked')
	})

	it('paints no count once the key is gone, before the lock is announced', async () => {
		const hold = holdCount()
		await refreshUntil(hold)
		await clearKey(accountId)
		hold.release()
		await settle()
		expect(await badge(7)).toBe('')
		expect(iconFolder()).toBe('toolbar-locked')
	})

	it('paints no count when the key goes while its status is read', async () => {
		const hold = holdRead({ area: 'session', key: `privateKeyPkcs8.${accountId}` }, { area: 'local', key: 'activeAccountId' })
		await refreshUntil(hold)
		await clearKey(accountId)
		hold.release()
		await settle()
		expect(await badge(7)).toBe('')
		expect(iconFolder()).toBe('toolbar-locked')
	})

	it('paints no count computed before a switch to a locked account, not even for a moment', async () => {
		const bob = await newAccount('bob')
		await setActive(accountId)
		await settle()
		const hold = holdCount()
		await refreshUntil(hold)
		// The fake announces a write before it resolves; Chrome may announce it after the count is painted.
		browser.storage.onChanged.removeListener(onStorageChanged)
		await setActive(bob)
		const painted = vi.spyOn(action, 'setBadgeText')
		hold.release()
		await settle()
		onStorageChanged({ activeAccountId: { newValue: bob, oldValue: accountId } }, 'local')
		await settle()
		expect(painted.mock.calls.map(([details]) => details.text)).toEqual(expect.not.arrayContaining(['3']))
		expect(await badge(7)).toBe('')
	})

	it('clears when the user switches to a locked account, and counts again on switching back', async () => {
		refreshToolbar('state')
		await settle()
		const bob = await newAccount('bob')
		await setActive(bob)
		await settle()
		expect(iconFolder()).toBe('toolbar-locked')
		expect(await badge(7)).toBe('')
		await setActive(accountId)
		await settle()
		expect(iconFolder()).toBe('toolbar')
		expect(await badge(7)).toBe('3')
	})

	it('ignores another account\'s lock', async () => {
		const bob = await newAccount('bob')
		await unlock(bob)
		await setActive(accountId)
		await lock(bob)
		await settle()
		expect(iconFolder()).toBe('toolbar')
		expect(await badge(7)).toBe('3')
	})

	it('never counts from a locked account\'s snapshot', async () => {
		await lock(accountId)
		await vault(accountId, logins(5))
		refreshToolbar(7)
		await settle()
		expect(await badge(7)).toBe('')
		expect(await browser.storage.local.get(vaultCacheKey(accountId))).not.toEqual({})
	})
})

describe('the badge counter setting', () => {
	it('empties every badge and drops the count from the tooltip, the icon keeps its state', async () => {
		tabs = [tab(7, SITE), tab(8, SITE, false)]
		for (const id of [7, 8]) refreshToolbar(id)
		await settle()
		await updateGlobalSettings({ showBadgeCounter: false })
		await settle()
		expect([await badge(7), await badge(8)]).toEqual(['', ''])
		expect([await title(7), await title(8)]).toEqual(['Keepiq', 'Keepiq'])
		expect(iconFolder()).toBe('toolbar')
	})

	it.each([
		['while counting', () => holdCount()],
		['while checking for a switch', () => holdRead({ area: 'local', key: 'activeAccountId' }, { area: 'local', key: GLOBAL_SETTINGS_KEY })],
	])('paints no count computed before it was turned off, held %s', async (_, holdAt) => {
		const hold = holdAt()
		await refreshUntil(hold)
		await updateGlobalSettings({ showBadgeCounter: false })
		const painted = vi.spyOn(action, 'setBadgeText')
		hold.release()
		await settle()
		expect(painted.mock.calls.map(([details]) => details.text)).toEqual(expect.not.arrayContaining(['3']))
	})

	it('counts again when turned back on', async () => {
		await updateGlobalSettings({ showBadgeCounter: false })
		await settle()
		await updateGlobalSettings({ showBadgeCounter: true })
		await settle()
		expect(await badge(7)).toBe('3')
	})
})

describe('the module boundary', () => {
	it('is the only code that sets the icon, the badge text or the title', () => {
		const roots = ['src', 'entrypoints']
		const callers = roots.flatMap((root) => readdirSync(root, { recursive: true, encoding: 'utf8' })
			.filter((file) => /\.tsx?$/.test(file) && !/\.test\.tsx?$/.test(file))
			.filter((file) => /\.(setIcon|setBadgeText|setTitle)\(/.test(readFileSync(`${root}/${file}`, 'utf8')))
			.map((file) => `${root}/${file}`))
		expect(callers).toEqual(['src/toolbar-icon.ts'])
	})

	it('imports no decrypt, API or messaging code', () => {
		const source = readFileSync('src/toolbar-icon.ts', 'utf8')
		expect(source).not.toMatch(/from '@\/src\/(crypto|api|send)|rsaDecrypt|fetch\(|sendMessage/)
	})
})
