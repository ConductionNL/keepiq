/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins the machine-lease store: the per-application lease and lease-policy
 * endpoints, the policy override payload, and that a revoked lease is replaced
 * in place by the server's row while the others stay untouched.
 *
 * @spec openspec/specs/machine-secret-leases/spec.md#requirement-lease-revocation-by-admin-owner-or-application
 * @spec openspec/specs/machine-secret-leases/spec.md#requirement-admin-lease-ttl-policy
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useLeaseStore } from '../../src/store/modules/lease.js'

describe('useLeaseStore', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	describe('fetchForApplication', () => {
		it('loads the application leases and toggles loading', async () => {
			const get = vi
				.spyOn(axios, 'get')
				.mockResolvedValue({ data: [{ id: 'l1', state: 'active' }] })
			const store = useLeaseStore()

			const pending = store.fetchForApplication('app-1')
			expect(store.loading).toBe(true)
			await pending

			expect(get).toHaveBeenCalledWith('/apps/keepiq/api/v1/applications/app-1/leases')
			expect(store.leases).toEqual([{ id: 'l1', state: 'active' }])
			expect(store.loading).toBe(false)
		})

		it('treats an empty body as no leases', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({ data: null })
			const store = useLeaseStore()
			store.leases = [{ id: 'old' }]
			await store.fetchForApplication('app-1')
			expect(store.leases).toEqual([])
		})

		it('resets loading and keeps the leases when the load fails', async () => {
			vi.spyOn(axios, 'get').mockRejectedValue(new Error('403'))
			const store = useLeaseStore()
			store.leases = [{ id: 'old' }]
			await expect(store.fetchForApplication('app-1')).rejects.toThrow('403')
			expect(store.loading).toBe(false)
			expect(store.leases).toEqual([{ id: 'old' }])
		})
	})

	describe('lease policy', () => {
		it('reads the policy view', async () => {
			const view = { effective: { defaultTtl: 60 }, canEdit: true }
			const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: view })
			const store = useLeaseStore()

			await expect(store.fetchPolicy('app-2')).resolves.toEqual(view)
			expect(get).toHaveBeenCalledWith(
				'/apps/keepiq/api/v1/applications/app-2/lease-policy',
			)
		})

		it('puts the override as given and returns the effective policy', async () => {
			const effective = { defaultTtl: 300, maxTtl: 3600, renewable: false }
			const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: effective })
			const store = useLeaseStore()
			const override = { defaultTtl: 300, maxTtl: null, renewable: false }

			await expect(store.savePolicy('app-2', override)).resolves.toEqual(effective)
			expect(put).toHaveBeenCalledWith(
				'/apps/keepiq/api/v1/applications/app-2/lease-policy',
				override,
			)
		})
	})

	describe('revoke', () => {
		it('deletes the lease and swaps in the server row in place', async () => {
			const revoked = { id: 'l2', state: 'revoked' }
			const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: revoked })
			const store = useLeaseStore()
			store.leases = [
				{ id: 'l1', state: 'active' },
				{ id: 'l2', state: 'active' },
				{ id: 'l3', state: 'active' },
			]

			await store.revoke('l2')

			expect(del).toHaveBeenCalledWith('/apps/keepiq/api/v1/leases/l2')
			expect(store.leases).toEqual([
				{ id: 'l1', state: 'active' },
				revoked,
				{ id: 'l3', state: 'active' },
			])
		})

		it('leaves the list unchanged when the revoke fails', async () => {
			vi.spyOn(axios, 'delete').mockRejectedValue(new Error('404'))
			const store = useLeaseStore()
			store.leases = [{ id: 'l1', state: 'active' }]
			await expect(store.revoke('l1')).rejects.toThrow('404')
			expect(store.leases).toEqual([{ id: 'l1', state: 'active' }])
		})
	})
})
