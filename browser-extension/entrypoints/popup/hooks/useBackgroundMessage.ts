import { useEffect, useRef } from 'react'
import type { BackgroundToPopup } from '@/src/messages'

/** Calls `handler` for each broadcast of `kind` while mounted. */
export function useBackgroundMessage<K extends BackgroundToPopup['kind']>(kind: K, handler: (message: Extract<BackgroundToPopup, { kind: K }>) => void): void {
	const latest = useRef(handler)
	useEffect(() => {
		latest.current = handler
	})
	useEffect(() => {
		// Returns nothing: a broadcast expects no reply (WXT-AND-BROWSERS.md § 3).
		const listener = (msg: unknown) => {
			const message = msg as BackgroundToPopup
			if (message?.kind === kind) latest.current(message as Extract<BackgroundToPopup, { kind: K }>)
		}
		browser.runtime.onMessage.addListener(listener)
		return () => browser.runtime.onMessage.removeListener(listener)
	}, [kind])
}
