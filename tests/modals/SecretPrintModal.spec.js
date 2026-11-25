/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Print a login (vault-item-clone-preview-and-print task 3.1): a wrong master
 * password renders no sheet and opens no print dialog; a right one renders
 * the name, address, username and password, never a one-time code seed, and
 * opens the browser's print dialog.
 *
 * @spec openspec/specs/secret-item-actions/spec.md#requirement-print-a-login-or-show-its-password-as-a-qr-code
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import SecretPrintModal from '../../src/modals/SecretPrintModal.vue'
import { verifyMasterPassword } from '../../src/crypto/reauth.js'
import { useSessionStore } from '../../src/store/modules/session.js'

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

const LOGIN = {
	name: 'Grafana staging',
	url: 'https://grafana.staging.example',
	login: 'ops',
	password: 'Xk9#mQ2$vL7@pR4!zT6&',
}

let wrapper

function mountPrint() {
	wrapper = mount(SecretPrintModal, {
		props: { ...LOGIN },
		global: { stubs },
		attachTo: document.body,
	})
	return wrapper
}

async function enter(masterPassword) {
	await wrapper
		.find('[data-testid="secret-print-password-field"]')
		.setValue(masterPassword)
	await wrapper.find('[data-testid="secret-print-submit"]').trigger('click')
	await vi.waitFor(() => expect(wrapper.vm.busy).toBe(false))
	await wrapper.vm.$nextTick()
	await wrapper.vm.$nextTick()
}

/**
 * The teleported sheet, looked up in the document body.
 *
 * @return {Element|null}
 */
function sheet() {
	return document.body.querySelector('[data-testid="secret-print-sheet"]')
}

beforeEach(() => {
	setActivePinia(createPinia())
	useSessionStore().encryptedPrivateKey = 'wrapped-key'
	verifyMasterPassword.mockReset()
	window.print = vi.fn()
})

afterEach(() => {
	wrapper?.unmount()
	wrapper = null
})

describe('SecretPrintModal', () => {
	it('renders no sheet, opens no print dialog and says the password is wrong on a wrong master password', async () => {
		verifyMasterPassword.mockResolvedValue(false)
		mountPrint()
		await enter('wrong')

		expect(verifyMasterPassword).toHaveBeenCalledWith('wrapped-key', 'wrong')
		expect(sheet()).toBeNull()
		expect(window.print).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="secret-print-error"]').text()).toBe(
			'Incorrect master password',
		)
	})

	it('renders the name, address, username and password and opens the print dialog on the right one', async () => {
		verifyMasterPassword.mockResolvedValue(true)
		mountPrint()
		await enter('right')

		const printed = sheet()
		expect(printed).not.toBeNull()
		expect(
			printed
				.querySelector('[data-testid="secret-print-name"]')
				.textContent.trim(),
		).toBe(LOGIN.name)
		expect(
			printed
				.querySelector('[data-testid="secret-print-url"]')
				.textContent.trim(),
		).toBe(LOGIN.url)
		expect(
			printed
				.querySelector('[data-testid="secret-print-login"]')
				.textContent.trim(),
		).toBe(LOGIN.login)
		expect(
			printed
				.querySelector('[data-testid="secret-print-password"]')
				.textContent.trim(),
		).toBe(LOGIN.password)
		expect(window.print).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.masterPassword).toBe('')
	})

	it('takes no one-time code seed, so none can be printed', () => {
		expect(Object.keys(SecretPrintModal.props)).toEqual([
			'name',
			'url',
			'login',
			'password',
		])
	})

	it('prints again on request and removes the sheet when closed', async () => {
		verifyMasterPassword.mockResolvedValue(true)
		mountPrint()
		await enter('right')
		await wrapper.find('[data-testid="secret-print-again"]').trigger('click')
		expect(window.print).toHaveBeenCalledTimes(2)

		await wrapper.find('[data-testid="secret-print-close"]').trigger('click')
		await wrapper.vm.$nextTick()
		expect(sheet()).toBeNull()
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('leaves out an address or username the login does not have', async () => {
		verifyMasterPassword.mockResolvedValue(true)
		wrapper = mount(SecretPrintModal, {
			props: { name: 'Router', password: 'p' },
			global: { stubs },
			attachTo: document.body,
		})
		await enter('right')
		expect(sheet().querySelector('[data-testid="secret-print-url"]')).toBeNull()
		expect(
			sheet().querySelector('[data-testid="secret-print-login"]'),
		).toBeNull()
	})
})
