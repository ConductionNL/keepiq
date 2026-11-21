/**
 * The request a page may send (spec § What crosses between the page and the content script), rebuilt from known
 * members only. The relay and the background both parse with it, since the page wrote it.
 */
import type { PasskeyCreateOptions, PasskeyDescriptor, PasskeyGetOptions, PasskeyMediation, PasskeyRequest } from '@/src/messages'

export const PASSKEY_CHANNEL = 'keepiq.passkey'

export const MAX_USER_ID_BYTES = 64
export const MAX_ID_BYTES = 1024
export const MAX_LIST = 64
export const MAX_STRING = 1024
/** The frame's URL is kept per tab in `storage.session`, whose quota the vault key shares. */
export const MAX_URL = 2048

const MEDIATIONS: Record<PasskeyRequest['type'], readonly PasskeyMediation[]> = {
	create: ['optional', 'required'],
	get: ['optional', 'required', 'conditional'],
}

const refuse = (): never => {
	throw new TypeError('refused')
}

const record = (value: unknown) => (typeof value === 'object' && value !== null && !Array.isArray(value) ? value as Record<string, unknown> : refuse())
const string = (value: unknown) => (typeof value === 'string' && value.length <= MAX_STRING ? value : refuse())
const boolean = (value: unknown) => (typeof value === 'boolean' ? value : refuse())
const number = (value: unknown) => (typeof value === 'number' && Number.isFinite(value) ? value : refuse())
const optional = <T>(value: unknown, parse: (value: unknown) => T): T | undefined => (value === undefined ? undefined : parse(value))
const list = <T>(value: unknown, parse: (value: unknown) => T): T[] =>
	Array.isArray(value) && value.length <= MAX_LIST ? value.map((entry: unknown) => parse(entry)) : refuse()
/** WebAuthn's default for a missing credential list. */
const descriptors = (value: unknown) => (value === undefined ? [] : list(value, descriptor))

/** Unpadded base64url of at most `max` bytes. */
const bytes = (max: number, min = 0) => (value: unknown): string =>
	typeof value === 'string' && value.length % 4 !== 1 && Math.floor(value.length * 3 / 4) <= max && Math.floor(value.length * 3 / 4) >= min
		&& /^[A-Za-z0-9_-]*$/.test(value) ? value : refuse()

/** Drops `undefined` members, so the message holds only what the page gave. */
export function defined<T extends object>(value: T): T {
	return Object.fromEntries(Object.entries(value).filter(([, member]) => member !== undefined)) as T
}

function descriptor(value: unknown): PasskeyDescriptor {
	const d = record(value)
	return defined({ type: string(d.type), id: bytes(MAX_ID_BYTES)(d.id), transports: optional(d.transports, (v) => list(v, string)) })
}

function createOptions(value: unknown): PasskeyCreateOptions {
	const o = record(value)
	const rp = record(o.rp)
	const user = record(o.user)
	return defined({
		rp: defined({ id: optional(rp.id, string), name: string(rp.name) }),
		user: { id: bytes(MAX_USER_ID_BYTES, 1)(user.id), name: string(user.name), displayName: string(user.displayName) },
		challenge: bytes(MAX_ID_BYTES)(o.challenge),
		pubKeyCredParams: list(o.pubKeyCredParams, (v) => {
			const p = record(v)
			const alg = number(p.alg)
			return { type: string(p.type), alg: Number.isInteger(alg) ? alg : refuse() }
		}),
		timeout: optional(o.timeout, number),
		excludeCredentials: descriptors(o.excludeCredentials),
		authenticatorSelection: optional(o.authenticatorSelection, (v) => {
			const s = record(v)
			return defined({
				authenticatorAttachment: optional(s.authenticatorAttachment, string),
				residentKey: optional(s.residentKey, string),
				requireResidentKey: optional(s.requireResidentKey, boolean),
				userVerification: optional(s.userVerification, string),
			})
		}),
		attestation: optional(o.attestation, string),
		extensions: optional(o.extensions, (v) => (record(v).credProps === true ? { credProps: true } : {})),
	})
}

function getOptions(value: unknown): PasskeyGetOptions {
	const o = record(value)
	return defined({
		challenge: bytes(MAX_ID_BYTES)(o.challenge),
		timeout: optional(o.timeout, number),
		rpId: optional(o.rpId, string),
		allowCredentials: descriptors(o.allowCredentials),
		userVerification: optional(o.userVerification, string),
	})
}

/** `null` for anything out of shape or over a limit, which the relay answers with `fallback`. */
export function parsePasskeyRequest(value: unknown): PasskeyRequest | null {
	try {
		const r = record(value)
		const type = r.type === 'create' || r.type === 'get' ? r.type : refuse()
		const mediation = optional(r.mediation, (v) => MEDIATIONS[type].find((allowed) => allowed === v) ?? refuse())
		return type === 'create'
			? defined<PasskeyRequest>({ type, options: createOptions(r.options), mediation })
			: defined<PasskeyRequest>({ type, options: getOptions(r.options), mediation })
	} catch {
		return null
	}
}
