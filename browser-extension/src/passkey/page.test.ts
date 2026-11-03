import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest'
import { toBase64Url } from '@/src/crypto/base64'
import type { PasskeyCredentialJSON, RelayToPage } from '@/src/messages'
import { FakeMessageChannel, flush, type FakePort } from '@/src/testing/message-channel'
import { ACK_TIMEOUT_MS, AVAILABLE_TIMEOUT_MS, installPasskeyPage, type PageScope } from './page'

const b64 = (...bytes: number[]) => toBase64Url(new Uint8Array(bytes))

/** One call as the relay saw it: what the page posted and the port to answer on. */
interface Posted {
	message: Record<string, unknown>
	port: FakePort
}

class PublicKeyCredential {
	static isUserVerifyingPlatformAuthenticatorAvailable = vi.fn(async () => false)
	static isConditionalMediationAvailable = vi.fn(async () => false)
}
class AuthenticatorAttestationResponse {}
class AuthenticatorAssertionResponse {}

let posted: Posted[]
let browserCreate: Mock<(...args: unknown[]) => Promise<unknown>>
let browserGet: Mock<(...args: unknown[]) => Promise<unknown>>
let browserConditional: Mock<() => Promise<boolean>>
let credentials: { create: (...args: unknown[]) => Promise<unknown>; get: (...args: unknown[]) => Promise<unknown> }
/** Delivers what is posted to the window to every listener on it, as a real window does. */
let win: EventTarget
let scope: PageScope & Pick<EventTarget, 'addEventListener' | 'removeEventListener'>

const answer = (call: Posted, reply: RelayToPage) => call.port.postMessage(reply)
const ack = (call: Posted) => answer(call, { type: 'ack' })

const createOptions = (overrides: Record<string, unknown> = {}) => ({
	publicKey: {
		rp: { id: 'example.test', name: 'Example' },
		user: { id: new Uint8Array([1, 2, 3]), name: 'alice', displayName: 'Alice' },
		challenge: new Uint8Array([9, 9, 9, 9]).buffer,
		pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
		...overrides,
	},
})
const getOptions = (extra: Record<string, unknown> = {}) => ({ publicKey: { challenge: new Uint8Array([7, 7]), rpId: 'example.test' }, ...extra })

const assertionJSON = (): PasskeyCredentialJSON => ({
	id: b64(5, 6), rawId: b64(5, 6), type: 'public-key', authenticatorAttachment: 'platform', clientExtensionResults: {},
	response: { clientDataJSON: b64(1), authenticatorData: b64(2), signature: b64(3), userHandle: b64(4) },
})

const attestationJSON = (): PasskeyCredentialJSON => ({
	id: b64(5, 6), rawId: b64(5, 6), type: 'public-key', authenticatorAttachment: 'platform', clientExtensionResults: { credProps: { rk: true } },
	response: { clientDataJSON: b64(1), attestationObject: b64(2), authenticatorData: b64(3), publicKey: b64(4), publicKeyAlgorithm: -7, transports: ['internal'] },
})

/** Settles to `'pending'` while the promise is still open. */
async function state(promise: Promise<unknown>): Promise<{ value?: unknown; error?: unknown } | 'pending'> {
	let result: { value?: unknown; error?: unknown } | 'pending' = 'pending'
	promise.then((value) => (result = { value }), (error: unknown) => (result = { error }))
	await flush()
	return result
}

