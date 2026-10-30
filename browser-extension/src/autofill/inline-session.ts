/**
 * The background half of the inline list (design.md § Inline list). A session holds no plaintext: usernames
 * and codes are computed per `inline.state` and go only to the list page; the content script gets nothing but the key.
 */
import type { Browser } from 'wxt/browser'
import { readSettings } from '@/src/accounts/settings'
import { accountStatus, getAccount, getActiveAccountId } from '@/src/accounts/store'
import { onBroadcast } from '@/src/background/broadcast'
import { popout } from '@/src/background/requests'
import { rsaDecrypt } from '@/src/crypto'
import {
	INLINE_MODES, inlineClampHeight, inlineHeight,
	type BackgroundToContent, type InlineClosed, type InlineListToBackground, type InlineMode, type InlineOpen, type InlineOpenReply, type InlinePickReply, type InlineRow,
	type InlineStateReply, type PickInput,
} from '@/src/messages'
import { createKeyedQueue } from '@/src/queue'
import { readGlobalSettings } from '@/src/settings/global'
import { getKey, sessionValues } from '@/src/vault/key-store'
import { enforce, touch } from '@/src/vault/timeout'
import { pickCode } from './code-fill'
import { fill, matchingLogins } from './fill'
import { inlineCode, matchingCodes } from './login-codes'

export interface InlineSession {
	key: string
	accountId: string
	/** The frame hosting the list, and its URL, both from `sender`. */
	frameId: number
	frameUrl: string
	/** The list page's frame, bound by the first accepted `inline.state`. */
	listFrameId: number | null
	/** `code`: the list is open on a code field and offers authenticator items. */
	mode: InlineMode
	itemIds: string[]
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

const isWeb = (url: string) => /^https?:\/\//i.test(url)

/** The rows a list in `mode` offers: matching logins, or those of them with a code. */
const matchingFor = (mode: InlineMode, accountId: string, frameUrl: string) =>
	(mode === 'code' ? matchingCodes : matchingLogins)(accountId, frameUrl)

/**
 * `inline.open` from a content script, routed by sender page. Showing the list is no interaction, so no `touch()`.
 * The height counts the matches without decrypting any; `inline.state` decides again what the list shows.
 */
export async function openSession(message: InlineOpen, sender: Browser.runtime.MessageSender): Promise<InlineOpenReply> {
	const mode: InlineMode = INLINE_MODES.includes(message.mode) ? message.mode : 'login'
	const tabId = sender.tab?.id
	const frameId = sender.frameId
	const frameUrl = sender.url
	if (tabId === undefined || frameId === undefined || !frameUrl) return null
	await drop(tabId)
	if (!isWeb(frameUrl)) return null
	const accountId = await getActiveAccountId()
	const account = accountId ? await getAccount(accountId) : undefined
	const status = account ? await accountStatus(account) : 'logged_out'
	if (!account || status === 'logged_out') return null
	if (!(await readSettings(account.id)).inlineSuggestions) return null
	const height = inlineHeight(status === 'unlocked' ? (await matchingFor(mode, account.id, frameUrl)).rows.length : 1)
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

async function decryptLogin(login: string | null, key: CryptoKey): Promise<string | null> {
	if (login === null) return null
	try {
		return await rsaDecrypt(login, key)
	} catch {
		return null
	}
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
	if (!key) return { state: 'locked', mode, host, rows: [], theme }
	const { rows: matched } = await matchingFor(mode, session.accountId, session.frameUrl)
	const now = Date.now()
	const rows: InlineRow[] = await Promise.all(matched.map(async (row) => ({
		id: row.id, name: row.name, username: await decryptLogin(row.login, key),
		...(mode === 'code' && { code: await inlineCode(row, key, now) }),
	})))
	await queued(String(tabId), async () => {
		const current = await read(tabId)
		if (current?.key === session.key) await write(tabId, { ...current, itemIds: rows.map((row) => row.id) })
	})
	return { state: 'unlocked', mode, host, rows, theme }
}

const isPickInput = (input: unknown): input is PickInput => {
	const value = input as Partial<PickInput> | null
	return (value?.kind === 'pointer' || value?.kind === 'key') && [value.at, value.x, value.y].every((n) => typeof n === 'number' && Number.isFinite(n))
}

async function pick(tabId: number, session: InlineSession, message: Extract<InlineListToBackground, { kind: 'inline.pick' }>): Promise<InlinePickReply> {
	if (typeof message.itemId !== 'string' || !isPickInput(message.input)) return null
	const refuse = async (refused: 'locked' | 'stale'): Promise<InlinePickReply> => {
		await drop(tabId, { key: session.key, refocus: true })
		return { refused }
	}
	if ((await sessionAccount(session)) !== 'unlocked') return refuse('locked')
	const { rows } = await matchingFor(session.mode, session.accountId, session.frameUrl)
	if (!session.itemIds.includes(message.itemId) || !rows.some((row) => row.id === message.itemId)) return refuse('stale')
	const verify: BackgroundToContent = { kind: 'inline.verify', key: session.key, input: message.input }
	// The list stays open to say why: assistive styling on the page (filters, zoom) refuses every pick.
	if (await toHost(tabId, session, verify) !== true) return { refused: 'not_visible' }
	await touch()
	const target = {
		itemId: message.itemId, tabId, confirmedInsecure: message.confirmedInsecure === true,
		only: { frameId: session.frameId, url: session.frameUrl, anchorKey: session.key },
	}
	const result = session.mode === 'code' ? await pickCode(target) : await fill({ ...target, ask: 'reply', code: true })
	if (result.needsInsecureConfirm) return { needsInsecureConfirm: true }
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
