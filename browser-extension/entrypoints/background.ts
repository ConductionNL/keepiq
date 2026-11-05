/**
 * Background service worker (Chrome MV3) / background page (Firefox MV2). Owns
 * every account, key and request; the popup only renders what it returns.
 * Listeners are registered synchronously so an MV3 wake re-registers them.
 */

import type { Browser } from 'wxt/browser'
import { setUnauthorizedHandler } from '@/src/api/client'
import { accountStatus, getAccount, markLoggedOut } from '@/src/accounts/store'
import { captureSubmitted, dropAllCaptures, focusBar, handleNotification, registerCaptureQueue, reshow, showPending } from '@/src/autofill/capture-queue'
import { registerDefaultManager } from '@/src/autofill/default-manager'
import { handleFillRequest } from '@/src/autofill/fill'
import { forgetTab, recordFrame } from '@/src/autofill/frames'
import { closeAllSessions, closedByHost, handleInlineList, openSession, registerInlineSessions } from '@/src/autofill/inline-session'
import { fillFromShortcut, fillOnPageLoad } from '@/src/autofill/triggers'
import { handlePopupRequest, syncOnAlarm, type HandledRequest } from '@/src/background/requests'
import { handlePopupMessage } from '@/src/background/router'
import { acceptedFrom, senderPage } from '@/src/background/sender'
import { onBroadcast } from '@/src/background/broadcast'
import { CLIPBOARD_ALARM, clearClipboard, clearCodeAfterLock } from '@/src/clipboard'
import { rebuildMenus, registerMenus } from '@/src/menus'
import {
	PASSKEY_POPUP_KINDS, POPUP_PORT, POPUP_REQUEST_KINDS,
	type ContentToBackground, type FillRequest, type InlineListToBackground, type NotificationToBackground, type PasskeyPopupRequest, type PopupToBackground,
} from '@/src/messages'
import { endForAccountChange, handlePasskeyContent, handlePasskeyWindow, registerPasskeyCeremonies } from '@/src/passkey/ceremony'
import { refreshToolbar, registerToolbarListeners } from '@/src/toolbar-icon'
import { SYNC_ALARM } from '@/src/vault/sync'
import { enforce, onIdleStateChanged, onPopupClosed, onPopupOpened, syncAlarm, TIMEOUT_ALARM } from '@/src/vault/timeout'

/** Popup kinds that change what the context menu shows; locks, logouts and syncs reach it by broadcast. */
const MENU_CHANGES = new Set(['accounts.add', 'accounts.reauthenticate', 'accounts.switch', 'vault.unlock', 'passkey.verify', 'account.settings.set', 'fill.request'])
/** Popup kinds that change the active account, which closes every inline list and drops every capture; locks and logouts do so by broadcast. */
const ACCOUNT_CHANGES = new Set(['accounts.add', 'accounts.switch'])
/** Popup kinds that can change the active account's status, failed unlocks too (they may hold the key briefly); locks and logouts arrive by broadcast. */
const TOOLBAR_CHANGES = new Set(['accounts.add', 'accounts.reauthenticate', 'accounts.switch', 'vault.unlock', 'passkey.verify'])

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

type Sender = Browser.runtime.MessageSender
type PopupMessage = PopupToBackground | HandledRequest | FillRequest | PasskeyPopupRequest

/** The reply to a content script's question; `null` for the fire-and-forget kinds. */
function contentReply(m: ContentToBackground, sender: Sender): Promise<unknown> | null {
	if (m.kind === 'inline.open') return openSession(m, sender).catch(() => null)
	if (m.kind === 'passkey.start' || m.kind === 'passkey.available') return handlePasskeyContent(m, sender)
	return null
}

function onContentEvent(m: ContentToBackground, sender: Sender): void {
	if (m.kind === 'page_ready') onPageReady(m, sender)
	else if (m.kind === 'inline.closed') void closedByHost(m, sender).catch(() => {})
	else if (m.kind === 'capture.submitted') void captureSubmitted(m, sender).catch((error: unknown) => console.error('[keepiq] capture', error))
	else if (m.kind === 'capture.reshow') void reshow(sender).catch(() => {})
	else if (m.kind === 'passkey.abort') void handlePasskeyContent(m, sender)
}

