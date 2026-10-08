/**
 * Content script. Announces itself to the background; ext-autofill adds the rest.
 * It runs on every page, as in Bitwarden; CLAUDE.md says why that is safe.
 */

import type { ContentToBackground } from '@/src/messages'

export default defineContentScript({
	matches: ['*://*/*'],
	runAt: 'document_idle',
	main() {
		// Content scripts outlive the extension that injected them (any reload,
		// which in dev is constant). Once the worker is gone every send throws
		// synchronously, so latch after the first failure instead of flooding the
		// page console with "Extension context invalidated".
		let contextInvalidated = false
		const send = (msg: ContentToBackground) => {
			if (contextInvalidated) return
			// `browser.runtime.id` goes undefined the moment the worker dies —
			// the cheapest liveness probe, and it beats the throw.
			if (!browser.runtime?.id) {
				contextInvalidated = true
				return
			}
			try {
				void browser.runtime.sendMessage(msg).catch(() => {
					// An MV3 worker asleep with no listener registered drops the
					// message; the next event wakes it.
				})
			} catch {
				// Invalidated mid-flight throws synchronously, so the .catch()
				// above never sees it.
				contextInvalidated = true
			}
		}

		send({ kind: 'page_ready', url: location.href })
	},
})
