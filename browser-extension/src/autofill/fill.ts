/**
 * The fill orchestrator every surface goes through. Plaintext exists only inside `fill()`,
 * between the decrypt and the frames' replies (ADR-002 "per fill").
 */
import type { Browser } from 'wxt/browser'
import type { OpenSecretRow } from '@/src/api/types'
import { readSettings } from '@/src/accounts/settings'
import { accountStatus, getAccount, getActiveAccountId } from '@/src/accounts/store'
import { writeFromBackground } from '@/src/clipboard'
import { rsaDecrypt } from '@/src/crypto'
import { readLastUsed, recordLastUsed, type LastUsed } from '@/src/last-used'
import {
	isFormRole, type BackgroundToContent, type FillConfirmForm, type FillExecute, type FillExecuteForm, type FillOutcome, type FillProbe,
	type FillRequest, type FillResult, type FormConfirmation, type FormKind, type FormQuestion, type FormRole, type FormValues, type UriMatch,
} from '@/src/messages'
import { getKey } from '@/src/vault/key-store'
import { parsePayload } from '@/src/vault/payloads'
import { readSnapshot } from '@/src/vault/store'
import { enforce, touch } from '@/src/vault/timeout'
import type { VaultSnapshot } from '@/src/vault/types'
import { webUrl } from '@/src/vault/url'
import { framesOf, isWebUrl, type FrameRecord } from './frames'
import { candidatesFor } from './matcher'
import { rememberItem } from './tab-items'
import { currentCode, seedOf } from './login-codes'
import { answered } from './form-answers'
import { membersFor, parseConfirmation, planFrames, walletKind, type FramePlan, type ProbedFrame } from './wallet'

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
	/** Fill and copy the login's code; `false` on page load. */
	code: boolean
	confirmedInsecure?: boolean
	/** A card's or identity's answers to an earlier `needsFormConfirm`. */
	confirmed?: FormConfirmation
	/**
	 * An inline pick: only the list's frame, and only the form its list is open on; for a card or identity the other frames still follow the frame plan.
	 * `url`: the frame's, from the session, which outlives the frame registry across an MV3 worker restart.
	 */
	only?: { frameId: number; url: string; anchorKey: string }
}

export const NONE: FillResult = { filled: 'none', code: false }
/** Unlike `NONE`, which also stands for a declined question, a lock or a failed decrypt. */
export const NO_FIELDS: FillResult = { ...NONE, noFields: true }

export const fits = (row: OpenSecretRow, types: VaultSnapshot['types'], rule: UriMatch, url: string) =>
	candidatesFor(url, { secrets: [row], types }, rule, {}).length > 0

/** The top frame as the browser reports the tab now, then the recorded subframes; empty when the tab is gone. */
async function tabFrames(tabId: number): Promise<FrameRecord[]> {
	const tab = await browser.tabs.get(tabId).catch(() => undefined)
	if (!tab?.url) return []
	return [{ frameId: 0, url: tab.url }, ...framesOf(tabId).filter((frame) => frame.frameId !== 0)]
}

async function framesFor(tabId: number, row: OpenSecretRow, types: VaultSnapshot['types'], rule: UriMatch): Promise<FrameRecord[]> {
	return (await tabFrames(tabId)).filter((frame) => fits(row, types, rule, frame.url))
}

// A bare host reads as https, as everywhere else; a url that does not parse (a regular expression) never warns.
export const isInsecure = (row: OpenSecretRow, frames: FrameRecord[]) =>
	webUrl(row.url)?.protocol === 'https:' && frames.some((frame) => new URL(frame.url).protocol === 'http:')

async function confirmInPage(tabId: number, itemUrl: string | null): Promise<boolean> {
	const message: BackgroundToContent = { kind: 'fill.confirmInsecure', itemHost: webUrl(itemUrl)?.host ?? '' }
	return await browser.tabs.sendMessage(tabId, message, { frameId: 0 }).catch(() => false) === true
}

const OUTCOMES: readonly unknown[] = ['both', 'username', 'password', 'none'] satisfies FillOutcome[]

export function combine(replies: unknown[]): { filled: FillOutcome; code: boolean } {
	const filled = replies.map((reply) => (reply as { filled?: unknown } | null)?.filled).filter((outcome) => OUTCOMES.includes(outcome))
	const user = filled.some((outcome) => outcome === 'both' || outcome === 'username')
	const pass = filled.some((outcome) => outcome === 'both' || outcome === 'password')
	const code = replies.some((reply) => (reply as { code?: unknown } | null)?.code === true)
	return { filled: user && pass ? 'both' : user ? 'username' : pass ? 'password' : 'none', code }
}

