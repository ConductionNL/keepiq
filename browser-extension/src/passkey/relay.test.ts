// @vitest-environment happy-dom
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
// Here, not beside the entrypoint: WXT reads every top-level file in entrypoints/ as one.
import relayScript from '@/entrypoints/passkey.content'
import { toBase64Url } from '@/src/crypto/base64'
import type { ContentToBackground, PasskeyCredentialJSON } from '@/src/messages'
import { FakeMessageChannel, flush, type FakePort } from '@/src/testing/message-channel'
import { createPasskeyRelay } from './relay'

const ORIGIN = 'https://example.test'
const b64 = (length: number) => toBase64Url(new Uint8Array(length).fill(7))

const getRequest = (options: Record<string, unknown> = {}, extra: Record<string, unknown> = {}) =>
	({ channel: 'keepiq.passkey', kind: 'get', id: '1', options: { challenge: b64(32), allowCredentials: [], ...options }, ...extra })
const createRequest = (options: Record<string, unknown> = {}) => ({
	channel: 'keepiq.passkey', kind: 'create', id: '1',
	options: {
		rp: { name: 'Example' }, user: { id: b64(16), name: 'alice', displayName: 'Alice' }, challenge: b64(32),
		pubKeyCredParams: [{ type: 'public-key', alg: -7 }], excludeCredentials: [], ...options,
	},
})

const credential: PasskeyCredentialJSON = {
	id: 'AQ', rawId: 'AQ', type: 'public-key', authenticatorAttachment: 'platform', clientExtensionResults: {},
	response: { clientDataJSON: 'AQ', authenticatorData: 'Ag', signature: 'Aw', userHandle: null },
}

/** The page script's end of one call: what the relay answered on it. */
interface PageEnd {
	port: FakePort
	replies: unknown[]
}

type Win = EventTarget & { location: { origin: string } }

let win: Win
let request: Mock<(message: ContentToBackground) => Promise<unknown>>
let relay: ReturnType<typeof createPasskeyRelay>
let ancestor: boolean

/** Posts `data` with a fresh port as `source` would; `source` defaults to the relay's own window. */
function post(data: unknown, source: unknown = win): PageEnd {
	const channel = new FakeMessageChannel()
	const replies: unknown[] = []
	channel.port1.onmessage = (event) => replies.push(event.data)
	win.dispatchEvent(Object.assign(new Event('message'), { data, source, ports: [channel.port2] }))
	return { port: channel.port1, replies }
}

const started = () => request.mock.calls.map(([message]) => message).filter((message) => message.kind === 'passkey.start')
const aborts = () => request.mock.calls.map(([message]) => message).filter((message) => message.kind === 'passkey.abort')

beforeEach(() => {
	win = Object.assign(new EventTarget(), { location: { origin: ORIGIN } })
	request = vi.fn(async (message: ContentToBackground) => (message.kind === 'passkey.start' ? { ceremonyId: 'c1' } : null))
	ancestor = false
	relay = createPasskeyRelay({ win: win as unknown as Window, request, crossOriginAncestor: () => ancestor })
})

