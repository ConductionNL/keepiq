// Chrome's background is a worker with no document; this page writes and clears the clipboard for it.
import type { Browser } from 'wxt/browser'
import { writeClipboard } from '@/src/clipboard'
import type { BackgroundToOffscreen } from '@/src/messages'

browser.runtime.onMessage.addListener((msg: unknown, sender: Browser.runtime.MessageSender, sendResponse: (reply: unknown) => void) => {
	// Every extension view and content script reaches this listener; only the worker (WXT's `background.js`) may set the clipboard.
	if (sender.id !== browser.runtime.id || sender.tab || sender.url !== browser.runtime.getURL('/background.js')) return
	const m = msg as BackgroundToOffscreen | null
	if (m?.kind === 'offscreen.clearClipboard') sendResponse(writeClipboard(''))
	else if (m?.kind === 'offscreen.writeClipboard' && typeof m.text === 'string') sendResponse(writeClipboard(m.text))
	else return
	return true
})
