/**
 * Content script, in every frame. It holds no vault state: it fills the one credential the
 * background sends after the user picked it. It runs on every page, as in Bitwarden; CLAUDE.md says why that is safe.
 */

import type { Browser } from 'wxt/browser'
import { i18n } from '#i18n'
import { executeFill } from '@/src/autofill/execute'
import { findLoginFields } from '@/src/autofill/fields'
import { createCaptureCollector } from '@/src/autofill/capture-collector'
import { createInlineHost } from '@/src/autofill/inline-host'
import { createNotificationHost } from '@/src/autofill/notification-host'
import type { BackgroundToContent, ContentToBackground } from '@/src/messages'

export default defineContentScript({
	matches: ['*://*/*'],
	allFrames: true,
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

		const request = async (msg: ContentToBackground): Promise<unknown> => {
			if (contextInvalidated || !browser.runtime?.id) return null
			try {
				return await browser.runtime.sendMessage(msg)
			} catch {
				return null
			}
		}
		const firefox = import.meta.env.BROWSER === 'firefox'
		const inline = createInlineHost({ request, send, firefox })
		const top = window === window.top
		const bar = top ? createNotificationHost({ firefox, send }) : null

		browser.runtime.onMessage.addListener((msg: unknown, sender: Browser.runtime.MessageSender, sendResponse: (reply: unknown) => void) => {
			if (sender.id !== browser.runtime.id) return
			const m = msg as BackgroundToContent | null
			if (m?.kind === 'fill.confirmInsecure') {
				const host = typeof m.itemHost === 'string' ? m.itemHost : ''
				sendResponse(window.confirm(host ? i18n.t('pageFill.insecure', { host }) : i18n.t('pageFill.insecureNoHost')))
			} else if (m?.kind === 'fill.execute') sendResponse(executeFill(m, inline.openList()))
			else if (m?.kind === 'inline.verify') sendResponse(inline.handle(m) === true)
			else if (m?.kind === 'inline.ready' || m?.kind === 'inline.refocus' || m?.kind === 'inline.close' || m?.kind === 'inline.resize') inline.handle(m)
			else if (m?.kind === 'capture.verify') sendResponse(bar?.handle(m) === true)
			else if (m?.kind === 'capture.show') sendResponse(bar?.handle(m))
			else if (m?.kind === 'capture.hide' || m?.kind === 'capture.focus' || m?.kind === 'capture.expand') bar?.handle(m)
		})

		const announce = () => {
			// Only the top frame's answer is read, for fill on page load.
			const passwordField = top && findLoginFields(document).some((group) => group.currentPassword !== null)
			send({ kind: 'page_ready', url: location.href, passwordField })
		}
		const begin = () => {
			announce()
			inline.start()
			createCaptureCollector(send)
		}
		// A prerendered page is not shown yet, so it must not be filled on load or show a list before the user sees it.
		if ((document as { prerendering?: boolean }).prerendering === true) document.addEventListener('prerenderingchange', begin, { once: true })
		else begin()
		// The tab forgot its frames when it navigated away. A page restored from the back/forward cache
		// registers again, but going Back is not a page load, so it is not filled again.
		window.addEventListener('pageshow', (event) => {
			if (event.persisted) send({ kind: 'page_ready', url: location.href, passwordField: false })
		})
	},
})
