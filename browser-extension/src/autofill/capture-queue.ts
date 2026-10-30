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
	BackgroundToContent, CaptureRefused, CaptureStateReply, CaptureSubmitted, NotificationReplies, NotificationToBackground, PickInput, UriMatch, WriteResult,
} from '@/src/messages'
import { readGlobalSettings } from '@/src/settings/global'
import { getKey, hasKey } from '@/src/vault/key-store'
import { flattenFolders, folderPath } from '@/src/vault/list'
import { baseDomain } from '@/src/vault/match'
import { readSnapshot } from '@/src/vault/store'
import { webUrl } from '@/src/vault/url'
import { enforce, touch } from '@/src/vault/timeout'
import { createItem, run, updateItem } from '@/src/vault/write'
import { activeUnlockedId } from './fill'
import { candidatesFor } from './matcher'
import { forgetAllItems, forgetItem, rememberedItem, rememberItem } from './tab-items'

export const CAPTURE_LIFESPAN_MS = 5 * 60_000
/** How long the bar shows "Login saved" or "Password updated" before the background closes it. */
export const SAVED_CLOSE_MS = 3000
/** A submit that navigates starts loading within this; one that does not (a single-page app) shows the bar after it. */
export const SHOW_DELAY_MS = 300
/** Keys of earlier shows stay valid for the bar a slower page load still shows; a show that makes a new bar keeps this many, its own included. */
const MAX_KEYS = 4

interface Capture {
	/** Sent with every show, so the host replaces a bar of an earlier capture. */
	id: string
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
	/** Each show's key and the bar frame that first presented it, oldest first; a kept bar's key moves to newest. */
	keys: Map<string, number | null>
	/** The tab started loading after the submit, so the bar waits for `complete`. */
	navigated: boolean
	expiresAt: number
	timer: ReturnType<typeof setTimeout>
	/** A save or update in flight, which a second click joins instead of writing twice. */
	writing?: Promise<WriteResult<null> | CaptureRefused>
}

/** `username`: a change form's login's, for a form without one. */
type Decision = { mode: 'add'; username?: string } | { mode: 'update'; itemId: string; itemUsername?: string } | null

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

/** The frame's logins in the vault: on its base domain, or under the user's rule where that reaches further. */
async function siteLogins(accountId: string, frameUrl: string, rule: UriMatch): Promise<OpenSecretRow[]> {
	const vault = await readSnapshot(accountId)
	if (!vault) return []
	const found = new Set([...candidatesFor(frameUrl, vault, 'baseDomain', {}), ...candidatesFor(frameUrl, vault, rule, {})])
	return [...found].filter((row): row is OpenSecretRow => !row.blocked)
}

