/**
 * The "Keepiq" context menu on editable fields. Menus outlive an MV3 worker, so every build
 * starts from `removeAll`; item entries carry the item id, never a username or password.
 */
import type { Browser } from 'wxt/browser'
import { i18n } from '#i18n'
import { readSettings } from '@/src/accounts/settings'
import { accountStatus, getAccount, getActiveAccountId } from '@/src/accounts/store'
import { activeTab, activeUnlockedId, fill, matchingLogins } from '@/src/autofill/fill'
import { onBroadcast } from '@/src/background/broadcast'
import { openPopupOrPopout } from '@/src/browser-action'
import { writeFromBackground } from '@/src/clipboard'
import { rsaDecrypt } from '@/src/crypto'
import { generate } from '@/src/generator/generate'
import { addHistory } from '@/src/generator/history'
import { readOptions, readPolicy } from '@/src/generator/store'
import { getKey, sessionEpoch } from '@/src/vault/key-store'
import { enforce, touch } from '@/src/vault/timeout'

export const MENU_LIMIT = 10
const CONTEXTS: ['editable'] = ['editable']
const ROOT = 'keepiq'

export interface MenuEntry {
	id: string
	title?: string
	parentId?: string
	type?: 'separator'
	enabled?: false
}

// `&` marks an access key in menu titles on both browsers.
const title = (name: string) => name.replaceAll('&', '&&')

/** One entry for a single item, a submenu for several, a disabled entry for none. */
function perItem(prefix: string, label: string, rows: ReadonlyArray<{ id: string; name: string }>): MenuEntry[] {
	if (rows.length === 1) return [{ id: `${prefix}:${rows[0]!.id}`, parentId: ROOT, title: label }]
	if (!rows.length) return [{ id: prefix, parentId: ROOT, title: label, enabled: false }]
	return [
		{ id: prefix, parentId: ROOT, title: label },
		...rows.slice(0, MENU_LIMIT).map((row) => ({ id: `${prefix}:${row.id}`, parentId: prefix, title: title(row.name) })),
	]
}

/** What the menu shows now: nothing without an account, for a logged-out one or with the setting off. */
export async function menuEntries(): Promise<MenuEntry[]> {
	const id = await getActiveAccountId()
	const account = id ? await getAccount(id) : undefined
	const status = account ? await accountStatus(account) : 'logged_out'
	if (!account || status === 'logged_out' || !(await readSettings(account.id)).contextMenu) return []
	const root: MenuEntry = { id: ROOT, title: i18n.t('contextMenu.root') }
	if (status === 'locked') return [root, { id: 'unlock', parentId: ROOT, title: i18n.t('contextMenu.unlock') }]
	const { rows } = await matchingLogins(account.id, (await activeTab())?.url)
	const autofill: MenuEntry[] = rows.length
		? [
			{ id: 'autofill', parentId: ROOT, title: i18n.t('contextMenu.autofill') },
			...rows.slice(0, MENU_LIMIT).map((row) => ({ id: `autofill:${row.id}`, parentId: 'autofill', title: title(row.name) })),
			{ id: 'autofill-separator', parentId: 'autofill', type: 'separator' as const },
			{ id: 'open', parentId: 'autofill', title: i18n.t('contextMenu.openKeepiq') },
		]
		: [{ id: 'autofill', parentId: ROOT, title: i18n.t('contextMenu.noMatches'), enabled: false }]
	return [
		root,
		...autofill,
		...perItem('copy-user', i18n.t('contextMenu.copyUsername'), rows.filter((row) => row.login !== null)),
		// A use-only copy's password is never shown or copied (ADR-003).
		...perItem('copy-pass', i18n.t('contextMenu.copyPassword'), rows.filter((row) => row.useOnly !== true)),
		{ id: 'generate', parentId: ROOT, title: i18n.t('contextMenu.generatePassword') },
	]
}

async function build(): Promise<void> {
	const entries = await menuEntries()
	await browser.contextMenus.removeAll()
	for (const entry of entries) browser.contextMenus.create({ ...entry, contexts: CONTEXTS })
}

let chain: Promise<void> = Promise.resolve()
let waiting = false

/** Requests while one build waits to start join it; one during a build runs after it. */
export function rebuildMenus(): Promise<void> {
	if (waiting) return chain
	waiting = true
	chain = chain.then(() => {
		waiting = false
		return build()
	}).catch((error: unknown) => console.error('[keepiq] context menu', error))
	return chain
}

async function copy(accountId: string, itemId: string, field: 'login' | 'key', pageUrl: string | undefined): Promise<void> {
	// Still a match for the tab: the menu may be older than the vault or the page.
	const row = (await matchingLogins(accountId, pageUrl)).rows.find((match) => match.id === itemId)
	const ciphertext = field === 'login' ? row?.login : row?.useOnly === true ? null : row?.key
	const key = await getKey(accountId)
	if (!ciphertext || !key) return
	await writeFromBackground(await rsaDecrypt(ciphertext, key), (await readSettings(accountId)).clearClipboardMs)
}

async function generateAndCopy(accountId: string): Promise<void> {
	const epoch = sessionEpoch(accountId)
	const result = await generate('password', await readOptions(accountId), await readPolicy(accountId), { website: null, accountEmail: null })
	if (!result.ok) return
	// In the history like the popup's, so a password copied into a sign-up that then fails is not lost.
	if (await writeFromBackground(result.value, (await readSettings(accountId)).clearClipboardMs)) await addHistory(accountId, { value: result.value, kind: 'password' }, epoch)
}

async function act(menuId: string, tab: Browser.tabs.Tab | undefined): Promise<void> {
	await enforce()
	const accountId = await activeUnlockedId()
	if (!accountId) return
	await touch()
	if (menuId === 'generate') return generateAndCopy(accountId)
	const split = menuId.indexOf(':')
	if (split < 0 || tab?.id === undefined) return
	const action = menuId.slice(0, split)
	const itemId = menuId.slice(split + 1)
	if (action === 'autofill') {
		await fill({ itemId, tabId: tab.id, ask: 'page' })
		await rebuildMenus()
	}
	if (action === 'copy-user') await copy(accountId, itemId, 'login', tab.url)
	if (action === 'copy-pass') await copy(accountId, itemId, 'key', tab.url)
}

export function onMenuClicked(info: Browser.contextMenus.OnClickData, tab?: Browser.tabs.Tab): void {
	const menuId = String(info.menuItemId)
	// Before any await: Firefox below 149 opens the popup only from the click itself.
	if (menuId === 'open' || menuId === 'unlock') {
		void openPopupOrPopout(tab?.id).catch((error: unknown) => console.error('[keepiq] open popup', error))
		return
	}
	void act(menuId, tab).catch((error: unknown) => console.error('[keepiq] context menu', menuId, error))
}

/** Lock, logout and sync reach it through `onBroadcast`; the background rebuilds after the popup changes accounts or settings. */
export function registerMenus(): void {
	const rebuild = () => void rebuildMenus()
	browser.runtime.onInstalled.addListener(rebuild)
	// The menu persisted from before the restart, when the vault was unlocked.
	browser.runtime.onStartup.addListener(rebuild)
	browser.tabs.onActivated.addListener(rebuild)
	browser.tabs.onUpdated.addListener((_tabId, change, tab) => {
		if (change.url && tab.active) rebuild()
	})
	// Focus leaving the browser changes no tab.
	browser.windows.onFocusChanged.addListener((windowId) => {
		if (windowId !== browser.windows.WINDOW_ID_NONE) rebuild()
	})
	onBroadcast(rebuild)
	browser.contextMenus.onClicked.addListener(onMenuClicked)
}
