/**
 * The offscreen document that clears the clipboard for the worker in
 * Chromium, where a service worker has no clipboard (clients-extension-gaps).
 *
 * @spec openspec/specs/extension-clipboard/spec.md#requirement-every-copy-is-cleared-after-a-delay-the-user-sets
 */
import { clearWithDocument } from '../background/clipboard-clear.js'

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
	if (msg?.type !== 'offscreen-clear-clipboard') return false
	// Only the extension itself, never a page's content script.
	if (sender?.id !== chrome.runtime.id || sender?.tab) return false
	sendResponse({ cleared: clearWithDocument(document) })
	return false
})
