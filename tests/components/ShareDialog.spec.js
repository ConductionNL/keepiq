/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The share dialog validates the recipient and their encryption suite,
 * encrypts the snapshot for them and emits the result.
 *
 * @spec openspec/changes/implement-user-sharing/tasks.md#task-12.1
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const shareStore = { encryptForRecipient: vi.fn() }

vi.mock('../../src/store/modules/share.js', () => ({
	useShareStore: () => shareStore,
}))

const { default: ShareDialog } = await import(
	'../../src/components/share/ShareDialog.vue'
)

/**
 * A fake instance with onSubmit bound to it.
 *
 * @param {object} overrides Fields to set.
 * @return {object} The instance.
 */
function makeVm(overrides = {}) {
	const vm = {
		...ShareDialog.data(),
		plaintextSnapshot: { password: 'p' },
		recipientCertificate: 'CERT',
		$emit: vi.fn(),
		...overrides,
	}
	vm.onSubmit = ShareDialog.methods.onSubmit.bind(vm)
	return vm
}

describe('ShareDialog', () => {
	beforeEach(() => {
		shareStore.encryptForRecipient = vi.fn().mockResolvedValue({ password: 'enc' })
	})

	it('requires a recipient before anything else', async () => {
		const vm = makeVm({ targetUserId: '', recipientCertificate: '' })

		await vm.onSubmit()

		expect(vm.error).toBe('Recipient is required')
		expect(shareStore.encryptForRecipient).not.toHaveBeenCalled()
		expect(vm.$emit).not.toHaveBeenCalled()
	})

	it('refuses a recipient without an active encryption suite', async () => {
		const vm = makeVm({ targetUserId: 'bob', recipientCertificate: '' })

		await vm.onSubmit()

		expect(vm.error).toBe('Recipient has no active encryption suite')
		expect(shareStore.encryptForRecipient).not.toHaveBeenCalled()
	})

	it('encrypts the snapshot for the recipient and emits shared', async () => {
		const vm = makeVm({ targetUserId: 'bob', error: 'old' })

		await vm.onSubmit()

		expect(shareStore.encryptForRecipient).toHaveBeenCalledWith(
			{ password: 'p' },
			'CERT',
		)
		expect(vm.$emit).toHaveBeenCalledWith('shared', {
			targetUserId: 'bob',
			encryptedFields: { password: 'enc' },
		})
		expect(vm.error).toBeNull()
		expect(vm.busy).toBe(false)
	})

	it('shows the encryption error and emits nothing', async () => {
		shareStore.encryptForRecipient.mockRejectedValue(new Error('bad cert'))
		const vm = makeVm({ targetUserId: 'bob' })

		await vm.onSubmit()

		expect(vm.error).toBe('bad cert')
		expect(vm.$emit).not.toHaveBeenCalled()
		expect(vm.busy).toBe(false)
	})

	it('falls back to a generic message for an error without one', async () => {
		shareStore.encryptForRecipient.mockRejectedValue({})
		const vm = makeVm({ targetUserId: 'bob' })

		await vm.onSubmit()

		expect(vm.error).toBe('Failed to encrypt for recipient')
	})

	it('resets its state when closed, and only then', () => {
		const vm = { targetUserId: 'bob', error: 'x', busy: true }

		ShareDialog.watch.open.call(vm, true)
		expect(vm).toEqual({ targetUserId: 'bob', error: 'x', busy: true })

		ShareDialog.watch.open.call(vm, false)
		expect(vm).toEqual({ targetUserId: '', error: null, busy: false })
	})
})
