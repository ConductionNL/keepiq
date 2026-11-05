/**
 * The passkey relay (src/passkey/relay.ts), in every frame at `document_start`, so it listens before the page calls.
 * It holds no vault state; on Firefox it also injects the page script, which Chrome runs as a `MAIN` world script.
 */

import type { Browser } from 'wxt/browser'
import { sameOriginAncestors } from '@/src/autofill/inline-host'
import type { ContentToBackground } from '@/src/messages'
import { createPasskeyRelay } from '@/src/passkey/relay'

export default defineContentScript({
	matches: ['*://*/*'],
	allFrames: true,
	runAt: 'document_start',
	main() {
		// A lost extension context throws or rejects; either way the page gets `fallback`.
		const request = async (message: ContentToBackground): Promise<unknown> => {
			if (!browser.runtime?.id) return null
			try {
				return await browser.runtime.sendMessage(message)
			} catch {
				return null
			}
		}
		const relay = createPasskeyRelay({ win: window, request, crossOriginAncestor: () => sameOriginAncestors() === null })
		browser.runtime.onMessage.addListener((msg: unknown, sender: Browser.runtime.MessageSender) => {
			if (sender.id === browser.runtime.id) relay.handle(msg)
		})
		if (import.meta.env.BROWSER === 'firefox') void injectScript('/passkey-page.js').catch(() => {})
	},
})
