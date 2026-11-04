/**
 * The background's half of a passkey ceremony (design.md § Background flow): one per tab in `passkeyCeremony.<tabId>`,
 * the window that answers it, and every way it ends. `passkey.start` answers from the settings, the account's
 * logged-in state and the request only, so a page learns nothing of the vault or its lock.
 */
import type { Browser } from 'wxt/browser'
import { readSettings } from '@/src/accounts/settings'
import { getAccount, getActiveAccountId } from '@/src/accounts/store'
import { onBroadcast } from '@/src/background/broadcast'
import { toBase64Url } from '@/src/crypto/base64'
import { Failure } from '@/src/failure'
import type {
	BackgroundToContent, PasskeyContentToBackground, PasskeyPopupRequest, PasskeyResult, PasskeyStartReply, PasskeyView, PasskeyWindowReply, UnlockMethod, VaultWriteError,
} from '@/src/messages'
import { createKeyedQueue } from '@/src/queue'
import { getKey } from '@/src/vault/key-store'
import { sync } from '@/src/vault/sync'
import { cancelPopout, enforce, expectPopout, syncAlarm, touch } from '@/src/vault/timeout'
import { unlock } from '@/src/vault/unlock'
import { alreadySaved, readSaveRequest, savePasskey, saveView, type SaveAttempt, type SaveOutcome, type SaveRequest } from './create'
import { matchPasskeys } from './match'
import { forgetOfferedPasskeys } from './offered'
import { frameGate, readStart, type CeremonyRequest, type FrameInput } from './request'
import { signIn, type SignOutcome } from './sign'

type Step = { action: 'pick'; itemId: string; credentialId: string } | { action: 'save'; request: SaveRequest; attempt?: SaveAttempt }

/** A pick or save that failed, for Retry; ids and the typed name only, never a key or decrypted value. */
type FailedStep = Step & { error: VaultWriteError }

export interface Ceremony extends CeremonyRequest {
	id: string
	accountId: string
	/** The passkey window and its one tab, the only sender that may answer the ceremony. */
	window: { id: number; tabId: number } | null
	/** The user entered the master password or PIN within this ceremony. */
	verified: boolean
	/** The window showed "already saved", so closing it answers `InvalidStateError`. */
	alreadySaved?: true
	failed?: FailedStep
	/** A passkey picked in the inline list that waits on "Confirm it's you"; `passkey.verify` signs it. */
	pending?: { itemId: string; credentialId: string }
}

type Sender = Browser.runtime.MessageSender

const PREFIX = 'passkeyCeremony.'
export const ceremonyKey = (tabId: number) => `${PREFIX}${tabId}`

const NOT_ALLOWED: PasskeyResult = { type: 'error', name: 'NotAllowedError' }
const ENDED: PasskeyWindowReply = { ok: false, code: 'ended' }
const STALE: PasskeyWindowReply = { ok: false, code: 'stale' }

/** Cancel or closing the window; after "already saved" that is its Close. */
const cancelled = (ceremony: Ceremony): PasskeyResult => (ceremony.alreadySaved ? { type: 'error', name: 'InvalidStateError' } : NOT_ALLOWED)

/** Firefox below 115 has no `storage.session`; its MV2 background page is persistent, so memory lasts as long. */
const memory = new Map<string, Ceremony>()

const store = {
	async get(tabId: number): Promise<Ceremony | null> {
		const key = ceremonyKey(tabId)
		if (!browser.storage.session) return structuredClone(memory.get(key)) ?? null
		return ((await browser.storage.session.get(key))[key] as Ceremony | undefined) ?? null
	},
	async set(ceremony: Ceremony): Promise<void> {
		if (!browser.storage.session) memory.set(ceremonyKey(ceremony.tabId), structuredClone(ceremony))
		else await browser.storage.session.set({ [ceremonyKey(ceremony.tabId)]: ceremony })
	},
	async remove(tabId: number): Promise<void> {
		if (!browser.storage.session) memory.delete(ceremonyKey(tabId))
		else await browser.storage.session.remove(ceremonyKey(tabId))
	},
	async all(): Promise<Ceremony[]> {
		if (!browser.storage.session) return [...memory.values()].map((ceremony) => structuredClone(ceremony))
		const stored = await browser.storage.session.get(null)
		return Object.entries(stored).filter(([key]) => key.startsWith(PREFIX)).map(([, ceremony]) => ceremony as Ceremony)
	},
}

