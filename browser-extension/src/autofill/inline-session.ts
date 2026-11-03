/**
 * The background half of the inline list (design.md § Inline list). A session holds no plaintext: usernames, codes and
 * card or identity details are computed per `inline.state` and go only to the list page; the content script gets nothing but the key.
 */
import type { Browser } from 'wxt/browser'
import type { OpenSecretRow } from '@/src/api/types'
import { readSettings, type AccountSettings } from '@/src/accounts/settings'
import { accountStatus, getAccount, getActiveAccountId } from '@/src/accounts/store'
import { onBroadcast } from '@/src/background/broadcast'
import { popout } from '@/src/background/requests'
import { rsaDecrypt } from '@/src/crypto'
import { readLastUsed } from '@/src/last-used'
import {
	INLINE_MODES, inlineClampHeight, inlineFixedRows, inlineHeight, isFormKind,
	type BackgroundToContent, type FormKind, type FormValues, type InlineClosed, type InlineListToBackground, type InlineMode, type InlineOpen, type InlineOpenReply, type InlinePickReply,
	type InlineRow, type InlineStateReply, type PasskeyRow, type PickInput,
} from '@/src/messages'
import { conditionalCeremony, pickFromList, type Ceremony } from '@/src/passkey/ceremony'
import { offeredPasskeys } from '@/src/passkey/offered'
import { createKeyedQueue } from '@/src/queue'
import { readGlobalSettings } from '@/src/settings/global'
import { getKey, sessionValues } from '@/src/vault/key-store'
import { cardBrand, cardLast4, parsePayload, text } from '@/src/vault/payloads'
import { readSnapshot } from '@/src/vault/store'
import { enforce, touch } from '@/src/vault/timeout'
import { pickCode } from './code-fill'
import { fill, matchingLogins } from './fill'
import { isWebUrl } from './frames'
import { inlineCode, matchingCodes } from './login-codes'
import { membersFor, parseConfirmation, walletCandidates } from './wallet'

export interface InlineSession {
	key: string
	accountId: string
	/** The frame hosting the list, and its URL, both from `sender`. */
	frameId: number
	frameUrl: string
	/** The list page's frame, bound by the first accepted `inline.state`. */
	listFrameId: number | null
	/** `code`: the list is open on a code field and offers logins with a code; `card` and `identity` offer every one of that kind. */
	mode: InlineMode
	itemIds: string[]
	/** The conditional passkey request the last `inline.state` showed, and the passkeys it offered as login and credential ids. */
	passkeys?: { ceremonyId: string; offered: Array<[string, string]> }
	openedAt: number
}

export const inlineSessionKey = (tabId: number) => `inlineSession.${tabId}`

const queued = createKeyedQueue()

async function read(tabId: number): Promise<InlineSession | null> {
	const stored = await sessionValues.get(inlineSessionKey(tabId))
	return stored === undefined ? null : JSON.parse(stored) as InlineSession
}

const write = (tabId: number, session: InlineSession) => sessionValues.set({ [inlineSessionKey(tabId)]: JSON.stringify(session) })

function toHost(tabId: number, session: InlineSession, message: BackgroundToContent): Promise<unknown> {
	return browser.tabs.sendMessage(tabId, message, { frameId: session.frameId }).catch(() => undefined)
}

/** Drops the tab's session, or only the one with `key`, and closes its list unless the tab is gone. */
function drop(tabId: number, options: { key?: string; refocus?: boolean; notify?: boolean } = {}): Promise<void> {
	return queued(String(tabId), async () => {
		const session = await read(tabId)
		if (!session || (options.key !== undefined && session.key !== options.key)) return
		await sessionValues.remove([inlineSessionKey(tabId)])
		if (options.notify !== false) void toHost(tabId, session, { kind: 'inline.close', key: session.key, refocus: options.refocus === true })
	})
}

function newKey(): string {
	const bytes = crypto.getRandomValues(new Uint8Array(16))
	return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')
}