beforeEach(() => {
	vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
	posted = []
	browserCreate = vi.fn(async () => 'browser credential')
	browserGet = vi.fn(async () => 'browser credential')
	credentials = { create: browserCreate, get: browserGet }
	PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable = vi.fn(async () => false)
	PublicKeyCredential.isConditionalMediationAvailable = browserConditional = vi.fn(async () => false)
	win = new EventTarget()
	scope = {
		navigator: { credentials: credentials as unknown as CredentialsContainer },
		PublicKeyCredential,
		AuthenticatorAttestationResponse,
		AuthenticatorAssertionResponse,
		MessageChannel: FakeMessageChannel as unknown as PageScope['MessageChannel'],
		AbortController,
		AbortSignal,
		EventTarget,
		DOMException,
		addEventListener: win.addEventListener.bind(win),
		removeEventListener: win.removeEventListener.bind(win),
		// `posted` is the relay's view of the window.
		postMessage: (message, _origin, transfer) => {
			const port = transfer[0] as FakePort | undefined
			if (port) posted.push({ message: structuredClone(message) as Record<string, unknown>, port })
			const event = Object.assign(new Event('message'), { data: structuredClone(message), ports: [...transfer], source: scope })
			queueMicrotask(() => win.dispatchEvent(event))
		},
		setTimeout: (handler, ms) => setTimeout(handler, ms),
		clearTimeout: (id) => clearTimeout(id as number),
	}
	installPasskeyPage(scope)
})

afterEach(() => {
	vi.useRealTimers()
})