const queued = createKeyedQueue()
const inTab = <T>(tabId: number, change: () => Promise<T>) => queued(String(tabId), change)

const log = (what: string) => (error: unknown) => console.error(`[keepiq] passkey ${what}`, error)

function toFrame(ceremony: Ceremony, result: PasskeyResult): void {
	const message: BackgroundToContent = { kind: 'passkey.result', ceremonyId: ceremony.id, origin: ceremony.origin, result }
	void browser.tabs.sendMessage(ceremony.tabId, message, { frameId: ceremony.frameId }).catch(() => undefined)
}

/** Not awaited: removing fires `windows.onRemoved`, whose handler waits on this tab's queue. */
function closeWindow(ceremony: Ceremony): void {
	if (ceremony.window) void browser.windows.remove(ceremony.window.id).catch(() => {})
}

/** The longest a `fallback` waits for the window to close. */
export const CLOSE_MS = 1_000
/** The longest a `fallback` then waits for the page's window to take focus. */
export const FOCUS_MS = 500

/** Settles when `work` does or after `ms`, whichever comes first; never rejects. */
function within(work: Promise<unknown>, ms: number): Promise<void> {
	let timer: ReturnType<typeof setTimeout> | undefined
	const cap = new Promise<void>((resolve) => (timer = setTimeout(resolve, Math.max(0, ms))))
	return Promise.race([work.then(() => {}, () => {}), cap]).finally(() => clearTimeout(timer))
}

/** Closes the window and settles once `windows.onRemoved` reports it, its removal fails, or `ms` pass. */
async function closeWithin(windowId: number, ms: number): Promise<void> {
	let gone!: () => void
	const removed = new Promise<void>((resolve) => (gone = resolve))
	const onRemoved = (id: number) => {
		if (id === windowId) gone()
	}
	browser.windows.onRemoved.addListener(onRemoved)
	try {
		await within(Promise.race([removed, browser.windows.remove(windowId).then(() => removed)]), ms)
	} finally {
		browser.windows.onRemoved.removeListener(onRemoved)
	}
}

/** Activates the tab when asked and focuses its window; settles once `windows.onFocusChanged` reports that window, or `ms` pass. */
async function focusWithin(tabId: number, activate: boolean, ms: number): Promise<void> {
	let windowId: number | undefined
	// Set once this settles, so a late step can't pull the page over a newer window after `fallback` went out.
	let over = false
	let arrived!: () => void
	const focused = new Promise<void>((resolve) => (arrived = resolve))
	const onFocusChanged = (id: number) => {
		if (id === windowId) arrived()
	}
	browser.windows.onFocusChanged.addListener(onFocusChanged)
	try {
		await within((async () => {
			windowId = (await browser.tabs.get(tabId)).windowId
			if (over) return
			if (activate) await browser.tabs.update(tabId, { active: true }).catch(() => {})
			if ((await browser.windows.get(windowId).catch(() => null))?.focused || over) return
			await browser.windows.update(windowId, { focused: true })
			await focused
		})(), ms)
	} finally {
		over = true
		browser.windows.onFocusChanged.removeListener(onFocusChanged)
	}
}

/**
 * Chromium refuses WebAuthn from a page without focus, so the browser's own dialog waits until the window is gone and the tab's window has focus.
 * A newer ceremony that has or is opening a window keeps the focus.
 */