/** Whether the login's normalised url is a prefix of the frame's that ends at a path boundary, so `/jira` is not under `/jira-test`. */
function under(itemUrl: string | null, frame: URL): boolean {
	const prefix = webUrl(itemUrl)?.href
	if (!prefix || !frame.href.startsWith(prefix)) return false
	return /[/?#]$/.test(prefix) || /^[/?#]?$/.test(frame.href.charAt(prefix.length))
}

/** Whether the login is for a bare host the frame's is a subdomain of, so its `login.` page updates it; a sibling subdomain is not. */
function onParentHost(itemUrl: string | null, frame: URL): boolean {
	const item = webUrl(itemUrl)
	if (!item || item.href !== `${item.origin}/` || item.protocol !== frame.protocol || item.port !== frame.port) return false
	return frame.hostname.endsWith(`.${item.hostname}`)
}

/**
 * Add, update or nothing (design.md § Capture queue). What the vault already holds counts on the base domain, but an
 * update only for the one editable login whose url is a prefix of the frame's, else that is a bare parent host of it, so
 * a sibling app's password is never overwritten; a change form, which may sit on another path, first falls back to the
 * frame's host. A password that no login can take is offered as a new login rather than lost.
 */
async function decide(accountId: string, tabId: number, frameUrl: string, report: CaptureSubmitted, rule: UriMatch): Promise<Decision> {
	const key = await getKey(accountId)
	if (!key) return null
	const frame = new URL(frameUrl)
	const rows = await siteLogins(accountId, frameUrl, rule)
	const logins = await Promise.all(rows.map((row) => open(row.login, key)))
	const loginOf = (row: OpenSecretRow) => logins[rows.indexOf(row)]
	// An empty username and none are the same.
	const holders = rows.filter((row) => loginOf(row) !== undefined && (loginOf(row) ?? '') === (report.username ?? ''))
	const editable = (row: OpenSecretRow) => !row.useOnly && !row.readOnly
	const only = (candidates: OpenSecretRow[], counts: (row: OpenSecretRow) => boolean) => {
		// The nearest tier with a login decides, so a login for the frame's own host wins over one for its parent.
		const tiers = [
			(row: OpenSecretRow) => under(row.url, frame),
			...(report.changed ? [(row: OpenSecretRow) => webUrl(row.url)?.host === frame.host] : []),
			(row: OpenSecretRow) => onParentHost(row.url, frame),
		]
		const pool = (tiers.map((tier) => candidates.filter(tier)).find((rows) => rows.length > 0) ?? []).filter(counts)
		return pool.length === 1 ? pool[0] : undefined
	}
	const remembered = (candidates: OpenSecretRow[]) => (report.changed ? candidates.find((row) => row.id === rememberedItem(tabId)) : undefined)
	let target: OpenSecretRow | undefined
	if (report.changed && !report.username) {
		// Named by what this tab filled or captured, else by being the only login, editable or not, so a team login's change never lands on another.
		target = remembered(rows) ?? only(rows, () => true)
		if (!target) return null
		if ((await open(target.key, key)) === report.password) {
			rememberItem(tabId, target.id)
			return null
		}
	} else {
		target = remembered(holders) ?? only(holders, editable)
		// A change form's own login decides, so a password reused from another login is still a change.
		const compared = report.changed && target ? [target] : holders
		const passwords = await Promise.all(compared.map((row) => open(row.key, key)))
		const saved = compared.find((_, i) => passwords[i] === report.password)
		if (saved) {
			rememberItem(tabId, saved.id)
			return null
		}
		if (!target) return { mode: 'add' }
	}
	const itemUsername = loginOf(target)
	// `updateItem` refuses these, so the password is offered as a new login for the same username instead.
	if (!editable(target)) return itemUsername ? { mode: 'add', username: itemUsername } : { mode: 'add' }
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
		const decision = await decide(accountId, tabId, frameUrl, message, settings.uriMatch)
		if (!decision || !(decision.mode === 'add' ? settings.askToAdd : settings.askToUpdate)) return null
		return { accountId, domain, tabDomain, tabHost: new URL(tabUrl!).host, decision }
	})()
	if (newest.get(tabId) !== submit) return
	// A newer submit replaces the older capture, whose bar would answer for a capture that is gone.
	drop(tabId)
	// A lock while deciding must not leave a capture behind.
	if (!decided || !(await hasKey(decided.accountId)) || newest.get(tabId) !== submit) return
	hide(tabId, closing.get(tabId) ?? [])
	closing.delete(tabId)
	const entry: Capture = {
		id: newKey(), accountId: decided.accountId, frameUrl, baseDomain: decided.domain, tabBaseDomain: decided.tabDomain, tabHost: decided.tabHost,
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
	for (let attempt = 0; attempt < 2 && pending.get(tabId) === entry; attempt++) {
		const key = newKey()
		entry.keys.set(key, null)
		const held: unknown = await toTop(tabId, { kind: 'capture.show', key, capture: entry.id })
		// A new bar, or no answer, as when the page has no content script yet.
		if (held === key || typeof held !== 'string') return dropOlder(entry, key)
		entry.keys.delete(key)
		if (entry.keys.has(held)) {
			const frameId = entry.keys.get(held)!
			entry.keys.delete(held)
			entry.keys.set(held, frameId)
			return
		}
		// A bar holding no key this capture issued, as one back from the back/forward cache, is replaced.
		await toTop(tabId, { kind: 'capture.hide', key: held })
	}
}

/** Only keys older than the show's own, so a reply never drops its bar's key, nor one a show still in flight brings. */
function dropOlder(entry: Capture, key: string): void {
	const keys = [...entry.keys.keys()]
	for (const old of keys.slice(0, Math.max(0, keys.indexOf(key) - (MAX_KEYS - 1)))) entry.keys.delete(old)
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
	// A change form without a username saves the new login under the gone item's.
	entry.username ??= entry.itemUsername ?? null
	delete entry.itemId
	delete entry.itemUsername
}

/** The bar shows its confirmation, then the background closes it; a newer capture that needs a bar closes it at once. */
function finish(tabId: number, entry: Capture, itemId: string, key: string): void {
	rememberItem(tabId, itemId)
	if (pending.get(tabId) === entry) drop(tabId, false)
	closing.set(tabId, new Set([key]))
	setTimeout(() => {
		if (!closing.get(tabId)?.has(key)) return
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
