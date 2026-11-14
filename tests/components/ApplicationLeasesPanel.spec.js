/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The application leases panel loads the application's leases, hides a 404
 * silently and reports other failures, and revokes a lease.
 *
 * @spec openspec/specs/machine-secret-leases/spec.md#requirement-lease-revocation-by-admin-owner-or-application
 */

import { describe, expect, it, vi } from 'vitest'

const leaseStore = { leases: [] }

vi.mock('../../src/store/modules/lease.js', () => ({
	useLeaseStore: () => leaseStore,
}))

const { default: Panel } = await import(
	'../../src/components/application/ApplicationLeasesPanel.vue'
)
const { localDateTime } = await import('../../src/utils/dateTime.js')

/**
 * A fake instance with the panel's methods bound to it.
 *
 * @param {object} store Store fields.
 * @return {object} The instance.
 */
function makeVm(store) {
	const vm = { ...Panel.data(), applicationId: 'app-1', store }
	vm.onRevoke = Panel.methods.onRevoke.bind(vm)
	return vm
}

describe('ApplicationLeasesPanel', () => {
	it('reads the leases from the lease store', () => {
		leaseStore.leases = [{ id: 'l1' }]
		const store = Panel.computed.store.call({})

		expect(store).toBe(leaseStore)
		expect(Panel.computed.leases.call({ store })).toEqual([{ id: 'l1' }])
	})

	it('formats dates with the shared local date-time helper', () => {
		expect(Panel.methods.formatDate).toBe(localDateTime)
	})

	describe('mounted', () => {
		it('loads the leases of the application and clears the error', async () => {
			const vm = makeVm({ fetchForApplication: vi.fn().mockResolvedValue() })
			vm.error = 'old'

			await Panel.mounted.call(vm)

			expect(vm.store.fetchForApplication).toHaveBeenCalledWith('app-1')
			expect(vm.error).toBeNull()
		})

		it('hides a 404 silently', async () => {
			const vm = makeVm({
				fetchForApplication: vi.fn().mockRejectedValue({
					response: { status: 404, data: { message: 'Not found' } },
				}),
			})

			await Panel.mounted.call(vm)

			expect(vm.error).toBeNull()
		})

		it('reports any other failure, most specific message first', async () => {
			const fetch = vi.fn()
			const vm = makeVm({ fetchForApplication: fetch })

			fetch.mockRejectedValueOnce({
				response: { status: 500, data: { message: 'Server error' } },
			})
			await Panel.mounted.call(vm)
			expect(vm.error).toBe('Server error')

			fetch.mockRejectedValueOnce(new Error('offline'))
			await Panel.mounted.call(vm)
			expect(vm.error).toBe('offline')

			fetch.mockRejectedValueOnce({})
			await Panel.mounted.call(vm)
			expect(vm.error).toBe('Failed to load leases')
		})
	})

	describe('onRevoke', () => {
		it('revokes the lease by id and clears the error', async () => {
			const vm = makeVm({ revoke: vi.fn().mockResolvedValue() })
			vm.error = 'old'

			await vm.onRevoke({ id: 'l7' })

			expect(vm.store.revoke).toHaveBeenCalledWith('l7')
			expect(vm.error).toBeNull()
		})

		it('reports a failed revoke, most specific message first', async () => {
			const revoke = vi.fn()
			const vm = makeVm({ revoke })

			revoke.mockRejectedValueOnce({ response: { data: { message: 'Denied' } } })
			await vm.onRevoke({ id: 'l7' })
			expect(vm.error).toBe('Denied')

			revoke.mockRejectedValueOnce(new Error('offline'))
			await vm.onRevoke({ id: 'l7' })
			expect(vm.error).toBe('offline')

			revoke.mockRejectedValueOnce({})
			await vm.onRevoke({ id: 'l7' })
			expect(vm.error).toBe('Failed to revoke lease')
		})
	})
})
