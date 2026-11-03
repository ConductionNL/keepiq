/**
 * Background side of card and identity fill (spec `card-identity-fill`): which items are cards and identities, their members per role, and which frames may take part.
 * Values leave here only for the frames that asked for them.
 */
import type { OpenSecretRow } from '@/src/api/types'
import { isFormRole, type FormConfirmation, type FormKind, type FormQuestion, type FormRole, type FormValues } from '@/src/messages'
import { baseDomain } from '@/src/vault/match'
import { cardBrand, text } from '@/src/vault/payloads'
import { isLocalhost } from '@/src/vault/url'
import type { VaultSnapshot } from '@/src/vault/types'
import { byLastUsed } from './matcher'

/** By the cached system type, so a user type named "card" is not a card. */
export function walletKind(row: { typeId: string | null }, types: VaultSnapshot['types']): FormKind | null {
	const type = types.find((each) => each.id === row.typeId)
	if (type?.scope !== 'system') return null
	return type.name === 'card' || type.name === 'identity' ? type.name : null
}

/** Every unblocked card or identity, last used first, then by name; never matched against the page. */
export function walletCandidates(kind: FormKind, vault: Pick<VaultSnapshot, 'secrets' | 'types'>, lastUsed: Readonly<Record<string, number>>): OpenSecretRow[] {
	const rows = vault.secrets.filter((row): row is OpenSecretRow => !row.blocked && walletKind(row, vault.types) === kind)
	return byLastUsed(rows, lastUsed)
}

export interface Expiry {
	/** "01" to "12". */
	month: string
	/** Four digits. */
	year: string
}

// [pattern, month group, year group]; YYYY-MM first, so its year is never read as a month.
const EXPIRY_FORMS: Array<[RegExp, number, number]> = [
	[/^(\d{4})-(\d{1,2})$/, 2, 1],
	[/^(\d{1,2})\s*[/\-.\s]\s*(\d{2}|\d{4})$/, 1, 2],
	[/^(\d{2})(\d{2}|\d{4})$/, 1, 2],
]

/** Keepiq's free-text `expiry` as a month and year, or null for anything else. */
export function parseExpiry(raw: string): Expiry | null {
	const value = raw.trim()
	for (const [pattern, month, year] of EXPIRY_FORMS) {
		const match = pattern.exec(value)
		if (match) return expiryOf(match[month]!, match[year]!)
	}
	return null
}

function expiryOf(month: string, year: string): Expiry | null {
	const number = Number(month)
	if (number < 1 || number > 12) return null
	return { month: String(number).padStart(2, '0'), year: year.length === 2 ? `20${year}` : year }
}

type Payload = Record<string, unknown>

const addressLines = (payload: Payload) => text(payload, 'address').split(/\r?\n/).map((line) => line.trim()).filter(Boolean)
const expiry = (payload: Payload) => parseExpiry(text(payload, 'expiry'))

// The one place members are read: a role not listed here gets nothing, and `pin` and `bsn` are never read.
const MEMBERS: Record<FormRole, (payload: Payload) => FormValues> = {
	cardholder: (payload) => ({ cardholder: text(payload, 'cardholder') }),
	number: (payload) => ({ number: text(payload, 'number').replace(/\D/g, '') }),
	expiry: (payload) => ({ expMonth: expiry(payload)?.month, expYear: expiry(payload)?.year }),
	expMonth: (payload) => ({ expMonth: expiry(payload)?.month }),
	expYear: (payload) => ({ expYear: expiry(payload)?.year }),
	securityCode: (payload) => ({ cvv: text(payload, 'cvv') }),
	brand: (payload) => ({ brand: cardBrand(text(payload, 'number')) ?? undefined }),
	fullName: (payload) => ({ fullName: `${text(payload, 'firstName')} ${text(payload, 'lastName')}`.trim() }),
	firstName: (payload) => ({ firstName: text(payload, 'firstName') }),
	lastName: (payload) => ({ lastName: text(payload, 'lastName') }),
	email: (payload) => ({ email: text(payload, 'email') }),
	phone: (payload) => ({ phone: text(payload, 'phone') }),
	streetAddress: (payload) => ({ streetAddress: addressLines(payload).join('\n') }),
	addressLine1: (payload) => ({ addressLine1: addressLines(payload)[0] }),
}

