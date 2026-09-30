/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An administrator creates and edits a global item type and its fields
 * (admin-18).
 *
 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretTypeEditorDialog from '../../src/dialogs/SecretTypeEditorDialog.vue'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'

const stubs = {
	NcDialog: {
		props: ['name', 'open', 'size'],
		template: '<div><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		props: ['disabled', 'variant', 'ariaLabel', 'title'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcSelect: {
		props: [
			'options',
			'reduce',
			'inputLabel',
			'clearable',
			'modelValue',
			'label',
		],
		template: '<div />',
	},
	NcTextField: {
		props: ['modelValue', 'label', 'required'],
		template: '<input :value="modelValue" />',
	},
	NcCheckboxRadioSwitch: {
		props: ['modelValue'],
		template: '<span><slot /></span>',
	},
	NcNoteCard: { props: ['type'], template: '<div><slot /></div>' },
	NcLoadingIcon: { template: '<span />' },
}

describe('SecretTypeEditorDialog', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useSecretTypeStore()
		store.types = [
			{ id: 'type-login', name: 'login', label: 'Login', scope: 'system' },
		]
		store.createType = vi.fn(async (data) => ({ id: 'new', ...data }))
		store.updateType = vi.fn(async (id, label, fields) => ({
			id,
			label,
			fields,
		}))
	})

	it('creates Server access as a global type with its fields in order', async () => {
		const wrapper = mount(SecretTypeEditorDialog, { global: { stubs } })
		wrapper.vm.label = 'Server access'
		wrapper.vm.addField()
		wrapper.vm.addField()
		wrapper.vm.addField()
		Object.assign(wrapper.vm.fields[0], {
			label: 'Host',
			kind: 'url',
			required: true,
		})
		Object.assign(wrapper.vm.fields[1], { label: 'Port', kind: 'text' })
		Object.assign(wrapper.vm.fields[2], {
			label: 'Root password',
			kind: 'hidden',
		})

		await wrapper.vm.save()

		expect(store.createType).toHaveBeenCalledWith({
			name: 'server-access',
			label: 'Server access',
			scope: 'global',
			fields: [
				{ key: 'host', label: 'Host', kind: 'url', required: true },
				{ key: 'port', label: 'Port', kind: 'text', required: false },
				{
					key: 'root-password',
					label: 'Root password',
					kind: 'hidden',
					required: false,
				},
			],
		})
		expect(wrapper.emitted('saved')).toHaveLength(1)
	})

	it('keeps the key of an existing field when its label changes', async () => {
		const type = {
			id: 'type-server',
			name: 'server-access',
			label: 'Server access',
			scope: 'global',
			fields: [{ key: 'host', label: 'Host', kind: 'url', required: true }],
		}
		const wrapper = mount(SecretTypeEditorDialog, {
			props: { type },
			global: { stubs },
		})
		wrapper.vm.fields[0].label = 'Hostname'

		await wrapper.vm.save()

		expect(store.updateType).toHaveBeenCalledWith(
			'type-server',
			'Server access',
			[{ key: 'host', label: 'Hostname', kind: 'url', required: true }],
		)
	})

	it('will not save two fields with the same label', async () => {
		const wrapper = mount(SecretTypeEditorDialog, { global: { stubs } })
		wrapper.vm.label = 'Twin'
		wrapper.vm.addField()
		wrapper.vm.addField()
		wrapper.vm.fields[0].label = 'Host'
		wrapper.vm.fields[1].label = 'host'
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.canSave).toBe(false)
		expect(wrapper.find('[data-testid="type-editor-invalid"]').exists()).toBe(
			true,
		)
		await wrapper.vm.save()
		expect(store.createType).not.toHaveBeenCalled()
	})

	it('moves a field up', () => {
		const wrapper = mount(SecretTypeEditorDialog, { global: { stubs } })
		wrapper.vm.addField()
		wrapper.vm.addField()
		wrapper.vm.fields[0].label = 'A'
		wrapper.vm.fields[1].label = 'B'

		wrapper.vm.move(1, -1)

		expect(wrapper.vm.fields.map((f) => f.label)).toEqual(['B', 'A'])
	})
})
