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

	async function relay(port: Port, data: Record<string, unknown>): Promise<void> {
		if (data.kind === 'available') {
			const available = await request({ kind: 'passkey.available', crossOriginAncestor: crossOriginAncestor() })
			return answer(port, available === true ? { type: 'available' } : FALLBACK)
		}
		const parsed = parsePasskeyRequest({ type: data.kind, options: data.options, mediation: data.mediation })
		if (!parsed) return answer(port, FALLBACK)
		const ceremony: Ceremony = { port, id: null, ended: false }
		live.add(ceremony)
		port.onmessage = (event) => {
			if ((event.data as { kind?: unknown } | null)?.kind === 'abort') abort(ceremony)
		}
		const reply = await request({ kind: 'passkey.start', request: parsed, crossOriginAncestor: crossOriginAncestor() }) as { ceremonyId?: unknown } | null
		const id = typeof reply?.ceremonyId === 'string' ? reply.ceremonyId : null
		if (ceremony.ended) {
			if (id !== null) void request({ kind: 'passkey.abort', ceremonyId: id })
		} else if (id === null) end(ceremony, FALLBACK)
		else ceremony.id = id
	}

	win.addEventListener('message', (event: MessageEvent) => {
		let port: Port | undefined
		let data: Record<string, unknown>
		try {
			// Only this frame's own scripts: another window holding a port must not start a ceremony as this frame.
			if (event.source !== win) return
			data = event.data as Record<string, unknown>
			if (typeof data !== 'object' || data === null || data.channel !== PASSKEY_CHANNEL) return
			port = event.ports[0]
			if (!port) return
			port.postMessage({ type: 'ack' } satisfies RelayToPage)
		} catch {
			return
		}
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
