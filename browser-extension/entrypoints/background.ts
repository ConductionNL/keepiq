/**
 * Background service worker (Chrome MV3) / background page (Firefox MV2). Owns
 * every account, key and request; the popup only renders what it returns.
 * Listeners are registered synchronously so an MV3 wake re-registers them.
 */

import type { Browser } from 'wxt/browser'
import { setUnauthorizedHandler } from '@/src/api/client'
import { markLoggedOut } from '@/src/accounts/store'
import { handlePopupRequest, syncOnAlarm } from '@/src/background/requests'
import { handlePopupMessage } from '@/src/background/router'
import { CLIPBOARD_ALARM, clearClipboard } from '@/src/clipboard'
import { POPUP_PORT, POPUP_REQUEST_KINDS, type ContentToBackground, type PopupRequest, type PopupToBackground } from '@/src/messages'
import { SYNC_ALARM } from '@/src/vault/sync'
import { enforce, onIdleStateChanged, onPopupClosed, onPopupOpened, syncAlarm, TIMEOUT_ALARM } from '@/src/vault/timeout'

/** Extension pages only; a content script runs in a page and must not drive accounts. */
function fromExtensionPage(sender: Browser.runtime.MessageSender): boolean {
	return sender.id === browser.runtime.id && !!sender.url?.startsWith(browser.runtime.getURL('/'))
}

export default defineBackground(() => {
	setUnauthorizedHandler((accountId) => markLoggedOut(accountId, true))

	browser.runtime.onMessage.addListener((msg: unknown, sender: Browser.runtime.MessageSender, sendResponse: (reply: unknown) => void) => {
		const m = msg as ContentToBackground | PopupToBackground | PopupRequest
		// Fire-and-forget arms return undefined; ext-autofill handles `page_ready`.
		if (m.kind === 'page_ready') return
		if (!fromExtensionPage(sender)) return
		if (POPUP_REQUEST_KINDS.has(m.kind)) void handlePopupRequest(m as PopupRequest).then(sendResponse)
		else void handlePopupMessage(m as PopupToBackground).then(sendResponse)
		return true
	})

	browser.alarms.onAlarm.addListener((alarm) => {
		// Also drops the alarms when nothing is unlocked any more, e.g. after a browser restart.
		if (alarm.name === TIMEOUT_ALARM) void enforce().then(syncAlarm)
		if (alarm.name === SYNC_ALARM) void syncOnAlarm()
		if (alarm.name === CLIPBOARD_ALARM) void clearClipboard()
	})

	browser.idle.onStateChanged.addListener((state) => void onIdleStateChanged(state))

	browser.runtime.onConnect.addListener((port) => {
		if (port.name !== POPUP_PORT) return
		onPopupOpened()
		port.onDisconnect.addListener(() => void onPopupClosed())
	})
})
