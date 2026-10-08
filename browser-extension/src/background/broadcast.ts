import type { BackgroundToPopup } from '@/src/messages'

/** Tells an open popup; with none open the send rejects, which is fine. */
export function broadcast(message: BackgroundToPopup): void {
	try {
		browser.runtime.sendMessage(message).catch(() => {})
	} catch {
		// Thrown synchronously when no extension page can receive it.
	}
}
