/**
 * Background service worker — the extension's trust core (browser-extension-
 * autofill §"Extension architecture"). It is the ONLY place vault CryptoKeys
 * live (in memory, never persisted); the popup and content scripts are
 * untrusted UIs that message it. The handlers live in `router.js`.
 *
 * Auto-lock: per-account idle timers (in the vault), every account on OS or
 * browser lock, and worker termination clears memory for free.
 */

import { migrateLegacyConfig } from '../lib/api.js'
import { handleMessage, handles, onIdleState } from './router.js'

// An extension paired before several accounts keeps its pairing as the first
// account (extension-account-switching). Messages wait for it.
const ready = migrateLegacyConfig().catch(() => {})

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
	if (!handles(msg?.type)) return false
	ready
		.then(() => handleMessage(msg, sender))
		.then((result) => sendResponse(result))
	return true // async response
})

// Auto-lock every account on OS/browser lock.
if (chrome.idle && chrome.idle.onStateChanged) {
	chrome.idle.onStateChanged.addListener(onIdleState)
}
