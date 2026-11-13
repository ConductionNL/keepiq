/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for the controls of `src/dialogs/ApplicationRegisterDialog.vue`
 * that the submit tests do not reach: Cancel, switching the type back to
 * internal, and reading an uploaded CSR file into the PEM field.
 *
 * @spec openspec/specs/application-mgmt/spec.md#requirement-encryptionsuite-via-csr
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ApplicationRegisterDialog from '../../src/dialogs/ApplicationRegisterDialog.vue'
import { useApplicationStore } from '../../src/store/modules/application.js'

/**
 * Mount the dialog open.
 *
 * @return {object} The wrapper.
 */
function mountDialog() {
	return mount(ApplicationRegisterDialog, { propsData: { open: true } })
}

/**
 * The radio stub carrying a test id.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} testid The data-testid.
 * @return {object} The component wrapper.
 */
function radio(wrapper, testid) {
	return wrapper
		.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		.find((c) => c.attributes('data-testid') === testid)
}

/**
 * Fire a change on the CSR file input with the given files.
 *
 * @param {object} wrapper The wrapper.
 * @param {Array<File>} files The selected files.
 * @return {Promise<void>}
 */
async function uploadCsr(wrapper, files) {
	const input = wrapper.find('[data-testid="application-register-csr-upload"]')
	Object.defineProperty(input.element, 'files', { value: files, configurable: true })
	await input.trigger('change')
}

describe('ApplicationRegisterDialog controls', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('emits close on Cancel without registering anything', async () => {
		const register = vi.spyOn(useApplicationStore(), 'registerApplication')
		const wrapper = mountDialog()

		await wrapper.find('[data-testid="application-register-cancel"]').trigger('click')

		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(register).not.toHaveBeenCalled()
	})

	it('switches the type back to internal after external was picked', async () => {
		const wrapper = mountDialog()

		radio(wrapper, 'application-register-type-external').vm.$emit('update:modelValue', 'external')
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.form.type).toBe('external')

		radio(wrapper, 'application-register-type-internal').vm.$emit('update:modelValue', 'internal')
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.form.type).toBe('internal')
	})

	it('reads an uploaded CSR file into the PEM field', async () => {
		const pem = '-----BEGIN CERTIFICATE REQUEST-----\nMIIB\n-----END CERTIFICATE REQUEST-----'
		const wrapper = mountDialog()

		await uploadCsr(wrapper, [new File([pem], 'app.csr', { type: 'text/plain' })])

		await vi.waitFor(() => expect(wrapper.vm.form.csr).toBe(pem))
	})

	it('leaves the PEM field alone when the picker is cancelled', async () => {
		const wrapper = mountDialog()
		wrapper.vm.form.csr = 'typed by hand'

		await uploadCsr(wrapper, [])

		expect(wrapper.vm.form.csr).toBe('typed by hand')
	})
})
