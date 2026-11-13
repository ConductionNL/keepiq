/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/dialogs/DeviceApprovalDialog.vue`.
 *
 * Pins when the dialog opens (only while the vault is unlocked, on the first
 * request not dismissed), the polling lifecycle, and what approve / deny /
 * dismiss do with the master password and the error text.
 *
 * @spec openspec/specs/new-device-approval/spec.md#requirement-approval-seals-the-unlock-key-and-needs-proof-of-the-master-password
 * @spec openspec/specs/new-device-approval/spec.md#requirement-deny-expiry-audit-and-administrator-switch
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import DeviceApprovalDialog, {
	PENDING_POLL_MS,
} from '../../src/dialogs/DeviceApprovalDialog.vue'
import { useDeviceApprovalStore } from '../../src/store/modules/deviceApproval.js'

const stubs = {
	NcDialog: {
		name: 'NcDialog',
		props: ['name', 'open'],
		emits: ['update:open'],
		template: '<div><h1>{{ name }}</h1><slot /><slot name="actions" /></div>',
	},
	NcNoteCard: {
		props: ['type'],
		template: '<div :class="`note note--${type}`"><slot /></div>',
	},
	NcButton: {
		emits: ['click'],
		template: '<button v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>',
	},
	NcPasswordField: {
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template:
			'<input v-bind="$attrs" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const REQUEST = {
	id: 'r1',
	deviceLabel: 'Laptop',
	clientKind: 'extension',
	requesterIp: '10.0.0.1',
	createdAt: '2026-01-02T03:04:05Z',
	phrase: 'apple banana cherry',
}

function mountDialog(active = true) {
	return mount(DeviceApprovalDialog, {
		props: { active },
		global: { stubs },
	})
}

describe('DeviceApprovalDialog', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useDeviceApprovalStore()
		vi.spyOn(store, 'fetchPending').mockResolvedValue(undefined)
		vi.spyOn(store, 'approve').mockResolvedValue(undefined)
		vi.spyOn(store, 'deny').mockResolvedValue(undefined)
	})

	afterEach(() => {
		vi.useRealTimers()
		vi.restoreAllMocks()
	})

	it('renders nothing while the vault is locked, and does not poll', () => {
		store.pending = [REQUEST]
		const wrapper = mountDialog(false)

		expect(wrapper.find('[data-testid="device-approval-phrase"]').exists()).toBe(
			false,
		)
		expect(wrapper.vm.current).toBeNull()
		expect(store.fetchPending).not.toHaveBeenCalled()
	})

	it('shows the first open request with its facts when unlocked', () => {
		store.pending = [REQUEST, { ...REQUEST, id: 'r2', phrase: 'other' }]
		const wrapper = mountDialog()

		expect(store.fetchPending).toHaveBeenCalledTimes(1)
		expect(wrapper.find('[data-testid="device-approval-label"]').text()).toBe(
			'Laptop',
		)
		expect(wrapper.find('[data-testid="device-approval-phrase"]').text()).toBe(
			'apple banana cherry',
		)
		expect(wrapper.text()).toContain('Browser extension')
		expect(wrapper.text()).toContain('10.0.0.1')
		expect(wrapper.vm.askedAt).toBe(
			new Date('2026-01-02T03:04:05Z').toLocaleString(),
		)
		expect(wrapper.vm.securityUrl).toBe('/settings/user/security')
	})

	it('falls back to an unknown device, the web app and an empty time', () => {
		store.pending = [
			{ id: 'r3', clientKind: 'web', createdAt: 'not a date', phrase: 'x' },
		]
		const wrapper = mountDialog()

		expect(wrapper.find('[data-testid="device-approval-label"]').text()).toBe(
			'Unknown device',
		)
		expect(wrapper.text()).toContain('Web app')
		expect(wrapper.vm.askedAt).toBe('')
	})

	it('polls every interval while active and stops when locked', async () => {
		vi.useFakeTimers()
		const wrapper = mountDialog()
		expect(store.fetchPending).toHaveBeenCalledTimes(1)

		vi.advanceTimersByTime(PENDING_POLL_MS)
		expect(store.fetchPending).toHaveBeenCalledTimes(2)

		await wrapper.setProps({ active: false })
		expect(wrapper.vm.timer).toBeNull()
		vi.advanceTimersByTime(PENDING_POLL_MS * 3)
		expect(store.fetchPending).toHaveBeenCalledTimes(2)
	})

	it('clears the poll on unmount and swallows a failed refresh', async () => {
		vi.useFakeTimers()
		store.fetchPending.mockRejectedValue(new Error('offline'))
		const wrapper = mountDialog()
		await expect(wrapper.vm.refresh()).resolves.toBeUndefined()

		wrapper.unmount()
		vi.advanceTimersByTime(PENDING_POLL_MS * 2)
		// One call from the immediate watcher, one from the explicit refresh.
		expect(store.fetchPending).toHaveBeenCalledTimes(2)
	})

	it('keeps approve disabled until a master password is typed', async () => {
		store.pending = [REQUEST]
		const wrapper = mountDialog()
		const approve = wrapper.find('[data-testid="device-approval-approve"]')
		expect(approve.attributes('disabled')).toBeDefined()

		await wrapper
			.find('[data-testid="device-approval-password"]')
			.setValue('secret')
		expect(approve.attributes('disabled')).toBeUndefined()
	})

	it('approves the current request with the password and then clears it', async () => {
		store.pending = [REQUEST]
		const wrapper = mountDialog()
		await wrapper
			.find('[data-testid="device-approval-password"]')
			.setValue('secret')

		await wrapper.find('[data-testid="device-approval-approve"]').trigger('click')
		await flush()

		expect(store.approve).toHaveBeenCalledWith(REQUEST, 'secret')
		expect(wrapper.vm.masterPassword).toBe('')
		expect(wrapper.vm.busy).toBe(false)
		expect(wrapper.vm.error).toBeNull()
	})

	it('shows the server message when approval is refused, keeping the password', async () => {
		store.approve.mockRejectedValue({
			response: { data: { message: 'Request expired' } },
		})
		store.pending = [REQUEST]
		const wrapper = mountDialog()
		wrapper.vm.masterPassword = 'secret'

		await wrapper.vm.approve()
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.error).toBe('Request expired')
		expect(wrapper.vm.masterPassword).toBe('secret')
		expect(wrapper.find('.note--error').text()).toBe('Request expired')
	})

	it('falls back to a generic message when the refusal carries none', async () => {
		store.approve.mockRejectedValue(new Error('boom'))
		store.pending = [REQUEST]
		const wrapper = mountDialog()

		await wrapper.vm.approve()

		expect(wrapper.vm.error).toBe(
			'The master password is not right, or the request has ended.',
		)
	})

	it('denies by id and points to the security settings', async () => {
		store.pending = [REQUEST]
		const wrapper = mountDialog()

		await wrapper.find('[data-testid="device-approval-deny"]').trigger('click')
		await flush()

		expect(store.deny).toHaveBeenCalledWith('r1')
		expect(wrapper.vm.denied).toBe(true)
		const hint = wrapper.find('[data-testid="device-approval-denied-hint"]')
		expect(hint.find('a').attributes('href')).toBe('/settings/user/security')
	})

	it('reports a failed deny without marking it denied', async () => {
		store.deny.mockRejectedValue(new Error('Network down'))
		store.pending = [REQUEST]
		const wrapper = mountDialog()

		await wrapper.vm.deny()

		expect(wrapper.vm.denied).toBe(false)
		expect(wrapper.vm.error).toBe('Network down')
		expect(wrapper.vm.busy).toBe(false)
	})

	it('dismissing moves on to the next request and clears the form', async () => {
		store.pending = [REQUEST, { ...REQUEST, id: 'r2', phrase: 'second' }]
		const wrapper = mountDialog()
		wrapper.vm.masterPassword = 'secret'
		wrapper.vm.error = 'old'

		wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', false)
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.dismissed).toEqual(['r1'])
		expect(wrapper.vm.masterPassword).toBe('')
		expect(wrapper.vm.error).toBeNull()
		expect(wrapper.find('[data-testid="device-approval-phrase"]').text()).toBe(
			'second',
		)
	})
})