async function walletRows(kind: FormKind, accountId: string): Promise<{ rows: OpenSecretRow[] }> {
	const vault = await readSnapshot(accountId)
	return { rows: vault ? walletCandidates(kind, vault, await readLastUsed(accountId)) : [] }
}

/** The rows a list in each mode offers: matching logins, those of them with a code, or every card or identity. */
const CANDIDATES: Record<InlineMode, (accountId: string, frameUrl: string) => Promise<{ rows: OpenSecretRow[] }>> = {
	login: matchingLogins,
	code: matchingCodes,
	card: (accountId) => walletRows('card', accountId),
	identity: (accountId) => walletRows('identity', accountId),
}

const matchingFor = (mode: InlineMode, accountId: string, frameUrl: string) => CANDIDATES[mode](accountId, frameUrl)

/** Each kind's own switch, under `inlineSuggestions`. */
const KIND_SETTINGS = { card: 'inlineCards', identity: 'inlineIdentities' } as const satisfies Record<FormKind, keyof AccountSettings>

const listsIn = (settings: AccountSettings, mode: InlineMode) => settings.inlineSuggestions && (!isFormKind(mode) || settings[KIND_SETTINGS[mode]])

/** Where a list shows: its mode, the account it lists from, and the frame hosting it. */
type ListPlace = Pick<InlineSession, 'mode' | 'accountId' | 'frameId' | 'frameUrl'>

/** A login list's pending conditional passkey request in its frame (ext-passkey-provider), or null. */
const conditionalFor = (tabId: number, place: ListPlace) =>
	(place.mode === 'login' ? conditionalCeremony(tabId, place.frameId, place.accountId, place.frameUrl) : Promise.resolve(null))

async function passkeysFor(accountId: string, ceremony: Pick<Ceremony, 'rpId' | 'credentialIds'>): Promise<PasskeyRow[]> {
	const key = await getKey(accountId)
	return key ? offeredPasskeys(accountId, key, { rpId: ceremony.rpId, allowCredentials: ceremony.credentialIds }) : []
}

/** The active account and its status, while it is logged in with the list of `mode` on; null otherwise. */
async function listingAccount(mode: InlineMode): Promise<{ id: string; status: 'locked' | 'unlocked' } | null> {
	const accountId = await getActiveAccountId()
	const account = accountId ? await getAccount(accountId) : undefined
	const status = account ? await accountStatus(account) : 'logged_out'
	if (!account || status === 'logged_out') return null
	if (!listsIn(await readSettings(account.id), mode)) return null
	return { id: account.id, status }
}

/** The rows an unlocked list shows: its matching logins, and the passkeys a pending conditional request offers. */
async function rowCount(place: ListPlace, ceremony: Pick<Ceremony, 'rpId' | 'credentialIds'> | null): Promise<number> {
	const logins = (await matchingFor(place.mode, place.accountId, place.frameUrl)).rows.length
	return logins + (ceremony ? (await passkeysFor(place.accountId, ceremony)).length : 0)
}

/** Cards and identities are the same on every page, so their list reads nothing to size itself and its height tells the page nothing. */
async function openHeight(place: ListPlace, locked: boolean, ceremony: Pick<Ceremony, 'rpId' | 'credentialIds'> | null): Promise<number> {
	return inlineHeight(inlineFixedRows(place.mode, locked) ?? await rowCount(place, ceremony))
}

/**
 * `inline.open` from a content script, routed by sender page. Showing the list is no interaction, so no `touch()`.
 * The height counts the logins without decrypting any, and the passkeys of a pending conditional request, which
 * decrypts their logins' `passkeys` once per vault version (design.md § Risks); `inline.state` decides again what the list shows.
 */
