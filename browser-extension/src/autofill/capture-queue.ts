/**
 * The background half of login capture (spec `login-capture`, design.md § Capture queue): one pending capture
 * per tab, in background memory only, so a captured password never reaches storage (ADR-002). An MV3 worker
 * restart loses it, which is accepted. The bar page gets the mode, hosts, item name, username and folders, never
 * the password, and only with the key its host posted to it (design.md § Notification bar).
 */
import type { Browser } from 'wxt/browser'
import { addExcludedDomain, readSettings } from '@/src/accounts/settings'
import type { OpenSecretRow } from '@/src/api/types'
import { onBroadcast } from '@/src/background/broadcast'
import { rsaDecrypt } from '@/src/crypto'
import type {
	BackgroundToContent, CaptureRefused, CaptureStateReply, CaptureSubmitted, NotificationReplies, NotificationToBackground, PickInput, WriteResult,
} from '@/src/messages'
import { readGlobalSettings } from '@/src/settings/global'
import { getKey, hasKey } from '@/src/vault/key-store'
import { flattenFolders, folderPath } from '@/src/vault/list'
import { baseDomain } from '@/src/vault/match'
import { readSnapshot } from '@/src/vault/store'
import { enforce, touch } from '@/src/vault/timeout'
import { createItem, run, updateItem } from '@/src/vault/write'
import { activeUnlockedId, matchingLogins } from './fill'
import { forgetAllItems, forgetItem, rememberedItem, rememberItem } from './tab-items'

export const CAPTURE_LIFESPAN_MS = 5 * 60_000
/** How long the bar shows "Login saved" or "Password updated" before the background closes it. */
export const SAVED_CLOSE_MS = 3000
/** A submit that navigates starts loading within this; one that does not (a single-page app) shows the bar after it. */
export const SHOW_DELAY_MS = 300
/** Keys of earlier shows stay valid for the bar a slower page load still shows; older ones are dropped. */
const MAX_KEYS = 4

interface Capture {
	accountId: string
	/** The submitting frame's URL, from `sender`: the item is matched, excluded and saved under it. */
	frameUrl: string
	baseDomain: string
	/** The top frame's at the submit; the bar shows only while the tab stays on it. */
	tabBaseDomain: string
	tabHost: string
	username: string | null
	password: string
	mode: 'add' | 'update'
	itemId?: string
	/** The item's own username, for the update question. */
	itemUsername?: string
	/** Each show's key and the bar frame that first presented it. */
	keys: Map<string, number | null>
	/** The tab started loading after the submit, so the bar waits for `complete`. */
	navigated: boolean
	expiresAt: number
	timer: ReturnType<typeof setTimeout>
	/** A save or update in flight, which a second click joins instead of writing twice. */
	writing?: Promise<WriteResult<null> | CaptureRefused>
}

type Decision = { mode: 'add' } | { mode: 'update'; itemId: string; itemUsername?: string } | null

const pending = new Map<number, Capture>()
/** A saved or updated capture's bound keys, so its bar's Close still closes it before the background does. */
const closing = new Map<number, Set<string>>()
/** The newest submit per tab, so a slower decision on an older one never replaces it. */
const newest = new Map<number, number>()
let submits = 0

const refused = (code: 'invalid' | 'locked' | 'unknown'): WriteResult<null> => ({ ok: false, error: { code } })
const NOT_VISIBLE: CaptureRefused = { refused: 'not_visible' }

/** The bar lives in the top frame only. */
const toTop = (tabId: number, message: BackgroundToContent) => browser.tabs.sendMessage(tabId, message, { frameId: 0 }).catch(() => undefined)

function hide(tabId: number, keys: Iterable<string>, done?: true): void {
	for (const key of keys) void toTop(tabId, done ? { kind: 'capture.hide', key, done } : { kind: 'capture.hide', key })
}

function drop(tabId: number, hideBar = true): void {
	const entry = pending.get(tabId)
	if (!entry) return
	clearTimeout(entry.timer)
	pending.delete(tabId)
	if (hideBar) hide(tabId, entry.keys.keys())
}

/** Timers can run late, so the expiry is checked on every use too. */
function live(tabId: number): Capture | undefined {
	const entry = pending.get(tabId)
	if (entry && entry.expiresAt <= Date.now()) drop(tabId)
	return pending.get(tabId)
}

const isWeb = (url: string) => /^https?:\/\//i.test(url)

const isPickInput = (input: unknown): input is PickInput => {
	const value = input as Partial<PickInput> | null
	return (value?.kind === 'pointer' || value?.kind === 'key') && [value.at, value.x, value.y].every((n) => typeof n === 'number' && Number.isFinite(n))
}

