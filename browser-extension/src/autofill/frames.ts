/** Frames per tab as their `page_ready` sender reported them, never as page content claims. Background memory only. */

export interface FrameRecord {
	frameId: number
	url: string
}

const tabs = new Map<number, Map<number, string>>()

export function recordFrame(tabId: number, frameId: number, url: string): void {
	let frames = tabs.get(tabId)
	if (!frames) tabs.set(tabId, (frames = new Map()))
	frames.set(frameId, url)
}

/** On the tab starting to load a new page, and on its close. */
export function forgetTab(tabId: number): void {
	tabs.delete(tabId)
}

/** Empty without a record, as after an MV3 worker restart. */
export function framesOf(tabId: number): FrameRecord[] {
	return [...(tabs.get(tabId) ?? [])].map(([frameId, url]) => ({ frameId, url }))
}
