/**
 * The fill orchestrator every surface goes through. Plaintext exists only inside `fill()`,
 * between the decrypt and the frames' replies (ADR-002 "per fill").
 */
import type { Browser } from 'wxt/browser'
import type { OpenSecretRow } from '@/src/api/types'
import { readSettings } from '@/src/accounts/settings'
import { accountStatus, getAccount, getActiveAccountId } from '@/src/accounts/store'
import { rsaDecrypt } from '@/src/crypto'
import { readLastUsed, recordLastUsed, type LastUsed } from '@/src/last-used'
import type { BackgroundToContent, FillExecute, FillOutcome, FillRequest, FillResult, UriMatch } from '@/src/messages'
import { getKey } from '@/src/vault/key-store'
import { readSnapshot } from '@/src/vault/store'
import { enforce, touch } from '@/src/vault/timeout'
import type { VaultSnapshot } from '@/src/vault/types'
import { webUrl } from '@/src/vault/url'
import { framesOf, type FrameRecord } from './frames'
import { candidatesFor } from './matcher'
import { rememberItem } from './tab-items'

export async function activeUnlockedId(): Promise<string | null> {
	const id = await getActiveAccountId()
	const account = id ? await getAccount(id) : undefined
	return account && await accountStatus(account) === 'unlocked' ? account.id : null
}

export async function activeTab(): Promise<Browser.tabs.Tab | undefined> {
	return (await browser.tabs.query({ active: true, lastFocusedWindow: true }))[0]
}

/** The logins matching `pageUrl`, last used first; use-only copies included, they exist to be filled (ADR-003). */
export async function matchingLogins(accountId: string, pageUrl: string | undefined): Promise<{ rows: OpenSecretRow[]; lastUsed: LastUsed }> {
	const vault = await readSnapshot(accountId)
	const lastUsed = await readLastUsed(accountId)
	if (!vault) return { rows: [], lastUsed }
	const rows = candidatesFor(pageUrl, vault, (await readSettings(accountId)).uriMatch, lastUsed)
	return { rows: rows.filter((row): row is OpenSecretRow => !row.blocked), lastUsed }
}

/** The shortcut's and page load's choice: the most recently used match, or the only one. */
export function preferred<T extends { id: string }>(rows: readonly T[], lastUsed: LastUsed): T | null {
	const [first] = rows
	return first && (rows.length === 1 || Object.hasOwn(lastUsed, first.id)) ? first : null
}

/**
 * `reply`: the popup asks in its own UI, so an insecure page answers `needsInsecureConfirm`.
 * `page`: the menu, shortcut and page load ask the top frame.
 */
export interface FillTarget {
	itemId: string
	tabId: number
	ask: 'reply' | 'page'
	confirmedInsecure?: boolean
	/**
	 * An inline pick: only the list's frame, and only the form its list is open on. `url`: the frame's, from the
	 * session, which outlives the frame registry across an MV3 worker restart.
	 */
	only?: { frameId: number; url: string; anchorKey: string }
}

const NONE: FillResult = { filled: 'none' }

const fits = (row: OpenSecretRow, types: VaultSnapshot['types'], rule: UriMatch, url: string) =>
	candidatesFor(url, { secrets: [row], types }, rule, {}).length > 0

/** The top frame as the browser reports the tab now, plus recorded subframes; only those the item matches. */
async function framesFor(tabId: number, row: OpenSecretRow, types: VaultSnapshot['types'], rule: UriMatch): Promise<FrameRecord[]> {
	const tab = await browser.tabs.get(tabId).catch(() => undefined)
	if (!tab?.url) return []
	const frames = [{ frameId: 0, url: tab.url }, ...framesOf(tabId).filter((frame) => frame.frameId !== 0)]
	return frames.filter((frame) => fits(row, types, rule, frame.url))
}

// A bare host reads as https, as everywhere else; a url that does not parse (a regular expression) never warns.
const isInsecure = (row: OpenSecretRow, frames: FrameRecord[]) =>
	webUrl(row.url)?.protocol === 'https:' && frames.some((frame) => new URL(frame.url).protocol === 'http:')

async function confirmInPage(tabId: number, itemUrl: string | null): Promise<boolean> {
	const message: BackgroundToContent = { kind: 'fill.confirmInsecure', itemHost: webUrl(itemUrl)?.host ?? '' }
	return await browser.tabs.sendMessage(tabId, message, { frameId: 0 }).catch(() => false) === true
}

const OUTCOMES: readonly unknown[] = ['both', 'username', 'password', 'none'] satisfies FillOutcome[]

function combine(replies: unknown[]): FillOutcome {
	const filled = replies.map((reply) => (reply as { filled?: unknown } | null)?.filled).filter((outcome) => OUTCOMES.includes(outcome))
	const user = filled.some((outcome) => outcome === 'both' || outcome === 'username')
	const pass = filled.some((outcome) => outcome === 'both' || outcome === 'password')
	return user && pass ? 'both' : user ? 'username' : pass ? 'password' : 'none'
}

/** The popup's `fill.request`, already routed by sender page. Counts as interaction. */
export async function handleFillRequest(message: FillRequest): Promise<FillResult> {
	try {
		if (typeof message.itemId !== 'string') return NONE
		await enforce()
		await touch()
		const tabId = typeof message.tabId === 'number' ? message.tabId : (await activeTab())?.id
		if (tabId === undefined) return NONE
		return await fill({ itemId: message.itemId, tabId, ask: 'reply', confirmedInsecure: message.confirmedInsecure === true })
	} catch (error) {
		console.error('[keepiq] fill.request', error)
		return NONE
	}
}

export async function fill(target: FillTarget): Promise<FillResult> {
	const accountId = await activeUnlockedId()
	const vault = accountId ? await readSnapshot(accountId) : undefined
	const row = vault?.secrets.find((secret) => secret.id === target.itemId)
	if (!accountId || !vault || !row || row.blocked) return NONE
	const rule = (await readSettings(accountId)).uriMatch
	const frames = target.only
		? [{ frameId: target.only.frameId, url: target.only.url }].filter((frame) => fits(row, vault.types, rule, frame.url))
		: await framesFor(target.tabId, row, vault.types, rule)
	if (!frames.length) return NONE
	if (!target.confirmedInsecure && isInsecure(row, frames)) {
		if (target.ask === 'reply') return { filled: 'none', needsInsecureConfirm: true }
		if (!(await confirmInPage(target.tabId, row.url))) return NONE
	}
	// Read after the question, so a lock while it was open still stops the fill.
	const key = await getKey(accountId)
	if (!key) return NONE
	let username: string | null
	let password: string | null
	try {
		username = row.login === null ? null : await rsaDecrypt(row.login, key)
		password = await rsaDecrypt(row.key, key)
	} catch (error) {
		console.error('[keepiq] fill decrypt failed for', row.id, error)
		return NONE
	}
	const replies = await Promise.all(frames.map((frame) => {
		const message: FillExecute = { kind: 'fill.execute', username, password, origin: new URL(frame.url).origin }
		if (target.only) message.anchorKey = target.only.anchorKey
		return browser.tabs.sendMessage(target.tabId, message, { frameId: frame.frameId }).catch(() => null)
	}))
	const filled = combine(replies)
	if (filled !== 'none') {
		rememberItem(target.tabId, row.id)
		await recordLastUsed(accountId, row.id)
	}
	return { filled }
}
