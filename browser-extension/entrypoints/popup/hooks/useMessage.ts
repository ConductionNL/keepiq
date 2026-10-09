import type { PopupReplies, PopupRequest, PopupToBackground, Result } from '@/src/messages'

/** A reply from the background we can trust; a stale or missing listener answers `undefined`. */
function isResult(reply: unknown): reply is Result {
	if (typeof reply !== 'object' || reply === null) return false
	const r = reply as Record<string, unknown>
	if (r.ok === true) return typeof r.state === 'object' && r.state !== null && typeof (r.state as { screen?: unknown }).screen === 'string'
	return r.ok === false && typeof r.code === 'string'
}

/** The popup's only way to the background, and its only `unknown` cast. */
export async function sendMessage(message: PopupToBackground): Promise<Result> {
	try {
		const reply: unknown = await browser.runtime.sendMessage(message)
		if (isResult(reply)) return reply
		console.error('[keepiq] unexpected reply to', message.kind, reply)
	} catch (error) {
		console.error('[keepiq] no reply to', message.kind, error)
	}
	return { ok: false, code: 'not_responding' }
}

/** For requests with their own reply shape; `undefined` means the background did not answer. */
export async function request<K extends PopupRequest['kind']>(message: Extract<PopupRequest, { kind: K }>): Promise<PopupReplies[K] | undefined> {
	try {
		const reply: unknown = await browser.runtime.sendMessage(message)
		if (reply !== undefined) return reply as PopupReplies[K]
		console.error('[keepiq] no reply to', message.kind)
	} catch (error) {
		console.error('[keepiq] no reply to', message.kind, error)
	}
	return undefined
}