function newKey(): string {
	return Array.from(crypto.getRandomValues(new Uint8Array(16)), (byte) => byte.toString(16).padStart(2, '0')).join('')
}

async function open(ciphertext: string | null, key: CryptoKey): Promise<string | null | undefined> {
	if (ciphertext === null) return null
	try {
		return await rsaDecrypt(ciphertext, key)
	} catch {
		return undefined
	}
}

/** Add, update or nothing, from the decrypted `login` and `key` of the items matching the submitting frame. */
async function decide(accountId: string, tabId: number, frameUrl: string, report: CaptureSubmitted): Promise<Decision> {
	const key = await getKey(accountId)
	if (!key) return null
	const { rows } = await matchingLogins(accountId, frameUrl)
	const logins = await Promise.all(rows.map((row) => open(row.login, key)))
	const loginOf = (row: OpenSecretRow) => logins[rows.indexOf(row)]
	// An empty username and none are the same.
	const holders = rows.filter((row) => loginOf(row) !== undefined && (loginOf(row) ?? '') === (report.username ?? ''))
	let target: OpenSecretRow | undefined
	if (report.changed) {
		// A change form names its item by the username, else by what this tab filled or captured, else by being the only match.
		const candidates = report.username ? holders : rows
		target = candidates.find((row) => row.id === rememberedItem(tabId)) ?? (candidates.length === 1 ? candidates[0] : undefined)
		if (!target) return report.username && holders.length === 0 ? { mode: 'add' } : null
	} else {
		if (holders.length === 0) return { mode: 'add' }
		// Several items with this username: which one changed is unknown.
		if (holders.length > 1) return null
		target = holders[0]!
	}
	if ((await open(target.key, key)) === report.password) {
		rememberItem(tabId, target.id)
		return null
	}
	// `updateItem` refuses these, so a bar could only fail.
	if (target.useOnly || target.readOnly) return null
	const itemUsername = loginOf(target)
	return itemUsername ? { mode: 'update', itemId: target.id, itemUsername } : { mode: 'update', itemId: target.id }
}

async function tabUrlOf(sender: Browser.runtime.MessageSender, tabId: number): Promise<string | undefined> {
	if (sender.frameId === 0) return sender.url
	return sender.tab?.url ?? (await browser.tabs.get(tabId).catch(() => undefined))?.url
}

/** `capture.submitted` from a content script, already routed by sender page. URL, tab and frame come from `sender`. */
export async function captureSubmitted(message: CaptureSubmitted, sender: Browser.runtime.MessageSender): Promise<void> {
	const tabId = sender.tab?.id
	const frameUrl = sender.url
	if (tabId === undefined || sender.frameId === undefined || !frameUrl || !isWeb(frameUrl)) return
	const { username, password, changed } = message
	if (typeof password !== 'string' || password === '' || (username !== null && typeof username !== 'string') || typeof changed !== 'boolean') return
	const submit = ++submits
	newest.set(tabId, submit)
	const decided = await (async () => {
		await enforce()
		const accountId = await activeUnlockedId()
		if (!accountId) return null
		const domain = baseDomain(frameUrl)
		const tabUrl = await tabUrlOf(sender, tabId)
		const tabDomain = tabUrl ? baseDomain(tabUrl) : null
		if (!domain || !tabDomain) return null
		const settings = await readSettings(accountId)
		if (settings.excludedDomains.includes(domain) || (!settings.askToAdd && !settings.askToUpdate)) return null
		const decision = await decide(accountId, tabId, frameUrl, message)
		if (!decision || !(decision.mode === 'add' ? settings.askToAdd : settings.askToUpdate)) return null
		return { accountId, domain, tabDomain, tabHost: new URL(tabUrl!).host, decision }
	})()
	if (newest.get(tabId) !== submit) return
	// A newer submit replaces the older capture, also when it needs no bar.
	drop(tabId, !decided)
	// A lock while deciding must not leave a capture behind.
	if (!decided || !(await hasKey(decided.accountId)) || newest.get(tabId) !== submit) return
	closing.delete(tabId)
	const entry: Capture = {
		accountId: decided.accountId, frameUrl, baseDomain: decided.domain, tabBaseDomain: decided.tabDomain, tabHost: decided.tabHost,
		username: username || null, password, ...decided.decision, keys: new Map(), navigated: false, expiresAt: Date.now() + CAPTURE_LIFESPAN_MS,
		timer: setTimeout(() => {
			if (pending.get(tabId) === entry) drop(tabId)
		}, CAPTURE_LIFESPAN_MS),
	}
	pending.set(tabId, entry)
	// A page that navigates after the submit is not shown the bar, which would only flash before it unloads.
	setTimeout(() => {
		if (pending.get(tabId) === entry && !entry.navigated) void showPending(tabId)
	}, SHOW_DELAY_MS)
}

