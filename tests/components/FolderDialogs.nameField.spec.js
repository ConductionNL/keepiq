/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden for the top of the folder create and edit forms: the error note
 * (only while there is an error) and the name field, labelled by the level
 * the dialog works at, whose input becomes the dialog's name.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import FolderCreateDialog from '../../src/dialogs/FolderCreateDialog.vue'
import FolderEditDialog from '../../src/dialogs/FolderEditDialog.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const stubs = {
	NcDialog: {
		props: ['name', 'open', 'size'],
		template: '<div><slot /><footer><slot name="actions" /></footer></div>',
	},
	NcNoteCard: {
		props: ['type'],
		template: '<p class="note" :data-type="type"><slot /></p>',
	},
	NcTextField: {
		props: ['modelValue', 'label', 'required'],
		emits: ['update:modelValue'],
		template:
			'<input :aria-label="label" :value="modelValue" :data-required="String(required)" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	NcSelect: { props: ['options', 'inputLabel'], template: '<div class="select" />' },
	CnIconColorPicker: { template: '<div class="picker" />' },
	CnDialogActions: { template: '<div class="actions" />' },
}

/**
 * @param {object} wrapper The mounted dialog.
 * @return {string} The form markup without scope ids, comments or layout whitespace.
 */
function formHtml(wrapper) {
	return wrapper
		.find('.folder-form')
		.html()
		.replace(/ data-v-[0-9a-f]+=""/g, '')
		.replace(/<!--[\s\S]*?-->/g, '')
		.replace(/>\s+</g, '><')
}

/**
 * @param {object} wrapper The mounted dialog.
 * @return {Promise<object>} The form before and after typing a name and showing an error.
 */
async function exercise(wrapper) {
	await flush()
	const empty = formHtml(wrapper)
	const input = wrapper.find('input')
	input.element.value = 'Renamed'
	await input.trigger('input')
	const typedName = wrapper.vm.name
	wrapper.vm.error = 'Duplicate name'
	await wrapper.vm.$nextTick()
	return { empty, typedName, withError: formHtml(wrapper) }
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.restoreAllMocks()
	vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
})

describe('folder dialogs name field (golden)', () => {
	it('create, vault level', async () => {
		const wrapper = mount(FolderCreateDialog, { global: { stubs } })
		expect(await exercise(wrapper)).toMatchSnapshot()
	})

	it('create, folder level', async () => {
		const wrapper = mount(FolderCreateDialog, {
			props: { parentId: 'v-1' },
			global: { stubs },
		})
		expect(await exercise(wrapper)).toMatchSnapshot()
	})

	it('edit, vault level', async () => {
		const wrapper = mount(FolderEditDialog, {
			props: { folder: { id: 'v-1', name: 'Vault', parentId: null } },
			global: { stubs },
		})
		expect(await exercise(wrapper)).toMatchSnapshot()
	})

	it('edit, folder level', async () => {
		const wrapper = mount(FolderEditDialog, {
			props: { folder: { id: 'f-2', name: 'Docs', parentId: 'v-1' } },
			global: { stubs },
		})
		expect(await exercise(wrapper)).toMatchSnapshot()
	})
})
