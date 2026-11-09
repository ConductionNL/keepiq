import { serverOrigins } from './accounts/normalize-server-url'
import { isSafari } from './platform'

/** Safari runs content scripts and background requests only where the user allowed them (website-access spec). */
export type WebsiteAccessState = 'server' | 'sites' | 'all'

/** The manifest's `optional_host_permissions` on Safari. */
export const SITE_ORIGINS = ['https://*/*', 'http://*/*']

/** Asks the browser each time, since the user can change it in Safari's settings while the popup is closed. */
export async function websiteAccess(serverUrl: string): Promise<WebsiteAccessState> {
	if (!isSafari()) return 'all'
	if (!(await browser.permissions.contains({ origins: serverOrigins(serverUrl) }))) return 'server'
	return (await browser.permissions.contains({ origins: SITE_ORIGINS })) ? 'all' : 'sites'
}
