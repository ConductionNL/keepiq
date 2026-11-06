/**
 * The "Keepiq" context menu, anywhere on the page. Menus outlive an MV3 worker, so every build starts from `removeAll`.
 * Titles are item names only: Chrome writes them to its extension state on disk (ext-context-menu design.md § Names only).
 */
import type { Browser } from 'wxt/browser'
import { i18n } from '#i18n'
import { readSettings } from '@/src/accounts/settings'
import { accountStatus, getAccount, getActiveAccountId } from '@/src/accounts/store'
import { registerAddIntent, setIntent } from '@/src/add-intent'
import { activeTab, activeUnlockedId, fill, matchingLogins } from '@/src/autofill/fill'
import { isWebUrl } from '@/src/autofill/frames'
import { currentCode, matchingCodes, seedOf } from '@/src/autofill/login-codes'
import { walletCandidates } from '@/src/autofill/wallet'
import { onBroadcast } from '@/src/background/broadcast'
import { openPopupOrPopout } from '@/src/browser-action'
import { writeFromBackground } from '@/src/clipboard'
import { rsaDecrypt } from '@/src/crypto'
import { generate } from '@/src/generator/generate'
import { addHistory, openHistory } from '@/src/generator/history'
import { readOptions, readPolicy } from '@/src/generator/store'
import type { LastUsed } from '@/src/last-used'
import type { AddIntent, BackgroundToContent, FormKind, PageNoticeCode } from '@/src/messages'
import { getKey, sessionEpoch } from '@/src/vault/key-store'
import { readSnapshot } from '@/src/vault/store'
import { enforce, touch } from '@/src/vault/timeout'

export const MENU_LIMIT = 10
/** Not `all`, which on Chrome also covers the toolbar icon's own menu; Firefox's `password` is inside `editable`. */
const CONTEXTS: NonNullable<Browser.contextMenus.CreateProperties['contexts']> = ['page', 'frame', 'selection', 'link', 'editable', 'image', 'video', 'audio']
const ROOT = 'keepiq'

export interface MenuEntry {
	id: string
	title?: string
	parentId?: string
	type?: 'separator'
	enabled?: false
}

type Rows = ReadonlyArray<{ id: string; name: string }>

// `&` marks an access key in menu titles on both browsers.
const title = (name: string) => name.replaceAll('&', '&&')

/** `prefix`: its items' ids are `<prefix>:<itemId>`. */
interface Submenu {
	id: string
	prefix: string
	label: () => string
	empty: () => string
}

/** A submenu that fills, and ends with Add and Open Keepiq. */
interface FillMenu extends Submenu {
	add: AddIntent['add']
	separator: string
	addLabel: () => string
	notice: PageNoticeCode
}

const LOGIN_MENU: FillMenu = {
	id: 'autofill', prefix: 'autofill', add: 'login', separator: 'autofill-separator', notice: 'no_login_form',
	label: () => i18n.t('contextMenu.autofill'), empty: () => i18n.t('contextMenu.noMatches'), addLabel: () => i18n.t('contextMenu.addLogin'),
}
const FORM_MENUS: ReadonlyArray<FillMenu & { add: FormKind }> = [
	{
		id: 'autofill-identity', prefix: 'identity', add: 'identity', separator: 'identity-separator', notice: 'no_identity_form',
		label: () => i18n.t('contextMenu.autofillIdentity'), empty: () => i18n.t('contextMenu.noIdentities'), addLabel: () => i18n.t('contextMenu.addIdentity'),
	},
	{
		id: 'autofill-card', prefix: 'card', add: 'card', separator: 'card-separator', notice: 'no_card_form',
		label: () => i18n.t('contextMenu.autofillCard'), empty: () => i18n.t('contextMenu.noCards'), addLabel: () => i18n.t('contextMenu.addCard'),
	},
]
const COPY_MENUS = {
	user: { id: 'copy-user', prefix: 'copy-user', label: () => i18n.t('contextMenu.copyUsername'), empty: () => i18n.t('contextMenu.noUsernames') },
	pass: { id: 'copy-pass', prefix: 'copy-pass', label: () => i18n.t('contextMenu.copyPassword'), empty: () => i18n.t('contextMenu.noPasswords') },
	code: { id: 'copy-code', prefix: 'copy-code', label: () => i18n.t('contextMenu.copyCode'), empty: () => i18n.t('contextMenu.noCodes') },
} satisfies Record<string, Submenu>

const FILL_MENUS = [LOGIN_MENU, ...FORM_MENUS]
const OPENS_POPUP = new Set(['unlock', ...FILL_MENUS.map((menu) => `open:${menu.add}`)])
const ADDS = new Map(FILL_MENUS.map((menu) => [`add:${menu.add}`, menu.add]))
const FILLS = new Map(FILL_MENUS.map((menu) => [menu.prefix, menu]))

/** Always a parent, never disabled itself; without rows it holds one disabled line. */
function submenu(menu: Submenu, rows: Rows): MenuEntry[] {
	const items: MenuEntry[] = rows.length
		? rows.slice(0, MENU_LIMIT).map((row) => ({ id: `${menu.prefix}:${row.id}`, parentId: menu.id, title: title(row.name) }))
		: [{ id: `${menu.prefix}-none`, parentId: menu.id, title: menu.empty(), enabled: false }]
	return [{ id: menu.id, parentId: ROOT, title: menu.label() }, ...items]
}

