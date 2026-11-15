/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The certificate inventory's row actions: parse, renewal checklist and
 * re-issue, each holding the busy flag for the duration of the call.
 *
 * @spec openspec/changes/certificate-lifecycle/specs/certificate-lifecycle/spec.md#requirement-certificate-inventory
 */

import { afterEach, describe, expect, it, vi } from 'vitest'

const dialogs = vi.hoisted(() => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
}))

vi.mock('@nextcloud/dialogs', () => dialogs)

const { default: CertificateInventoryView } = await import(
	'../../src/views/CertificateInventoryView.vue'
)
const { methods } = CertificateInventoryView

/**
 * A fake instance with the view's methods bound to it and a faked store.
 *
 * @param {object} store Store fields.
 * @return {object} The instance.
 */
function makeVm(store = {}) {
	const vm = { busy: false, checklist: null, store }
	for (const [name, fn] of Object.entries(methods)) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

/**
 * A promise that resolves when told to, so the busy flag can be observed.
 *
 * @return {{promise: Promise, resolve: Function, reject: Function}}
 */
function deferred() {
	let resolve
	let reject
	const promise = new Promise((res, rej) => {
		resolve = res
		reject = rej
	})
	return { promise, resolve, reject }
}

describe('CertificateInventoryView', () => {
	afterEach(() => {
		vi.clearAllMocks()
	})

	it('reports locked from the session store', () => {
		const locked = CertificateInventoryView.computed.locked
		expect(locked.call({ session: { isLocked: true } })).toBe(true)
		expect(locked.call({ session: { isLocked: false } })).toBe(false)
	})

	it('fetches the inventory when created', () => {
		const store = { fetchInventory: vi.fn() }

		CertificateInventoryView.created.call({ store })

		expect(store.fetchInventory).toHaveBeenCalledTimes(1)
	})

	describe('onParse', () => {
		it('is busy while parsing and confirms a parsed certificate', async () => {
			const pending = deferred()
			const vm = makeVm({ parseAndSubmit: vi.fn(() => pending.promise) })

			const run = vm.onParse({ id: 'c1' })
			expect(vm.busy).toBe(true)
			pending.resolve({ id: 'c1' })
			await run

			expect(vm.store.parseAndSubmit).toHaveBeenCalledWith('c1')
			expect(dialogs.showSuccess).toHaveBeenCalledWith(
				'Certificate parsed — expiry reminder set.',
			)
			expect(vm.busy).toBe(false)
		})

		it('stays silent when parsing yields nothing', async () => {
			const vm = makeVm({ parseAndSubmit: vi.fn().mockResolvedValue(null) })

			await vm.onParse({ id: 'c1' })

			expect(dialogs.showSuccess).not.toHaveBeenCalled()
			expect(vm.busy).toBe(false)
		})

		it('clears busy when parsing throws', async () => {
			const vm = makeVm({
				parseAndSubmit: vi.fn().mockRejectedValue(new Error('bad pem')),
			})

			await expect(vm.onParse({ id: 'c1' })).rejects.toThrow('bad pem')
			expect(vm.busy).toBe(false)
		})
	})

	describe('onChecklist', () => {
		it('stores the renewal checklist for the row', async () => {
			const checklist = { steps: ['a', 'b'] }
			const vm = makeVm({ renewalChecklist: vi.fn().mockResolvedValue(checklist) })

			await vm.onChecklist({ id: 'c2' })

			expect(vm.store.renewalChecklist).toHaveBeenCalledWith('c2')
			expect(vm.checklist).toBe(checklist)
			expect(vm.busy).toBe(false)
		})

		it('clears busy and keeps the old checklist when loading throws', async () => {
			const vm = makeVm({
				renewalChecklist: vi.fn().mockRejectedValue(new Error('500')),
			})
			vm.checklist = { steps: [] }

			await expect(vm.onChecklist({ id: 'c2' })).rejects.toThrow('500')
			expect(vm.checklist).toEqual({ steps: [] })
			expect(vm.busy).toBe(false)
		})
	})

	describe('onReissue', () => {
		it('confirms a re-issue', async () => {
			const vm = makeVm({ reissueSuite: vi.fn().mockResolvedValue({ ok: true }) })

			await vm.onReissue({ id: 'c3' })

			expect(vm.store.reissueSuite).toHaveBeenCalledWith('c3')
			expect(dialogs.showSuccess).toHaveBeenCalledWith(
				'Certificate re-issued with the same key pair.',
			)
			expect(dialogs.showError).not.toHaveBeenCalled()
			expect(vm.busy).toBe(false)
		})

		it('shows the store error when the re-issue fails', async () => {
			const vm = makeVm({
				reissueSuite: vi.fn().mockResolvedValue(null),
				error: 'No active suite',
			})

			await vm.onReissue({ id: 'c3' })

			expect(dialogs.showError).toHaveBeenCalledWith('No active suite')
			expect(dialogs.showSuccess).not.toHaveBeenCalled()
		})

		it('shows nothing when the re-issue fails without an error', async () => {
			const vm = makeVm({ reissueSuite: vi.fn().mockResolvedValue(null), error: '' })

			await vm.onReissue({ id: 'c3' })

			expect(dialogs.showError).not.toHaveBeenCalled()
			expect(dialogs.showSuccess).not.toHaveBeenCalled()
			expect(vm.busy).toBe(false)
		})
	})
})