describe('passkey relay', () => {
	it('acks at once and starts the ceremony with the parsed request and the ancestor flag only', async () => {
		ancestor = true
		const page = post(getRequest({ rpId: 'example.test', junk: 'dropped' }, { mediation: 'conditional', origin: 'https://evil.example' }))
		expect(page.port.other.posted).toEqual([{ type: 'ack' }])
		await flush()
		expect(started()).toEqual([{
			kind: 'passkey.start',
			request: { type: 'get', options: { challenge: b64(32), rpId: 'example.test', allowCredentials: [] }, mediation: 'conditional' },
			crossOriginAncestor: true,
		}])
		expect(page.replies).toEqual([{ type: 'ack' }])
	})

	it('delivers a result only for its own ceremony and while the frame is on the ceremony’s origin', async () => {
		const page = post(getRequest())
		await flush()
		relay.handle({ kind: 'passkey.result', ceremonyId: 'c2', origin: ORIGIN, result: { type: 'credential', credential } })
		relay.handle({ kind: 'passkey.result', ceremonyId: 'c1', origin: 'https://other.test', result: { type: 'credential', credential } })
		await flush()
		expect(page.replies).toEqual([{ type: 'ack' }])
		relay.handle({ kind: 'passkey.result', ceremonyId: 'c1', origin: ORIGIN, result: { type: 'credential', credential } })
		await flush()
		expect(page.replies).toEqual([{ type: 'ack' }, { type: 'credential', credential }])
		expect(page.port.other.closed).toBe(true)
	})

	it('passes on a known DOMException name and turns anything else into fallback', async () => {
		const first = post(getRequest())
		await flush()
		relay.handle({ kind: 'passkey.result', ceremonyId: 'c1', origin: ORIGIN, result: { type: 'error', name: 'InvalidStateError' } })
		request.mockResolvedValueOnce({ ceremonyId: 'c3' })
		const second = post(getRequest())
		await flush()
		relay.handle({ kind: 'passkey.result', ceremonyId: 'c3', origin: ORIGIN, result: { type: 'error', name: 'SecurityError' } })
		await flush()
		expect(first.replies.at(-1)).toEqual({ type: 'error', name: 'InvalidStateError' })
		expect(second.replies.at(-1)).toEqual({ type: 'fallback' })
	})

	it.each([
		['a user.id over 64 bytes', createRequest({ user: { id: b64(65), name: 'alice', displayName: 'Alice' } })],
		['an empty user.id', createRequest({ user: { id: '', name: 'alice', displayName: 'Alice' } })],
		['a challenge over 1024 bytes', getRequest({ challenge: b64(1025) })],
		['a credential id over 1024 bytes', getRequest({ allowCredentials: [{ type: 'public-key', id: b64(1025) }] })],
		['more than 64 descriptors', getRequest({ allowCredentials: Array.from({ length: 65 }, () => ({ type: 'public-key', id: b64(16) })) })],
		['a string over 1024 characters', getRequest({ rpId: 'a'.repeat(1025) })],
		['a challenge that is not base64url', getRequest({ challenge: 'a+b/' })],
		['a non-integer algorithm', createRequest({ pubKeyCredParams: [{ type: 'public-key', alg: -7.5 }] })],
		['options that are not an object', { channel: 'keepiq.passkey', kind: 'get', id: '1', options: 'x' }],
		['an unknown kind', { channel: 'keepiq.passkey', kind: 'store', id: '1', options: {} }],
		['a silent request', getRequest({}, { mediation: 'silent' })],
		['a conditional create', { ...createRequest(), mediation: 'conditional' }],
	])('answers fallback to %s, sending the background nothing', async (_, data) => {
		const page = post(data)
		await flush()
		expect(page.replies).toEqual([{ type: 'ack' }, { type: 'fallback' }])
		expect(request).not.toHaveBeenCalled()
	})

	it('accepts the limits themselves', async () => {
		const page = post(createRequest({
			user: { id: b64(64), name: 'a'.repeat(1024), displayName: 'Alice' },
			challenge: b64(1024),
			excludeCredentials: Array.from({ length: 64 }, () => ({ type: 'public-key', id: b64(1024) })),
		}))
		await flush()
		expect(started()).toHaveLength(1)
		expect(page.replies).toEqual([{ type: 'ack' }])
	})

	it('answers fallback when the background does', async () => {
		request.mockResolvedValueOnce({ fallback: true })
		const page = post(getRequest())
		await flush()
		expect(page.replies).toEqual([{ type: 'ack' }, { type: 'fallback' }])
	})

	it('ignores a message from another window, or without the channel or a port', async () => {
		const other = post(getRequest(), {})
		const unmarked = post({ ...getRequest(), channel: 'other' })
		win.dispatchEvent(Object.assign(new Event('message'), { data: getRequest(), source: win, ports: [] }))
		await flush()
		expect(other.replies).toEqual([])
		expect(unmarked.replies).toEqual([])
		expect(request).not.toHaveBeenCalled()
	})

	it('sends passkey.abort when the page aborts, also when the ceremony id arrives later', async () => {
		const page = post(getRequest())
		await flush()
		page.port.postMessage({ kind: 'abort', id: '1' })
		await flush()
		expect(aborts()).toEqual([{ kind: 'passkey.abort', ceremonyId: 'c1' }])
		let answerStart!: (reply: unknown) => void
		request.mockImplementationOnce(() => new Promise<unknown>((resolve) => (answerStart = resolve)))
		const early = post(getRequest())
		early.port.postMessage({ kind: 'abort', id: '1' })
		await flush()
		answerStart({ ceremonyId: 'c9' })
		await flush()
		expect(aborts()).toEqual([{ kind: 'passkey.abort', ceremonyId: 'c1' }, { kind: 'passkey.abort', ceremonyId: 'c9' }])
		relay.handle({ kind: 'passkey.result', ceremonyId: 'c9', origin: ORIGIN, result: { type: 'credential', credential } })
		await flush()
		expect(early.replies).toEqual([{ type: 'ack' }])
	})

	it('aborts a ceremony whose abort and close arrive before the relay sees the window message', async () => {
		const channel = new FakeMessageChannel()
		channel.port1.postMessage({ kind: 'abort', id: '1' })
		channel.port1.close()
		win.dispatchEvent(Object.assign(new Event('message'), { data: getRequest(), source: win, ports: [channel.port2] }))
		await flush()
		expect(started()).toHaveLength(1)
		expect(aborts()).toEqual([{ kind: 'passkey.abort', ceremonyId: 'c1' }])
		relay.handle({ kind: 'passkey.result', ceremonyId: 'c1', origin: ORIGIN, result: { type: 'credential', credential } })
		await flush()
		expect(channel.port2.posted).toEqual([{ type: 'ack' }])
	})

	it('aborts every ceremony in flight on pagehide', async () => {
		post(getRequest())
		await flush()
		win.dispatchEvent(new Event('pagehide'))
		expect(aborts()).toEqual([{ kind: 'passkey.abort', ceremonyId: 'c1' }])
	})

	it('answers available only when the background says the provider handles this frame', async () => {
		request.mockResolvedValueOnce(true)
		const yes = post({ channel: 'keepiq.passkey', kind: 'available', id: '1' })
		await flush()
		request.mockResolvedValueOnce('true')
		const no = post({ channel: 'keepiq.passkey', kind: 'available', id: '2' })
		await flush()
		expect(request.mock.calls[0]![0]).toEqual({ kind: 'passkey.available', crossOriginAncestor: false })
		expect(yes.replies).toEqual([{ type: 'ack' }, { type: 'available' }])
		expect(no.replies).toEqual([{ type: 'ack' }, { type: 'fallback' }])
	})

	it('answers fallback when passing the call on throws', async () => {
		win = Object.assign(new EventTarget(), { location: { origin: ORIGIN } })
		createPasskeyRelay({ win: win as unknown as Window, request, crossOriginAncestor: () => {
			throw new Error('frame gone')
		} })
		const page = post(getRequest())
		await flush()
		expect(page.replies).toEqual([{ type: 'ack' }, { type: 'fallback' }])
	})
})