async function handBack(ceremony: Ceremony, activate: boolean): Promise<void> {
	const left = (ms: number) => Math.min(ms, (ceremony.deadline ?? Infinity) - Date.now())
	if (ceremony.window) await closeWithin(ceremony.window.id, left(CLOSE_MS))
	const newer = await store.get(ceremony.tabId)
	if (newer && (newer.window || !newer.conditional)) return
	const ms = left(FOCUS_MS)
	if (ms > 0) await focusWithin(ceremony.tabId, activate, ms)
}

/**
 * Ends the tab's ceremony while it is still `id`; `result` goes to its frame. False when it had ended already.
 * `activateTab` is for the user's own fallback only, so no other path brings a hidden tab forward.
 */
function end(tabId: number, id: string, result: PasskeyResult | null, activateTab = false): Promise<boolean> {
	return inTab(tabId, async () => {
		const ceremony = await store.get(tabId)
		if (ceremony?.id !== id) return false
		await store.remove(tabId)
		if (result?.type === 'fallback') void handBack(ceremony, activateTab).catch(log('hand back')).then(() => toFrame(ceremony, result))
		else {
			if (result) toFrame(ceremony, result)
			closeWindow(ceremony)
		}
		return true
	})
}

/** A closed tab, or a navigation away from the ceremony's origin when `url` is given: nothing is sent, the frame is gone. */
function dropTab(tabId: number, url?: string): Promise<void> {
	return inTab(tabId, async () => {
		const ceremony = await store.get(tabId)
		if (!ceremony || (url !== undefined && originOf(url) === ceremony.origin)) return
		await store.remove(tabId)
		closeWindow(ceremony)
	})
}

function originOf(url: string): string | null {
	try {
		return new URL(url).origin
	} catch {
		return null
	}
}

/** The active account while logged in, locked or not; settings are read on every request, so a change needs no reload. */
async function activeAccount(): Promise<{ id: string; gate: NonNullable<FrameInput['account']> } | null> {
	const id = await getActiveAccountId()
	const account = id ? await getAccount(id) : undefined
	if (!account || account.appPassword === null) return null
	const { askPasskeys, excludedDomains } = await readSettings(account.id)
	return { id: account.id, gate: { askPasskeys, excludedDomains } }
}

async function stillActive(ceremony: Ceremony): Promise<boolean> {
	return (await activeAccount())?.id === ceremony.accountId
}

/** A conditional request outlives the settings it started under: once they would refuse it, it offers and signs nothing. */
async function stillAsked(ceremony: Ceremony): Promise<boolean> {
	const account = await activeAccount()
	if (account?.id !== ceremony.accountId) return false
	const sender = { origin: ceremony.origin, url: ceremony.url, tab: { id: ceremony.tabId }, frameId: ceremony.frameId }
	return !('fallback' in frameGate({ sender, crossOriginAncestor: false, account: account.gate }))
}

function newId(): string {
	return toBase64Url(crypto.getRandomValues(new Uint8Array(16)))
}

/** Opens the ceremony's window, or shows it in the one it took over; runs in the tab's queue. */
async function showWindow(tabId: number, id: string): Promise<void> {
	const ceremony = await store.get(tabId)
	if (ceremony?.id !== id) return
	const url = browser.runtime.getURL(`/popup.html?passkey=${encodeURIComponent(id)}`)
	if (ceremony.window) {
		const { id: windowId, tabId: windowTab } = ceremony.window
		// The reload drops the page's `popup` port before the new one connects; that gap is not "popup closed".
		expectPopout()
		if (await browser.tabs.update(windowTab, { url }).then(() => true, () => false)) {
			void browser.windows.update(windowId, { focused: true }).catch(() => {})
			return
		}
		cancelPopout()
	}
	const created = await browser.windows.create({ type: 'popup', width: 380, height: 630, url })
	const windowId = created?.id
	if (windowId === undefined) throw new Error('No window to show the ceremony in')
	const windowTab = created!.tabs?.[0]?.id ?? (await browser.windows.get(windowId, { populate: true })).tabs?.[0]?.id
	if (windowTab === undefined) {
		// Awaited, so the fallback that follows reaches the page after this window is gone.
		await closeWithin(windowId, CLOSE_MS)
		throw new Error('No tab in the ceremony window')
	}
	await store.set({ ...ceremony, window: { id: windowId, tabId: windowTab } })
}

