import { useEffect, useState } from 'react'
import { webUrl } from '@/src/vault/url'

export interface CurrentTab {
	id: number | undefined
	/** Where Launch opens tabs, so a popout opens them beside the tab it came from. */
	windowId: number | undefined
	url: string | undefined
	/** Set only for http(s) tabs, the ones that get a host line and suggestions. */
	host: string | null
}

const params = new URLSearchParams(location.search)

/** The popup page was opened in its own window by "Pop out". */
export const isPopout = params.get('popout') === '1'

/** In a popout the active tab is the popout itself, so the tab it came from rides along as `tabId`. */
async function readTab(): Promise<CurrentTab> {
	const tabId = Number(params.get('tabId'))
	const tab = isPopout
		? (Number.isInteger(tabId) && tabId > 0 ? await browser.tabs.get(tabId).catch(() => undefined) : undefined)
		: (await browser.tabs.query({ active: true, lastFocusedWindow: true }))[0]
	return { id: tab?.id, windowId: tab?.windowId, url: tab?.url, host: webUrl(tab?.url)?.host ?? null }
}

/** `undefined` while loading. */
export function useCurrentTab(): CurrentTab | undefined {
	const [tab, setTab] = useState<CurrentTab>()
	useEffect(() => {
		readTab().then(setTab, () => setTab({ id: undefined, windowId: undefined, url: undefined, host: null }))
	}, [])
	return tab
}
