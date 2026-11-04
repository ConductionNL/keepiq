// Fills nobody picked an item for: on page load and from the `autofill_login` shortcut.
import type { Browser } from 'wxt/browser'
import { readSettings } from '@/src/accounts/settings'
import { openPopupOrPopout } from '@/src/browser-action'
import { enforce, touch } from '@/src/vault/timeout'
import { activeTab, activeUnlockedId, fill, matchingLogins, preferred } from './fill'

/** Called for the top frame's `page_ready` with a password field; never counts as interaction. */
export async function fillOnPageLoad(tabId: number, pageUrl: string): Promise<void> {
	await enforce()
	const accountId = await activeUnlockedId()
	if (!accountId) return
	const { autofillOnPageLoad, defaultAutofill } = await readSettings(accountId)
	if (!autofillOnPageLoad || defaultAutofill !== 'onPageLoad') return
	const { rows, lastUsed } = await matchingLogins(accountId, pageUrl)
	const pick = preferred(rows, lastUsed)
	if (pick) await fill({ itemId: pick.id, tabId, ask: 'page', code: false })
}

/** Without an unambiguous match, or locked, the popup opens instead. */
export async function fillFromShortcut(commandTab: Browser.tabs.Tab | undefined): Promise<void> {
	const tab = commandTab?.id !== undefined ? commandTab : await activeTab()
	await enforce()
	const accountId = await activeUnlockedId()
	const matches = accountId && tab ? await matchingLogins(accountId, tab.url) : null
	const pick = matches && preferred(matches.rows, matches.lastUsed)
	if (!pick || tab?.id === undefined) return openPopupOrPopout(tab?.id)
	await touch()
	await fill({ itemId: pick.id, tabId: tab.id, ask: 'page', code: true })
}
