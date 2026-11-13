/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin handover panel loads the capability once and takes a secret
 * over, reporting the server's reason when that fails.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-ownership-delegation
 */

import { describe, expect, it, vi } from 'vitest'

const delegationStore = {}

vi.mock('../../src/store/modules/delegation.js', () => ({
	useDelegationStore: () => delegationStore,
}))

const { default: Panel } = await import(
	'../../src/components/share/AdminHandoverPanel.vue'
)

/**
 * A fake instance with onHandover bound to it.
 *
 * @param {object} store Store fields.
 * @return {object} The instance.
 */
function makeVm(store) {
	const vm = { ...Panel.data(), secretId: 's1', store, $emit: vi.fn() }
	vm.onHandover = Panel.methods.onHandover.bind(vm)
	return vm
}

describe('AdminHandoverPanel', () => {
	it('exposes the delegation store from setup', () => {
		expect(Panel.setup()).toEqual({ store: delegationStore })
	})

	it('fetches the capability only when it is still unknown', () => {
		const unknown = { canHandover: null, fetchCapabilities: vi.fn() }
		Panel.created.call({ store: unknown })
		expect(unknown.fetchCapabilities).toHaveBeenCalledTimes(1)

		const known = { canHandover: false, fetchCapabilities: vi.fn() }
		Panel.created.call({ store: known })
		expect(known.fetchCapabilities).not.toHaveBeenCalled()
	})

	it('takes the secret over and emits the new row', async () => {
		const row = { id: 's1', owner: 'admin' }
		const vm = makeVm({ adminHandover: vi.fn().mockResolvedValue(row) })
		vm.failure = 'old'

		await vm.onHandover()

		expect(vm.store.adminHandover).toHaveBeenCalledWith('s1')
		expect(vm.done).toBe(true)
		expect(vm.failure).toBe('')
		expect(vm.$emit).toHaveBeenCalledWith('taken-over', row)
	})

	it('reports the server message and stays not done on failure', async () => {
		const vm = makeVm({
			adminHandover: vi.fn().mockRejectedValue({
				response: { data: { message: 'Not an admin' } },
			}),
			error: 'store error',
		})

		await vm.onHandover()

		expect(vm.failure).toBe('Not an admin')
		expect(vm.done).toBe(false)
		expect(vm.$emit).not.toHaveBeenCalled()
	})

	it('falls back to the store error, then to nothing', async () => {
		const vm = makeVm({
			adminHandover: vi.fn().mockRejectedValue(new Error('x')),
			error: 'store error',
		})
		await vm.onHandover()
		expect(vm.failure).toBe('store error')

		vm.store.error = null
		await vm.onHandover()
		expect(vm.failure).toBe('')
	})
})