export async function openSession(message: InlineOpen, sender: Browser.runtime.MessageSender): Promise<InlineOpenReply> {
	// A focus after the worker slept past the timeout must not count passkeys with the overdue key.
	await enforce()
	const mode: InlineMode = INLINE_MODES.includes(message.mode) ? message.mode : 'login'
	const tabId = sender.tab?.id
	const frameId = sender.frameId
	const frameUrl = sender.url
	if (tabId === undefined || frameId === undefined || !frameUrl) return null
	await drop(tabId)
	if (!isWebUrl(frameUrl)) return null
	const account = await listingAccount(mode)
	if (!account) return null
	const place: ListPlace = { mode, accountId: account.id, frameId, frameUrl }
	const ceremony = await conditionalFor(tabId, place)
	if (message.webauthn === true && !ceremony) return null
	const height = await openHeight(place, account.status === 'locked', ceremony)
	const session: InlineSession = { key: newKey(), accountId: account.id, frameId, frameUrl, listFrameId: null, mode, itemIds: [], openedAt: Date.now() }
	// Another frame's open may have written its session since the drop above; its list closes too.
	await queued(String(tabId), async () => {
		const previous = await read(tabId)
		await write(tabId, session)
		if (previous) void toHost(tabId, previous, { kind: 'inline.close', key: previous.key, refocus: false })
	})
	return { key: session.key, height }
}

/** `inline.closed` from the hosting frame. */
export async function closedByHost(message: InlineClosed, sender: Browser.runtime.MessageSender): Promise<void> {
	const tabId = sender.tab?.id
	if (tabId === undefined || typeof message.key !== 'string') return
	const session = await read(tabId)
	if (session?.key === message.key && session.frameId === sender.frameId) await drop(tabId, { key: message.key, notify: false })
}

/** The list page's message passes only from the session's tab, with its key, from the first list frame that showed it. */
function accept(message: { kind: string; key?: unknown }, sender: Browser.runtime.MessageSender): Promise<InlineSession | null> {
	const tabId = sender.tab?.id
	const frameId = sender.frameId
	if (tabId === undefined || frameId === undefined || typeof message.key !== 'string') return Promise.resolve(null)
	return queued(String(tabId), async () => {
		const session = await read(tabId)
		if (!session || session.key !== message.key) return null
		if (session.listFrameId === null) {
			if (message.kind !== 'inline.state') return null
			session.listFrameId = frameId
			await write(tabId, session)
		}
		return session.listFrameId === frameId ? session : null
	})
}

/** The session's account, while it is still the active one and logged in. */
async function sessionAccount(session: InlineSession) {
	await enforce()
	if ((await getActiveAccountId()) !== session.accountId) return null
	const account = await getAccount(session.accountId)
	const status = account ? await accountStatus(account) : 'logged_out'
	return status === 'logged_out' ? null : status
}

async function decrypted(ciphertext: string | null, key: CryptoKey): Promise<string | null> {
	if (ciphertext === null) return null
	try {
		return await rsaDecrypt(ciphertext, key)
	} catch {
		return null
	}
}

/** The list page words a card's brand and digits, as the background sends no text. */
function cardDetail(payload: Record<string, unknown> | null): Pick<InlineRow, 'card'> {
	const number = payload ? text(payload, 'number') : ''
	const last4 = cardLast4(number)
	return last4 ? { card: { brand: cardBrand(number), last4 } } : {}
}

function identityDetail(payload: Record<string, unknown> | null): Pick<InlineRow, 'detail'> {
	const members: FormValues = payload ? membersFor('identity', payload, ['fullName', 'email']) : {}
	return { detail: members.fullName ?? members.email ?? null }
}

const DETAILS: Record<FormKind, (payload: Record<string, unknown> | null) => Pick<InlineRow, 'card' | 'detail'>> = { card: cardDetail, identity: identityDetail }

/** A use-only copy shows no member, so its `key` is not even decrypted. */
async function walletRow(kind: FormKind, row: OpenSecretRow, key: CryptoKey): Promise<InlineRow> {
	const payload = row.useOnly ? null : parsePayload(await decrypted(row.key, key) ?? '')
	return { id: row.id, name: row.name, username: null, ...DETAILS[kind](payload) }
}

