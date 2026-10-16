import { beforeEach, describe, expect, it } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { GLOBAL_SETTINGS_KEY, readGlobalSettings, updateGlobalSettings } from './global'

beforeEach(() => fakeBrowser.reset())

describe('global settings', () => {
	it('defaults to the system theme', async () => {
		expect(await readGlobalSettings()).toEqual({ theme: 'system' })
	})

	it('reads an unknown stored theme as the default', async () => {
		await browser.storage.local.set({ [GLOBAL_SETTINGS_KEY]: { theme: 'nord' } })
		expect(await readGlobalSettings()).toEqual({ theme: 'system' })
	})

	it('stores a valid patch and returns the record', async () => {
		expect(await updateGlobalSettings({ theme: 'dark' })).toEqual({ theme: 'dark' })
		expect(await readGlobalSettings()).toEqual({ theme: 'dark' })
	})

	it('ignores an invalid patch value', async () => {
		await updateGlobalSettings({ theme: 'light' })
		expect(await updateGlobalSettings({ theme: 'blue' as never })).toEqual({ theme: 'light' })
		expect(await readGlobalSettings()).toEqual({ theme: 'light' })
	})
})