function fillMenu(menu: FillMenu, rows: Rows): MenuEntry[] {
	return [
		...submenu(menu, rows),
		{ id: menu.separator, parentId: menu.id, type: 'separator' },
		{ id: `add:${menu.add}`, parentId: menu.id, title: menu.addLabel() },
		{ id: `open:${menu.add}`, parentId: menu.id, title: i18n.t('contextMenu.openKeepiq') },
	]
}

/** Every card and identity, not matched by site, on any web page (spec `card-identity-fill`); none elsewhere. */
async function formMenus(accountId: string, page: { url: string | undefined; lastUsed: LastUsed }): Promise<MenuEntry[]> {
	const vault = await readSnapshot(accountId)
	const web = vault !== undefined && page.url !== undefined && isWebUrl(page.url)
	return FORM_MENUS.flatMap((menu) => fillMenu(menu, web ? walletCandidates(menu.add, vault, page.lastUsed) : []))
}

/** What the menu shows now: nothing without an account, for a logged-out one or with the setting off. */
export async function menuEntries(): Promise<MenuEntry[]> {
	const id = await getActiveAccountId()
	const account = id ? await getAccount(id) : undefined
	const status = account ? await accountStatus(account) : 'logged_out'
	if (!account || status === 'logged_out' || !(await readSettings(account.id)).contextMenu) return []
	const root: MenuEntry = { id: ROOT, title: i18n.t('contextMenu.root') }
	if (status === 'locked') return [root, { id: 'unlock', parentId: ROOT, title: i18n.t('contextMenu.unlock') }]
	const pageUrl = (await activeTab())?.url
	const { rows, lastUsed } = await matchingLogins(account.id, pageUrl)
	const { rows: codes } = await matchingCodes(account.id, pageUrl)
	return [
		root,
		...fillMenu(LOGIN_MENU, rows),
		...submenu(COPY_MENUS.user, rows.filter((row) => row.login !== null)),
		// A use-only copy's password is never shown or copied (ADR-003).
		...submenu(COPY_MENUS.pass, rows.filter((row) => row.useOnly !== true)),
		...submenu(COPY_MENUS.code, codes),
		{ id: 'separator-1', parentId: ROOT, type: 'separator' },
		...await formMenus(account.id, { url: pageUrl, lastUsed }),
		{ id: 'separator-2', parentId: ROOT, type: 'separator' },
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

/** The current code, computed at the click; the lock clears it from the clipboard at once. */
async function copyCode(accountId: string, itemId: string, pageUrl: string | undefined): Promise<void> {
	const row = (await matchingCodes(accountId, pageUrl)).rows.find((match) => match.id === itemId)
	const key = await getKey(accountId)
	const seed = row && key ? await seedOf(row, key) : null
	const code = seed === null ? null : await currentCode(seed)
	if (code) await writeFromBackground(code.digits, (await readSettings(accountId)).clearClipboardMs, { codeOf: accountId })
}

async function generateAndCopy(accountId: string): Promise<void> {
	const epoch = sessionEpoch(accountId)
	const result = await generate('password', await readOptions(accountId), await readPolicy(accountId), { website: null, accountEmail: null })
	if (!result.ok) return
	// In the history like the popup's, so a password copied into a sign-up that then fails is not lost.
	if (!(await writeFromBackground(result.value, (await readSettings(accountId)).clearClipboardMs))) return
	// As for the popup's add, what the lock screen sealed moves into the history first.
	await openHistory(accountId, epoch)
	await addHistory(accountId, { value: result.value, kind: 'password' }, epoch)
}

async function fillFromMenu(itemId: string, tabId: number, notice: PageNoticeCode): Promise<void> {
	const result = await fill({ itemId, tabId, ask: 'page', code: true })
	if (result.noFields) {
		const message: BackgroundToContent = { kind: 'notice.show', notice }
		// A tab without our content script (a PDF, a store page) gets no notice.
		void browser.tabs.sendMessage(tabId, message, { frameId: 0 }).catch(() => {})
	}
	await rebuildMenus()
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
	const menu = FILLS.get(action)
	if (menu) await fillFromMenu(itemId, tab.id, menu.notice)
	if (action === 'copy-user') await copy(accountId, itemId, 'login', tab.url)
	if (action === 'copy-pass') await copy(accountId, itemId, 'key', tab.url)
	if (action === 'copy-code') await copyCode(accountId, itemId, tab.url)
}

export function onMenuClicked(info: Browser.contextMenus.OnClickData, tab?: Browser.tabs.Tab): void {
	const menuId = String(info.menuItemId)
	// Before any await: Firefox below 149 opens the popup only from the click itself.
	const add = ADDS.get(menuId)
	if (add) setIntent(add)
	if (add || OPENS_POPUP.has(menuId)) {
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
	registerAddIntent()
	browser.contextMenus.onClicked.addListener(onMenuClicked)
}
