/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for how `src/views/EmergencyAccessView.vue` wires the revoke
 * dialog: the Revoke button of a contact opens the dialog for that contact,
 * the password the dialog reports is the one the store revokes with, and the
 * dialog's close and confirm events reach the view.
 *
 * @spec openspec/specs/emergency-access/spec.md#requirement-revoke-emergency-contact
 */

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import EmergencyAccessView from '../../src/views/EmergencyAccessView.vue'
import { useEmergencyAccessStore } from '../../src/store/modules/emergencyAccess.js'

const DialogStub = {
	name: 'EmergencyRevokeDialog',
	props: ['open', 'password', 'revoking', 'error'],
	emits: ['update:password', 'close', 'confirm'],
	template: `<div v-if="open" class="revoke-dialog">
		<input class="pw" :value="password" @input="$emit('update:password', $event.target.value)" />
		<button class="close" @click="$emit('close')" />
		<button class="confirm" @click="$emit('confirm')" />
	</div>`,
}

/**
 * Mount the view with two designated contacts.
 *
 * @return {Promise<{wrapper: object, store: object}>} The wrapper and store.
 */
async function mountView() {
	const store = useEmergencyAccessStore()
	vi.spyOn(store, 'fetchContacts').mockResolvedValue()
	vi.spyOn(store, 'fetchIncoming').mockResolvedValue()
	store.contacts = [
		{ id: 'rel-1', granteeUserId: 'bob', state: 'granted', waitPeriodDays: 7 },
		{ id: 'rel-2', granteeUserId: 'carol', state: 'granted', waitPeriodDays: 7 },
	]
	store.incoming = []

	const wrapper = mount(EmergencyAccessView, {
		global: {
			stubs: {
				NcButton: { template: '<button v-bind="$attrs"><slot /></button>' },
				NcNoteCard: { template: '<div><slot /></div>' },
				NcPasswordField: { template: '<input />' },
				NcTextField: { template: '<input />' },
				NcSelect: { template: '<div />' },
				NcEmptyContent: { template: '<div />' },
				EmergencyRevokeDialog: DialogStub,
			},
		},
	})
	await flushPromises()
	return { wrapper, store }
}

describe('EmergencyAccessView — revoke dialog wiring', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('opens the dialog for the contact whose Revoke button was clicked', async () => {
		const { wrapper } = await mountView()
		expect(wrapper.find('.revoke-dialog').exists()).toBe(false)

		await wrapper.findAll('[data-testid="emergency-revoke"]')[1].trigger('click')

		expect(wrapper.vm.revokeTarget).toBe('rel-2')
		expect(wrapper.find('.revoke-dialog').exists()).toBe(true)
	})

	it('revokes with the password the dialog reports', async () => {
		const { wrapper, store } = await mountView()
		const revoke = vi.spyOn(store, 'revoke').mockResolvedValue()
		await wrapper.findAll('[data-testid="emergency-revoke"]')[0].trigger('click')

		await wrapper.find('.revoke-dialog .pw').setValue('master-pw')
		expect(wrapper.vm.revokePassword).toBe('master-pw')
		await wrapper.find('.revoke-dialog .confirm').trigger('click')
		await flushPromises()

		expect(revoke).toHaveBeenCalledWith('rel-1', 'master-pw')
		expect(wrapper.vm.revokeTarget).toBeNull()
		expect(wrapper.find('.revoke-dialog').exists()).toBe(false)
	})

	it('closes the dialog without revoking when it is dismissed', async () => {
		const { wrapper, store } = await mountView()
		const revoke = vi.spyOn(store, 'revoke').mockResolvedValue()
		await wrapper.findAll('[data-testid="emergency-revoke"]')[0].trigger('click')
		await wrapper.find('.revoke-dialog .pw').setValue('typed')

		await wrapper.find('.revoke-dialog .close').trigger('click')

		expect(revoke).not.toHaveBeenCalled()
		expect(wrapper.vm.revokeTarget).toBeNull()
		expect(wrapper.vm.revokePassword).toBe('')
	})
})
