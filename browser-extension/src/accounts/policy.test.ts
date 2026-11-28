import { beforeEach, describe, expect, it } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { accountPolicyKey, clearAccountPolicy, patchAccountPolicy, readAccountPolicy } from './policy'

beforeEach(() => {
	fakeBrowser.reset()
})

describe('account policy', () => {
	it('reads no maximum and export allowed before anything was stored', async () => {
		expect(await readAccountPolicy('a')).toEqual({ maxIdleMinutes: null, exportDisabled: false })
	})

	it('patches one half and keeps the other', async () => {
		await patchAccountPolicy('a', { maxIdleMinutes: 30 })
		await patchAccountPolicy('a', { exportDisabled: true })
		expect(await readAccountPolicy('a')).toEqual({ maxIdleMinutes: 30, exportDisabled: true })
		expect(await browser.storage.local.get(accountPolicyKey('a'))).toEqual({ [accountPolicyKey('a')]: { maxIdleMinutes: 30, exportDisabled: true } })
	})

	it.each([0, -5, 2.5, '30', Number.POSITIVE_INFINITY])('reads a stored maximum of %j as none', async (maxIdleMinutes) => {
		await browser.storage.local.set({ [accountPolicyKey('a')]: { maxIdleMinutes, exportDisabled: true } })
		expect(await readAccountPolicy('a')).toEqual({ maxIdleMinutes: null, exportDisabled: true })
	})

	it('reads a stored switch that is not a boolean as export allowed', async () => {
		await browser.storage.local.set({ [accountPolicyKey('a')]: { maxIdleMinutes: 60, exportDisabled: 'yes' } })
		expect(await readAccountPolicy('a')).toEqual({ maxIdleMinutes: 60, exportDisabled: false })
	})

	it('ignores patch values that fail validation', async () => {
		await patchAccountPolicy('a', { maxIdleMinutes: 30, exportDisabled: true })
		await patchAccountPolicy('a', { maxIdleMinutes: 0, exportDisabled: 'no' as never })
		expect(await readAccountPolicy('a')).toEqual({ maxIdleMinutes: 30, exportDisabled: true })
	})

	it('loses neither of two patches made at once', async () => {
		await Promise.all([patchAccountPolicy('a', { maxIdleMinutes: 15 }), patchAccountPolicy('a', { exportDisabled: true })])
		expect(await readAccountPolicy('a')).toEqual({ maxIdleMinutes: 15, exportDisabled: true })
	})

	it('clears the record', async () => {
		await patchAccountPolicy('a', { maxIdleMinutes: 30 })
		await clearAccountPolicy('a')
		expect(await browser.storage.local.get(null)).toEqual({})
	})
})
