/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The settings store: what the settings GET and POST send (URL, request
 * token, JSON body), how the payload fills the admin flag and the held admin
 * areas, the holdsArea getter, and that failures leave state and return null.
 *
 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#2.5
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSettingsStore } from '../../src/store/modules/settings.js'

const URL = '/apps/keepiq/api/settings'
const TOKEN = 'csrf-token-123'

/**
 * A fetch Response stand-in.
 *
 * @param {object} body The JSON body.
 * @param {boolean} ok Whether the status is 2xx.
 * @return {object}
 */
function response(body, ok = true) {
	return { ok, json: vi.fn().mockResolvedValue(body) }
}

describe('settings store', () => {
	let fetchMock

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		fetchMock = vi.fn()
		vi.stubGlobal('fetch', fetchMock)
		vi.stubGlobal('OC', { requestToken: TOKEN })
		vi.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('starts empty, not admin, with no areas', () => {
		const store = useSettingsStore()
		expect(store.getSettings).toEqual({})
		expect(store.getIsAdmin).toBe(false)
		expect(store.adminAreas).toEqual([])
		expect(store.loading).toBe(false)
	})

	describe('holdsArea', () => {
		it('is true only for an area the user holds', () => {
			const store = useSettingsStore()
			store.adminAreas = ['policies', 'audit']
			expect(store.holdsArea('policies')).toBe(true)
			expect(store.holdsArea('audit')).toBe(true)
			expect(store.holdsArea('suites')).toBe(false)
		})
	})

	describe('fetchSettings', () => {
		it('GETs with the request token and fills settings, admin flag and areas', async () => {
			const payload = {
				isAdmin: true,
				adminAreas: ['policies'],
				openregister: true,
			}
			const store = useSettingsStore()
			let loadingDuringRequest = null
			fetchMock.mockImplementation(async () => {
				loadingDuringRequest = store.loading
				return response(payload)
			})

			const result = await store.fetchSettings()

			expect(fetchMock).toHaveBeenCalledWith(URL, {
				headers: { requesttoken: TOKEN },
			})
			expect(loadingDuringRequest).toBe(true)
			expect(result).toEqual(payload)
			expect(store.getSettings).toEqual(payload)
			expect(store.getIsAdmin).toBe(true)
			expect(store.adminAreas).toEqual(['policies'])
			expect(store.holdsArea('policies')).toBe(true)
			expect(store.loading).toBe(false)
		})

		it('coerces a missing admin flag to false and non-array areas to []', async () => {
			fetchMock.mockResolvedValue(response({ adminAreas: 'policies' }))
			const store = useSettingsStore()
			store.isAdmin = true
			store.adminAreas = ['audit']

			await store.fetchSettings()

			expect(store.getIsAdmin).toBe(false)
			expect(store.adminAreas).toEqual([])
		})

		it('handles a null JSON body', async () => {
			fetchMock.mockResolvedValue(response(null))
			const store = useSettingsStore()

			expect(await store.fetchSettings()).toBeNull()
			expect(store.settings).toBeNull()
			expect(store.isAdmin).toBe(false)
			expect(store.adminAreas).toEqual([])
		})

		it('returns null and keeps state on a non-OK response', async () => {
			const res = response({ isAdmin: true }, false)
			fetchMock.mockResolvedValue(res)
			const store = useSettingsStore()

			expect(await store.fetchSettings()).toBeNull()
			expect(res.json).not.toHaveBeenCalled()
			expect(store.settings).toEqual({})
			expect(store.isAdmin).toBe(false)
			expect(store.loading).toBe(false)
		})

		it('logs, returns null and clears loading when fetch rejects', async () => {
			const error = new Error('offline')
			fetchMock.mockRejectedValue(error)
			const store = useSettingsStore()

			expect(await store.fetchSettings()).toBeNull()
			expect(console.error).toHaveBeenCalledWith(
				'Failed to fetch settings:',
				error,
			)
			expect(store.loading).toBe(false)
		})
	})

	describe('saveSettings', () => {
		it('POSTs the settings as JSON with the token and stores the reply', async () => {
			const saved = { theme: 'dark', retention: 30 }
			const store = useSettingsStore()
			store.isAdmin = true
			let loadingDuringRequest = null
			fetchMock.mockImplementation(async () => {
				loadingDuringRequest = store.loading
				return response(saved)
			})

			const result = await store.saveSettings({ theme: 'dark' })

			expect(fetchMock).toHaveBeenCalledWith(URL, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					requesttoken: TOKEN,
				},
				body: JSON.stringify({ theme: 'dark' }),
			})
			expect(loadingDuringRequest).toBe(true)
			expect(result).toEqual(saved)
			expect(store.settings).toEqual(saved)
			// Saving does not re-derive the admin flag.
			expect(store.isAdmin).toBe(true)
			expect(store.loading).toBe(false)
		})

		it('returns null and keeps the old settings on a non-OK response', async () => {
			fetchMock.mockResolvedValue(response({ theme: 'x' }, false))
			const store = useSettingsStore()
			store.settings = { theme: 'light' }

			expect(await store.saveSettings({ theme: 'x' })).toBeNull()
			expect(store.settings).toEqual({ theme: 'light' })
			expect(store.loading).toBe(false)
		})

		it('logs, returns null and clears loading when fetch rejects', async () => {
			const error = new Error('offline')
			fetchMock.mockRejectedValue(error)
			const store = useSettingsStore()

			expect(await store.saveSettings({})).toBeNull()
			expect(console.error).toHaveBeenCalledWith(
				'Failed to save settings:',
				error,
			)
			expect(store.loading).toBe(false)
		})
	})
})
