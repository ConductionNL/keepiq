/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Show as QR code (vault-item-clone-preview-and-print task 3.2): the code is
 * built only after the master password verifies, and the code shown decodes
 * back to the password.
 *
 * @spec openspec/specs/secret-item-actions/spec.md#requirement-print-a-login-or-show-its-password-as-a-qr-code
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretQrModal from '../../src/modals/SecretQrModal.vue'
import { verifyMasterPassword } from '../../src/crypto/reauth.js'
import { useSessionStore } from '../../src/store/modules/session.js'
import { decodeMatrix } from '../vitest/support/decodeQr.js'

vi.mock('../../src/crypto/reauth.js', () => ({
	verifyMasterPassword: vi.fn(),
}))

const stubs = {
	NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
	NcNoteCard: {
		props: ['type'],
		template: '<div :class="`note--${type}`" v-bind="$attrs"><slot /></div>',
	},
	NcButton: {
		emits: ['click'],
		template:
			'<button v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>',
	},
	NcPasswordField: {
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template:
			'<input v-bind="$attrs" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
}

const PASSWORD = 'gast-wifi-2026!'

function mountQr() {
	return mount(SecretQrModal, {
		props: { secretName: 'Guest Wi-Fi', password: PASSWORD },
		global: { stubs },
	})
}

async function enter(wrapper, masterPassword) {
	await wrapper.find('[data-testid="secret-qr-password"]').setValue(masterPassword)
	await wrapper.find('[data-testid="secret-qr-show"]').trigger('click')
	await vi.waitFor(() => expect(wrapper.vm.busy).toBe(false))
	await wrapper.vm.$nextTick()
}

beforeEach(() => {
	setActivePinia(createPinia())
	useSessionStore().encryptedPrivateKey = 'wrapped-key'
	verifyMasterPassword.mockReset()
})

describe('SecretQrModal', () => {
	it('shows no code and says the password is wrong when the master password does not verify', async () => {
		verifyMasterPassword.mockResolvedValue(false)
		const wrapper = mountQr()
		await enter(wrapper, 'wrong')

		expect(verifyMasterPassword).toHaveBeenCalledWith('wrapped-key', 'wrong')
		expect(wrapper.find('[data-testid="secret-qr-code"]').exists()).toBe(false)
		expect(wrapper.vm.matrix).toBeNull()
		expect(wrapper.find('[data-testid="secret-qr-error"]').text()).toBe(
			'Incorrect master password',
		)
		// The typed password is not kept.
		expect(wrapper.vm.masterPassword).toBe('')
	})

	it('shows a code of the password once the master password verifies', async () => {
		verifyMasterPassword.mockResolvedValue(true)
		const wrapper = mountQr()
		await enter(wrapper, 'right')

		const svg = wrapper.find('[data-testid="secret-qr-code"]')
		expect(svg.exists()).toBe(true)
		expect(svg.find('path').attributes('d')).not.toBe('')
		expect(decodeMatrix(wrapper.vm.matrix).data).toBe(PASSWORD)
		expect(wrapper.find('[data-testid="secret-qr-show"]').exists()).toBe(false)
	})

	it('does not check an empty master password', async () => {
		const wrapper = mountQr()
		await wrapper.vm.onVerify()
		expect(verifyMasterPassword).not.toHaveBeenCalled()
		expect(
			wrapper.find('[data-testid="secret-qr-show"]').attributes('disabled'),
		).toBeDefined()
	})

	it('drops the code and emits close when dismissed', async () => {
		verifyMasterPassword.mockResolvedValue(true)
		const wrapper = mountQr()
		await enter(wrapper, 'right')
		await wrapper.find('[data-testid="secret-qr-close"]').trigger('click')

		expect(wrapper.vm.matrix).toBeNull()
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})
