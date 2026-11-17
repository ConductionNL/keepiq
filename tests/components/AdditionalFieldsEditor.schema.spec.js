/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The additional-fields editor with schemas: a preset sets a member's schema,
 * a change under Advanced that no preset matches shows Custom, and a value
 * that does not fit is reported on its input.
 *
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-presets-pick-a-schema-advanced-shows-it
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-value-must-fit-its-schema
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import AdditionalFieldsEditor from '../../src/components/AdditionalFieldsEditor.vue'
import SchemaAdvanced from '../../src/components/SchemaAdvanced.vue'

/**
 * Mount the editor over a member list.
 *
 * @param {Array<object>} members The members.
 * @return {object} The wrapper.
 */
function mountEditor(members) {
	return mount(AdditionalFieldsEditor, { propsData: { members } })
}

describe('AdditionalFieldsEditor schemas', () => {
	it('gives a member the schema of the picked preset', async () => {
		const wrapper = mountEditor([
			{ name: 'Webhook', value: '', schema: { type: 'string' } },
		])

		const kind = wrapper.findComponent('[data-testid="additional-field-kind-0"]')
		expect(kind.props('modelValue')).toBe('text')
		await kind.vm.$emit('update:modelValue', 'url')

		expect(wrapper.emitted('update:members')[0][0]).toEqual([
			{
				name: 'Webhook',
				value: '',
				schema: { type: 'string', format: 'uri' },
			},
		])
	})

	it('shows Advanced on request and offers Custom for a schema no preset matches', async () => {
		const wrapper = mountEditor([
			{
				name: 'Webhook',
				value: '',
				schema: { type: 'string', format: 'uri', maxLength: 200 },
			},
		])

		expect(
			wrapper.find('[data-testid="additional-field-schema-0"]').exists(),
		).toBe(false)
		await wrapper
			.findComponent('[data-testid="additional-field-advanced-0"]')
			.trigger('click')
		expect(
			wrapper.find('[data-testid="additional-field-schema-0"]').exists(),
		).toBe(true)

		const kind = wrapper.findComponent('[data-testid="additional-field-kind-0"]')
		expect(kind.props('modelValue')).toBe('custom')
		expect(kind.props('options').map((o) => o.value)).toContain('custom')
	})

	it('reports a value that does not fit on its input', () => {
		const wrapper = mountEditor([
			{
				name: 'Port',
				value: '70000',
				schema: { type: 'integer', minimum: 1, maximum: 65535 },
			},
		])

		const input = wrapper.findComponent({ name: 'SchemaValueInput' })
		expect(input.props('error')).toBe('Enter at most 65535.')
	})
})

describe('AdditionalFieldsEditor hidden values', () => {
	// openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-any-custom-field-can-be-hidden
	it.each([
		['multi-line', { type: 'string', format: 'text', hidden: true }, 'masked-text-area-toggle'],
		['JSON', { type: 'object', hidden: true }, 'schema-value-input-json-toggle'],
	])('keeps a hidden %s value out of the page until Show', async (_, schema, toggle) => {
		const value = schema.type === 'object' ? '{"secret":"s3cr3t-recovery"}' : 's3cr3t-recovery'
		const wrapper = mountEditor([{ name: 'Recovery', value, schema }])

		const input = wrapper.findComponent({ name: 'SchemaValueInput' })
		expect(wrapper.html()).not.toContain('s3cr3t-recovery')
		expect(input.findComponent({ name: 'NcTextArea' }).exists()).toBe(false)
		expect(input.findComponent({ name: 'CnJsonViewer' }).exists()).toBe(false)

		await wrapper.find(`[data-testid="${toggle}"]`).trigger('click')
		const shown = schema.type === 'object'
			? input.findComponent({ name: 'CnJsonViewer' }).props('value')
			: input.findComponent({ name: 'NcTextArea' }).props('modelValue')
		expect(shown).toBe(value)
	})

	it('does not pass a removed row’s Show to the row that takes its place', async () => {
		const hidden = { type: 'string', format: 'text', hidden: true }
		const second = { name: 'Second', value: 'second-secret', schema: hidden }
		const wrapper = mountEditor([{ name: 'First', value: 'first-secret', schema: hidden }, second])

		await wrapper.find('[data-testid="masked-text-area-toggle"]').trigger('click')
		await wrapper.findComponent('[data-testid="additional-field-remove-0"]').trigger('click')
		await wrapper.setProps({ members: wrapper.emitted('update:members').at(-1)[0] })

		expect(wrapper.props('members')).toEqual([second])
		expect(wrapper.findComponent({ name: 'NcTextArea' }).exists()).toBe(false)
	})

	it('opens a hidden JSON value that is empty, so it can be typed at once', () => {
		const wrapper = mountEditor([
			{ name: 'Account', value: '', schema: { type: 'object', hidden: true } },
		])

		expect(wrapper.findComponent({ name: 'CnJsonViewer' }).exists()).toBe(true)
	})
})

describe('AdditionalFieldsEditor names of the type', () => {
	// openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-custom-fields-carry-a-schema
	it('refuses a custom field named like one of the type\u2019s own fields', async () => {
		const wrapper = mount(AdditionalFieldsEditor, {
			propsData: { members: [], reservedNames: ['Port'] },
		})

		wrapper.vm.newName = 'Port'
		wrapper.vm.onAdd()
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('update:members')).toBeUndefined()
		expect(wrapper.find('[data-testid="additional-field-error"]').exists()).toBe(
			true,
		)
	})
})

describe('SchemaAdvanced limits', () => {
	// openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-presets-pick-a-schema-advanced-shows-it
	it('says so when a minimum is above its maximum or a pattern is invalid', () => {
		const wrapper = mount(SchemaAdvanced, {
			propsData: {
				schema: { type: 'string', minLength: 5, maxLength: 4, pattern: '[' },
			},
		})

		expect(wrapper.vm.lengthsConflict).toBe(true)
		expect(wrapper.vm.patternInvalid).toBe(true)
		expect(wrapper.vm.conflictText).toBe(
			'The minimum is higher than the maximum.',
		)

		const fine = mount(SchemaAdvanced, {
			propsData: { schema: { type: 'integer', minimum: 1, maximum: 9 } },
		})
		expect(fine.vm.rangeConflict).toBe(false)
		expect(fine.vm.patternInvalid).toBe(false)
	})
})
