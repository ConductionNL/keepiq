/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/dialogs/ApplicationDeleteDialog.vue`.
 *
 * The confirmation that replaced a native prompt: it must emit `confirm` only
 * from the destructive button and `close` from Cancel or dismissal.
 *
 * @spec openspec/changes/implement-application-mgmt/tasks.md#task-6.1
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ApplicationDeleteDialog from '../../src/dialogs/ApplicationDeleteDialog.vue'

const stubs = {
	NcDialog: {
		name: 'NcDialog',
		props: ['name', 'open'],
		emits: ['update:open'],
		template:
			'<div :data-open="String(open)"><h1 data-testid="title">{{ name }}</h1><slot /><slot name="actions" /></div>',
	},
	NcNoteCard: {
		props: ['type'],
		template: '<div :class="`note note--${type}`" v-bind="$attrs"><slot /></div>',
	},
	NcButton: {
		emits: ['click'],
		template: '<button v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>',
	},
	NcPasswordField: {
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template:
			'<input v-bind="$attrs" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options'],
		emits: ['update:modelValue'],
		template: '<select v-bind="$attrs"></select>',
	},
}

function mountDialog(open = true) {
	return mount(ApplicationDeleteDialog, { props: { open }, global: { stubs } })
}

describe('ApplicationDeleteDialog', () => {
	it('passes its open state through and warns about the cascade', () => {
		const wrapper = mountDialog(false)

		expect(wrapper.find('[data-open]').attributes('data-open')).toBe('false')
		expect(wrapper.find('[data-testid="title"]').text()).toBe('Delete application')
		expect(
			wrapper.find('[data-testid="application-delete-warning"]').text(),
		).toBe('Delete this application? This cascades to its secrets.')
	})

	it('emits confirm, and only confirm, from the delete button', async () => {
		const wrapper = mountDialog()

		await wrapper.find('[data-testid="application-delete-confirm"]').trigger('click')

		expect(wrapper.emitted('confirm')).toHaveLength(1)
		expect(wrapper.emitted('close')).toBeUndefined()
	})

	it('emits close from cancel and from dismissal, never confirm', async () => {
		const wrapper = mountDialog()

		await wrapper.find('[data-testid="application-delete-cancel"]').trigger('click')
		wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', false)

		expect(wrapper.emitted('close')).toHaveLength(2)
		expect(wrapper.emitted('confirm')).toBeUndefined()
	})
})