/** Shows the bar in the top frame once the tab has loaded, while it stays on the base domain it had at the submit. */
export async function showPending(tabId: number): Promise<void> {
	const entry = live(tabId)
	if (!entry) return
	const tab = await browser.tabs.get(tabId).catch(() => undefined)
	if (pending.get(tabId) !== entry || tab?.status !== 'complete' || !tab.url) return
	if (baseDomain(tab.url) !== entry.tabBaseDomain) return drop(tabId)
	const key = newKey()
	entry.keys.set(key, null)
	for (const old of [...entry.keys.keys()].slice(0, -MAX_KEYS)) entry.keys.delete(old)
	await toTop(tabId, { kind: 'capture.show', key })
}

/** The `focus_notification_bar` command. */
export function focusBar(tabId: number): void {
	if (live(tabId)) void toTop(tabId, { kind: 'capture.focus' })
}

async function state(entry: Capture): Promise<CaptureStateReply | null> {
	const vault = await readSnapshot(entry.accountId)
	if (!vault || (await activeUnlockedId()) !== entry.accountId) return null
	const folders = flattenFolders(vault.folders).map((folder) => ({ id: folder.id, name: folderPath(vault.folders, folder.id) ?? folder.name }))
	const itemName = entry.mode === 'update' ? vault.secrets.find((row) => row.id === entry.itemId)?.name : undefined
	// A sync dropped the item since the submit: as when Update finds it gone, the capture becomes an add.
	if (entry.mode === 'update' && itemName === undefined) toAdd(entry)
	const host = new URL(entry.frameUrl).host
	const reply: CaptureStateReply = { mode: entry.mode, host, folders, theme: (await readGlobalSettings()).theme }
	if (entry.tabHost !== host) reply.tabHost = entry.tabHost
	if (itemName !== undefined) reply.itemName = itemName
	if (itemName !== undefined && entry.itemUsername) reply.username = entry.itemUsername
	const shortcut = await barShortcut()
	if (shortcut) reply.shortcut = shortcut
	return reply
}

/** As the browser names it; empty when the user unassigned it or the browser cannot say. */
async function barShortcut(): Promise<string> {
	try {
		return (await browser.commands.getAll()).find((command) => command.name === 'focus_notification_bar')?.shortcut ?? ''
	} catch {
		return ''
	}
}

function toAdd(entry: Capture): void {
	entry.mode = 'add'
	delete entry.itemId
	delete entry.itemUsername
}

/** The bar shows its confirmation, then the background closes it, unless a newer capture shows by then. */
function finish(tabId: number, entry: Capture, itemId: string, key: string): void {
	rememberItem(tabId, itemId)
	if (pending.get(tabId) === entry) drop(tabId, false)
	closing.set(tabId, new Set([key]))
	setTimeout(() => {
		if (pending.has(tabId) || !closing.get(tabId)?.has(key)) return
		closing.delete(tabId)
		hide(tabId, [key], true)
	}, SAVED_CLOSE_MS)
}

/** The host's visibility guard at the press (design.md § Visibility guard), before anything is decided. */
async function visibleAt(tabId: number, key: string, input: unknown): Promise<boolean> {
	if (!isPickInput(input)) return false
	return await toTop(tabId, { kind: 'capture.verify', key, input }) === true
}

/** A decision is a user's action, so it counts as interaction, after the timeout had its say. */
async function interact(tabId: number, entry: Capture): Promise<boolean> {
	await enforce()
	if (live(tabId) !== entry || !(await hasKey(entry.accountId))) return false
	await touch()
	return true
}

async function save(tabId: number, entry: Capture, key: string, folderId: unknown): Promise<WriteResult<null>> {
	if (entry.mode !== 'add' || (folderId !== null && typeof folderId !== 'string')) return refused('invalid')
	if (!(await interact(tabId, entry))) return refused('locked')
	const typeId = (await readSnapshot(entry.accountId))?.types.find((type) => type.name === 'login')?.id
	if (!typeId) return refused('unknown')
	const page = new URL(entry.frameUrl)
	const result = await createItem({
		typeId, name: page.host, url: page.origin, folderId, key: entry.password, login: entry.username, additionalFields: {},
	}, entry.accountId)
	if (!result.ok) return result
	finish(tabId, entry, result.value.id, key)
	return { ok: true, value: null }
}

