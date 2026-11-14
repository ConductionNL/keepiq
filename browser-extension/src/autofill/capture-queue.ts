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
	BackgroundToContent, CaptureRefused, CaptureStateReply, CaptureSubmitted, NotificationReplies, NotificationToBackground, PickInput, UriMatch, VaultWriteError, WriteResult,
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
import { isWebUrl } from './frames'
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
async function siteLogins(frameUrl: string, scope: MatchScope): Promise<OpenSecretRow[]> {
	const { accountId, rule } = scope
	const vault = await readSnapshot(accountId)
	if (!vault) return []
	const found = new Set([...candidatesFor(frameUrl, vault, 'baseDomain', {}), ...candidatesFor(frameUrl, vault, rule, {})])
	return [...found].filter((row): row is OpenSecretRow => !row.blocked)
}

/** A path, query or fragment starts at `char`, or the url ends there (`char` is empty). */
const atBoundary = (char: string): boolean => char === '' || '/?#'.includes(char)

/** Whether the login's normalised url is a prefix of the frame's that ends at a path boundary, so `/jira` is not under `/jira-test`. */
function under(itemUrl: string | null, frame: URL): boolean {
	const prefix = webUrl(itemUrl)?.href
	if (!prefix || !frame.href.startsWith(prefix)) return false
	return atBoundary(prefix.slice(-1)) || atBoundary(frame.href.charAt(prefix.length))
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
async function decide(submit: Submit, scope: MatchScope): Promise<Decision> {
	const { tabId, frameUrl, message: report } = submit
	const key = await getKey(scope.accountId)
	if (!key) return null
	const frame = new URL(frameUrl)
	const rows = await siteLogins(frameUrl, scope)
	const logins = await Promise.all(rows.map((row) => open(row.login, key)))
	const loginOf = (row: OpenSecretRow) => logins[rows.indexOf(row)]
	// An empty username and none are the same.
	const holders = rows.filter((row) => loginOf(row) !== undefined && (loginOf(row) ?? '') === (report.username ?? ''))
	const editable = (row: OpenSecretRow) => !row.useOnly && !row.readOnly
	const only = (candidates: OpenSecretRow[], counts: (row: OpenSecretRow) => boolean) => {
		// The nearest tier with a login decides, so a login for the frame's own host wins over one for its parent.
		const onHost = (row: OpenSecretRow) => webUrl(row.url)?.host === frame.host
		const tiers = [
			(row: OpenSecretRow) => under(row.url, frame),
			...(report.changed ? [onHost] : []),
			// Any login on the frame's own host, at whatever path, may be the one meant, so the parent's is never guessed over it.
			...(candidates.some(onHost) ? [] : [(row: OpenSecretRow) => onParentHost(row.url, frame)]),
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

/** The vault a submit is matched against, and the user's match rule. */
interface MatchScope {
	accountId: string
	rule: UriMatch
}

/** One accepted `capture.submitted`, numbered so a slower decision on an older one never replaces a newer one. */
interface Submit {
	message: CaptureSubmitted
	sender: Browser.runtime.MessageSender
	tabId: number
	frameUrl: string
	order: number
}

/** What a submit was decided to be, with the domains the capture is matched and shown under. */
interface Decided {
	accountId: string
	domain: string
	tabDomain: string
	tabHost: string
	decision: NonNullable<Decision>
}

type CaptureSettings = Awaited<ReturnType<typeof readSettings>>

/** The submitting tab and web frame, from `sender`; nothing for a frame without a web URL. */
function submittingFrame(sender: Browser.runtime.MessageSender): { tabId: number; frameUrl: string } | null {
	const tabId = sender.tab?.id
	const frameUrl = sender.url
	if (tabId === undefined || sender.frameId === undefined || !frameUrl || !isWebUrl(frameUrl)) return null
	return { tabId, frameUrl }
}

function isWellFormed({ username, password, changed }: CaptureSubmitted): boolean {
	if (typeof password !== 'string' || password === '') return false
	return (username === null || typeof username === 'string') && typeof changed === 'boolean'
}

/** Numbers the submit as its tab's newest; nothing for a malformed report or a frame it cannot come from. */
function acceptSubmit(message: CaptureSubmitted, sender: Browser.runtime.MessageSender): Submit | null {
	const frame = submittingFrame(sender)
	if (!frame || !isWellFormed(message)) return null
	const order = ++submits
	newest.set(frame.tabId, order)
	return { message, sender, ...frame, order }
}

const isNewest = (submit: Submit): boolean => newest.get(submit.tabId) === submit.order

/** The frame's and the tab's base domains, and the tab's url; nothing when either has no base domain. */
async function siteOf(submit: Submit): Promise<{ domain: string; tabDomain: string; tabUrl: string } | null> {
	const domain = baseDomain(submit.frameUrl)
	const tabUrl = await tabUrlOf(submit.sender, submit.tabId)
	const tabDomain = tabUrl ? baseDomain(tabUrl) : null
	if (!domain || !tabDomain) return null
	return { domain, tabDomain, tabUrl: tabUrl! }
}

const ignoresSite = (settings: CaptureSettings, domain: string): boolean =>
	settings.excludedDomains.includes(domain) || (!settings.askToAdd && !settings.askToUpdate)

const asksFor = (settings: CaptureSettings, decision: Decision): decision is NonNullable<Decision> =>
	decision !== null && (decision.mode === 'add' ? settings.askToAdd : settings.askToUpdate)

async function decideCapture(submit: Submit): Promise<Decided | null> {
	await enforce()
	const accountId = await activeUnlockedId()
	if (!accountId) return null
	const site = await siteOf(submit)
	if (!site) return null
	const settings = await readSettings(accountId)
	if (ignoresSite(settings, site.domain)) return null
	const decision = await decide(submit, { accountId, rule: settings.uriMatch })
	if (!asksFor(settings, decision)) return null
	return { accountId, domain: site.domain, tabDomain: site.tabDomain, tabHost: new URL(site.tabUrl).host, decision }
}

/** A lock while deciding must not leave a capture behind. */
async function stillWanted(submit: Submit, decided: Decided): Promise<boolean> {
	return (await hasKey(decided.accountId)) && isNewest(submit)
}

function captureOf(submit: Submit, decided: Decided): Capture {
	const { tabId, frameUrl, message } = submit
	const entry: Capture = {
		id: newKey(), accountId: decided.accountId, frameUrl, baseDomain: decided.domain, tabBaseDomain: decided.tabDomain, tabHost: decided.tabHost,
		username: message.username || null, password: message.password, ...decided.decision, keys: new Map(), navigated: false, expiresAt: Date.now() + CAPTURE_LIFESPAN_MS,
		timer: setTimeout(() => {
			if (pending.get(tabId) === entry) drop(tabId)
		}, CAPTURE_LIFESPAN_MS),
	}
	return entry
}

function enqueue(submit: Submit, decided: Decided): void {
	const { tabId } = submit
	hide(tabId, closing.get(tabId) ?? [])
	closing.delete(tabId)
	const entry = captureOf(submit, decided)
	pending.set(tabId, entry)
	// A page that navigates after the submit is not shown the bar, which would only flash before it unloads.
	setTimeout(() => {
		if (pending.get(tabId) === entry && !entry.navigated) void showPending(tabId)
	}, SHOW_DELAY_MS)
}

/** `capture.submitted` from a content script, already routed by sender page. URL, tab and frame come from `sender`. */
export async function captureSubmitted(message: CaptureSubmitted, sender: Browser.runtime.MessageSender): Promise<void> {
	const submit = acceptSubmit(message, sender)
	if (!submit) return
	const decided = await decideCapture(submit)
	if (!isNewest(submit)) return
	// A newer submit replaces the older capture, whose bar would answer for a capture that is gone.
	drop(submit.tabId)
	if (decided && (await stillWanted(submit, decided))) enqueue(submit, decided)
}

/** Shows the bar in the top frame once the tab has loaded, while it stays on the base domain it had at the submit. */
export async function showPending(tabId: number): Promise<void> {
	const entry = live(tabId)
	if (!entry) return
	const url = await loadedUrl(tabId, entry)
	if (url === undefined) return
	if (baseDomain(url) !== entry.tabBaseDomain) return drop(tabId)
	await present(tabId, entry)
}

/** The tab's url once it has loaded, while the capture is still its pending one. */
async function loadedUrl(tabId: number, entry: Capture): Promise<string | undefined> {
	const tab = await browser.tabs.get(tabId).catch(() => undefined)
	if (pending.get(tabId) !== entry || tab?.status !== 'complete' || !tab.url) return undefined
	return tab.url
}

async function present(tabId: number, entry: Capture): Promise<void> {
	for (let attempt = 0; attempt < 2 && pending.get(tabId) === entry; attempt++) {
		if (await offerBar(tabId, entry)) return
	}
}

/** One show under a new key; true once a bar holds a key of this capture or the page did not answer. */
async function offerBar(tabId: number, entry: Capture): Promise<boolean> {
	const key = newKey()
	entry.keys.set(key, null)
	const held: unknown = await toTop(tabId, { kind: 'capture.show', key, capture: entry.id })
	// A new bar, or no answer, as when the page has no content script yet.
	if (held === key || typeof held !== 'string') {
		dropOlder(entry, key)
		return true
	}
	entry.keys.delete(key)
	if (entry.keys.has(held)) {
		keepAsNewest(entry, held)
		return true
	}
	// A bar holding no key this capture issued, as one back from the back/forward cache, is replaced.
	await toTop(tabId, { kind: 'capture.hide', key: held })
	return false
}

function keepAsNewest(entry: Capture, key: string): void {
	const frameId = entry.keys.get(key)!
	entry.keys.delete(key)
	entry.keys.set(key, frameId)
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

type Vault = NonNullable<Awaited<ReturnType<typeof readSnapshot>>>

/** The capture's vault, while its account is still the active, unlocked one. */
async function unlockedVault(entry: Capture): Promise<Vault | null> {
	const vault = await readSnapshot(entry.accountId)
	if (!vault || (await activeUnlockedId()) !== entry.accountId) return null
	return vault
}

/** An update's item name; a sync dropped the item since the submit: as when Update finds it gone, the capture becomes an add. */
function itemNameOf(entry: Capture, vault: Vault): string | undefined {
	if (entry.mode !== 'update') return undefined
	const itemName = vault.secrets.find((row) => row.id === entry.itemId)?.name
	if (itemName === undefined) toAdd(entry)
	return itemName
}

async function stateReply(entry: Capture, vault: Vault): Promise<CaptureStateReply> {
	const folders = flattenFolders(vault.folders).map((folder) => ({ id: folder.id, name: folderPath(vault.folders, folder.id) ?? folder.name }))
	const itemName = itemNameOf(entry, vault)
	const host = new URL(entry.frameUrl).host
	const reply: CaptureStateReply = { mode: entry.mode, host, folders, theme: (await readGlobalSettings()).theme }
	if (entry.tabHost !== host) reply.tabHost = entry.tabHost
	if (itemName !== undefined) {
		reply.itemName = itemName
		if (entry.itemUsername) reply.username = entry.itemUsername
	}
	return reply
}

async function state(entry: Capture): Promise<CaptureStateReply | null> {
	const vault = await unlockedVault(entry)
	if (!vault) return null
	const reply = await stateReply(entry, vault)
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

/** One bar message's capture, its tab and the key the bar holds. */
interface AnswerRun {
	tabId: number
	entry: Capture
	key: string
}

/** The bar shows its confirmation, then the background closes it; a newer capture that needs a bar closes it at once. */
function finish(bar: AnswerRun, itemId: string): void {
	const { tabId, entry, key } = bar
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
async function visibleAt(bar: AnswerRun, input: unknown): Promise<boolean> {
	if (!isPickInput(input)) return false
	return await toTop(bar.tabId, { kind: 'capture.verify', key: bar.key, input }) === true
}

/** A decision is a user's action, so it counts as interaction, after the timeout had its say. */
async function interact(tabId: number, entry: Capture): Promise<boolean> {
	await enforce()
	if (live(tabId) !== entry || !(await hasKey(entry.accountId))) return false
	await touch()
	return true
}

const isFolderChoice = (folderId: unknown): folderId is string | null => folderId === null || typeof folderId === 'string'

async function save(bar: AnswerRun, folderId: unknown): Promise<WriteResult<null>> {
	const { tabId, entry } = bar
	if (entry.mode !== 'add' || !isFolderChoice(folderId)) return refused('invalid')
	if (!(await interact(tabId, entry))) return refused('locked')
	return createLogin(bar, folderId)
}

async function createLogin(bar: AnswerRun, folderId: string | null): Promise<WriteResult<null>> {
	const { entry } = bar
	const typeId = (await readSnapshot(entry.accountId))?.types.find((type) => type.name === 'login')?.id
	if (!typeId) return refused('unknown')
	const page = new URL(entry.frameUrl)
	const result = await createItem({
		typeId, name: page.host, url: page.origin, folderId, key: entry.password, login: entry.username, additionalFields: {},
	}, entry.accountId)
	if (!result.ok) return result
	finish(bar, result.value.id)
	return { ok: true, value: null }
}

/** Only `key`, through `updateItem` and so share-save, on a fresh version; with no edit form behind it, partner shares fail as `unverified`. */
async function update(bar: AnswerRun): Promise<WriteResult<null>> {
	const { tabId, entry } = bar
	const itemId = entry.itemId
	if (entry.mode !== 'update' || itemId === undefined) return refused('invalid')
	if (!(await interact(tabId, entry))) return refused('locked')
	const result = await writePassword(entry, itemId)
	if (!result.ok) return updateFailed(bar, result.error)
	finish(bar, itemId)
	return { ok: true, value: null }
}

async function writePassword(entry: Capture, itemId: string): Promise<WriteResult<unknown>> {
	const fetched = await run(({ client }) => client.keepiq<OpenSecretRow | null>('GET', `/api/v1/secrets/${encodeURIComponent(itemId)}`), 'reply', entry.accountId)
	if (fetched.ok && typeof fetched.value?.updatedAt !== 'string') return refused('unknown')
	return fetched.ok ? await updateItem(itemId, { key: entry.password }, fetched.value!.updatedAt, entry.accountId) : fetched
}

/** Deleted meanwhile: the bar offers Save, and this capture becomes an add. */
function updateFailed(bar: AnswerRun, error: VaultWriteError): WriteResult<null> {
	if (error.code === 'not_found' && pending.get(bar.tabId) === bar.entry) toAdd(bar.entry)
	return { ok: false, error }
}

const writing = (entry: Capture, write: () => Promise<WriteResult<null> | CaptureRefused>) => {
	entry.writing ??= write().finally(() => delete entry.writing)
	return entry.writing
}

/** A bar message's key and kind, and the frame it came from. */
interface BarClaim {
	key: unknown
	kind: string
	frameId: number | undefined
}

/**
 * Only the bar frame that first presented a key this tab's capture issued; the first `capture.state` binds it.
 * A copy of the bar the page frames itself never gets a key.
 */
function bound(entry: Capture, claim: BarClaim): boolean {
	if (!issued(entry, claim)) return false
	const { key, frameId } = claim
	if (entry.keys.get(key) === null) {
		if (claim.kind !== 'capture.state') return false
		entry.keys.set(key, frameId)
	}
	return entry.keys.get(key) === frameId
}

const issued = (entry: Capture, claim: BarClaim): claim is BarClaim & { key: string; frameId: number } =>
	typeof claim.key === 'string' && claim.frameId !== undefined && entry.keys.has(claim.key)

/** A bar page message, routed by sender page; the capture is the sender tab's. */
export async function handleNotification<K extends NotificationToBackground['kind']>(
	message: Extract<NotificationToBackground, { kind: K }>, sender: Browser.runtime.MessageSender,
): Promise<NotificationReplies[K]> {
	const reply = await handle(message as NotificationToBackground, sender)
	return reply as NotificationReplies[K]
}

type Answers = { [K in NotificationToBackground['kind']]: (bar: AnswerRun, m: Extract<NotificationToBackground, { kind: K }>) => Promise<NotificationReplies[K]> | NotificationReplies[K] }

async function dismiss(bar: AnswerRun, m: Extract<NotificationToBackground, { kind: 'capture.dismiss' }>): Promise<CaptureRefused | null> {
	if (m.never === true && !(await visibleAt(bar, m.input))) return NOT_VISIBLE
	drop(bar.tabId)
	if (m.never === true) await addExcludedDomain(bar.entry.accountId, bar.entry.baseDomain)
	return null
}

const ANSWERS: Answers = {
	'capture.state': (bar) => state(bar.entry),
	'capture.save': (bar, m) => writing(bar.entry, async () => (await visibleAt(bar, m.input) ? save(bar, m.folderId) : NOT_VISIBLE)),
	'capture.update': (bar, m) => writing(bar.entry, async () => (await visibleAt(bar, m.input) ? update(bar) : NOT_VISIBLE)),
	'capture.dismiss': dismiss,
	'capture.expand': (bar) => {
		void toTop(bar.tabId, { kind: 'capture.expand', key: bar.key })
		return null
	},
}

/** A bar whose capture was saved or updated still closes at once on Close. */
function closeSaved(tabId: number, m: NotificationToBackground): null {
	if (m.kind === 'capture.dismiss' && typeof m.key === 'string' && closing.get(tabId)?.delete(m.key)) hide(tabId, [m.key])
	return null
}

async function handle(m: NotificationToBackground, sender: Browser.runtime.MessageSender): Promise<NotificationReplies[NotificationToBackground['kind']]> {
	const tabId = sender.tab?.id
	if (tabId === undefined) return null
	const entry = live(tabId)
	if (!entry) return closeSaved(tabId, m)
	if (!bound(entry, { key: m.key, kind: m.kind, frameId: sender.frameId })) return null
	const answer = ANSWERS[m.kind] as (bar: AnswerRun, m: NotificationToBackground) => ReturnType<Answers[typeof m.kind]>
	return answer({ tabId, entry, key: m.key }, m)
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

/** The tab's url after an update: the new one, or the tab's own when only its status changed. */
const changedUrl = (change: { url?: string; status?: string }, tab: { url?: string } | undefined): string | undefined =>
	change.url ?? (change.status !== undefined ? tab?.url : undefined)

const leftSite = (entry: Capture, url: string | undefined): boolean => url !== undefined && baseDomain(url) !== entry.tabBaseDomain

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
		if (leftSite(entry, changedUrl(change, tab))) return drop(tabId)
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