export async function startCeremony(message: Extract<PasskeyContentToBackground, { kind: 'passkey.start' }>, sender: Sender): Promise<PasskeyStartReply> {
	await enforce()
	const account = await activeAccount()
	const read = readStart({ request: message.request, crossOriginAncestor: message.crossOriginAncestor !== false, sender, account: account?.gate ?? null })
	if ('fallback' in read || !account) return { fallback: true }
	const ceremony: Ceremony = { ...read.request, id: newId(), accountId: account.id, window: null, verified: false }
	const { tabId } = ceremony
	await inTab(tabId, async () => {
		const previous = await store.get(tabId)
		if (previous) toFrame(previous, NOT_ALLOWED)
		// A modal ceremony takes over the previous one's window; a conditional one has nothing to show in it.
		if (previous?.window && !ceremony.conditional) ceremony.window = previous.window
		else if (previous) closeWindow(previous)
		await store.set(ceremony)
	})
	if (!ceremony.conditional) {
		// Queued behind the write, and ahead of an abort that follows the reply, so no window outlives its ceremony.
		void inTab(tabId, () => showWindow(tabId, ceremony.id)).catch(async (error: unknown) => {
			log('window')(error)
			await end(tabId, ceremony.id, { type: 'fallback' })
		})
	}
	return { ceremonyId: ceremony.id }
}

/** The page aborted or the frame went away; only the frame that started the ceremony may end it. */
export async function abortCeremony(message: Extract<PasskeyContentToBackground, { kind: 'passkey.abort' }>, sender: Sender): Promise<void> {
	await enforce()
	const tabId = sender.tab?.id
	if (tabId === undefined || typeof message.ceremonyId !== 'string') return
	const ceremony = await store.get(tabId)
	if (ceremony?.id === message.ceremonyId && ceremony.frameId === sender.frameId) await end(tabId, ceremony.id, null)
}

/** Whether the provider would handle a request from this frame; never the vault or its lock. */
export async function passkeyAvailable(message: Extract<PasskeyContentToBackground, { kind: 'passkey.available' }>, sender: Sender): Promise<boolean> {
	await enforce()
	const account = await activeAccount()
	return !('fallback' in frameGate({ sender, crossOriginAncestor: message.crossOriginAncestor !== false, account: account?.gate ?? null }))
}

/** A content kind; the reply is the one `src/messages.ts` names, or undefined for `passkey.abort`. */
export function handlePasskeyContent(message: PasskeyContentToBackground, sender: Sender): Promise<PasskeyStartReply | boolean | void> {
	switch (message.kind) {
		case 'passkey.start': return startCeremony(message, sender).catch((error: unknown) => {
			log('start')(error)
			return { fallback: true } as const
		})
		case 'passkey.available': return passkeyAvailable(message, sender).catch(() => false)
		case 'passkey.abort': return abortCeremony(message, sender).catch(log('abort'))
	}
}

const needsVerify = (ceremony: Ceremony) => ceremony.userVerification === 'required' && !ceremony.verified

/** Changes the tab's ceremony while it is still this one; null when it ended. A field changed to undefined is removed. */
function update(ceremony: Ceremony, change: Partial<Ceremony>): Promise<Ceremony | null> {
	return inTab(ceremony.tabId, async () => {
		const current = await store.get(ceremony.tabId)
		if (current?.id !== ceremony.id) return null
		const next = Object.fromEntries(Object.entries({ ...current, ...change }).filter(([, value]) => value !== undefined)) as unknown as Ceremony
		await store.set(next)
		return next
	})
}

