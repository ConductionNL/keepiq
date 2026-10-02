/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for `useDelegationStore` (`src/store/modules/delegation.js`).
 *
 * @spec openspec/changes/implement-user-sharing/tasks.md#16.2
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useDelegationStore } from '../../src/store/modules/delegation.js'

// Sharing needs a vault-key proof (keepiq#818). The proof itself is built by
// sessionKeyProofHeaders; here it returns a fixed header and echoes the
// password, so the tests can see that each request carried a proof.
vi.mock('../../src/crypto/keyProof.js', async (importOriginal) => ({
	...(await importOriginal()),
	sessionKeyProofHeaders: vi.fn(
		async ({ purpose, boundValues, masterPassword }) => ({
			headers: {
				'X-Keepiq-Key-Proof': `proof(${purpose}|${(boundValues ?? []).join(',')})`,
			},
			masterPassword: masterPassword || 'from-prompt',
		}),
	),
}))

describe('useDelegationStore', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	describe('fetchDelegations', () => {
		it('hydrates the delegations list', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({
				data: [
					{ id: 'd1', delegatedTo: 'bob', isPermanent: false },
					{ id: 'd2', delegatedTo: 'carol', isPermanent: true },
				],
			})
			const store = useDelegationStore()
			await store.fetchDelegations('sec-1')

			expect(store.count).toBe(2)
			expect(store.hasTemporary).toBe(true)
			expect(store.hasPermanent).toBe(true)
			expect(store.loading).toBe(false)
		})

		it('captures errors and surfaces them', async () => {
			vi.spyOn(axios, 'get').mockRejectedValue({
				response: { data: { message: 'forbidden' } },
			})
			const store = useDelegationStore()
			await expect(store.fetchDelegations('sec-1')).rejects.toBeDefined()

			expect(store.error).toBe('forbidden')
		})
	})

	describe('createDelegation', () => {
		it('appends the row returned by the API', async () => {
			vi.spyOn(axios, 'post').mockResolvedValue({
				data: { id: 'd1', delegatedTo: 'bob', isPermanent: false },
			})
			const store = useDelegationStore()
			const row = await store.createDelegation('sec-1', 'bob')

			// keepiq#818: every delegation carries a proof bound to the
			// secret and the delegate.
			expect(axios.post.mock.calls[0][2].headers).toEqual({
				'X-Keepiq-Key-Proof': 'proof(delegation-create|sec-1,bob)',
			})
			expect(row.id).toBe('d1')
			expect(store.count).toBe(1)
			expect(store.delegations[0].delegatedTo).toBe('bob')
		})
	})

	describe('reclaimDelegation', () => {
		it('drops temporary rows from local state on success', async () => {
			vi.spyOn(axios, 'post').mockResolvedValue({ data: { removed: 1 } })
			const store = useDelegationStore()
			store.delegations = [
				{ id: 'd1', delegatedTo: 'bob', isPermanent: false },
				{ id: 'd2', delegatedTo: 'carol', isPermanent: true },
			]

			const removed = await store.reclaimDelegation('sec-1')

			expect(removed).toBe(1)
			expect(store.count).toBe(1)
			expect(store.delegations[0].id).toBe('d2')
			expect(store.hasTemporary).toBe(false)
			expect(store.hasPermanent).toBe(true)
		})
	})

	describe('reset', () => {
		it('clears state', () => {
			const store = useDelegationStore()
			store.delegations = [{ id: 'd1' }]
			store.error = 'oops'

			store.reset()

			expect(store.delegations).toEqual([])
			expect(store.error).toBeNull()
		})
	})

	describe('fetchCapabilities (admin-scoped-roles §3.2)', () => {
		it('reads canHandover from the capabilities endpoint', async () => {
			const get = vi
				.spyOn(axios, 'get')
				.mockResolvedValue({ data: { canHandover: true } })
			const store = useDelegationStore()
			await store.fetchCapabilities()

			expect(get).toHaveBeenCalledWith(
				'/apps/keepiq/api/v1/delegations/capabilities',
			)
			expect(store.canHandover).toBe(true)
		})

		it('ignores the retired isVaultAdmin flag', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({
				data: { isVaultAdmin: true },
			})
			const store = useDelegationStore()
			await store.fetchCapabilities()

			expect(store.canHandover).toBe(false)
		})

		it('never offers the takeover after a failed request', async () => {
			vi.spyOn(axios, 'get').mockRejectedValue(new Error('offline'))
			const store = useDelegationStore()
			await store.fetchCapabilities()

			expect(store.canHandover).toBe(false)
		})
	})
})