/** The frame's script found no field; a frame that did not answer is not counted as one without a form. */
const answeredNone = (reply: unknown) => {
	const r = reply as { filled?: unknown; code?: unknown } | null
	return r?.filled === 'none' && r.code !== true
}

/** A fill that filled only a one-time code still filled something. */
export const filledSomething = (result: { filled: FillOutcome; code?: boolean }) => result.filled !== 'none' || result.code === true

/** The popup's `fill.request`, already routed by sender page. Counts as interaction. */
export async function handleFillRequest(message: FillRequest): Promise<FillResult> {
	try {
		if (typeof message.itemId !== 'string') return NONE
		await enforce()
		await touch()
		const tabId = typeof message.tabId === 'number' ? message.tabId : (await activeTab())?.id
		if (tabId === undefined) return NONE
		const confirmed = parseConfirmation(message.confirmed)
		return await fill({ itemId: message.itemId, tabId, ask: 'reply', code: true, confirmedInsecure: message.confirmedInsecure === true, confirmed })
	} catch (error) {
		console.error('[keepiq] fill.request', error)
		return NONE
	}
}

interface LoginFill {
	target: FillTarget
	row: OpenSecretRow
	types: VaultSnapshot['types']
	rule: UriMatch
}

/** The frames a login fills once the insecure question is answered; otherwise the result to return. */
async function loginFrames({ target, row, types, rule }: LoginFill): Promise<FrameRecord[] | FillResult> {
	const frames = target.only
		? [{ frameId: target.only.frameId, url: target.only.url }].filter((frame) => fits(row, types, rule, frame.url))
		: await framesFor(target.tabId, row, types, rule)
	if (!frames.length) return NO_FIELDS
	if (target.confirmedInsecure || !isInsecure(row, frames)) return frames
	if (target.ask === 'reply') return { ...NONE, needsInsecureConfirm: true }
	return await confirmInPage(target.tabId, row.url) ? frames : NONE
}

async function credentialOf(row: OpenSecretRow, key: CryptoKey): Promise<{ username: string | null; password: string } | null> {
	try {
		return { username: row.login === null ? null : await rsaDecrypt(row.login, key), password: await rsaDecrypt(row.key, key) }
	} catch (error) {
		console.error('[keepiq] fill decrypt failed for', row.id, error)
		return null
	}
}

export async function fill(target: FillTarget): Promise<FillResult> {
	const accountId = await activeUnlockedId()
	const vault = accountId ? await readSnapshot(accountId) : undefined
	const row = vault?.secrets.find((secret) => secret.id === target.itemId)
	if (!accountId || !vault || !row || row.blocked) return NONE
	const kind = walletKind(row, vault.types)
	if (kind) return await fillForm({ target, accountId, row, kind })
	const settings = await readSettings(accountId)
	const frames = await loginFrames({ target, row, types: vault.types, rule: settings.uriMatch })
	if (!Array.isArray(frames)) return frames
	// Read after the question, so a lock while it was open still stops the fill.
	const key = await getKey(accountId)
	const credential = key ? await credentialOf(row, key) : null
	if (!key || !credential) return NONE
	const { username, password } = credential
	const seed = target.code ? await seedOf(row, key) : null
	const code = seed === null ? null : (await currentCode(seed))?.digits ?? null
	const replies = await Promise.all(frames.map((frame) => {
		const message: FillExecute = { kind: 'fill.execute', username, password, code, origin: new URL(frame.url).origin }
		if (target.only) message.anchorKey = target.only.anchorKey
		return browser.tabs.sendMessage(target.tabId, message, { frameId: frame.frameId }).catch(() => null)
	}))
	const result = combine(replies)
	if (replies.every(answeredNone)) return NO_FIELDS
	if (filledSomething(result)) {
		rememberItem(target.tabId, row.id)
		await recordLastUsed(accountId, row.id)
		// Computed again, so a fill that crossed into the next period copies the code that works now.
		const copied = seed !== null && settings.autoCopyTotp ? (await currentCode(seed))?.digits : undefined
		if (copied) await writeFromBackground(copied, settings.clearClipboardMs, { codeOf: accountId })
	}
	return result
}

// Cards and identities, spec `card-identity-fill` § Each frame gets only its own members.

interface FormFill {
	target: FillTarget
	accountId: string
	row: OpenSecretRow
	kind: FormKind
}

/** A frame that has not answered the probe by then takes no part. */
const PROBE_MS = 500
/** Each in-page answer is followed by a fresh probe; a page that keeps adding frames gets nothing after this many. */
const MAX_ROUNDS = 3