/** The Sign in view's rows, and the held pick while it is still offered and waits on "Confirm it's you". */
async function signInRows(ceremony: Ceremony, key: CryptoKey, verify: boolean): Promise<Pick<PasskeyView, 'rows' | 'pending'>> {
	const rows = await matchPasskeys(ceremony.accountId, key, { rpId: ceremony.rpId, allowCredentials: ceremony.credentialIds })
	const { pending } = ceremony
	const held = pending && rows.find((row) => row.itemId === pending.itemId && row.credentialId === pending.credentialId)
	// A held pick no longer offered is dropped, so the window shows the list instead.
	if (pending && (!held || !verify)) await update(ceremony, { pending: undefined })
	return { rows, pending: held && verify ? held : null }
}

/** While locked nothing is decrypted, matched or counted, and every vault-derived part stays empty. */
async function view(ceremony: Ceremony): Promise<PasskeyView> {
	const key = await getKey(ceremony.accountId)
	const shown: PasskeyView = {
		kind: ceremony.kind,
		conditional: ceremony.conditional,
		rpId: ceremony.rpId,
		host: new URL(ceremony.origin).host,
		rpName: ceremony.rpName,
		userName: ceremony.user?.name ?? '',
		locked: key === null,
		verify: needsVerify(ceremony),
		rows: [],
		targets: [],
		folders: [],
		alreadySaved: false,
		failure: null,
		pending: null,
	}
	if (!key) return shown
	const failure = ceremony.failed?.error ?? null
	if (ceremony.kind === 'get') return { ...shown, failure, ...await signInRows(ceremony, key, shown.verify) }
	if (await alreadySaved(ceremony, key)) {
		await update(ceremony, { alreadySaved: true })
		return { ...shown, alreadySaved: true }
	}
	return { ...shown, failure, ...await saveView(ceremony, key) }
}

/** The ceremony a window names, only from that ceremony's own window and while it may still run. */
async function forWindow(ceremonyId: unknown, sender: Sender): Promise<Ceremony | null> {
	const windowTab = sender.tab?.id
	if (typeof ceremonyId !== 'string' || windowTab === undefined) return null
	const found = (await store.all()).find((candidate) => candidate.id === ceremonyId)
	if (!found) return null
	// Read again in the tab's queue, behind the write that records a window still opening.
	const ceremony = await inTab(found.tabId, () => store.get(found.tabId))
	if (ceremony?.id !== ceremonyId || ceremony.window?.tabId !== windowTab) return null
	const overdue = ceremony.deadline !== null && Date.now() >= ceremony.deadline
	if (overdue || !(await stillActive(ceremony))) {
		await end(ceremony.tabId, ceremony.id, NOT_ALLOWED)
		return null
	}
	return ceremony
}

const isMethod = (method: unknown): method is UnlockMethod => {
	const m = method as Partial<Record<'type' | 'masterPassword' | 'pin', unknown>> | null
	return (m?.type === 'masterPassword' && typeof m.masterPassword === 'string') || (m?.type === 'pin' && typeof m.pin === 'string')
}

/** Unlocking from the window and "Confirm it's you" both go through `unlock()`, so the PIN keeps its five tries. */
async function verify(ceremony: Ceremony, method: unknown): Promise<PasskeyWindowReply> {
	if (!isMethod(method)) return { ok: false, code: 'unknown' }
	await touch()
	const wasLocked = (await getKey(ceremony.accountId)) === null
	try {
		await unlock(ceremony.accountId, method)
	} catch (error) {
		if (!(error instanceof Failure)) throw error
		return error.attemptsLeft === undefined ? { ok: false, code: error.code } : { ok: false, code: error.code, attemptsLeft: error.attemptsLeft }
	}
	if (wasLocked) void sync(ceremony.accountId, 'full')
	await syncAlarm()
	const verified = await update(ceremony, { verified: true, pending: undefined })
	if (!verified) return ENDED
	// The held pick signs only when the user confirmed it as shown; after an unlock they pick again from the list.
	const { pending } = ceremony
	if (pending && !wasLocked && ceremony.kind === 'get') return once(verified, () => pick(verified, pending.itemId, pending.credentialId))
	return { ok: true, view: await view(verified) }
}

