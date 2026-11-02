/**
 * The background's reading of `passkey.start` and `passkey.available`: every gate of the spec's "go to the browser
 * untouched", from the settings, the sender and the page's request only, never the vault or its lock state.
 */
import { parse } from 'tldts'
import { baseDomain } from '@/src/vault/match'
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

const isLocalhost = (host: string) => host === 'localhost' || host.endsWith('.localhost')

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

/**
 * The origin's host, or a suffix of it within its registrable domain. A public suffix only counts as one when the
 * list names it, so an unlisted single label such as `localhost` is a site of its own.
 */
export function rpIdAllowed(rpId: string, host: string): boolean {
	if (!/^[a-z0-9-]+(\.[a-z0-9-]+)*$/.test(rpId)) return false
	const origin = parse(host, { allowPrivateDomains: true })
	const own = parse(rpId, { allowPrivateDomains: true })
	if (origin.isIp || own.isIp) return false
	if (own.publicSuffix === rpId && (own.isIcann || own.isPrivate)) return false
	if (rpId === host) return true
	const domain = origin.domain
	return domain !== null && host.endsWith(`.${rpId}`) && (rpId === domain || rpId.endsWith(`.${domain}`))
}

/** An unknown value is ignored, as WebAuthn does with an enum member it does not know. */
const userVerification = (value: string | undefined): UserVerification => (value === 'required' || value === 'discouraged' ? value : 'preferred')

export function readStart(input: StartInput): { request: CeremonyRequest } | { fallback: FallbackReason } {
	const parsed = parsePasskeyRequest(input.request)
	if (!parsed) return { fallback: 'malformed' }
	const frame = frameGate(input)
	if ('fallback' in frame) return frame
	const create = parsed.type === 'create' ? parsed.options : null
	const get = parsed.type === 'get' ? parsed.options : null
	const { options } = parsed
	const rpId = (create ? create.rp.id : get?.rpId) ?? frame.origin.hostname
	if (!rpIdAllowed(rpId, frame.origin.hostname)) return { fallback: 'rp_id' }
	// WebAuthn reads an empty list as ES256 and RS256.
	const es256 = create && (create.pubKeyCredParams.length === 0 || create.pubKeyCredParams.some((param) => param.type === 'public-key' && param.alg === ES256))
	if (create && !es256) return { fallback: 'algorithm' }
	if (create?.authenticatorSelection?.authenticatorAttachment === 'cross-platform') return { fallback: 'attachment' }

	const verification = userVerification(create ? create.authenticatorSelection?.userVerification : get?.userVerification)
	const conditional = parsed.mediation === 'conditional'
	const descriptors = create?.excludeCredentials ?? get?.allowCredentials ?? []
	return {
		request: {
			kind: parsed.type,
			tabId: frame.tabId,
			frameId: frame.frameId,
			origin: frame.origin.origin,
			url: frameUrl(input.sender, frame.origin),
			rpId,
			conditional,
			challenge: options.challenge,
			userVerification: verification,
			deadline: conditional ? null : (input.now ?? Date.now()) + ceremonyTimeout(options.timeout, verification),
			credentialIds: descriptors.filter((descriptor) => descriptor.type === 'public-key').map((descriptor) => descriptor.id),
			rpName: create?.rp.name ?? '',
			user: create ? { ...create.user } : null,
			credProps: create?.extensions?.credProps === true,
		},
	}
}
