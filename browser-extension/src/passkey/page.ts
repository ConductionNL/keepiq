/**
 * The page script, in the page's main world (design.md § Injecting the page script). It hands `publicKey` calls to the
 * relay and gives everything else, any throw and every `fallback` to the browser's own method with the page's
 * arguments. It holds only the call in flight, and trusts nothing on the port, which the page can write to as well.
 */
import { fromBase64Url, toBase64Url } from '@/src/crypto/base64'
import type {
	PageToRelay, PasskeyAssertionJSON, PasskeyAttestationJSON, PasskeyCreateOptions, PasskeyCredentialJSON, PasskeyDescriptor, PasskeyGetOptions, RelayToPage,
} from '@/src/messages'
import { ceremonyTimeout } from './deadline'
import { defined, PASSKEY_CHANNEL } from './shape'

export const ACK_TIMEOUT_MS = 500
/** How long an availability check waits for the background after the ack. */
export const AVAILABLE_TIMEOUT_MS = 2_000

/** On every method this script replaced, so a second copy (Firefox re-injects on update) leaves the first alone. */
const INSTALLED = Symbol.for('keepiq.passkey')

type Method = (...args: unknown[]) => Promise<unknown>
type Kind = 'create' | 'get'
type Outgoing = PageToRelay extends infer M ? M extends PageToRelay ? Omit<M, 'channel' | 'id'> : never : never
/** A `create` or `get` as it goes to the relay. */
type CeremonyMessage = Extract<Outgoing, { kind: Kind }>
type Listener = (this: EventTarget, type: string, listener: () => void) => void

/** What the page script uses of the page's globals, captured when it starts. */
export interface PageScope {
	navigator: { credentials?: CredentialsContainer }
	PublicKeyCredential?: { prototype: object; isUserVerifyingPlatformAuthenticatorAvailable?: Method; isConditionalMediationAvailable?: Method }
	AuthenticatorAttestationResponse?: { prototype: object }
	AuthenticatorAssertionResponse?: { prototype: object }
	MessageChannel: new () => { port1: MessagePort; port2: MessagePort }
	AbortController: typeof AbortController
	AbortSignal: typeof AbortSignal
	EventTarget: typeof EventTarget
	DOMException: typeof DOMException
	postMessage(message: unknown, targetOrigin: string, transfer: unknown[]): void
	setTimeout(handler: () => void, ms: number): unknown
	clearTimeout(id: unknown): void
}

/** Chrome's own wording; the page's code reads the name. */
const MESSAGES: Record<string, string> = {
	NotAllowedError: 'The operation either timed out or was not allowed.',
	InvalidStateError: 'The authenticator was previously registered.',
	AbortError: 'The operation was aborted.',
}

const encode = (value: unknown): string => {
	if (value instanceof ArrayBuffer) return toBase64Url(new Uint8Array(value))
	if (ArrayBuffer.isView(value)) return toBase64Url(new Uint8Array(value.buffer, value.byteOffset, value.byteLength))
	throw new TypeError('not a BufferSource')
}
const malformed = (): never => {
	throw new TypeError('malformed result')
}
const decode = (value: unknown): ArrayBuffer => (typeof value === 'string' ? fromBase64Url(value).buffer : malformed())
const required = (value: unknown): string => {
	if (value === undefined) throw new TypeError('missing member')
	return String(value)
}
const maybe = <V, T>(value: V | undefined, convert: (value: V) => T): T | undefined => (value === undefined ? undefined : convert(value))

const descriptors = (list: Iterable<PublicKeyCredentialDescriptor> | undefined): PasskeyDescriptor[] =>
	list === undefined ? [] : Array.from(list, (d) => defined({ type: required(d.type), id: encode(d.id), transports: maybe(d.transports, (t) => Array.from(t, String)) }))

const createOptions = (o: PublicKeyCredentialCreationOptions): PasskeyCreateOptions => defined({
	rp: defined({ id: maybe(o.rp.id, String), name: required(o.rp.name) }),
	user: { id: encode(o.user.id), name: required(o.user.name), displayName: required(o.user.displayName) },
	challenge: encode(o.challenge),
	pubKeyCredParams: Array.from(o.pubKeyCredParams, (p) => ({ type: required(p.type), alg: Number(p.alg) })),
	timeout: maybe(o.timeout, Number),
	excludeCredentials: descriptors(o.excludeCredentials),
	authenticatorSelection: maybe(o.authenticatorSelection, (s) => defined({
		authenticatorAttachment: maybe(s.authenticatorAttachment, String),
		residentKey: maybe(s.residentKey, String),
		requireResidentKey: maybe(s.requireResidentKey, Boolean),
		userVerification: maybe(s.userVerification, String),
	})),
	attestation: maybe(o.attestation, String),
	extensions: maybe(o.extensions, (e) => (e.credProps ? { credProps: true } : {})),
})