/** A failed write stays open for Retry: the page gets nothing, the step is kept. Any other refusal drops it, so its error does not show beside the new question. */
async function settled(ceremony: Ceremony, outcome: SaveOutcome | SignOutcome, step: Step): Promise<PasskeyWindowReply> {
	if (outcome === 'delivered') return { ok: true }
	if (outcome === 'ended') return ENDED
	if (typeof outcome === 'string') return !ceremony.failed || (await update(ceremony, { failed: undefined })) ? { ok: false, code: outcome } : ENDED
	const failed: FailedStep = step.action === 'save' && 'attempt' in outcome ? { ...step, attempt: outcome.attempt, error: outcome.error } : { ...step, error: outcome.error }
	return (await update(ceremony, { failed })) ? { ok: false, code: 'write_failed', error: outcome.error } : ENDED
}

/** The vault key once the user may act on the ceremony: unlocked, and verified where required. */
async function actingKey(ceremony: Ceremony): Promise<{ key: CryptoKey } | { refused: PasskeyWindowReply }> {
	const key = await getKey(ceremony.accountId)
	if (!key) return { refused: { ok: false, code: 'locked' } }
	if (needsVerify(ceremony)) return { refused: { ok: false, code: 'verify_required' } }
	await touch()
	return { key }
}

async function pick(ceremony: Ceremony, itemId: unknown, credentialId: unknown): Promise<PasskeyWindowReply> {
	if (ceremony.kind !== 'get' || typeof itemId !== 'string' || typeof credentialId !== 'string') return STALE
	if (ceremony.conditional && !(await stillAsked(ceremony))) return STALE
	// Any other pick replaces the held one, so a later "Confirm it's you" never signs a passkey the user moved away from.
	if (ceremony.pending && !(await update(ceremony, { pending: undefined }))) return ENDED
	const acting = await actingKey(ceremony)
	if ('refused' in acting) return acting.refused
	const { key } = acting
	const outcome = await signIn({ ceremony, key, itemId, credentialId, deliver: (result) => end(ceremony.tabId, ceremony.id, result) })
	return settled(ceremony, outcome, { action: 'pick', itemId, credentialId })
}

async function save(ceremony: Ceremony, request: SaveRequest | null, attempt?: SaveAttempt): Promise<PasskeyWindowReply> {
	if (ceremony.kind !== 'create' || !request) return STALE
	const acting = await actingKey(ceremony)
	if ('refused' in acting) return acting.refused
	const { key } = acting
	// Checked again here, so no save gets past an excluded credential the view showed.
	if (await alreadySaved(ceremony, key)) return (await update(ceremony, { alreadySaved: true })) ? { ok: false, code: 'already_saved' } : ENDED
	const outcome = await savePasskey({ ceremony, key, request, attempt, deliver: (result) => end(ceremony.tabId, ceremony.id, result) })
	return settled(ceremony, outcome, { action: 'save', request })
}

/** A fresh save to the failed save's target carries its attempt, so a try the server stored after a lost reply is found. */
function attemptFor(ceremony: Ceremony, request: SaveRequest | null): SaveAttempt | undefined {
	const { failed } = ceremony
	if (failed?.action !== 'save' || !request) return undefined
	const before = failed.request
	const same = 'itemId' in request
		? 'itemId' in before && before.itemId === request.itemId
		: 'name' in before && before.name.trim() === request.name.trim() && before.folderId === request.folderId
	return same ? failed.attempt : undefined
}

