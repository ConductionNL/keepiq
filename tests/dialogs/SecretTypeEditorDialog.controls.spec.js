/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The item type editor's rendered controls: typing a name and a field,
 * choosing a kind, the required switch, moving and removing fields, the
 * dialog footer and the error card.
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
		emits: ['update:open'],
		template: '<div class="dialog" :data-name="name"><button class="dialog-close" @click="$emit(\'update:open\', false)" /><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		props: ['disabled', 'variant', 'ariaLabel', 'title'],
		emits: ['click'],
		template: '<button :aria-label="ariaLabel" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcSelect: {
		props: ['options', 'reduce', 'inputLabel', 'clearable', 'modelValue', 'label'],
		emits: ['update:modelValue'],
		template: '<select class="kind" @change="$emit(\'update:modelValue\', reduce(options[$event.target.selectedIndex]))"><option v-for="o in options" :key="o.value">{{ o.label }}</option></select>',
	},
	NcTextField: {
		props: ['modelValue', 'label', 'required'],
		emits: ['update:modelValue'],
		template: '<input :aria-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	NcCheckboxRadioSwitch: {
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template: '<input type="checkbox" class="required" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)" />',
	},
	NcNoteCard: { props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
	CnDialogActions: {
		props: ['saving', 'canSubmit', 'confirmIcon', 'confirmLabel', 'cancelLabel'],
		emits: ['cancel', 'submit'],
		template: '<div><button class="cancel" @click="$emit(\'cancel\')" /><button class="submit" :disabled="!canSubmit" @click="$emit(\'submit\')" /></div>',
	},
}

const SERVER = {
	id: 'type-server',
	label: 'Server access',
	fields: [
		{ key: 'host', label: 'Host', kind: 'url', required: true },
		{ key: 'port', label: 'Port', kind: 'text', required: false },
	],
}

describe('SecretTypeEditorDialog controls', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useSecretTypeStore()
		store.types = []
		store.createType = vi.fn(async (data) => ({ id: 'new', ...data }))
		store.updateType = vi.fn(async (id, label, fields) => ({ id, label, fields }))
	})

	it('names the dialog after what it does', () => {
		const create = mount(SecretTypeEditorDialog, { global: { stubs } })
		const edit = mount(SecretTypeEditorDialog, { props: { type: SERVER }, global: { stubs } })

		expect(create.find('.dialog').attributes('data-name')).toBe('New item type')
		expect(edit.find('.dialog').attributes('data-name')).toBe('Edit item type')
	})

	it('takes the name, a field label, its kind and the required switch from the controls', async () => {
		const wrapper = mount(SecretTypeEditorDialog, { global: { stubs } })

		await wrapper.find('input[aria-label="Name"]').setValue('Router')
		await wrapper.find('[data-testid="type-editor-add-field"]').trigger('click')
		const row = wrapper.find('[data-testid="type-editor-field-0"]')
		await row.find('input[aria-label="Field name"]').setValue('Admin page')
		const select = row.find('select.kind')
		select.element.selectedIndex = 2
		await select.trigger('change')
		await row.find('input.required').setValue(true)

		expect(wrapper.vm.label).toBe('Router')
		expect(wrapper.vm.fields[0]).toMatchObject({ label: 'Admin page', kind: 'url', required: true })
	})

	it('moves a field up and removes one through their buttons', async () => {
		const wrapper = mount(SecretTypeEditorDialog, { props: { type: SERVER }, global: { stubs } })

		const firstUp = wrapper.find('[data-testid="type-editor-field-0"] button[aria-label="Move up"]')
		expect(firstUp.attributes('disabled')).toBeDefined()

		await wrapper.find('[data-testid="type-editor-field-1"] button[aria-label="Move up"]').trigger('click')
		expect(wrapper.vm.fields.map((f) => f.key)).toEqual(['port', 'host'])

		await wrapper.find('[data-testid="type-editor-field-0"] button[aria-label="Remove this field"]').trigger('click')
		expect(wrapper.vm.fields.map((f) => f.key)).toEqual(['host'])
	})

	it('ignores a move past either end', () => {
		const wrapper = mount(SecretTypeEditorDialog, { props: { type: SERVER }, global: { stubs } })

		wrapper.vm.move(0, -1)
		wrapper.vm.move(1, 1)

		expect(wrapper.vm.fields.map((f) => f.key)).toEqual(['host', 'port'])
	})

	it('closes from the dialog and from cancel', async () => {
		const wrapper = mount(SecretTypeEditorDialog, { props: { open: true }, global: { stubs } })

		await wrapper.find('.dialog-close').trigger('click')
		await wrapper.find('button.cancel').trigger('click')

		expect(wrapper.emitted('update:open')).toEqual([[false], [false]])
	})

	it('saves from the footer and shows the server message when saving fails', async () => {
		store.updateType = vi.fn(async () => {
			throw { response: { data: { message: 'A type with this name exists' } } }
		})
		const wrapper = mount(SecretTypeEditorDialog, { props: { type: SERVER }, global: { stubs } })

		await wrapper.find('button.submit').trigger('click')
		await vi.waitFor(() => expect(wrapper.vm.saving).toBe(false))

		expect(store.updateType).toHaveBeenCalledWith('type-server', 'Server access', [
			{ key: 'host', label: 'Host', kind: 'url', required: true },
			{ key: 'port', label: 'Port', kind: 'text', required: false },
		])
		const card = wrapper.find('.note[data-type="error"]')
		expect(card.text()).toBe('A type with this name exists')
		expect(wrapper.emitted('saved')).toBeUndefined()
	})

	it('falls back to its own message when the server gives none', async () => {
		store.createType = vi.fn(async () => {
			throw new Error('network')
		})
		const wrapper = mount(SecretTypeEditorDialog, { global: { stubs } })
		await wrapper.find('input[aria-label="Name"]').setValue('Router')

		await wrapper.vm.save()
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.note[data-type="error"]').text()).toBe('Could not save the item type.')
	})
})