describe('passkey relay entrypoint', () => {
	type MessageHandler = (event: unknown) => void

	/** Runs the content script, returning its window message handler and its runtime listener. */
	function start() {
		const addEventListener = vi.spyOn(window, 'addEventListener')
		const addListener = vi.spyOn(browser.runtime.onMessage, 'addListener')
		relayScript.main({} as never)
		const handler = addEventListener.mock.calls.find(([type]) => type === 'message')![1] as MessageHandler
		const listener = addListener.mock.calls[0]![0] as (message: unknown, sender: object) => unknown
		return {
			call(data: unknown): PageEnd {
				const channel = new FakeMessageChannel()
				const replies: unknown[] = []
				channel.port1.onmessage = (event) => replies.push(event.data)
				handler({ data, source: window, ports: [channel.port2] })
				return { port: channel.port1, replies }
			},
			listener,
		}
	}

	beforeEach(() => {
		fakeBrowser.reset()
	})

	it('runs in every frame of every http(s) page at document_start, in the isolated world', () => {
		expect(relayScript).toMatchObject({ matches: ['*://*/*'], allFrames: true, runAt: 'document_start' })
		expect((relayScript as { world?: string }).world).toBeUndefined()
	})

	it('answers fallback when the extension context is gone', async () => {
		const script = start()
		const id = vi.spyOn(browser.runtime, 'id', 'get').mockReturnValue(undefined as never)
		const sendMessage = vi.spyOn(browser.runtime, 'sendMessage')
		const page = script.call(getRequest())
		await flush()
		expect(page.replies).toEqual([{ type: 'ack' }, { type: 'fallback' }])
		expect(sendMessage).not.toHaveBeenCalled()
		id.mockRestore()
	})

	it('answers fallback when sending throws, as an invalidated context does', async () => {
		const script = start()
		vi.spyOn(browser.runtime, 'sendMessage').mockImplementation(() => {
			throw new Error('Extension context invalidated.')
		})
		const page = script.call(getRequest())
		await flush()
		expect(page.replies).toEqual([{ type: 'ack' }, { type: 'fallback' }])
	})

	it('delivers the background’s result for the frame’s ceremony, from this extension only', async () => {
		const script = start()
		vi.spyOn(browser.runtime, 'sendMessage').mockResolvedValue({ ceremonyId: 'c1' } as never)
		const page = script.call(getRequest())
		await flush()
		const result = { kind: 'passkey.result', ceremonyId: 'c1', origin: location.origin, result: { type: 'credential', credential } }
		expect(script.listener(result, { id: 'other-extension' })).toBeUndefined()
		await flush()
		expect(page.replies).toEqual([{ type: 'ack' }])
		expect(script.listener(result, { id: browser.runtime.id })).toBeUndefined()
		await flush()
		expect(page.replies).toEqual([{ type: 'ack' }, { type: 'credential', credential }])
	})
})
