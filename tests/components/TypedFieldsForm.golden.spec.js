/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden for the typed fields of an item type: per kind (text, hidden, url,
 * email) the field component and the props it gets — value, label with the
 * required marker, required, disabled, the missing error and its helper
 * text, the test id and, for text-like kinds, the input type — and that
 * typing reports the field's new value by key.
 *
 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import TypedFieldsForm from '../../src/components/TypedFieldsForm.vue'

/**
 * @param {string} kind The stubbed component's name.
 * @return {object} A field stub that records its props and attributes.
 */
function recorder(kind) {
	return {
		name: kind,
		inheritAttrs: false,
		props: ['modelValue', 'label', 'required', 'disabled', 'error', 'helperText', 'type'],
		emits: ['update:modelValue'],
		template: `<input data-kind="${kind}" v-bind="$attrs" @input="$emit('update:modelValue', $event.target.value)" />`,
	}
}

const FIELDS = [
	{ key: 'host', label: 'Host', kind: 'text', required: true },
	{ key: 'pin', label: 'PIN', kind: 'hidden', required: true },
	{ key: 'site', label: 'Site', kind: 'url', required: false },
	{ key: 'mail', label: 'Mail', kind: 'email' },
]

/**
 * @param {object} wrapper The mounted form.
 * @return {Array<object>} Per rendered field: its component, props and attributes.
 */
function rendered(wrapper) {
	return wrapper
		.findAllComponents({ name: 'NcPasswordField' })
		.concat(wrapper.findAllComponents({ name: 'NcTextField' }))
		.map((field) => ({
			component: field.vm.$options.name,
			props: { ...field.props() },
			attrs: Object.fromEntries(
				Object.entries(field.attributes()).filter(([key]) => !key.startsWith('data-v-')),
			),
		}))
		.sort((a, b) => a.attrs['data-testid'].localeCompare(b.attrs['data-testid']))
}

describe('TypedFieldsForm (golden)', () => {
	it('renders each kind with its props, marking a missing required field', () => {
		const wrapper = mount(TypedFieldsForm, {
			props: { fields: FIELDS, values: { host: 'db1', site: 'https://x' }, missing: ['pin'], disabled: false },
			global: { stubs: { NcPasswordField: recorder('NcPasswordField'), NcTextField: recorder('NcTextField') } },
		})
		expect(rendered(wrapper)).toMatchSnapshot()
	})

	it('renders every field disabled, and reports typing by key', async () => {
		const wrapper = mount(TypedFieldsForm, {
			props: { fields: FIELDS, values: { pin: '1234' }, disabled: true },
			global: { stubs: { NcPasswordField: recorder('NcPasswordField'), NcTextField: recorder('NcTextField') } },
		})
		expect(rendered(wrapper)).toMatchSnapshot()
		const pin = wrapper.find('[data-testid="typed-field-pin"]')
		pin.element.value = '9999'
		await pin.trigger('input')
		const mail = wrapper.find('[data-testid="typed-field-mail"]')
		mail.element.value = 'a@b.c'
		await mail.trigger('input')
		expect(wrapper.emitted('update:values')).toMatchSnapshot()
	})
})