const loginRow = async (row: OpenSecretRow, key: CryptoKey): Promise<InlineRow> => ({ id: row.id, name: row.name, username: await decrypted(row.login, key) })

/** A row as each mode shows it. */
const ROWS: Record<InlineMode, (row: OpenSecretRow, key: CryptoKey, now: number) => Promise<InlineRow>> = {
	login: loginRow,
	code: async (row, key, now) => ({ ...await loginRow(row, key), code: await inlineCode(row, key, now) }),
	card: (row, key) => walletRow('card', row, key),
	identity: (row, key) => walletRow('identity', row, key),
}

async function state(tabId: number, session: InlineSession): Promise<InlineStateReply> {
	// Before any vault work, so the host's CSP fallback does not wait on decryption.
	void toHost(tabId, session, { kind: 'inline.ready', key: session.key })
	const status = await sessionAccount(session)
	if (!status) {
		await drop(tabId, { key: session.key })
		return null
	}
	const host = new URL(session.frameUrl).host
	const { theme } = await readGlobalSettings()
	const { mode } = session
	const key = status === 'unlocked' ? await getKey(session.accountId) : null
	// Locked, nothing says whether a passkey request is pending or what it would offer.
	if (!key) return { state: 'locked', mode, host, rows: [], theme }
	const { rows: matched } = await matchingFor(mode, session.accountId, session.frameUrl)
	const now = Date.now()
	const rows = await Promise.all(matched.map((row) => ROWS[mode](row, key, now)))
	const ceremony = await conditionalFor(tabId, session)
	const passkeys = ceremony ? await passkeysFor(session.accountId, ceremony) : null
	await queued(String(tabId), async () => {
		const current = await read(tabId)
		if (current?.key !== session.key) return
		const next: InlineSession = { ...current, itemIds: rows.map((row) => row.id) }
		if (ceremony && passkeys) next.passkeys = { ceremonyId: ceremony.id, offered: passkeys.map((row) => [row.itemId, row.credentialId]) }
		else delete next.passkeys
		await write(tabId, next)
	})
	return { state: 'unlocked', mode, host, rows, ...(passkeys && { passkeys }), theme }
}

const isPickInput = (input: unknown): input is PickInput => {
	const value = input as Partial<PickInput> | null
	return (value?.kind === 'pointer' || value?.kind === 'key') && [value.at, value.x, value.y].every((n) => typeof n === 'number' && Number.isFinite(n))
}

type PickMessage = Extract<InlineListToBackground, { kind: 'inline.pick' }>

/** Closes the list on a pick that is no longer possible, with focus back on its field. */
async function refuse(tabId: number, session: InlineSession, refused: 'locked' | 'stale'): Promise<InlinePickReply> {
	await drop(tabId, { key: session.key, refocus: true })
	return { refused }
}

/** A passkey row passes only as offered by the last `inline.state`, for the conditional request still pending. */
async function pickPasskey(tabId: number, session: InlineSession, row: Pick<PasskeyRow, 'itemId' | 'credentialId'>, input: PickInput): Promise<InlinePickReply> {
	const offered = session.passkeys
	if (!offered?.offered.some(([id, credential]) => id === row.itemId && credential === row.credentialId)) return refuse(tabId, session, 'stale')
	const ceremony = await conditionalCeremony(tabId, session.frameId, session.accountId, session.frameUrl)
	if (ceremony?.id !== offered.ceremonyId) return refuse(tabId, session, 'stale')
	if (await toHost(tabId, session, { kind: 'inline.verify', key: session.key, input }) !== true) return { refused: 'not_visible' }
	await touch()
	const outcome = await pickFromList(tabId, ceremony.id, row.itemId, row.credentialId)
	if (outcome === 'locked' || outcome === 'stale') return refuse(tabId, session, outcome)
	await drop(tabId, { key: session.key, refocus: outcome === 'signed' })
	return { passkey: outcome }
}

