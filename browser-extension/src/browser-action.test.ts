import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { action, openPopupOrPopout } from './browser-action'

beforeEach(() => {
	fakeBrowser.reset()
})

describe('openPopupOrPopout', () => {
	it('opens the toolbar popup where the browser allows it', async () => {
		const openPopup = vi.fn(async () => {})
		Object.assign(action, { openPopup })
		const create = vi.spyOn(browser.windows, 'create')
		await openPopupOrPopout(7)
		expect(openPopup).toHaveBeenCalledOnce()
		expect(create).not.toHaveBeenCalled()
	})

	it.each([
		['refused', vi.fn(async () => Promise.reject(new Error('not from a user action')))],
		['missing', undefined],
	])('pops out for the tab when openPopup is %s', async (_, openPopup) => {
		Object.assign(action, { openPopup })
		const create = vi.spyOn(browser.windows, 'create').mockResolvedValue({} as never)
		await openPopupOrPopout(7)
		expect(create).toHaveBeenCalledWith(expect.objectContaining({ type: 'popup', url: expect.stringMatching(/\/popup\.html\?popout=1&tabId=7$/) }))
	})
})
