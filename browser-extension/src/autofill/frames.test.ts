import { beforeEach, describe, expect, it } from 'vitest'
import { forgetTab, framesOf, recordFrame } from './frames'

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
