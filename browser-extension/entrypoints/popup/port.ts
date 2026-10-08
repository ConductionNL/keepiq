import { POPUP_PORT } from '@/src/messages'

/** A port dropped this soon after connecting is a refusal, not a worker restart. */
const QUICK_DROP_MS = 1_000
const MAX_QUICK_DROPS = 3

/**
 * Held open for the popup's lifetime; its disconnect is the "Immediately" timeout.
 * Chrome may stop the background worker while the popup sits idle, which drops the port
 * and the worker's count of open popups, so the popup connects again.
 */
export function holdPopupPort(quickDrops = 0, now = () => Date.now()): void {
	let port: ReturnType<typeof browser.runtime.connect>
	try {
		port = browser.runtime.connect({ name: POPUP_PORT })
	} catch {
		// The extension was reloaded; this popup is orphaned and has nothing to hold.
		return
	}
	const connectedAt = now()
	port.onDisconnect.addListener(() => {
		const drops = now() - connectedAt < QUICK_DROP_MS ? quickDrops + 1 : 0
		if (drops < MAX_QUICK_DROPS) holdPopupPort(drops, now)
	})
}
