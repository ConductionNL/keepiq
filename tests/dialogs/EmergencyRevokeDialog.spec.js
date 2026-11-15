/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/dialogs/EmergencyRevokeDialog.vue`.
 *
 * A controlled confirmation: the password lives in the parent, so the dialog
 * must forward typing as `update:password`, keep Revoke disabled while the
 * password is empty or a revoke is running, and show the parent's error.
 *
 * @spec openspec/specs/emergency-access/spec.md#requirement-revoke-emergency-contact
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import EmergencyRevokeDialog from '../../src/dialogs/EmergencyRevokeDialog.vue'

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

function mountDialog(props = {}) {
	return mount(EmergencyRevokeDialog, {
		props: { open: true, ...props },
		global: { stubs },
	})
}

function confirmButton (wrapper) {
  return wrapper.find('[data-testid="emergency-revoke-confirm"]')
}

describe('EmergencyRevokeDialog', () => {
	it('keeps revoke disabled while the password is empty', () => {
		const wrapper = mountDialog()

		expect(confirmButton(wrapper).attributes('disabled')).toBeDefined()
		expect(confirmButton(wrapper).text()).toBe('Revoke')
	})

	it('enables revoke with a password and emits confirm', async () => {
		const wrapper = mountDialog({ password: 'secret' })

		expect(confirmButton(wrapper).attributes('disabled')).toBeUndefined()
		await confirmButton(wrapper).trigger('click')

		expect(wrapper.emitted('confirm')).toHaveLength(1)
	})

	it('disables everything and relabels while revoking', () => {
		const wrapper = mountDialog({ password: 'secret', revoking: true })

		expect(confirmButton(wrapper).attributes('disabled')).toBeDefined()
		expect(confirmButton(wrapper).text()).toBe('Revoking…')
		expect(wrapper.find('input').attributes('disabled')).toBeDefined()
	})

	it('forwards typing to the parent instead of keeping it', async () => {
		const wrapper = mountDialog()

		await wrapper.find('input').setValue('typed')

		expect(wrapper.emitted('update:password')).toEqual([['typed']])
		expect(wrapper.props('password')).toBe('')
	})

	it('shows the error only when one is given', () => {
		expect(mountDialog().find('.note--error').exists()).toBe(false)
		expect(
			mountDialog({ error: 'Wrong password' }).find('.note--error').text(),
		).toBe('Wrong password')
	})

	it('emits close from cancel and from dismissal', async () => {
		const wrapper = mountDialog()

		const cancel = wrapper.findAll('button').find((b) => b.text() === 'Cancel')
		await cancel.trigger('click')
		wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', false)

		expect(wrapper.emitted('close')).toHaveLength(2)
		expect(wrapper.emitted('confirm')).toBeUndefined()
	})
})
