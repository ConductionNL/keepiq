/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The tags field offers the holder's existing tags and emits the picked
 * and typed tags in stored form: trimmed, lowercase, unique, at most 32
 * characters each and 20 in all.
 *
 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import SecretTagsField from '../../src/components/SecretTagsField.vue'
import { useSecretStore } from '../../src/store/modules/secret.js'

const NcSelect = {
	name: 'NcSelect',
	props: ['modelValue', 'options', 'inputLabel', 'multiple', 'taggable', 'disabled', 'ariaLabelCombobox'],
	emits: ['update:modelValue'],
	template: '<div class="select" />',
}

/**
 * @param {object} props The field's props.
 * @return {object} The wrapper.
 */
function mountField(props = {}) {
	return mount(SecretTagsField, { props, global: { stubs: { NcSelect } } })
}

describe('SecretTagsField', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('offers the holder\'s existing tags and passes the current tags on', () => {
		useSecretStore().tags = [
			{ tag: 'work', count: 3 },
			{ tag: 'bank', count: 1 },
		]
		const wrapper = mountField({ modelValue: ['work'], disabled: true })
		const select = wrapper.findComponent({ name: 'NcSelect' })

		expect(select.props('options')).toEqual(['work', 'bank'])
		expect(select.props('modelValue')).toEqual(['work'])
		expect(select.props('disabled')).toBe(true)
		expect(select.props('multiple')).toBe(true)
		expect(select.props('taggable')).toBe(true)
		expect(select.props('inputLabel')).toBe('Tags')
	})

	it('says that tags are not encrypted', () => {
		const wrapper = mountField()
		expect(wrapper.find('[data-testid="secret-tags-help"]').text()).toBe(
			'Tags are not encrypted. Server administrators can read them, as they can folder names.',
		)
	})

	it('emits the tags trimmed, lowercased and without duplicates', async () => {
		const wrapper = mountField()
		await wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', [' Work ', 'work', '', 'BANK'])

		expect(wrapper.emitted('update:modelValue')).toEqual([[['work', 'bank']]])
	})

	it('cuts long tags to 32 characters and keeps the first 20', async () => {
		const wrapper = mountField()
		const many = Array.from({ length: 25 }, (_, i) => 'tag' + i)
		await wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', ['x'.repeat(40), ...many])

		const [[emitted]] = wrapper.emitted('update:modelValue')
		expect(emitted).toHaveLength(20)
		expect(emitted[0]).toBe('x'.repeat(32))
		expect(emitted[19]).toBe('tag18')
	})

	it('emits an empty list when the field is cleared', async () => {
		const wrapper = mountField({ modelValue: ['work'] })
		await wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', null)

		expect(wrapper.emitted('update:modelValue')).toEqual([[[]]])
	})
})
