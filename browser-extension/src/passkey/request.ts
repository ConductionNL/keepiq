/**
 * The background's reading of `passkey.start` and `passkey.available`: every gate of the spec's "go to the browser
 * untouched", from the settings, the sender and the page's request only, never the vault or its lock state.
 */
import { parse } from 'tldts'
import type { PasskeyCreateOptions, PasskeyRequest } from '@/src/messages'
import { baseDomain } from '@/src/vault/match'
import { isLocalhost } from '@/src/vault/url'
import { ceremonyTimeout } from './deadline'
import { MAX_URL, parsePasskeyRequest } from './shape'
import { ES256 } from './webauthn'

export type UserVerification = 'required' | 'preferred' | 'discouraged'

export type FallbackReason = 'malformed' | 'sender' | 'account' | 'setting_off' | 'insecure' | 'excluded' | 'rp_id' | 'cross_origin' | 'algorithm' | 'attachment'

export interface FrameInput {
	crossOriginAncestor: boolean
	sender: { origin?: string; url?: string; tab?: { id?: number }; frameId?: number }
	/** Null when there is no logged-in active account. */
	account: { askPasskeys: boolean; excludedDomains: readonly string[] } | null
}

export interface StartInput extends FrameInput {
	/** The message's `request`, parsed again here since the page wrote it. */
	request: unknown
	now?: number
}

/** Byte values stay base64url, so the request can be kept in `storage.session` as it is. */
export interface CeremonyRequest {
	kind: 'create' | 'get'
	tabId: number
	frameId: number
	origin: string
	/** The frame's URL without its fragment, for the Save view to match logins as autofill does. */
	url: string
	rpId: string
	conditional: boolean
	challenge: string
	userVerification: UserVerification
	/** Null for a conditional request, which has none. */
	deadline: number | null
	/** `excludeCredentials` for `create`, `allowCredentials` for `get`, `public-key` ones only. */
	credentialIds: string[]
	/** `create` only: the page's `rp.name`, `user` and whether it asked for `credProps`. */
	rpName: string
	user: { id: string; name: string; displayName: string } | null
	credProps: boolean
}

type Frame = { tabId: number; frameId: number; origin: URL }

function senderOrigin(sender: FrameInput['sender']): URL | null {
	try {
		const url = new URL(sender.origin ?? sender.url ?? '')
		return url.origin === 'null' ? null : new URL(url.origin)
	} catch {
		return null
	}
}

/** A `sender.url` of another origin, as `about:blank`, or over `MAX_URL` falls back to the origin's root. */
function frameUrl(sender: FrameInput['sender'], origin: URL): string {
	try {
		const url = new URL(sender.url ?? '')
		url.hash = ''
		return url.origin === origin.origin && url.href.length <= MAX_URL ? url.href : `${origin.origin}/`
	} catch {
		return `${origin.origin}/`
	}
}

/** The gates that hold for any request from the frame; `passkey.available` answers by these alone. */
export function frameGate({ sender, account, crossOriginAncestor }: FrameInput): Frame | { fallback: FallbackReason } {
	const tabId = sender.tab?.id
	if (tabId === undefined || sender.frameId === undefined) return { fallback: 'sender' }
	if (account === null) return { fallback: 'account' }
	if (!account.askPasskeys) return { fallback: 'setting_off' }
	const origin = senderOrigin(sender)
	if (!origin || (origin.protocol !== 'https:' && !(origin.protocol === 'http:' && isLocalhost(origin.hostname)))) return { fallback: 'insecure' }
	const domain = baseDomain(origin.origin)
	if (domain && account.excludedDomains.includes(domain)) return { fallback: 'excluded' }
	// Firefox's `sender.url` hides a sandboxed frame; it ends here as `sameOriginAncestors()` is null on origin `'null'`.
	if (crossOriginAncestor) return { fallback: 'cross_origin' }
	return { tabId, frameId: sender.frameId, origin }
}

/** A strict suffix of `host` that stays within `domain`, its registrable domain. */
function withinDomain(rpId: string, host: string, domain: string | null): boolean {
	return domain !== null && host.endsWith(`.${rpId}`) && (rpId === domain || rpId.endsWith(`.${domain}`))
}

/**
 * The origin's host, or a suffix of it within its registrable domain. A public suffix only counts as one when the
 * list names it, so an unlisted single label such as `localhost` is a site of its own.
 */
