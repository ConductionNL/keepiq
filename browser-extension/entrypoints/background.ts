/**
 * Background service worker (Chrome MV3) / background page (Firefox MV2). Owns
 * every account, key and request; the popup only renders what it returns.
 * Listeners are registered synchronously so an MV3 wake re-registers them.
 */

import type { Browser } from 'wxt/browser'
import { setUnauthorizedHandler } from '@/src/api/client'
import { markLoggedOut } from '@/src/accounts/store'
import { captureSubmitted, dropAllCaptures, focusBar, handleNotification, registerCaptureQueue, reshow, showPending } from '@/src/autofill/capture-queue'
import { registerDefaultManager } from '@/src/autofill/default-manager'
import { handleFillRequest } from '@/src/autofill/fill'
import { forgetTab, recordFrame } from '@/src/autofill/frames'
import { closeAllSessions, closedByHost, handleInlineList, openSession, registerInlineSessions } from '@/src/autofill/inline-session'
import { fillFromShortcut, fillOnPageLoad } from '@/src/autofill/triggers'
import { handlePopupRequest, syncOnAlarm, type HandledRequest } from '@/src/background/requests'
import { handlePopupMessage } from '@/src/background/router'
import { acceptedFrom, senderPage } from '@/src/background/sender'
import { CLIPBOARD_ALARM, clearClipboard } from '@/src/clipboard'
import { rebuildMenus, registerMenus } from '@/src/menus'
import { POPUP_PORT, POPUP_REQUEST_KINDS, type ContentToBackground, type FillRequest, type InlineListToBackground, type NotificationToBackground, type PopupToBackground } from '@/src/messages'
import { SYNC_ALARM } from '@/src/vault/sync'
import { enforce, onIdleStateChanged, onPopupClosed, onPopupOpened, syncAlarm, TIMEOUT_ALARM } from '@/src/vault/timeout'

/** Popup kinds that change what the context menu shows; locks, logouts and syncs reach it by broadcast. */
const MENU_CHANGES = new Set(['accounts.add', 'accounts.reauthenticate', 'accounts.switch', 'vault.unlock', 'account.settings.set', 'fill.request'])
/** Popup kinds that change the active account, which closes every inline list and drops every capture; locks and logouts do so by broadcast. */
const ACCOUNT_CHANGES = new Set(['accounts.add', 'accounts.switch'])

/** Tab, frame and URL from `sender`, which `senderPage` checked; never from the message. */
function onPageReady(msg: Extract<ContentToBackground, { kind: 'page_ready' }>, sender: Browser.runtime.MessageSender): void {
	const tabId = sender.tab!.id!
	recordFrame(tabId, sender.frameId!, sender.url!)
	// The top frame's script may start after the tab's `complete`, which then found no one to show the bar.
	if (sender.frameId === 0) void showPending(tabId).catch(() => {})
	if (sender.frameId === 0 && msg.passwordField === true) {
		void fillOnPageLoad(tabId, sender.url!).then(rebuildMenus).catch((error: unknown) => console.error('[keepiq] fill on page load', error))
	}
}

export default defineBackground(() => {
	setUnauthorizedHandler((accountId) => markLoggedOut(accountId, true))

	browser.runtime.onMessage.addListener((msg: unknown, sender: Browser.runtime.MessageSender, sendResponse: (reply: unknown) => void) => {
		const kind = (msg as { kind?: unknown } | null)?.kind
		if (typeof kind !== 'string' || !acceptedFrom(sender, kind)) return
		const page = senderPage(sender)
		if (page === 'content') {
			const m = msg as ContentToBackground
			if (m.kind === 'page_ready') onPageReady(m, sender)
			else if (m.kind === 'inline.closed') void closedByHost(m, sender).catch(() => {})
			else if (m.kind === 'capture.submitted') void captureSubmitted(m, sender).catch((error: unknown) => console.error('[keepiq] capture', error))
			else if (m.kind === 'capture.reshow') void reshow(sender).catch(() => {})
			else if (m.kind === 'inline.open') {
				void openSession(sender).catch(() => null).then(sendResponse)
				return true
			}
			return
		}
		if (page === 'inline-list') {
			const m = msg as InlineListToBackground
			const handled = handleInlineList(m, sender).catch((error: unknown) => {
				console.error('[keepiq] inline list', m.kind, error)
				return null
			})
			if (m.kind !== 'inline.state' && m.kind !== 'inline.pick') return
			void handled.then((reply) => {
				if (m.kind === 'inline.pick') void rebuildMenus()
				sendResponse(reply)
			})
			return true
		}
		if (page === 'notification') {
			const m = msg as NotificationToBackground
			void handleNotification(m, sender).catch((error: unknown) => {
				console.error('[keepiq] save bar', m.kind, error)
				return m.kind === 'capture.save' || m.kind === 'capture.update' ? { ok: false, error: { code: 'unknown' } } : null
			}).then(sendResponse)
			return true
		}
		if (page !== 'popup') return
		const rebuild = <T>(reply: T): T => {
			if (MENU_CHANGES.has(kind)) void rebuildMenus()
			if (ACCOUNT_CHANGES.has(kind)) {
				void closeAllSessions()
				dropAllCaptures()
			}
			return reply
		}
		const m = msg as PopupToBackground | HandledRequest | FillRequest
		if (m.kind === 'fill.request') void handleFillRequest(m).then(rebuild).then(sendResponse)
		else if (POPUP_REQUEST_KINDS.has(m.kind)) void handlePopupRequest(m as HandledRequest).then(rebuild).then(sendResponse)
		else void handlePopupMessage(m as PopupToBackground).then(rebuild).then(sendResponse)
		return true
	})

	browser.tabs.onUpdated.addListener((tabId, change) => {
		if (change.status === 'loading') forgetTab(tabId)
	})
	browser.tabs.onRemoved.addListener((tabId) => forgetTab(tabId))

	registerMenus()
	registerInlineSessions()
	registerCaptureQueue()
	registerDefaultManager()

	browser.commands.onCommand.addListener((command, tab) => {
		if (command === 'focus_notification_bar' && tab?.id !== undefined) focusBar(tab.id)
		if (command !== 'autofill_login') return
		void fillFromShortcut(tab).then(rebuildMenus).catch((error: unknown) => console.error('[keepiq] autofill shortcut', error))
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
