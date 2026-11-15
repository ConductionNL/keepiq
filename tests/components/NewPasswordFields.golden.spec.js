/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden for the new-password part of the master password change and the
 * compromise recovery forms: the new password field, the strength meter
 * (only once something is typed), the confirmation field, all disabled
 * while a change runs, with typing and the meter's verdict reaching the form.
 *
 * @spec openspec/specs/encryption-suites/spec.md
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import CompromiseRecoveryForm from '../../src/components/CompromiseRecoveryForm.vue'
import MasterPasswordForm from '../../src/components/MasterPasswordForm.vue'

const stubs = {
	NcPasswordField: {
		props: ['modelValue', 'label', 'disabled'],
		emits: ['update:modelValue'],
		template:
			'<input class="pw" :aria-label="label" :value="modelValue" :data-disabled="String(disabled)" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	PasswordStrengthMeter: {
		name: 'PasswordStrengthMeter',
		props: ['password'],
		emits: ['strengthChange'],
		template: '<meter class="strength" :data-password-length="password.length" />',
	},
	NcNoteCard: { props: ['type'], template: '<p class="note" :data-type="type"><slot /></p>' },
	NcButton: { template: '<button><slot /></button>' },
	NcCheckboxRadioSwitch: { template: '<label><slot /></label>' },
	NcProgressBar: { template: '<progress />' },
}

/**
 * @param {object} wrapper The mounted form.
 * @return {string} The password inputs and the meter, without scope ids or layout whitespace.
 */
function fieldsHtml(wrapper) {
	return wrapper
		.findAll('.pw, .strength')
		.map((node) => node.html().replace(/ data-v-[0-9a-f]+=""/g, ''))
		.join('')
}

/**
 * @param {object} wrapper The mounted form.
 * @param {string} label The field label.
 * @param {string} text What to type.
 */
async function type(wrapper, label, text) {
	const input = wrapper.find(`input[aria-label="${label}"]`)
	input.element.value = text
	await input.trigger('input')
}

beforeEach(() => {
	setActivePinia(createPinia())
})

describe.each([
	['MasterPasswordForm', MasterPasswordForm],
	['CompromiseRecoveryForm', CompromiseRecoveryForm],
])('%s new password fields (golden)', (_name, component) => {
	it('renders, takes input, shows the meter once typed and takes its verdict', async () => {
		const wrapper = mount(component, { global: { stubs } })
		const empty = fieldsHtml(wrapper)
		await type(wrapper, 'New password', 'correct horse')
		await type(wrapper, 'Confirm new password', 'correct horse')
		const typed = fieldsHtml(wrapper)
		expect(wrapper.vm.newPassword).toBe('correct horse')
		expect(wrapper.vm.confirmPassword).toBe('correct horse')
		expect(wrapper.vm.strengthValid).toBe(false)
		wrapper.findComponent({ name: 'PasswordStrengthMeter' }).vm.$emit('strengthChange', { isValid: true })
		expect(wrapper.vm.strengthValid).toBe(true)
		wrapper.vm.loading = true
		await wrapper.vm.$nextTick()
		const disabled = fieldsHtml(wrapper)
		expect({ empty, typed, disabled }).toMatchSnapshot()
	})
})
