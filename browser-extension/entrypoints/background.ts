/**
 * Background service worker (Chrome MV3) / background page (Firefox MV2).
 *
 * Owns the `browser.*` APIs content scripts can't reach and the state the popup
 * reads. Starter scaffold: tracks an on/off flag in `storage.local`, counts the
 * tabs with a live content script, and mirrors both onto the toolbar badge.
 */

import type { Browser } from 'wxt/browser'
import type {
	BackgroundToContent,
	ContentToBackground,
	PopupState,
	PopupToBackground,
} from '@/src/messages'
import { action } from '@/src/browser-action'

const ENABLED_KEY = 'enabled'

/**
 * Tabs with a content script that has announced itself. In-memory on purpose:
 * an MV3 worker restart invalidates the whole set anyway, and each content
 * script re-announces on its next page load.
 */
const activeTabs = new Set<number>()

export default defineBackground(() => {
	void refreshBadge()

	browser.runtime.onMessage.addListener((msg: unknown, sender: Browser.runtime.MessageSender) => {
		const m = msg as ContentToBackground | PopupToBackground

		// Request/response arms return a Promise — that's what tells the browser to
		// hold the message channel open for the reply.
		if (m.kind === 'get_state') return getState()
		if (m.kind === 'set_enabled') return setEnabled(m.enabled)

		// Fire-and-forget arms must return `undefined`. Returning a Promise or
		// `true` here would leave the channel waiting on a reply that never comes,
		// so the async work goes in a floating IIFE.
		if (m.kind === 'page_ready') {
			const tabId = sender.tab?.id
			if (tabId !== undefined) {
				activeTabs.add(tabId)
				void refreshBadge()
			}
		}
	})

	// Fires before the id can be reused for a new tab, so this can't evict a
	// live entry.
	browser.tabs.onRemoved.addListener((tabId: number) => {
		if (activeTabs.delete(tabId)) void refreshBadge()
	})
})

async function isEnabled(): Promise<boolean> {
	const stored = await browser.storage.local.get(ENABLED_KEY)
	// Default on for a fresh profile, where the key is absent entirely.
	return stored[ENABLED_KEY] !== false
}

async function getState(): Promise<PopupState> {
	return { enabled: await isEnabled(), activeTabs: activeTabs.size }
}

async function setEnabled(enabled: boolean): Promise<PopupState> {
	await browser.storage.local.set({ [ENABLED_KEY]: enabled })
	await refreshBadge()
	const payload: BackgroundToContent = { kind: 'enabled_changed', enabled }
	for (const tabId of activeTabs) {
		void browser.tabs.sendMessage(tabId, payload).catch(() => {
			// Tab navigated away or the script is gone; its replacement will
			// re-read the flag on boot.
		})
	}
	return getState()
}

async function refreshBadge(): Promise<void> {
	const on = await isEnabled()
	void action.setBadgeText({ text: on ? String(activeTabs.size) : 'off' })
}
