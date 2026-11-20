/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A multi-line secret in the dialogs starts masked when it holds a value, so
 * opening Edit on a note or a private key does not put it on screen; an empty
 * one is open to paste into, and a public value is never masked.
 *
 * @spec openspec/specs/builtin-type-fields/spec.md#requirement-built-in-types-carry-their-own-fields
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import MaskedTextArea from '../../src/components/MaskedTextArea.vue'

const stubs = {
	NcTextArea: {
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template:
			'<textarea :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	NcTextField: {
		props: ['modelValue', 'label'],
		template: '<input :value="modelValue" />',
	},
	NcButton: {
		emits: ['click'],
		template: '<button @click="$emit(\'click\')"><slot /></button>',
	},
}

/**
 * Mount the text area.
 *
 * @param {object} props Its props.
 * @return {object} The wrapper.
 */
function mountArea(props) {
	return mount(MaskedTextArea, { props, global: { stubs } })
}

describe('MaskedTextArea', () => {
	it('keeps a stored value out of the page until Show', async () => {
		const wrapper = mountArea({
			modelValue: '-----BEGIN PRIVATE KEY-----',
			label: 'Private key',
		})

		expect(wrapper.find('textarea').exists()).toBe(false)
		expect(wrapper.html()).not.toContain('PRIVATE KEY')

		await wrapper
			.find('[data-testid="masked-text-area-toggle"]')
			.trigger('click')
		expect(wrapper.find('textarea').element.value).toBe(
			'-----BEGIN PRIVATE KEY-----',
		)
	})

	it('opens an empty one, and masks a value loaded into it afterwards', async () => {
		const wrapper = mountArea({ modelValue: '', label: 'Note' })
		expect(wrapper.find('textarea').exists()).toBe(true)

		await wrapper.setProps({ modelValue: 'loaded note' })
		expect(wrapper.find('textarea').exists()).toBe(false)
	})

	it('stays open while the user types', async () => {
		const wrapper = mountArea({ modelValue: '', label: 'Note' })
		await wrapper.find('textarea').setValue('typed')
		await wrapper.setProps({ modelValue: 'typed' })

		expect(wrapper.find('textarea').exists()).toBe(true)
		expect(wrapper.emitted('update:modelValue')[0]).toEqual(['typed'])
	})

	it('never masks a public value', () => {
		const wrapper = mountArea({
			modelValue: 'CERT',
			label: 'Certificate',
			public: true,
		})

		expect(wrapper.find('textarea').element.value).toBe('CERT')
		expect(
			wrapper.find('[data-testid="masked-text-area-toggle"]').exists(),
		).toBe(false)
	})
})
