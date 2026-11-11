/**
 * The fill orchestrator every surface goes through. Plaintext exists only inside `fill()`,
 * between the decrypt and the frames' replies (ADR-002 "per fill").
 */
import type { Browser } from 'wxt/browser'
import type { OpenSecretRow } from '@/src/api/types'
import { readSettings, type AccountSettings } from '@/src/accounts/settings'
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
async function loginFrames(login: LoginFill): Promise<FrameRecord[] | FillResult> {
	const frames = await matchingFrames(login)
	if (!frames.length) return NO_FIELDS
	if (login.target.confirmedInsecure || !isInsecure(login.row, frames)) return frames
	return askInsecure(login.target, { url: login.row.url, frames })
}

/** An inline pick's own frame when it matches, else every frame of the tab that does. */
async function matchingFrames({ target, row, types, rule }: LoginFill): Promise<FrameRecord[]> {
	return target.only
		? [{ frameId: target.only.frameId, url: target.only.url }].filter((frame) => fits(row, types, rule, frame.url))
		: await framesFor(target.tabId, row, types, rule)
}

/** The list asks itself; the menu, shortcut and page load ask in the page. */
async function askInsecure(target: FillTarget, at: { url: string | null; frames: FrameRecord[] }): Promise<FrameRecord[] | FillResult> {
	if (target.ask === 'reply') return { ...NONE, needsInsecureConfirm: true }
	return await confirmInPage(target.tabId, at.url) ? at.frames : NONE
}

async function credentialOf(row: OpenSecretRow, key: CryptoKey): Promise<{ username: string | null; password: string } | null> {
	try {
		return { username: row.login === null ? null : await rsaDecrypt(row.login, key), password: await rsaDecrypt(row.key, key) }
	} catch (error) {
		console.error('[keepiq] fill decrypt failed for', row.id, error)
		return null
	}
}

const codeOf = async (seed: string | null) => seed === null ? null : (await currentCode(seed))?.digits ?? null

interface LoginSend {
	target: FillTarget
	frames: FrameRecord[]
	credential: { username: string | null; password: string }
	code: string | null
}

/** One reply per frame, `null` for a frame that did not answer. */
function sendLogin({ target, frames, credential, code }: LoginSend): Promise<unknown[]> {
	return Promise.all(frames.map((frame) => {
		const message: FillExecute = { kind: 'fill.execute', username: credential.username, password: credential.password, code, origin: new URL(frame.url).origin }
		if (target.only) message.anchorKey = target.only.anchorKey
		return browser.tabs.sendMessage(target.tabId, message, { frameId: frame.frameId }).catch(() => null)
	}))
}

interface LoginFilled {
	tabId: number
	accountId: string
	row: OpenSecretRow
	seed: string | null
	settings: AccountSettings
}

async function afterLoginFill({ tabId, accountId, row, seed, settings }: LoginFilled): Promise<void> {
	rememberItem(tabId, row.id)
	await recordLastUsed(accountId, row.id)
	// Computed again, so a fill that crossed into the next period copies the code that works now.
	const copied = settings.autoCopyTotp ? await codeOf(seed) : null
	if (copied) await writeFromBackground(copied, settings.clearClipboardMs, { codeOf: accountId })
}

/** The unlocked account's vault and the row a fill is for. */
interface FillSource {
	accountId: string
	vault: VaultSnapshot
	row: OpenSecretRow
}

export async function fill(target: FillTarget): Promise<FillResult> {
	const source = await fillSource(target)
	if (!source) return NONE
	const kind = walletKind(source.row, source.vault.types)
	if (kind) return await fillForm({ target, accountId: source.accountId, row: source.row, kind })
	return fillLogin(target, source)
}

async function fillSource(target: FillTarget): Promise<FillSource | null> {
	const accountId = await activeUnlockedId()
	const vault = accountId ? await readSnapshot(accountId) : undefined
	if (!accountId || !vault) return null
	const row = unblockedRow(vault, target.itemId)
	return row ? { accountId, vault, row } : null
}

function unblockedRow(vault: VaultSnapshot, itemId: string): OpenSecretRow | null {
	const row = vault.secrets.find((secret) => secret.id === itemId)
	return row && !row.blocked ? row : null
}

async function fillLogin(target: FillTarget, { accountId, vault, row }: FillSource): Promise<FillResult> {
	const settings = await readSettings(accountId)
	const frames = await loginFrames({ target, row, types: vault.types, rule: settings.uriMatch })
	if (!Array.isArray(frames)) return frames
	// Read after the question, so a lock while it was open still stops the fill.
	const key = await getKey(accountId)
	if (!key) return NONE
	const credential = await credentialOf(row, key)
	if (!credential) return NONE
	const seed = target.code ? await seedOf(row, key) : null
	const replies = await sendLogin({ target, frames, credential, code: await codeOf(seed) })
	return loginResult(replies, { tabId: target.tabId, accountId, row, seed, settings })
}

async function loginResult(replies: Awaited<ReturnType<typeof sendLogin>>, filled: LoginFilled): Promise<FillResult> {
	if (replies.every(answeredNone)) return NO_FIELDS
	const result = combine(replies)
	if (filledSomething(result)) await afterLoginFill(filled)
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
	addPickFrame(frames, target.only)
	return frames
}

/** An inline pick's frame the frame list does not hold yet, while it shows a web page. */
function addPickFrame(frames: FrameRecord[], only: FillTarget['only']): void {
	if (only && isWebUrl(only.url) && !frames.some((frame) => frame.frameId === only.frameId)) frames.push({ frameId: only.frameId, url: only.url })
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
		const next = await formRound(job, confirmed)
		if ('result' in next) return next.result
		confirmed = next.confirmed
	}
	return NONE
}

type Plan = Awaited<ReturnType<typeof planFor>>

/** A plan without a question settles the fill: no fields, nothing, or the send. */
async function unaskedResult(job: FormFill, plan: Plan): Promise<FillResult | null> {
	if (plan?.noRoles) return NO_FIELDS
	if (plan?.question) return null
	return plan ? await sendForm(job, plan.receivers) : NONE
}

/** One round: the fill's result, or the answers so far once the page's question is answered. */
async function formRound(job: FormFill, confirmed: FormConfirmation | undefined): Promise<{ result: FillResult } | { confirmed: FormConfirmation }> {
	const plan = await planFor(job, confirmed)
	const result = await unaskedResult(job, plan)
	if (result) return { result }
	const question = plan!.question!
	if (job.target.ask === 'reply') return { result: { ...NONE, needsFormConfirm: question } }
	if (!(await confirmFormInPage(job.target.tabId, question))) return { result: NONE }
	return { confirmed: answered(confirmed, question) }
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
