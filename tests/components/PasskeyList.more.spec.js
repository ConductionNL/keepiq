/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * PasskeyList edges: cancelling the confirmation deletes nothing, a confirm
 * with nothing awaiting confirmation emits nothing, a save in progress
 * disables Delete, and focus() moves to the passkey count.
 *
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import PasskeyList from '../../src/components/PasskeyList.vue'

const BANK = {
	credentialId: 'Y3JlZC1iYW5rLTAwMDE',
	rpId: 'bank.example',
	rpName: 'Bank',
	userName: 'alice@example.org',
	privateKey: 'PRIVATE-KEY-BANK',
	createdAt: '2026-09-01T10:00:00Z',
}

const stubs = {
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
	},
	NcDialog: {
		props: ['name', 'open'],
		template: '<div v-if="open"><slot /><slot name="actions" /></div>',
	},
}

/**
 * Mount the list over these credentials.
 *
 * @param {Array<object>} credentials The login's passkeys.
 * @param {object} [props] Other props.
 * @return {object} The wrapper.
 */
function mountList(credentials, props = {}) {
	return mount(PasskeyList, {
		props: {
			passkeysJson: JSON.stringify(credentials),
			canDelete: true,
			...props,
		},
		global: { stubs },
		attachTo: document.body,
	})
}

describe('PasskeyList edges', () => {
	it('deletes nothing when the confirmation is cancelled', async () => {
		const wrapper = mountList([BANK])

		await wrapper.find('[data-testid="passkey-list-delete"]').trigger('click')
		const dialog = wrapper.find('[data-testid="passkey-delete-dialog"]')
		const cancel = dialog
			.findAll('button')
			.find((button) => button.attributes('data-testid') === undefined)
		await cancel.trigger('click')

		expect(wrapper.vm.confirmIndex).toBeNull()
		expect(wrapper.find('[data-testid="passkey-delete-dialog"]').exists()).toBe(
			false,
		)
		expect(wrapper.emitted('delete')).toBeUndefined()
		wrapper.unmount()
	})

	it('emits nothing for a confirm with no passkey awaiting it', () => {
		const wrapper = mountList([BANK])

		wrapper.vm.onConfirm()
		wrapper.vm.confirmIndex = 5
		wrapper.vm.onConfirm()

		expect(wrapper.emitted('delete')).toBeUndefined()
		expect(wrapper.vm.confirmIndex).toBeNull()
		wrapper.unmount()
	})

	it('disables Delete while a delete is being saved', () => {
		const wrapper = mountList([BANK], { busy: true })
		expect(
			wrapper
				.find('[data-testid="passkey-list-delete"]')
				.attributes('disabled'),
		).toBeDefined()
		wrapper.unmount()
	})

	it('moves focus to the passkey count', () => {
		const wrapper = mountList([BANK])

		wrapper.vm.focus()

		expect(document.activeElement).toBe(
			wrapper.find('[data-testid="passkey-list-count"]').element,
		)
		wrapper.unmount()
	})
})
