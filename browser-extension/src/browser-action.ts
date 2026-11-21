import { popout } from '@/src/background/requests'

/**
 * The toolbar-action API renamed between manifest versions: `browser.action` on
 * MV3 (Chrome), `browser.browserAction` on MV2 (Firefox). Resolve it once here so
 * nothing else in the codebase has to care which target it's building for.
 *
 * `BrowserAction.Static` extends `Action.Static`, so the shared surface
 * (`setIcon`, `setBadgeText`, `setTitle`, …) is identically typed on both.
 */
export const action = browser.action ?? browser.browserAction

/**
 * Background only. Firefox below 149 opens the popup only from a user-input handler, so call this
 * before the handler's first `await`; where `openPopup` is refused or missing, the popout opens instead.
 */
export async function openPopupOrPopout(tabId: number | undefined): Promise<void> {
	try {
		if (action.openPopup) {
			await action.openPopup()
			return
		}
	} catch {
		// Refused outside a user action, or no focused browser window.
	}
	await popout(tabId)
}