/** Only `key`, through `updateItem` and so share-save, on a fresh version; with no edit form behind it, partner shares fail as `unverified`. */
async function update(tabId: number, entry: Capture, key: string): Promise<WriteResult<null>> {
	const itemId = entry.itemId
	if (entry.mode !== 'update' || itemId === undefined) return refused('invalid')
	if (!(await interact(tabId, entry))) return refused('locked')
	const fetched = await run(({ client }) => client.keepiq<OpenSecretRow | null>('GET', `/api/v1/secrets/${encodeURIComponent(itemId)}`), 'reply', entry.accountId)
	if (fetched.ok && typeof fetched.value?.updatedAt !== 'string') return refused('unknown')
	const result = fetched.ok ? await updateItem(itemId, { key: entry.password }, fetched.value!.updatedAt, entry.accountId) : fetched
	if (!result.ok) {
		// Deleted meanwhile: the bar offers Save, and this capture becomes an add.
		if (result.error.code === 'not_found' && pending.get(tabId) === entry) toAdd(entry)
		return { ok: false, error: result.error }
	}
	finish(tabId, entry, itemId, key)
	return { ok: true, value: null }
}

const writing = (entry: Capture, write: () => Promise<WriteResult<null> | CaptureRefused>) => {
	entry.writing ??= write().finally(() => delete entry.writing)
	return entry.writing
}

/**
 * Only the bar frame that first presented a key this tab's capture issued; the first `capture.state` binds it.
 * A copy of the bar the page frames itself never gets a key.
 */
function bound(entry: Capture, key: unknown, kind: string, frameId: number | undefined): key is string {
	if (typeof key !== 'string' || frameId === undefined || !entry.keys.has(key)) return false
	if (entry.keys.get(key) === null) {
		if (kind !== 'capture.state') return false
		entry.keys.set(key, frameId)
	}
	return entry.keys.get(key) === frameId
}

/** A bar page message, routed by sender page; the capture is the sender tab's. */
export async function handleNotification<K extends NotificationToBackground['kind']>(
	message: Extract<NotificationToBackground, { kind: K }>, sender: Browser.runtime.MessageSender,
): Promise<NotificationReplies[K]> {
	const reply = await handle(message as NotificationToBackground, sender)
	return reply as NotificationReplies[K]
}

async function handle(m: NotificationToBackground, sender: Browser.runtime.MessageSender): Promise<NotificationReplies[NotificationToBackground['kind']]> {
	const tabId = sender.tab?.id
	if (tabId === undefined) return null
	const entry = live(tabId)
	if (!entry) {
		// A bar whose capture was saved or updated still closes at once on Close.
		if (m.kind === 'capture.dismiss' && typeof m.key === 'string' && closing.get(tabId)?.delete(m.key)) hide(tabId, [m.key])
		return null
	}
	if (!bound(entry, m.key, m.kind, sender.frameId)) return null
	const key = m.key
	switch (m.kind) {
		case 'capture.state':
			return state(entry)
		case 'capture.save':
			return writing(entry, async () => (await visibleAt(tabId, key, m.input) ? save(tabId, entry, key, m.folderId) : NOT_VISIBLE))
		case 'capture.update':
			return writing(entry, async () => (await visibleAt(tabId, key, m.input) ? update(tabId, entry, key) : NOT_VISIBLE))
		case 'capture.dismiss':
			if (m.never === true && !(await visibleAt(tabId, key, m.input))) return NOT_VISIBLE
			drop(tabId)
			if (m.never === true) await addExcludedDomain(entry.accountId, entry.baseDomain)
			return null
		case 'capture.expand':
			void toTop(tabId, { kind: 'capture.expand', key })
			return null
	}
}

/** `capture.reshow` from the top frame's host, which closed its bar to show it again inside a modal. */
export async function reshow(sender: Browser.runtime.MessageSender): Promise<void> {
	const tabId = sender.tab?.id
	if (tabId !== undefined && sender.frameId === 0) await showPending(tabId)
}

/** Account switch: the capture was matched against another vault. */
export function dropAllCaptures(): void {
	for (const tabId of [...pending.keys()]) drop(tabId)
	forgetAllItems()
}

export function registerCaptureQueue(): void {
	browser.tabs.onRemoved.addListener((tabId) => {
		drop(tabId, false)
		closing.delete(tabId)
		newest.delete(tabId)
		forgetItem(tabId)
	})
	browser.tabs.onUpdated.addListener((tabId, change, tab) => {
		const entry = pending.get(tabId)
		if (!entry) return
		const url = change.url ?? (change.status !== undefined ? tab?.url : undefined)
		if (url !== undefined && baseDomain(url) !== entry.tabBaseDomain) return drop(tabId)
		if (change.status === 'loading') entry.navigated = true
		if (change.status === 'complete') void showPending(tabId)
	})
	onBroadcast((message) => {
		if (message.kind !== 'vault.locked') return
		forgetAllItems()
		for (const [tabId, entry] of pending) {
			void hasKey(entry.accountId).then((unlocked) => {
				if (!unlocked && pending.get(tabId) === entry) drop(tabId)
			})
		}
	})
}
