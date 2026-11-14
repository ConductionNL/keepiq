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
