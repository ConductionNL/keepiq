/**
 * The relay between the page script and the background (design.md § Boundaries), in the isolated world. It acks every
 * call at once and answers `fallback` whenever it cannot pass a call on. A result goes only to the port of the
 * ceremony it names, while the frame is still on the ceremony's origin.
 */
import { PASSKEY_ERRORS, type BackgroundToContent, type ContentToBackground, type PasskeyResult, type RelayToPage } from '@/src/messages'
import { parsePasskeyRequest, PASSKEY_CHANNEL } from './shape'

export interface PasskeyRelayOptions {
	win: Pick<Window, 'addEventListener' | 'location'>
	/** The background's reply, or `null` when the extension context is gone. */
	request: (message: ContentToBackground) => Promise<unknown>
	crossOriginAncestor: () => boolean
}

type Port = Pick<MessagePort, 'postMessage' | 'close' | 'onmessage'>

interface Ceremony {
	port: Port
	/** `null` until `passkey.start` answered. */
	id: string | null
	ended: boolean
}

const FALLBACK: RelayToPage = { type: 'fallback' }

function forPage(result: unknown): RelayToPage {
	const r = result as PasskeyResult | null
	if (r?.type === 'credential') return { type: 'credential', credential: r.credential }
	if (r?.type === 'error' && PASSKEY_ERRORS.includes(r.name)) return { type: 'error', name: r.name }
	return FALLBACK
}

const isPasskeyCall = (data: Record<string, unknown> | null): boolean => typeof data === 'object' && data !== null && data.channel === PASSKEY_CHANNEL

export function createPasskeyRelay({ win, request, crossOriginAncestor }: PasskeyRelayOptions) {
	const live = new Set<Ceremony>()

	const answer = (port: Port, reply: RelayToPage) => {
		port.postMessage(reply)
		port.onmessage = null
		port.close()
	}
	const end = (ceremony: Ceremony, reply?: RelayToPage) => {
		if (ceremony.ended) return
		ceremony.ended = true
		live.delete(ceremony)
		if (reply) answer(ceremony.port, reply)
		else ceremony.port.close()
	}
	/** Ends the ceremony without answering; one whose id is still coming is aborted once it arrives. */
	const abort = (ceremony: Ceremony) => {
		end(ceremony)
		if (ceremony.id !== null) void request({ kind: 'passkey.abort', ceremonyId: ceremony.id })
	}

	async function answerAvailable(port: Port): Promise<void> {
		const available = await request({ kind: 'passkey.available', crossOriginAncestor: crossOriginAncestor() })
		return answer(port, available === true ? { type: 'available' } : FALLBACK)
	}

	/** An aborted or ended ceremony drops the id the background sent; a missing one is a fallback. */
	function settleStart(ceremony: Ceremony, id: string | null): void {
		if (ceremony.ended) {
			if (id !== null) void request({ kind: 'passkey.abort', ceremonyId: id })
		} else if (id === null) end(ceremony, FALLBACK)
		else ceremony.id = id
	}

	async function relay(port: Port, data: Record<string, unknown>): Promise<void> {
		if (data.kind === 'available') return answerAvailable(port)
		const parsed = parsePasskeyRequest({ type: data.kind, options: data.options, mediation: data.mediation })
		if (!parsed) return answer(port, FALLBACK)
		const ceremony: Ceremony = { port, id: null, ended: false }
		live.add(ceremony)
		port.onmessage = (event) => {
			if ((event.data as { kind?: unknown } | null)?.kind === 'abort') abort(ceremony)
		}
		const reply = await request({ kind: 'passkey.start', request: parsed, crossOriginAncestor: crossOriginAncestor() }) as { ceremonyId?: unknown } | null
		settleStart(ceremony, typeof reply?.ceremonyId === 'string' ? reply.ceremonyId : null)
	}

	/** The port and request of a call from this frame's own scripts on the passkey channel, acknowledged; null for any other. */
	function acceptedCall(event: MessageEvent): { port: Port; data: Record<string, unknown> } | null {
		try {
			// Only this frame's own scripts: another window holding a port must not start a ceremony as this frame.
			if (event.source !== win) return null
			const data = event.data as Record<string, unknown>
			if (!isPasskeyCall(data)) return null
			const port = event.ports[0]
			if (!port) return null
			port.postMessage({ type: 'ack' } satisfies RelayToPage)
			return { port, data }
		} catch {
			return null
		}
	}

	win.addEventListener('message', (event: MessageEvent) => {
		const call = acceptedCall(event)
		if (!call) return
		const { port, data } = call
		void relay(port, data).catch(() => {
			const ceremony = [...live].find((c) => c.port === port)
			if (ceremony) end(ceremony, FALLBACK)
			else answer(port!, FALLBACK)
		})
	})

	win.addEventListener('pagehide', () => {
		for (const ceremony of [...live]) abort(ceremony)
	})

	return {
		/** A `passkey.result` from the background; anything else is ignored. */
		handle(message: unknown): void {
			const m = message as BackgroundToContent | null
			if (m?.kind !== 'passkey.result' || m.origin !== win.location.origin) return
			const ceremony = [...live].find((c) => c.id !== null && c.id === m.ceremonyId)
			if (ceremony) end(ceremony, forPage(m.result))
		},
	}
}