/** Each listener below returns `true` when it answers through `respond` later, as `onMessage` needs. */
function onContentMessage(m: ContentToBackground, sender: Sender, respond: (reply: unknown) => void): true | undefined {
	const reply = contentReply(m, sender)
	if (!reply) {
		onContentEvent(m, sender)
		return undefined
	}
	void reply.then(respond)
	return true
}

function onInlineListMessage(m: InlineListToBackground, sender: Sender, respond: (reply: unknown) => void): true | undefined {
	const handled = handleInlineList(m, sender).catch((error: unknown) => {
		console.error('[keepiq] inline list', m.kind, error)
		return null
	})
	if (m.kind !== 'inline.state' && m.kind !== 'inline.pick') return undefined
	void handled.then((reply) => {
		if (m.kind === 'inline.pick') void rebuildMenus()
		respond(reply)
	})
	return true
}

function onNotificationMessage(m: NotificationToBackground, sender: Sender, respond: (reply: unknown) => void): true {
	void handleNotification(m, sender).catch((error: unknown) => {
		console.error('[keepiq] save bar', m.kind, error)
		return m.kind === 'capture.save' || m.kind === 'capture.update' ? { ok: false, error: { code: 'unknown' } } : null
	}).then(respond)
	return true
}

function applyPopupChanges(kind: string): void {
	if (MENU_CHANGES.has(kind)) void rebuildMenus()
	if (TOOLBAR_CHANGES.has(kind)) refreshToolbar('state')
	if (ACCOUNT_CHANGES.has(kind)) {
		void closeAllSessions()
		dropAllCaptures()
		void endForAccountChange().catch(() => {})
	}
}

function popupReply(m: PopupMessage, sender: Sender): Promise<unknown> {
	if (PASSKEY_POPUP_KINDS.has(m.kind)) return handlePasskeyWindow(m as PasskeyPopupRequest, sender)
	if (m.kind === 'fill.request') return handleFillRequest(m as FillRequest)
	if (POPUP_REQUEST_KINDS.has(m.kind)) return handlePopupRequest(m as HandledRequest)
	return handlePopupMessage(m as PopupToBackground)
}

function onPopupMessage(m: PopupMessage, sender: Sender, respond: (reply: unknown) => void): true {
	void popupReply(m, sender).then((reply) => {
		applyPopupChanges(m.kind)
		return reply
	}).then(respond)
	return true
}

export default defineBackground(() => {
	setUnauthorizedHandler((accountId) => markLoggedOut(accountId, true))

	browser.runtime.onMessage.addListener((msg: unknown, sender: Sender, sendResponse: (reply: unknown) => void) => {
		const kind = (msg as { kind?: unknown } | null)?.kind
		if (typeof kind !== 'string' || !acceptedFrom(sender, kind)) return
		const page = senderPage(sender)
		if (page === 'content') return onContentMessage(msg as ContentToBackground, sender, sendResponse)
		if (page === 'inline-list') return onInlineListMessage(msg as InlineListToBackground, sender, sendResponse)
		if (page === 'notification') return onNotificationMessage(msg as NotificationToBackground, sender, sendResponse)
		if (page === 'popup') return onPopupMessage(msg as PopupMessage, sender, sendResponse)
	})

	browser.tabs.onUpdated.addListener((tabId, change) => {
		if (change.status === 'loading') forgetTab(tabId)
	})
	browser.tabs.onRemoved.addListener((tabId) => forgetTab(tabId))

	registerMenus()
	registerInlineSessions()
	registerCaptureQueue()
	registerDefaultManager()
	registerPasskeyCeremonies()
	registerToolbarListeners()
	onBroadcast((message) => {
		if (message.kind !== 'vault.locked') return
		const isUnlocked = async (accountId: string) => {
			const account = await getAccount(accountId)
			return account !== undefined && await accountStatus(account) === 'unlocked'
		}
		void clearCodeAfterLock(isUnlocked).catch((error: unknown) => console.error('[keepiq] clear copied code', error))
	})

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
