import { describe, expect, it } from 'vitest'
import type { Browser } from 'wxt/browser'
import { CONTENT_KINDS, INLINE_LIST_KINDS, NOTIFICATION_KINDS, POPUP_MESSAGE_KINDS, POPUP_REQUEST_KINDS } from '@/src/messages'
import { acceptedFrom } from './sender'

const page = (path: string) => browser.runtime.getURL(path as '/popup.html')
const tab = { tab: { id: 7 }, frameId: 3 }

const senders = {
	popup: { id: browser.runtime.id, url: page('/popup.html') },
	popout: { id: browser.runtime.id, url: `${page('/popup.html')}?popout=1&tabId=7`, tab: { id: 9 }, frameId: 0 },
	bar: { id: browser.runtime.id, url: page('/notification.html'), ...tab },
	list: { id: browser.runtime.id, url: page('/inline-list.html'), ...tab },
	content: { id: browser.runtime.id, url: 'https://example.test/login', ...tab },
	offscreen: { id: browser.runtime.id, url: page('/offscreen.html') },
	unknownPage: { id: browser.runtime.id, url: page('/popup.html.evil') },
	contentWithoutTab: { id: browser.runtime.id, url: 'https://example.test/login', frameId: 0 },
	contentWithoutFrame: { id: browser.runtime.id, url: 'https://example.test/login', tab: { id: 7 } },
	noUrl: { id: browser.runtime.id, ...tab },
	otherExtension: { id: 'other-extension', url: 'chrome-extension://other-extension/popup.html' },
	otherExtensionContent: { id: 'other-extension', url: 'https://example.test/login', ...tab },
}

// Written out, so a kind moved to the wrong set fails here instead of passing through the same constant.
const CONTENT = ['page_ready', 'inline.open', 'inline.closed', 'capture.submitted', 'capture.reshow']
const LIST = ['inline.state', 'inline.pick', 'inline.refocus', 'inline.close', 'inline.unlock']
const BAR = ['capture.state', 'capture.save', 'capture.update', 'capture.dismiss', 'capture.expand']
const POPUP = [...POPUP_REQUEST_KINDS, ...POPUP_MESSAGE_KINDS]
const ALL = [...new Set([
	...POPUP, ...CONTENT, ...LIST, ...BAR, ...CONTENT_KINDS, ...INLINE_LIST_KINDS, ...NOTIFICATION_KINDS,
	'offscreen.writeClipboard', 'offscreen.clearClipboard', 'fill.execute', 'capture.show', 'inline.verify', 'no.such.kind',
])]

const accepted: Record<keyof typeof senders, string[]> = {
	popup: POPUP,
	popout: POPUP,
	bar: BAR,
	list: LIST,
	content: CONTENT,
	offscreen: [],
	unknownPage: [],
	contentWithoutTab: [],
	contentWithoutFrame: [],
	noUrl: [],
	otherExtension: [],
	otherExtensionContent: [],
}

describe('acceptedFrom', () => {
	it('gives the popup its own kinds, fill.request included', () => {
		expect(POPUP).toEqual(expect.arrayContaining(['fill.request', 'vault.status', 'vault.snapshot', 'accounts.removeAll', 'item.decrypt']))
		expect(POPUP.filter((kind) => [...CONTENT, ...LIST, ...BAR].includes(kind))).toEqual([])
	})

	describe.each(Object.keys(senders) as Array<keyof typeof senders>)('from %s', (name) => {
		it.each(ALL)('%s', (kind) => {
			expect(acceptedFrom(senders[name] as Browser.runtime.MessageSender, kind)).toBe(accepted[name].includes(kind))
		})
	})
})