async function pick(tabId: number, session: InlineSession, message: PickMessage): Promise<InlinePickReply> {
	if (typeof message.itemId !== 'string' || !isPickInput(message.input)) return null
	const { credentialId } = message
	if (credentialId !== undefined && typeof credentialId !== 'string') return null
	if ((await sessionAccount(session)) !== 'unlocked') return refuse(tabId, session, 'locked')
	if (credentialId !== undefined) return pickPasskey(tabId, session, { itemId: message.itemId, credentialId }, message.input)
	return pickItem(tabId, session, message)
}

/** A login, a login's code, or a card or identity, which fills the group the list is open on and other frames by the frame plan. */
async function pickItem(tabId: number, session: InlineSession, message: PickMessage): Promise<InlinePickReply> {
	const { rows } = await matchingFor(session.mode, session.accountId, session.frameUrl)
	if (!session.itemIds.includes(message.itemId) || !rows.some((row) => row.id === message.itemId)) return refuse(tabId, session, 'stale')
	const verify: BackgroundToContent = { kind: 'inline.verify', key: session.key, input: message.input }
	// The list stays open to say why: assistive styling on the page (filters, zoom) refuses every pick.
	if (await toHost(tabId, session, verify) !== true) return { refused: 'not_visible' }
	await touch()
	const target = {
		itemId: message.itemId, tabId, confirmedInsecure: message.confirmedInsecure === true,
		only: { frameId: session.frameId, url: session.frameUrl, anchorKey: session.key },
	}
	const confirmed = parseConfirmation(message.confirmed)
	const result = session.mode === 'code' ? await pickCode(target) : await fill({ ...target, confirmed, ask: 'reply', code: true })
	if (result.needsInsecureConfirm) return { needsInsecureConfirm: true }
	if (result.needsFormConfirm) return { needsFormConfirm: result.needsFormConfirm }
	await drop(tabId, { key: session.key, refocus: true })
	return { filled: result.filled, code: result.code }
}

/** A list page message, routed by sender page. Only `inline.state` and `inline.pick` reply. */
export async function handleInlineList(message: InlineListToBackground, sender: Browser.runtime.MessageSender): Promise<InlineStateReply | InlinePickReply | undefined> {
	const session = await accept(message, sender)
	const tabId = sender.tab?.id
	if (!session || tabId === undefined) return message.kind === 'inline.state' || message.kind === 'inline.pick' ? null : undefined
	switch (message.kind) {
		case 'inline.state': return state(tabId, session)
		case 'inline.pick': return pick(tabId, session, message)
		case 'inline.refocus':
			void toHost(tabId, session, { kind: 'inline.refocus', key: session.key })
			return undefined
		case 'inline.close':
			await drop(tabId, { key: session.key, refocus: message.refocus === true })
			return undefined
		case 'inline.unlock':
			// The list page tried `action.openPopup()` from its click already; this is the fallback.
			await drop(tabId, { key: session.key })
			await popout(tabId).catch((error: unknown) => console.error('[keepiq] inline unlock', error))
			return undefined
		case 'inline.resize': {
			const height = inlineClampHeight(message.height)
			if (height !== null) void toHost(tabId, session, { kind: 'inline.resize', key: session.key, height })
			return undefined
		}
	}
}

/** Lock, logout and account switch close every list. */
export async function closeAllSessions(): Promise<void> {
	try {
		for (const tab of await browser.tabs.query({})) {
			if (tab.id !== undefined) await drop(tab.id)
		}
	} catch (error) {
		console.error('[keepiq] closing inline lists', error)
	}
}

export function registerInlineSessions(): void {
	browser.tabs.onRemoved.addListener((tabId) => void drop(tabId, { notify: false }))
	browser.tabs.onUpdated.addListener((tabId, change) => {
		if (change.status === 'loading') void drop(tabId)
	})
	onBroadcast((message) => {
		if (message.kind === 'vault.locked') void closeAllSessions()
	})
}