function retry(ceremony: Ceremony): Promise<PasskeyWindowReply> {
	const { failed } = ceremony
	if (!failed) return Promise.resolve(STALE)
	return failed.action === 'pick' ? pick(ceremony, failed.itemId, failed.credentialId) : save(ceremony, failed.request, failed.attempt)
}

/** A conditional request outlives the window a pick in the inline list opened: the page keeps waiting, as before the pick. */
function release(ceremony: Ceremony): Promise<boolean> {
	return inTab(ceremony.tabId, async () => {
		const current = await store.get(ceremony.tabId)
		if (current?.id !== ceremony.id) return false
		const kept: Ceremony = { ...current, window: null }
		delete kept.failed
		delete kept.pending
		await store.set(kept)
		closeWindow(current)
		return true
	})
}

/** A pick or save still running when the user clicks again is joined, so nothing is signed or saved twice. */
const running = new Map<string, Promise<PasskeyWindowReply>>()
function once(ceremony: Ceremony, act: () => Promise<PasskeyWindowReply>): Promise<PasskeyWindowReply> {
	let reply = running.get(ceremony.id)
	if (!reply) {
		reply = act().finally(() => running.delete(ceremony.id))
		running.set(ceremony.id, reply)
	}
	return reply
}

async function fallbackOrCancel(ceremony: Ceremony, kind: 'passkey.fallback' | 'passkey.cancel'): Promise<PasskeyWindowReply> {
	await touch()
	// A conditional request has no fallback (spec § Sign in with a passkey), so its window offers none and this acts as Cancel.
	const closed = ceremony.conditional
		? release(ceremony)
		: kind === 'passkey.fallback' ? end(ceremony.tabId, ceremony.id, { type: 'fallback' }, true) : end(ceremony.tabId, ceremony.id, cancelled(ceremony))
	return await closed ? { ok: true } : ENDED
}

/** A passkey window kind, for the ceremony the window may answer. */
async function answer(ceremony: Ceremony, message: PasskeyPopupRequest): Promise<PasskeyWindowReply> {
	switch (message.kind) {
		case 'passkey.state':
			return { ok: true, view: await view(ceremony) }
		case 'passkey.pick':
			return once(ceremony, () => pick(ceremony, message.itemId, message.credentialId))
		case 'passkey.save':
			return once(ceremony, () => {
				const request = readSaveRequest(message.target)
				return save(ceremony, request, attemptFor(ceremony, request))
			})
		case 'passkey.retry':
			return once(ceremony, () => retry(ceremony))
		case 'passkey.verify':
			return verify(ceremony, message.method)
		case 'passkey.fallback':
		case 'passkey.cancel':
			return fallbackOrCancel(ceremony, message.kind)
		default:
			return { ok: false, code: 'unknown' }
	}
}

/** A passkey window kind. */
export async function handlePasskeyWindow(message: PasskeyPopupRequest, sender: Sender): Promise<PasskeyWindowReply> {
	try {
		await enforce()
		const ceremony = await forWindow(message.ceremonyId, sender)
		return ceremony ? await answer(ceremony, message) : ENDED
	} catch (error) {
		log(message.kind)(error)
		return { ok: false, code: 'unknown' }
	}
}

/** A logout or an account switch ends every ceremony of an account that is no longer the active, logged-in one. */
export async function endForAccountChange(): Promise<void> {
	forgetOfferedPasskeys()
	for (const ceremony of await store.all()) {
		if (!(await stillActive(ceremony))) await end(ceremony.tabId, ceremony.id, NOT_ALLOWED)
	}
}

/**
 * A lock forgets every held pick: after unlocking, the user picks again from what the window then shows. Each ceremony is
 * read again in its tab's queue, behind a `hold` that checked the key just before the lock.
 */
async function forgetHeldPicks(): Promise<void> {
	for (const ceremony of await store.all()) await update(ceremony, { pending: undefined })
}