export function rpIdAllowed(rpId: string, host: string): boolean {
	if (!/^[a-z0-9-]+(\.[a-z0-9-]+)*$/.test(rpId)) return false
	const names = parsedNames(rpId, host)
	if (!names || isListedSuffix(names.own, rpId)) return false
	return rpId === host || withinDomain(rpId, host, names.origin.domain)
}

/** Both names parsed, unless either is an IP address, which is never a site. */
function parsedNames(rpId: string, host: string) {
	const origin = parse(host, { allowPrivateDomains: true })
	const own = parse(rpId, { allowPrivateDomains: true })
	return origin.isIp || own.isIp ? null : { origin, own }
}

const isListedSuffix = (own: ReturnType<typeof parse>, rpId: string) => own.publicSuffix === rpId && (own.isIcann || own.isPrivate)

/** An unknown value is ignored, as WebAuthn does with an enum member it does not know. */
const userVerification = (value: string | undefined): UserVerification => (value === 'required' || value === 'discouraged' ? value : 'preferred')

/** The members `create` and `get` name differently, under one name; `create` is null for a `get`. */
function kindMembers(parsed: PasskeyRequest) {
	if (parsed.type === 'create') {
		const { options } = parsed
		return { create: options, rpId: options.rp.id, userVerification: options.authenticatorSelection?.userVerification, descriptors: options.excludeCredentials }
	}
	const { options } = parsed
	return { create: null, rpId: options.rpId, userVerification: options.userVerification, descriptors: options.allowCredentials }
}

/** The gates only a `create` has: an ES256 key the page accepts, and no demand for a roaming authenticator. */
function createFallback(create: PasskeyCreateOptions): FallbackReason | null {
	const params = create.pubKeyCredParams
	// WebAuthn reads an empty list as ES256 and RS256.
	if (params.length > 0 && !params.some((param) => param.type === 'public-key' && param.alg === ES256)) return 'algorithm'
	if (create.authenticatorSelection?.authenticatorAttachment === 'cross-platform') return 'attachment'
	return null
}

/** The RP ID first, then a create's own gates. */
function startRefusal(rpId: string, { hostname, create }: { hostname: string; create: PasskeyCreateOptions | null }): FallbackReason | null {
	if (!rpIdAllowed(rpId, hostname)) return 'rp_id'
	return create && createFallback(create)
}

/** A request the gates let through, with what the frame and the page's options say about it. */
interface Started {
	input: StartInput
	parsed: PasskeyRequest
	frame: Frame
	members: ReturnType<typeof kindMembers>
	rpId: string
}

/** A modal ceremony ends at the page's timeout; a conditional one waits. */
function deadlineOf({ input, parsed }: Started, verification: UserVerification): number | null {
	if (parsed.mediation === 'conditional') return null
	return (input.now ?? Date.now()) + ceremonyTimeout(parsed.options.timeout, verification)
}

/** What only a `create` carries: the RP's name, the user and `credProps`. */
function createMembers(create: PasskeyCreateOptions | null) {
	return { rpName: create?.rp.name ?? '', user: create ? { ...create.user } : null, credProps: create?.extensions?.credProps === true }
}

function ceremonyRequest(started: Started): CeremonyRequest {
	const { input, parsed, frame, members, rpId } = started
	const verification = userVerification(members.userVerification)
	return {
		kind: parsed.type,
		tabId: frame.tabId,
		frameId: frame.frameId,
		origin: frame.origin.origin,
		url: frameUrl(input.sender, frame.origin),
		rpId,
		conditional: parsed.mediation === 'conditional',
		challenge: parsed.options.challenge,
		userVerification: verification,
		deadline: deadlineOf(started, verification),
		credentialIds: members.descriptors.filter((descriptor) => descriptor.type === 'public-key').map((descriptor) => descriptor.id),
		...createMembers(members.create),
	}
}

export function readStart(input: StartInput): { request: CeremonyRequest } | { fallback: FallbackReason } {
	const parsed = parsePasskeyRequest(input.request)
	if (!parsed) return { fallback: 'malformed' }
	const frame = frameGate(input)
	if ('fallback' in frame) return frame
	const members = kindMembers(parsed)
	const rpId = members.rpId ?? frame.origin.hostname
	const refused = startRefusal(rpId, { hostname: frame.origin.hostname, create: members.create })
	if (refused) return { fallback: refused }
	return { request: ceremonyRequest({ input, parsed, frame, members, rpId }) }
}
