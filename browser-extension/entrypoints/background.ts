/**
 * Background service worker (Chrome MV3) / background page (Firefox MV2). Owns
 * every account, key and request; the popup only renders what it returns.
 * Listeners are registered synchronously so an MV3 wake re-registers them.
 */

import type { Browser } from 'wxt/browser'
import { setUnauthorizedHandler } from '@/src/api/client'
import { markLoggedOut } from '@/src/accounts/store'
import { handlePopupMessage } from '@/src/background/router'
import { POPUP_PORT, type ContentToBackground, type PopupToBackground } from '@/src/messages'
import { enforce, onIdleStateChanged, onPopupClosed, TIMEOUT_ALARM } from '@/src/vault/timeout'

/** Extension pages only; a content script runs in a page and must not drive accounts. */
function fromExtensionPage(sender: Browser.runtime.MessageSender): boolean {
	return sender.id === browser.runtime.id && !!sender.url?.startsWith(browser.runtime.getURL('/'))
}

export default defineBackground(() => {
	setUnauthorizedHandler((accountId) => markLoggedOut(accountId, true))

	browser.runtime.onMessage.addListener((msg: unknown, sender: Browser.runtime.MessageSender) => {
		const m = msg as ContentToBackground | PopupToBackground
		// Fire-and-forget arms return undefined; ext-autofill handles `page_ready`.
		if (m.kind === 'page_ready') return
		if (!fromExtensionPage(sender)) return
		return handlePopupMessage(m)
	})

	browser.alarms.onAlarm.addListener((alarm) => {
		if (alarm.name === TIMEOUT_ALARM) void enforce()
	})

	browser.idle.onStateChanged.addListener((state) => void onIdleStateChanged(state))

	browser.runtime.onConnect.addListener((port) => {
		if (port.name === POPUP_PORT) port.onDisconnect.addListener(() => void onPopupClosed())
	})
})