async function windowClosed(windowId: number): Promise<void> {
	for (const ceremony of await store.all()) {
		if (ceremony.window?.id === windowId) await (ceremony.conditional ? release(ceremony) : end(ceremony.tabId, ceremony.id, cancelled(ceremony)))
	}
}

/** A pending conditional `get` as the inline list sees it: whether it names credentials, never which. */
export interface ConditionalRequest {
	id: string
	rpId: string
	namesCredentials: boolean
}

/** The pending conditional `get` of this frame and account, for the inline list; never one of another frame or origin. */
export async function conditionalCeremony(tabId: number, frameId: number, accountId: string, frameUrl: string): Promise<ConditionalRequest | null> {
	const ceremony = await store.get(tabId)
	if (!ceremony?.conditional || ceremony.kind !== 'get' || ceremony.frameId !== frameId || ceremony.accountId !== accountId) return null
	if (originOf(frameUrl) !== ceremony.origin || !(await stillAsked(ceremony))) return null
	return { id: ceremony.id, rpId: ceremony.rpId, namesCredentials: ceremony.credentialIds.length !== 0 }
}

export type ListPick = 'signed' | 'window' | 'locked' | 'stale'

/** Keeps a list pick for "Confirm it's you", unless the vault locked since it was checked: that lock already forgot held picks. */
function hold(ceremony: Ceremony, pending: NonNullable<Ceremony['pending']>): Promise<'held' | 'locked' | 'stale'> {
	return inTab(ceremony.tabId, async () => {
		const current = await store.get(ceremony.tabId)
		if (current?.id !== ceremony.id) return 'stale'
		if (!(await getKey(ceremony.accountId))) return 'locked'
		await store.set({ ...current, pending })
		return 'held'
	})
}

/** The tab's ceremony while it is still `ceremonyId`, a conditional one, and still asked. */
async function askedConditional(tabId: number, ceremonyId: string): Promise<Ceremony | null> {
	const ceremony = await store.get(tabId)
	if (ceremony?.id !== ceremonyId || !ceremony.conditional) return null
	return (await stillAsked(ceremony)) ? ceremony : null
}

/**
 * A passkey the user picked in the inline list, signed as a pick in the window is; verification or a failed counter
 * save opens the window on that step (spec § Conditional requests). The caller checked it was offered and visible.
 */
export async function pickFromList(tabId: number, ceremonyId: string, itemId: string, credentialId: string): Promise<ListPick> {
	await enforce()
	const ceremony = await askedConditional(tabId, ceremonyId)
	if (!ceremony) return 'stale'
	const reply = await once(ceremony, () => pick(ceremony, itemId, credentialId))
	if (reply.ok) return 'signed'
	if (reply.code === 'locked') return 'locked'
	if (reply.code !== 'verify_required' && reply.code !== 'write_failed') return 'stale'
	if (reply.code === 'verify_required') {
		const held = await hold(ceremony, { itemId, credentialId })
		if (held !== 'held') return held
	}
	await inTab(tabId, () => showWindow(tabId, ceremony.id)).catch(log('window'))
	return 'window'
}

/** A lock keeps the ceremony, so the window can go on after an unlock. */
export function registerPasskeyCeremonies(): void {
	browser.tabs.onRemoved.addListener((tabId) => void dropTab(tabId).catch(log('tab closed')))
	// Chrome reports `loading` for `pushState` and late frame loads too; the relay's `pagehide` ends a ceremony whose frame unloads.
	browser.tabs.onUpdated.addListener((tabId, change) => {
		if (typeof change.url === 'string') void dropTab(tabId, change.url).catch(log('navigation'))
	})
	browser.windows.onRemoved.addListener((windowId) => void windowClosed(windowId).catch(log('window closed')))
	onBroadcast((message) => {
		if (message.kind === 'vault.locked' || message.kind === 'vault.changed') forgetOfferedPasskeys()
		if (message.kind === 'vault.locked') void forgetHeldPicks().then(endForAccountChange).catch(log('lock'))
	})
}