describe('page script', () => {
	it('gives a call without publicKey to the browser exactly as made, sending nothing', async () => {
		const options = { password: true }
		const result = await credentials.get(options)
		expect(result).toBe('browser credential')
		expect(browserGet).toHaveBeenCalledExactlyOnceWith(options)
		expect(browserGet.mock.contexts[0]).toBe(credentials)
		expect(await credentials.create()).toBe('browser credential')
		expect(posted).toEqual([])
	})

	it('posts publicKey options with every BufferSource as base64url, one port per call', async () => {
		void credentials.create(createOptions({ excludeCredentials: [{ type: 'public-key', id: new Uint8Array([8]), transports: ['usb'] }], extensions: { credProps: true, prf: {} } }))
		void credentials.get(getOptions())
		await flush()
		expect(posted).toHaveLength(2)
		expect(posted[0]!.port).not.toBe(posted[1]!.port)
		expect(posted[0]!.message).toEqual({
			channel: 'keepiq.passkey', kind: 'create', id: '1',
			options: {
				rp: { id: 'example.test', name: 'Example' },
				user: { id: b64(1, 2, 3), name: 'alice', displayName: 'Alice' },
				challenge: b64(9, 9, 9, 9),
				pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
				excludeCredentials: [{ type: 'public-key', id: b64(8), transports: ['usb'] }],
				extensions: { credProps: true },
			},
		})
		expect(posted[1]!.message).toEqual({ channel: 'keepiq.passkey', kind: 'get', id: '2', options: { challenge: b64(7, 7), rpId: 'example.test', allowCredentials: [] } })
	})

	it('gives the browser the page’s own arguments when the options do not serialise', async () => {
		const options = createOptions({ challenge: 'not a buffer' })
		expect(await credentials.create(options)).toBe('browser credential')
		expect(browserCreate).toHaveBeenCalledExactlyOnceWith(options)
		expect(posted).toEqual([])
	})

	it('falls back to the browser when no ack arrives within 500 ms, and tells the relay to drop the call', async () => {
		const options = getOptions()
		const call = credentials.get(options)
		await flush()
		vi.advanceTimersByTime(ACK_TIMEOUT_MS - 1)
		expect(await state(call)).toBe('pending')
		vi.advanceTimersByTime(1)
		expect(await state(call)).toEqual({ value: 'browser credential' })
		expect(browserGet).toHaveBeenCalledExactlyOnceWith(options)
		// A relay that acks late must not open a Keepiq window beside the browser's dialog.
		expect(posted[0]!.port.other.posted).toEqual([{ kind: 'abort', id: '1' }])
	})

	it('waits past 500 ms once the relay acked', async () => {
		const call = credentials.get(getOptions())
		await flush()
		ack(posted[0]!)
		await flush()
		vi.advanceTimersByTime(10_000)
		expect(await state(call)).toBe('pending')
		expect(browserGet).not.toHaveBeenCalled()
	})

	it('falls back to the browser on the relay’s fallback, with the page’s options and signal', async () => {
		const options = getOptions({ signal: new AbortController().signal })
		const call = credentials.get(options)
		await flush()
		ack(posted[0]!)
		answer(posted[0]!, { type: 'fallback' })
		expect(await state(call)).toEqual({ value: 'browser credential' })
		expect(browserGet).toHaveBeenCalledExactlyOnceWith(options)
	})

	it('falls back on a result it cannot read', async () => {
		const call = credentials.get(getOptions())
		await flush()
		answer(posted[0]!, { type: 'credential', credential: { rawId: 42 } } as never)
		expect(await state(call)).toEqual({ value: 'browser credential' })
	})

	it('ignores a forged result posted to the window: results come only over the call’s own port', async () => {
		const call = credentials.get(getOptions())
		await flush()
		ack(posted[0]!)
		const forged = { channel: 'keepiq.passkey', type: 'credential', id: '1', credential: assertionJSON() }
		const delivered: unknown[] = []
		scope.addEventListener('message', (event) => delivered.push((event as MessageEvent).data))
		scope.postMessage(forged, '*', [])
		// A port the page made itself is not the call's.
		const own = new FakeMessageChannel()
		scope.postMessage(forged, '*', [own.port2])
		own.port1.postMessage({ type: 'credential', credential: assertionJSON() })
		await flush()
		expect(delivered).toEqual([forged, forged])
		expect(await state(call)).toBe('pending')
		answer(posted[0]!, { type: 'error', name: 'NotAllowedError' })
		const result = await state(call)
		expect(result).not.toBe('pending')
		expect((result as { error: DOMException }).error.name).toBe('NotAllowedError')
	})

	it('rebuilds an assertion with the PublicKeyCredential and response prototypes', async () => {
		const call = credentials.get(getOptions())
		await flush()
		ack(posted[0]!)
		answer(posted[0]!, { type: 'credential', credential: assertionJSON() })
		const credential = (await call) as Record<string, unknown> & { response: Record<string, unknown> & { toJSON(): unknown }; toJSON(): unknown; getClientExtensionResults(): unknown }
		expect(credential).toBeInstanceOf(PublicKeyCredential)
		expect(credential.response).toBeInstanceOf(AuthenticatorAssertionResponse)
		expect(credential.id).toBe(b64(5, 6))
		expect(new Uint8Array(credential.rawId as ArrayBuffer)).toEqual(new Uint8Array([5, 6]))
		expect(credential.type).toBe('public-key')
		expect(credential.authenticatorAttachment).toBe('platform')
		expect(new Uint8Array(credential.response.signature as ArrayBuffer)).toEqual(new Uint8Array([3]))
		expect(new Uint8Array(credential.response.userHandle as ArrayBuffer)).toEqual(new Uint8Array([4]))
		expect(credential.getClientExtensionResults()).toEqual({})
		expect(credential.toJSON()).toEqual({ ...assertionJSON(), response: { clientDataJSON: b64(1), authenticatorData: b64(2), signature: b64(3), userHandle: b64(4) } })
	})

	it('leaves userHandle out of the JSON when the passkey has none, as WebAuthn does', async () => {
		const call = credentials.get(getOptions())
		await flush()
		const json = assertionJSON()
		answer(posted[0]!, { type: 'credential', credential: { ...json, response: { ...json.response, userHandle: null } } })
		const credential = (await call) as { response: { userHandle: unknown; toJSON(): Record<string, unknown> } }
		expect(credential.response.userHandle).toBeNull()
		expect(credential.response.toJSON()).toEqual({ clientDataJSON: b64(1), authenticatorData: b64(2), signature: b64(3) })
	})

	it('rebuilds an attestation with working response methods', async () => {
		const call = credentials.create(createOptions())
		await flush()
		answer(posted[0]!, { type: 'credential', credential: attestationJSON() })
		const credential = (await call) as { response: Record<string, () => unknown>; getClientExtensionResults(): unknown; toJSON(): unknown }
		expect(credential).toBeInstanceOf(PublicKeyCredential)
		expect(credential.response).toBeInstanceOf(AuthenticatorAttestationResponse)
		expect(credential.response.getTransports!()).toEqual(['internal'])
		expect(credential.response.getPublicKeyAlgorithm!()).toBe(-7)
		expect(new Uint8Array(credential.response.getPublicKey!() as ArrayBuffer)).toEqual(new Uint8Array([4]))
		expect(new Uint8Array(credential.response.getAuthenticatorData!() as ArrayBuffer)).toEqual(new Uint8Array([3]))
		expect(new Uint8Array(credential.response.attestationObject as unknown as ArrayBuffer)).toEqual(new Uint8Array([2]))
		expect(credential.getClientExtensionResults()).toEqual({ credProps: { rk: true } })
		expect(credential.toJSON()).toEqual(attestationJSON())
	})

	it('rejects with the named DOMException and falls back on an unknown name', async () => {
		const call = credentials.create(createOptions())
		await flush()
		answer(posted[0]!, { type: 'error', name: 'InvalidStateError' })
		await expect(call).rejects.toMatchObject({ name: 'InvalidStateError' })
		const other = credentials.create(createOptions())
		await flush()
		answer(posted[1]!, { type: 'error', name: 'SecurityError' } as never)
		expect(await other).toBe('browser credential')
	})

	it('on abort rejects with the signal’s reason and tells the relay', async () => {
		const controller = new AbortController()
		const call = credentials.get(getOptions({ signal: controller.signal }))
		await flush()
		ack(posted[0]!)
		await flush()
		const reason = new DOMException('stop', 'AbortError')
		controller.abort(reason)
		expect(await state(call)).toEqual({ error: reason })
		expect(posted[0]!.port.other.posted).toContainEqual({ kind: 'abort', id: '1' })
		// A late result changes nothing.
		answer(posted[0]!, { type: 'credential', credential: assertionJSON() })
		await flush()
		expect(browserGet).not.toHaveBeenCalled()
	})

	it('gives an already aborted call to the browser', async () => {
		const controller = new AbortController()
		controller.abort()
		const options = getOptions({ signal: controller.signal })
		await credentials.get(options)
		expect(browserGet).toHaveBeenCalledExactlyOnceWith(options)
		expect(posted).toEqual([])
	})

	it.each([
		['a plain object', () => ({})],
		['null', () => null],
		['an object with AbortSignal’s prototype', () => Object.create(AbortSignal.prototype) as unknown],
	])('gives a call whose signal is %s to the browser, sending nothing', async (_name, signal) => {
		for (const options of [getOptions({ signal: signal() }), getOptions({ signal: signal(), mediation: 'conditional' }), { ...createOptions(), signal: signal() }]) {
			const method = 'rp' in options.publicKey ? browserCreate : browserGet
			method.mockClear()
			expect(await (method === browserCreate ? credentials.create(options) : credentials.get(options))).toBe('browser credential')
			expect(method).toHaveBeenCalledExactlyOnceWith(options)
		}
		expect(posted).toEqual([])
	})

	it('listens on the signal with the browser’s own methods, whatever the page put on it', async () => {
		const thrower = () => {
			throw new TypeError('page')
		}
		const signal = Object.assign(new AbortController().signal, { addEventListener: thrower, removeEventListener: thrower })
		const call = credentials.get({ publicKey: { challenge: new Uint8Array([1]), timeout: 45_000 }, signal })
		await flush()
		ack(posted[0]!)
		await flush()
		expect(await state(call)).toBe('pending')
		vi.advanceTimersByTime(45_000)
		expect(((await state(call)) as { error: DOMException }).error.name).toBe('NotAllowedError')
		expect(posted[0]!.port.other.posted).toContainEqual({ kind: 'abort', id: '1' })

		const controller = new AbortController()
		const again = credentials.get(getOptions({ signal: Object.assign(controller.signal, { removeEventListener: thrower }) }))
		await flush()
		ack(posted[1]!)
		controller.abort('gone')
		expect(await state(again)).toEqual({ error: 'gone' })
		expect(posted[1]!.port.other.posted).toContainEqual({ kind: 'abort', id: '2' })
	})

	it('rejects with NotAllowedError at the deadline and tells the relay', async () => {
		const call = credentials.get({ publicKey: { challenge: new Uint8Array([1]), timeout: 45_000 } })
		await flush()
		ack(posted[0]!)
		await flush()
		vi.advanceTimersByTime(44_999)
		expect(await state(call)).toBe('pending')
		vi.advanceTimersByTime(1)
		const result = await state(call)
		expect((result as { error: DOMException }).error.name).toBe('NotAllowedError')
		expect(posted[0]!.port.other.posted).toContainEqual({ kind: 'abort', id: '1' })
	})

	describe('conditional request', () => {
		const conditional = (signal?: AbortSignal) => getOptions({ mediation: 'conditional', ...(signal && { signal }) })

		it('resolves with Keepiq’s credential and aborts the browser’s own request', async () => {
			browserGet.mockImplementation((options) => new Promise((_, reject) => {
				const signal = (options as { signal: AbortSignal }).signal
				signal.addEventListener('abort', () => reject(signal.reason))
			}))
			const call = credentials.get(conditional())
			await flush()
			expect(browserGet).toHaveBeenCalledOnce()
			expect(browserGet.mock.calls[0]![0]).toMatchObject({ mediation: 'conditional', publicKey: getOptions().publicKey })
			expect(posted[0]!.message).toMatchObject({ kind: 'get', mediation: 'conditional' })
			ack(posted[0]!)
			answer(posted[0]!, { type: 'credential', credential: assertionJSON() })
			const result = await state(call)
			expect((result as { value: unknown }).value).toBeInstanceOf(PublicKeyCredential)
			expect((browserGet.mock.calls[0]![0] as { signal: AbortSignal }).signal.aborted).toBe(true)
		})

		it('resolves with the browser’s credential and ends the Keepiq ceremony without a reply', async () => {
			let pick!: (value: unknown) => void
			browserGet.mockImplementation(() => new Promise((resolve) => (pick = resolve)))
			const call = credentials.get(conditional())
			await flush()
			ack(posted[0]!)
			await flush()
			pick('browser credential')
			expect(await state(call)).toEqual({ value: 'browser credential' })
			expect(posted[0]!.port.other.posted).toContainEqual({ kind: 'abort', id: '1' })
		})

		it('leaves the browser’s own request alone after a fallback', async () => {
			let pick!: (value: unknown) => void
			browserGet.mockImplementation(() => new Promise((resolve) => (pick = resolve)))
			const call = credentials.get(conditional())
			await flush()
			answer(posted[0]!, { type: 'fallback' })
			await flush()
			expect(await state(call)).toBe('pending')
			pick('browser credential')
			expect(await state(call)).toEqual({ value: 'browser credential' })
			expect(browserGet).toHaveBeenCalledOnce()
		})

		it('rejects both with the signal’s reason on abort', async () => {
			browserGet.mockImplementation((options) => new Promise((_, reject) => {
				const signal = (options as { signal: AbortSignal }).signal
				signal.addEventListener('abort', () => reject(signal.reason))
			}))
			const controller = new AbortController()
			const call = credentials.get(conditional(controller.signal))
			await flush()
			ack(posted[0]!)
			await flush()
			controller.abort('gone')
			expect(await state(call)).toEqual({ error: 'gone' })
			expect(posted[0]!.port.other.posted).toContainEqual({ kind: 'abort', id: '1' })
		})

		it('passes the browser options whose members sit on the prototype, as WebIDL reads them', async () => {
			browserGet.mockImplementation(() => new Promise(() => {}))
			const controller = new AbortController()
			const options = Object.create({ ...conditional(), signal: controller.signal }) as Record<string, unknown>
			void credentials.get(options)
			await flush()
			const passed = browserGet.mock.calls[0]![0] as { publicKey: unknown; mediation: unknown; signal: AbortSignal }
			expect(passed.publicKey).toBe((options as { publicKey: unknown }).publicKey)
			expect(passed.mediation).toBe('conditional')
			expect(passed.signal).not.toBe(controller.signal)
			expect(passed.signal.aborted).toBe(false)
			expect(posted[0]!.message).toMatchObject({ kind: 'get', mediation: 'conditional' })
		})

		it('listens on the signal with the browser’s own method, whatever the page put on it', async () => {
			browserGet.mockImplementation((options) => new Promise((_, reject) => {
				const signal = (options as { signal: AbortSignal }).signal
				signal.addEventListener('abort', () => reject(signal.reason))
			}))
			const controller = new AbortController()
			const signal = Object.assign(controller.signal, {
				addEventListener: () => {
					throw new TypeError('page')
				},
			})
			const call = credentials.get(conditional(signal))
			await flush()
			ack(posted[0]!)
			await flush()
			expect(await state(call)).toBe('pending')
			controller.abort('gone')
			expect(await state(call)).toEqual({ error: 'gone' })
			expect((browserGet.mock.calls[0]![0] as { signal: AbortSignal }).signal.aborted).toBe(true)
		})
	})

	describe('availability', () => {
		it('answers true when the relay says the provider handles this frame', async () => {
			const call = PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable()
			await flush()
			expect(posted[0]!.message).toEqual({ channel: 'keepiq.passkey', kind: 'available', id: '1' })
			answer(posted[0]!, { type: 'available' })
			expect(await call).toBe(true)
		})

		it('answers the browser’s own answer otherwise, or without an ack', async () => {
			const call = PublicKeyCredential.isConditionalMediationAvailable()
			await flush()
			answer(posted[0]!, { type: 'fallback' })
			expect(await call).toBe(false)
			expect(browserConditional).toHaveBeenCalledOnce()
			const silent = PublicKeyCredential.isConditionalMediationAvailable()
			vi.advanceTimersByTime(ACK_TIMEOUT_MS)
			expect(await state(silent)).toEqual({ value: false })
		})

		it('answers the browser’s own answer when the relay acked but stays silent', async () => {
			const call = PublicKeyCredential.isConditionalMediationAvailable()
			await flush()
			ack(posted[0]!)
			await flush()
			vi.advanceTimersByTime(AVAILABLE_TIMEOUT_MS - 1)
			expect(await state(call)).toBe('pending')
			vi.advanceTimersByTime(1)
			expect(await state(call)).toEqual({ value: false })
			expect(browserConditional).toHaveBeenCalledOnce()
		})
	})

	it('installs once: a second copy leaves the first in place', async () => {
		const installed = { create: credentials.create, get: credentials.get, available: PublicKeyCredential.isConditionalMediationAvailable }
		installPasskeyPage(scope)
		expect(credentials.create).toBe(installed.create)
		expect(credentials.get).toBe(installed.get)
		expect(PublicKeyCredential.isConditionalMediationAvailable).toBe(installed.available)
		// The marker gives the page nothing to enumerate.
		expect(Object.keys(credentials.get)).toEqual([])
		const call = credentials.get(getOptions())
		await flush()
		answer(posted[0]!, { type: 'fallback' })
		expect(await state(call)).toEqual({ value: 'browser credential' })
		expect(posted).toHaveLength(1)
	})

	it('replaces only the methods the browser has, leaving a missing one missing', () => {
		const own = { create: browserCreate, get: browserGet }
		const available = vi.fn(async () => false)
		class OlderCredential {
			static isUserVerifyingPlatformAuthenticatorAvailable = available
		}
		installPasskeyPage({ ...scope, navigator: { credentials: own as unknown as CredentialsContainer }, PublicKeyCredential: OlderCredential })
		expect(own.get).not.toBe(browserGet)
		expect(OlderCredential.isUserVerifyingPlatformAuthenticatorAvailable).not.toBe(available)
		expect('isConditionalMediationAvailable' in OlderCredential).toBe(false)
	})

	it('does nothing in a browser without WebAuthn', () => {
		const bare = { create: browserCreate, get: browserGet }
		installPasskeyPage({ ...scope, navigator: { credentials: bare as unknown as CredentialsContainer }, PublicKeyCredential: undefined })
		expect(bare.get).toBe(browserGet)
	})
})
