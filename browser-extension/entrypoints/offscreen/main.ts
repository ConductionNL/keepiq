// Chrome's background is a worker with no document; this page clears the clipboard for it.
import { overwriteClipboard } from '@/src/clipboard'
import type { BackgroundToOffscreen } from '@/src/messages'

browser.runtime.onMessage.addListener((msg: unknown, _sender, sendResponse: (reply: unknown) => void) => {
	if ((msg as BackgroundToOffscreen).kind !== 'offscreen.clearClipboard') return
	sendResponse(overwriteClipboard())
	return true
})