/** The tab's web frames and an inline pick's frame; null off the web. */
async function formFrames(target: FillTarget): Promise<FrameRecord[] | null> {
	const [top, ...subframes] = await tabFrames(target.tabId)
	if (!top || !isWebUrl(top.url)) return null
	const frames = [top, ...subframes.filter((frame) => isWebUrl(frame.url))]
	const only = target.only
	if (only && isWebUrl(only.url) && !frames.some((frame) => frame.frameId === only.frameId)) frames.push({ frameId: only.frameId, url: only.url })
	return frames
}

function withTimeout<T>(promise: Promise<T>, ms: number): Promise<T | null> {
	return new Promise((resolve) => {
		const timer = setTimeout(() => resolve(null), ms)
		promise.then(resolve, () => resolve(null)).finally(() => clearTimeout(timer))
	})
}

const anchorKeyOf = (target: FillTarget, frameId: number) => (target.only?.frameId === frameId ? { anchorKey: target.only.anchorKey } : {})

function rolesIn(reply: unknown, kind: FormKind): FormRole[] {
	const roles = (reply as { roles?: unknown } | null)?.roles
	return Array.isArray(roles) ? [...new Set(roles.filter((role): role is FormRole => isFormRole(kind, role)))] : []
}

/** A frame no longer on the origin it was recorded at reports no roles, so the user is never asked about a host without card fields. */
async function probe(job: FormFill, frame: FrameRecord): Promise<ProbedFrame> {
	const message: FillProbe = { kind: 'fill.probe', form: job.kind, ...anchorKeyOf(job.target, frame.frameId) }
	const reply = await withTimeout(browser.tabs.sendMessage(job.target.tabId, message, { frameId: frame.frameId }), PROBE_MS)
	const origin = (reply as { origin?: unknown } | null)?.origin
	return { ...frame, roles: origin === new URL(frame.url).origin ? rolesIn(reply, job.kind) : [] }
}

/** `noRoles`: no probed frame reported a field of the kind. */
async function planFor(job: FormFill, confirmed: FormConfirmation | undefined): Promise<(FramePlan & { noRoles: boolean }) | null> {
	const frames = await formFrames(job.target)
	if (!frames) return null
	const probed = await Promise.all(frames.map((frame) => probe(job, frame)))
	const noRoles = probed.every((frame) => !frame.roles.length)
	return { ...planFrames({ kind: job.kind, topUrl: frames[0]!.url, frames: probed, confirmed }), noRoles }
}

async function confirmFormInPage(tabId: number, question: FormQuestion): Promise<boolean> {
	const message: FillConfirmForm = { kind: 'fill.confirmForm', ...question }
	return await browser.tabs.sendMessage(tabId, message, { frameId: 0 }).catch(() => false) === true
}

/** Nothing is decrypted before every question is answered; the background keeps no answer between requests. */
async function fillForm(job: FormFill): Promise<FillResult> {
	let confirmed = job.target.confirmed
	for (let round = 0; round < MAX_ROUNDS; round++) {
		const plan = await planFor(job, confirmed)
		if (plan?.noRoles) return NO_FIELDS
		if (!plan?.question) return plan ? await sendForm(job, plan.receivers) : NONE
		if (job.target.ask === 'reply') return { ...NONE, needsFormConfirm: plan.question }
		if (!(await confirmFormInPage(job.target.tabId, plan.question))) return NONE
		confirmed = answered(confirmed, plan.question)
	}
	return NONE
}

async function decryptPayload(job: FormFill): Promise<Record<string, unknown> | null> {
	// Read after the questions, so a lock while one was open still stops the fill.
	const key = await getKey(job.accountId)
	if (!key) return null
	try {
		return parsePayload(await rsaDecrypt(job.row.key, key))
	} catch (error) {
		console.error('[keepiq] fill decrypt failed for', job.row.id, error)
		return null
	}
}

async function sendMembers(job: FormFill, frame: FrameRecord & { values: FormValues }): Promise<number> {
	const { values } = frame
	if (!Object.keys(values).length) return 0
	const message: FillExecuteForm = { kind: 'fill.executeForm', form: job.kind, values, origin: new URL(frame.url).origin, ...anchorKeyOf(job.target, frame.frameId) }
	const filled = (await browser.tabs.sendMessage(job.target.tabId, message, { frameId: frame.frameId }).catch(() => null) as { filled?: unknown } | null)?.filled
	return typeof filled === 'number' && filled > 0 ? filled : 0
}

async function sendForm(job: FormFill, receivers: ProbedFrame[]): Promise<FillResult> {
	if (!receivers.length) return NONE
	const payload = await decryptPayload(job)
	if (!payload) return NONE
	const filled = await Promise.all(receivers.map((frame) => sendMembers(job, { ...frame, values: membersFor(job.kind, payload, frame.roles) })))
	if (!filled.some((count) => count > 0)) return NONE
	await recordLastUsed(job.accountId, job.row.id)
	return { filled: 'form', code: false }
}