/** Only the values `roles` of `kind` need, empty ones left out. */
export function membersFor(kind: FormKind, payload: Payload, roles: readonly FormRole[]): FormValues {
	const values: FormValues = {}
	for (const role of roles) {
		if (!isFormRole(kind, role)) continue
		for (const [member, value] of Object.entries(MEMBERS[role](payload))) if (value) values[member as keyof FormValues] = value
	}
	return values
}

const isLoopback = (host: string) => isLocalhost(host) || host === '[::1]' || /^127\.\d{1,3}\.\d{1,3}\.\d{1,3}$/.test(host)

/** Not a secure context: anything but `https:`, except `http:` on loopback, which browsers treat as secure. */
export function isInsecureUrl(raw: string): boolean {
	try {
		const url = new URL(raw)
		return url.protocol !== 'https:' && !(url.protocol === 'http:' && isLoopback(url.hostname))
	} catch {
		return true
	}
}

/** A frame as the registry recorded it, with the roles its probe reported. */
export interface ProbedFrame {
	frameId: number
	url: string
	roles: FormRole[]
}

export interface PlanInput {
	kind: FormKind
	/** The top frame's URL, as the browser reports the tab. */
	topUrl: string
	frames: ProbedFrame[]
	confirmed?: FormConfirmation
}

/** `question`: what to ask before anything is decrypted; `receivers`: the frames to send members to once answered. */
export interface FramePlan {
	question: FormQuestion | null
	receivers: ProbedFrame[]
}

const hostOf = (url: string) => new URL(url).host

/** Whether a cross-site frame is on a host the confirmation did not name. */
function asksHosts(frameHosts: string[], confirmed: FormConfirmation | undefined): boolean {
	if (!confirmed) return frameHosts.length > 0
	return confirmed.frames === 'all' && frameHosts.some((host) => !confirmed.frameHosts.includes(host))
}

export function planFrames({ kind, topUrl, frames, confirmed: answer }: PlanInput): FramePlan {
	// An answer given for another page, as after a navigation or a tab switch under a popped-out popup, is no answer.
	const origin = new URL(topUrl).origin
	const confirmed = answer?.origin === origin ? answer : undefined
	const taking = frames.filter((frame) => frame.roles.length > 0)
	const site = baseDomain(topUrl)
	const crossSite = (frame: ProbedFrame) => site === null || baseDomain(frame.url) !== site
	const receivers = confirmed?.frames === 'sameSite' ? taking.filter((frame) => !crossSite(frame)) : taking
	if (!receivers.length) return { question: null, receivers }
	const frameHosts = [...new Set(taking.filter(crossSite).map((frame) => hostOf(frame.url)))]
	const insecure = kind === 'card' && !confirmed?.insecure && [topUrl, ...receivers.map((frame) => frame.url)].some(isInsecureUrl)
	const hosts = asksHosts(frameHosts, confirmed)
	const question = insecure || hosts ? { insecure, host: hostOf(topUrl), origin, frameHosts: hosts ? frameHosts : [] } : null
	return { question, receivers }
}

/** The popup's or list's answers, rebuilt from known members; anything else is no answer. */
export function parseConfirmation(value: unknown): FormConfirmation | undefined {
	const input = value as Partial<FormConfirmation> | null | undefined
	if (input?.frames !== 'all' && input?.frames !== 'sameSite') return undefined
	if (!Array.isArray(input.frameHosts) || !input.frameHosts.every((host) => typeof host === 'string')) return undefined
	if (typeof input.origin !== 'string') return undefined
	return { ...(input.insecure === true && { insecure: true }), frames: input.frames, frameHosts: [...input.frameHosts], origin: input.origin }
}