const getOptions = (o: PublicKeyCredentialRequestOptions): PasskeyGetOptions => defined({
	challenge: encode(o.challenge),
	timeout: maybe(o.timeout, Number),
	rpId: maybe(o.rpId, String),
	allowCredentials: descriptors(o.allowCredentials),
	userVerification: maybe(o.userVerification, String),
})

/** Whether an earlier copy of this script replaced any of the methods it replaces. */
function installedBefore(container: CredentialsContainer, Credential: NonNullable<PageScope['PublicKeyCredential']>): boolean {
	const methods: unknown[] = [container.create, container.get, Credential.isUserVerifyingPlatformAuthenticatorAvailable, Credential.isConditionalMediationAvailable]
	return methods.some((method) => typeof method === 'function' && Object.hasOwn(method, INSTALLED))
}

/**
 * Puts `wrap(method)` in the place of the target's method, marked as this script's; a method the target lacks stays
 * missing. A page that forges the marker only turns Keepiq off for itself, which deleting the replacements already does.
 */
function replaceMethod(target: object, name: string, wrap: (method: Method) => object): void {
	const method = (target as Record<string, unknown>)[name]
	if (typeof method !== 'function') return
	const value = wrap(method as Method)
	Object.defineProperty(value, INSTALLED, { value: true })
	Object.defineProperty(target, name, { value, writable: true, configurable: true })
}

