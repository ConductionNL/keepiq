/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The template wiring of `src/modals/SubfolderResolutionDialog.vue`: each
 * picker reduces its option to the action value, a pick lands in the
 * resolution plan the delete is sent with, and a dialog opened without a
 * children payload starts from an empty one. The folder store is the
 * mocked boundary; the picker is stubbed.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
 * @spec openspec/specs/secrets/spec.md#scenario-delete-folder-with-subfolders-user-directed-resolution
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SubfolderResolutionDialog from '../../src/modals/SubfolderResolutionDialog.vue'
import { useFolderStore } from '../../src/store/modules/folder.js'

const CHILDREN = {
	directSecretCount: 3,
	subfolders: [
		{ id: 'sub-a', name: 'Subproject A', secretCount: 2 },
		{ id: 'sub-b', name: 'Subproject B', secretCount: 5 },
	],
}

describe('SubfolderResolutionDialog wiring', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	function mountDialog(props) {
		return mount(SubfolderResolutionDialog, {
			props: { open: true, folderId: 'f-parent', ...props },
			global: { stubs: { NcSelect: true } },
		})
	}

	function selects(wrapper) {
		return wrapper.findAllComponents({ name: 'NcSelect' })
	}

	it('starts from an empty plan when no children payload is given', () => {
		const wrapper = mountDialog({})

		expect(wrapper.props('children')).toEqual({ directSecretCount: 0, subfolders: [] })
		expect(wrapper.vm.plan).toEqual({})
		expect(selects(wrapper)).toHaveLength(1)
	})

	it('reduces every picker\'s option to its action value', () => {
		const wrapper = mountDialog({ children: CHILDREN })
		const pickers = selects(wrapper)

		expect(pickers).toHaveLength(3)
		expect(pickers[0].props('reduce')({ value: 'delete', label: 'Delete them' })).toBe('delete')
		expect(pickers[1].props('reduce')({ value: 'move', label: 'Move' })).toBe('move')
		expect(pickers[2].props('reduce')({ value: 'keep', label: 'Keep' })).toBe('keep')
	})

	it('sends the picks made in the pickers with the delete', async () => {
		const folderStore = useFolderStore()
		folderStore.deleteFolder = vi.fn().mockResolvedValue()
		const wrapper = mountDialog({ children: CHILDREN })
		const pickers = selects(wrapper)

		pickers[0].vm.$emit('update:modelValue', 'delete')
		pickers[2].vm.$emit('update:modelValue', 'delete')
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.directSecrets).toBe('delete')
		expect(wrapper.vm.plan).toEqual({ 'sub-a': 'keep', 'sub-b': 'delete' })

		await wrapper.vm.submit()

		expect(folderStore.deleteFolder).toHaveBeenCalledWith('f-parent', {
			directSecrets: 'delete',
			subfolders: { 'sub-a': 'keep', 'sub-b': 'delete' },
		})
	})
})
