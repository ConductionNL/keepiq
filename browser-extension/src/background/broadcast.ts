import type { BackgroundToPopup } from '@/src/messages'

/** The background's own listeners; `runtime.sendMessage` never reaches the context that sent it. */
const local = new Set<(message: BackgroundToPopup) => void>()

export function onBroadcast(listener: (message: BackgroundToPopup) => void): void {
	local.add(listener)
}

/** Tells an open popup; with none open the send rejects, which is fine. */
export function broadcast(message: BackgroundToPopup): void {
	for (const listener of local) listener(message)
	try {
		browser.runtime.sendMessage(message).catch(() => {})
	} catch {
		// Thrown synchronously when no extension page can receive it.
	}
}