export function installPasskeyPage(scope: PageScope = globalThis as unknown as PageScope): void {
	const container = scope.navigator?.credentials
	const Credential = scope.PublicKeyCredential
	if (!container || !Credential || installedBefore(container, Credential)) return
	const { MessageChannel, AbortController, DOMException } = scope
	// Read from the prototype, as the page may set a signal's own members; they throw for anything but a real AbortSignal.
	// On Firefox a page script that ran before this one may have replaced them, which only harms that page's own calls.
	const isAborted = Object.getOwnPropertyDescriptor(scope.AbortSignal.prototype, 'aborted')?.get
	const reasonOf = Object.getOwnPropertyDescriptor(scope.AbortSignal.prototype, 'reason')?.get
	const listen = scope.EventTarget.prototype.addEventListener as Listener
	const unlisten = scope.EventTarget.prototype.removeEventListener as Listener
	if (!isAborted || !reasonOf) return
	const aborted = (signal: unknown) => isAborted.call(signal) as boolean
	const reason = (signal: AbortSignal): unknown => reasonOf.call(signal)
	const post = scope.postMessage.bind(scope)
	const setTimer = scope.setTimeout.bind(scope)
	const clearTimer = scope.clearTimeout.bind(scope)
	let nextId = 0

	/** One call's own channel; `answer` is the relay's last word, or `fallback` when the relay is too late. */
	function open(message: Outgoing, afterAck?: number): { answer: Promise<unknown>; abort: () => void } {
		const id = String(++nextId)
		const { port1, port2 } = new MessageChannel()
		let settle!: (reply: unknown) => void
		const answer = new Promise<unknown>((resolve) => (settle = resolve))
		const end = (reply: unknown) => {
			clearTimer(timer)
			port1.onmessage = null
			port1.close()
			settle(reply)
		}
		// Tells the relay as well, so it drops the call. One whose `passkey.start` already went out is aborted in the
		// background right after it started, so its window may flash open and shut.
		const abort = (reply: unknown) => {
			port1.postMessage({ kind: 'abort', id })
			end(reply)
		}
		port1.onmessage = (event) => {
			const reply = event.data as { type?: unknown } | null
			if (reply?.type !== 'ack') return end(reply)
			clearTimer(timer)
			if (afterAck !== undefined) timer = setTimer(() => abort({ type: 'fallback' }), afterAck)
		}
		post({ ...message, channel: PASSKEY_CHANNEL, id }, '*', [port2])
		let timer = setTimer(() => abort({ type: 'fallback' }), ACK_TIMEOUT_MS)
		return { answer, abort: () => abort(null) }
	}

	const buffers = <K extends string>(json: Record<K, unknown>, keys: K[]) => Object.fromEntries(keys.map((key) => [key, decode(json[key])])) as Record<K, ArrayBuffer>
	const encodeAll = (values: Record<string, ArrayBuffer | null>) => Object.fromEntries(Object.entries(values).map(([key, value]) => [key, value && toBase64Url(new Uint8Array(value))]))
	const define = (prototype: object | undefined, values: Record<string, unknown>, methods: Record<string, () => unknown>) => {
		const target = Object.create(prototype ?? Object.prototype) as Record<string, unknown>
		for (const [key, value] of Object.entries(values)) Object.defineProperty(target, key, { value, enumerable: true })
		for (const [key, value] of Object.entries(methods)) Object.defineProperty(target, key, { value, writable: true, configurable: true })
		return target
	}

	function attestation(json: PasskeyAttestationJSON) {
		const b = buffers(json, ['clientDataJSON', 'attestationObject', 'authenticatorData', 'publicKey'])
		const algorithm = typeof json.publicKeyAlgorithm === 'number' ? json.publicKeyAlgorithm : malformed()
		const transports = Array.isArray(json.transports) ? json.transports.map(String) : malformed()
		const own = { clientDataJSON: b.clientDataJSON, attestationObject: b.attestationObject }
		return define(scope.AuthenticatorAttestationResponse?.prototype, own, {
			getTransports: () => [...transports],
			getAuthenticatorData: () => b.authenticatorData.slice(0),
			getPublicKey: () => b.publicKey.slice(0),
			getPublicKeyAlgorithm: () => algorithm,
			toJSON: () => ({ ...encodeAll(b), publicKeyAlgorithm: algorithm, transports: [...transports] }),
		})
	}

	function assertion(json: PasskeyAssertionJSON) {
		const b = buffers(json, ['clientDataJSON', 'authenticatorData', 'signature'])
		const userHandle = json.userHandle === null ? null : decode(json.userHandle)
		const own = { ...b, userHandle }
		// WebAuthn's JSON omits a missing user handle rather than writing null.
		return define(scope.AuthenticatorAssertionResponse?.prototype, own, { toJSON: () => encodeAll(userHandle === null ? b : own) })
	}

	function credential(kind: Kind, json: PasskeyCredentialJSON): Credential {
		const rawId = decode(json.rawId)
		const id = toBase64Url(new Uint8Array(rawId))
		const response = kind === 'create' ? attestation(json.response as PasskeyAttestationJSON) : assertion(json.response as PasskeyAssertionJSON)
		const rk = json.clientExtensionResults?.credProps?.rk
		const extensions = () => (typeof rk === 'boolean' ? { credProps: { rk } } : {})
		const values = { id, rawId, type: 'public-key', authenticatorAttachment: 'platform', response }
		return define(Credential!.prototype, values, {
			getClientExtensionResults: extensions,
			toJSON: () => ({ id, rawId: id, type: 'public-key', authenticatorAttachment: 'platform', clientExtensionResults: extensions(), response: (response.toJSON as () => unknown)() }),
		}) as unknown as Credential
	}

	/** The relay's answer as the page receives it; anything unexpected, or a throw, goes to the browser. */
	function outcome(kind: Kind, reply: unknown, signal: AbortSignal | undefined, fallback: () => Promise<unknown>): Promise<unknown> {
		try {
			const r = reply as RelayToPage | null
			if (r?.type === 'credential') return Promise.resolve(credential(kind, r.credential))
			if (r?.type === 'error' && Object.hasOwn(MESSAGES, r.name)) {
				return Promise.reject(r.name === 'AbortError' && signal && aborted(signal) ? reason(signal) : new DOMException(MESSAGES[r.name], r.name))
			}
		} catch {
			// Falls back below.
		}
		return fallback()
	}

	function modal(message: CeremonyMessage, signal: AbortSignal | undefined, fallback: () => Promise<unknown>) {
		const userVerification = message.kind === 'create' ? message.options.authenticatorSelection?.userVerification : message.options.userVerification
		const timeout = ceremonyTimeout(message.options.timeout, userVerification)
		const call = open(message)
		return new Promise<unknown>((resolve, reject) => {
			let done = false
			const finish = () => {
				done = true
				clearTimer(deadline)
				if (signal) unlisten.call(signal, 'abort', onAbort)
			}
			const onAbort = () => {
				if (done) return
				call.abort()
				finish()
				reject(reason(signal!))
			}
			const deadline = setTimer(() => {
				if (done) return
				call.abort()
				finish()
				reject(new DOMException(MESSAGES.NotAllowedError, 'NotAllowedError'))
			}, timeout)
			if (signal) listen.call(signal, 'abort', onAbort)
			void call.answer.then((reply) => {
				if (done) return
				finish()
				try {
					outcome(message.kind, reply, signal, fallback).then(resolve, reject)
				} catch (error) {
					reject(error)
				}
			})
		})
	}

	/** Keepiq's ceremony beside the browser's own conditional request; the first credential wins and the other is aborted. */
	function conditional(publicKey: PasskeyGetOptions, signal: AbortSignal | undefined, own: (signal: AbortSignal) => Promise<unknown>) {
		const controller = new AbortController()
		const browserCall = own(controller.signal)
		const call = open({ kind: 'get', options: publicKey, mediation: 'conditional' })
		return new Promise<unknown>((resolve, reject) => {
			let done = false
			let keepiqOut = false
			let browserFailed: { error: unknown } | null = null
			const finish = (settle: () => void) => {
				if (done) return
				done = true
				settle()
				if (signal) unlisten.call(signal, 'abort', onAbort)
			}
			const onAbort = () => finish(() => {
				call.abort()
				controller.abort(reason(signal!))
				reject(reason(signal!))
			})
			if (signal) listen.call(signal, 'abort', onAbort)
			// Copying the page's options for the browser's call ran its getters, which may have aborted the signal.
			if (signal && aborted(signal)) onAbort()
			browserCall.then(
				(credential) => finish(() => {
					call.abort()
					resolve(credential)
				}),
				(error: unknown) => {
					if (keepiqOut) finish(() => reject(error))
					else browserFailed = { error }
				},
			)
			void call.answer.then((reply) => {
				if (done) return
				try {
					const r = reply as RelayToPage | null
					if (r?.type === 'credential') {
						const result = credential('get', r.credential)
						return finish(() => {
							controller.abort()
							resolve(result)
						})
					}
					if (r?.type === 'error' && Object.hasOwn(MESSAGES, r.name)) {
						const error = new DOMException(MESSAGES[r.name], r.name)
						return finish(() => {
							controller.abort()
							reject(error)
						})
					}
				} catch {
					// The browser's own request decides alone.
				}
				keepiqOut = true
				if (browserFailed) finish(() => reject(browserFailed!.error))
			})
		})
	}

	function replacement(kind: Kind, method: Method) {
		return function (this: unknown, ...args: unknown[]): Promise<unknown> {
			try {
				const options = args[0] as (CredentialCreationOptions & CredentialRequestOptions) | undefined
				if (!options?.publicKey) return method.apply(this, args)
				const fallback = () => method.apply(this, args)
				const ceremony = kind === 'create'
					? { kind, options: createOptions(options.publicKey as PublicKeyCredentialCreationOptions) }
					: { kind, options: getOptions(options.publicKey as PublicKeyCredentialRequestOptions) }
				const mediation = maybe(options.mediation as string | undefined, String)
				const signal = options.signal as AbortSignal | undefined
				// Before any ceremony starts: `aborted` throws for anything but a real AbortSignal, which the catch hands to
				// the browser; an aborted one the browser rejects with the page's own reason.
				if (signal !== undefined && aborted(signal)) return fallback()
				if (ceremony.kind === 'get' && mediation === 'conditional') {
					// The browser reads inherited members too, which a spread would drop.
					const withSignal = (own: AbortSignal) => Object.create(options, { signal: { value: own, enumerable: true } }) as unknown
					return conditional(ceremony.options, signal, (own) => method.call(this, withSignal(own)))
				}
				return modal({ ...ceremony, mediation }, signal, fallback)
			} catch {
				return method.apply(this, args)
			}
		}
	}

	function availability(method: Method) {
		return function (this: unknown, ...args: unknown[]): Promise<unknown> {
			try {
				return open({ kind: 'available' }, AVAILABLE_TIMEOUT_MS).answer.then((reply) => ((reply as RelayToPage | null)?.type === 'available' ? true : method.apply(this, args)))
			} catch {
				return method.apply(this, args)
			}
		}
	}

	for (const kind of ['create', 'get'] as const) replaceMethod(container, kind, (method) => replacement(kind, method))
	for (const name of ['isUserVerifyingPlatformAuthenticatorAvailable', 'isConditionalMediationAvailable'] as const) replaceMethod(Credential, name, availability)
}
