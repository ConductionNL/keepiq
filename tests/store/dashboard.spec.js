/**
 * SPDX-FileCopyrightText: 2026 Conduction / Doriath Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for `useDashboardStore` (`src/store/modules/dashboard.js`).
 *
 * @spec openspec/changes/implement-dashboard-settings/tasks.md#13.1
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import axios from '@nextcloud/axios'

import { useDashboardStore } from '../../src/store/modules/dashboard.js'

describe('useDashboardStore', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	describe('fetchSummary', () => {
		it('hydrates the summary payload', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({
				data: {
					total_secrets: 7,
					shared_secrets: 2,
					folder_count: 3,
					compromised_count: 1,
					pending_apps_count: 2,
					migration_status: 'in_progress',
					migration_remaining: 4,
				},
			})
			const store = useDashboardStore()
			await store.fetchSummary()
			expect(store.summary.total_secrets).toBe(7)
			expect(store.pendingAppsCount).toBe(2)
			expect(store.migrationStatus).toBe('in_progress')
			expect(store.isEmpty).toBe(false)
			expect(store.isLoading).toBe(false)
		})

		it('flags isEmpty when there are zero secrets', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({ data: { total_secrets: 0 } })
			const store = useDashboardStore()
			await store.fetchSummary()
			expect(store.isEmpty).toBe(true)
		})

		it('captures the error message and rethrows', async () => {
			vi.spyOn(axios, 'get').mockRejectedValue({ response: { data: { message: 'boom' } } })
			const store = useDashboardStore()
			await expect(store.fetchSummary()).rejects.toBeTruthy()
			expect(store.error).toBe('boom')
			expect(store.isLoading).toBe(false)
		})
	})

	describe('default state', () => {
		it('treats a null summary as non-empty so the loading state can render', () => {
			const store = useDashboardStore()
			expect(store.isEmpty).toBe(false)
			expect(store.pendingAppsCount).toBe(0)
			expect(store.migrationStatus).toBeNull()
		})
	})
})
