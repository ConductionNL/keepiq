/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The personal activity and admin filter actions of `useAuditStore`.
 *
 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.1
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuditStore } from '../../src/store/modules/audit.js'

describe('useAuditStore personal activity and filters', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	describe('fetchPersonalActivity', () => {
		it("stores the session user's entries from the personal endpoint", async () => {
			const get = vi.spyOn(axios, 'get').mockResolvedValue({
				data: { entries: [{ id: 3, eventType: 'secret.read' }] },
			})
			const store = useAuditStore()

			await store.fetchPersonalActivity()

			expect(get.mock.calls[0][0]).toMatch(
				/\/apps\/keepiq\/api\/v1\/audit\/me$/,
			)
			expect(store.personalEntries).toEqual([
				{ id: 3, eventType: 'secret.read' },
			])
			expect(store.loading).toBe(false)
		})

		it('stores an empty list when the answer has no entries', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
			const store = useAuditStore()
			store.personalEntries = [{ id: 1 }]

			await store.fetchPersonalActivity()

			expect(store.personalEntries).toEqual([])
		})

		it('rethrows a failure and still clears loading', async () => {
			vi.spyOn(axios, 'get').mockRejectedValue(new Error('down'))
			const store = useAuditStore()
			store.personalEntries = [{ id: 1 }]

			await expect(store.fetchPersonalActivity()).rejects.toThrow('down')
			expect(store.loading).toBe(false)
			expect(store.personalEntries).toEqual([{ id: 1 }])
		})
	})

	describe('applyAdminFilters', () => {
		it('merges the filters and reloads from page one with them', async () => {
			const get = vi.spyOn(axios, 'get').mockResolvedValue({
				data: { entries: [{ id: 8 }], total: 1, page: 1, limit: 50 },
			})
			const store = useAuditStore()
			store.adminPage = 4
			store.adminFilters = { ...store.adminFilters, actor: 'kim' }

			await store.applyAdminFilters({ eventType: 'secret.read' })

			expect(store.adminFilters.actor).toBe('kim')
			expect(store.adminFilters.eventType).toBe('secret.read')
			const params = get.mock.calls[0][1].params
			expect(params.page).toBe(1)
			expect(params.actor).toBe('kim')
			expect(params.eventType).toBe('secret.read')
			expect(store.adminEntries).toEqual([{ id: 8 }])
			expect(store.adminPage).toBe(1)
		})
	})
})
