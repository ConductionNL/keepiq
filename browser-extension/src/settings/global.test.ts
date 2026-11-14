import { beforeEach, describe, expect, it } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { DEFAULT_GLOBAL_SETTINGS, GLOBAL_SETTINGS_KEY, readGlobalSettings, updateGlobalSettings } from './global'

const defaults = { theme: 'system', compactMode: false, showAnimations: true, quickCopy: true, showBadgeCounter: true, websiteAccessDismissed: false }

beforeEach(() => fakeBrowser.reset())

describe('global settings', () => {
	it('defaults to the system theme, no compact mode, animations, quick copy, the badge counter and no dismissed website access card', async () => {
		expect(DEFAULT_GLOBAL_SETTINGS).toEqual(defaults)
		expect(await readGlobalSettings()).toEqual(defaults)
	})

	it('hands out a copy, never the defaults themselves', async () => {
		;(await readGlobalSettings()).theme = 'dark'
		expect(DEFAULT_GLOBAL_SETTINGS.theme).toBe('system')
	})

	it('reads an unknown stored theme as the default', async () => {
		await browser.storage.local.set({ [GLOBAL_SETTINGS_KEY]: { theme: 'nord' } })
		expect(await readGlobalSettings()).toEqual(defaults)
	})

	it.each(['compactMode', 'showAnimations', 'quickCopy', 'showBadgeCounter', 'websiteAccessDismissed'])('reads a stored %s that is not a boolean as its default', async (key) => {
		await browser.storage.local.set({ [GLOBAL_SETTINGS_KEY]: { theme: 'dark', [key]: 'yes' } })
		expect(await readGlobalSettings()).toEqual({ ...defaults, theme: 'dark' })
	})

	it('stores a valid patch and returns the record', async () => {
		expect(await updateGlobalSettings({ theme: 'dark' })).toEqual({ ...defaults, theme: 'dark' })
		const all = { theme: 'dark', compactMode: true, showAnimations: false, quickCopy: false, showBadgeCounter: false, websiteAccessDismissed: true } as const
		expect(await updateGlobalSettings(all)).toEqual(all)
		expect(await readGlobalSettings()).toEqual(all)
	})

	it('ignores an invalid patch value', async () => {
		await updateGlobalSettings({ theme: 'light', compactMode: true })
		expect(await updateGlobalSettings({ theme: 'blue' as never, compactMode: 'no' as never })).toEqual({ ...defaults, theme: 'light', compactMode: true })
		expect(await readGlobalSettings()).toEqual({ ...defaults, theme: 'light', compactMode: true })
	})
})
