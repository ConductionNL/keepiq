import { beforeEach, describe, expect, it } from 'vitest'
import { forgetTab, framesOf, isWebUrl, recordFrame } from './frames'

describe('isWebUrl', () => {
	it('takes http and https pages only', () => {
		expect(['https://shop.test/', 'http://localhost:8100/card.html', 'HTTPS://SHOP.TEST/'].map(isWebUrl)).toEqual([true, true, true])
		const others = ['about:blank', 'chrome://newtab/', 'file:///tmp/a.html', 'data:text/html,x', 'blob:https://shop.test/1', 'moz-extension://id/popup.html', 'https:shop.test', '', ' https://shop.test/']
		expect(others.map(isWebUrl)).toEqual(others.map(() => false))
	})
})

beforeEach(() => {
	forgetTab(1)
	forgetTab(2)
})

describe('frame registry', () => {
	it('records each frame of a tab, a later page_ready replacing its url', () => {
		recordFrame(1, 0, 'https://example.test/')
		recordFrame(1, 4, 'https://login.example.test/frame')
		recordFrame(1, 4, 'https://other.test/')
		expect(framesOf(1)).toEqual([{ frameId: 0, url: 'https://example.test/' }, { frameId: 4, url: 'https://other.test/' }])
	})

	it('keeps tabs apart and forgets one tab only', () => {
		recordFrame(1, 0, 'https://a.test/')
		recordFrame(2, 0, 'https://b.test/')
		forgetTab(1)
		expect(framesOf(1)).toEqual([])
		expect(framesOf(2)).toEqual([{ frameId: 0, url: 'https://b.test/' }])
	})
})
