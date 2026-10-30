import type { Browser } from 'wxt/browser'
import { CONTENT_KINDS, INLINE_LIST_KINDS, NOTIFICATION_KINDS, POPUP_MESSAGE_KINDS, POPUP_REQUEST_KINDS } from '@/src/messages'

export type SenderPage = 'popup' | 'notification' | 'inline-list' | 'content'

const PAGE_PATHS: Record<string, SenderPage> = { '/popup.html': 'popup', '/notification.html': 'notification', '/inline-list.html': 'inline-list' }

/** Extension pages by path; anything else of ours is a content script only with the tab and frame it runs in. */
export function senderPage(sender: Browser.runtime.MessageSender): SenderPage | null {
	if (sender.id !== browser.runtime.id || !sender.url) return null
	if (sender.url.startsWith(browser.runtime.getURL('/'))) return PAGE_PATHS[new URL(sender.url).pathname] ?? null
	return sender.tab?.id !== undefined && sender.frameId !== undefined ? 'content' : null
}

/** Each extension page gets only its own kinds, so a bug in one cannot reach another's; a content script gets the fewest. */
const ACCEPTED: Record<SenderPage, (kind: string) => boolean> = {
	'popup': (kind) => POPUP_REQUEST_KINDS.has(kind) || POPUP_MESSAGE_KINDS.has(kind),
	'notification': (kind) => NOTIFICATION_KINDS.has(kind),
	'inline-list': (kind) => INLINE_LIST_KINDS.has(kind),
	'content': (kind) => CONTENT_KINDS.has(kind),
}

export function acceptedFrom(sender: Browser.runtime.MessageSender, kind: string): boolean {
	const page = senderPage(sender)
	return page !== null && ACCEPTED[page](kind)
}
