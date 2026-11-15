/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/dialogs/BulkTeamFolderDialog.vue` outside the
 * fan-out: the team-folder choices (named after their folder, or by folder id
 * when the folder is not loaded), the per-secret move into the chosen folder,
 * a failed team-folder load on mount, and both ways of closing.
 *
 * @spec openspec/specs/bulk-actions/spec.md#requirement-the-four-bulk-operations
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import BulkTeamFolderDialog from '../../src/dialogs/BulkTeamFolderDialog.vue'
import { useFolderStore } from '../../src/store/modules/folder.js'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useTeamFolderStore } from '../../src/store/modules/teamFolder.js'

const STUBS = {
	NcDialog: {
		emits: ['update:open'],
		template: '<div><button data-testid="dismiss" @click="$emit(\'update:open\', false)" /><slot /><slot name="actions" /></div>',
	},
	NcButton: { template: '<button v-bind="$attrs"><slot /></button>' },
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options'],
		emits: ['update:modelValue'],
		template: '<div class="stub-select" />',
	},
	NcNoteCard: { template: '<div><slot /></div>' },
}

/**
 * Mount the dialog open.
 *
 * @return {object} The wrapper.
 */
function mountDialog() {
	return mount(BulkTeamFolderDialog, {
		propsData: { open: true },
		global: { stubs: STUBS },
	})
}

const flushPromises = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('BulkTeamFolderDialog choices and move', () => {
	let teamFolders

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		teamFolders = useTeamFolderStore()
		teamFolders.fetchTeamFolders = vi.fn().mockResolvedValue()
	})

	it('offers each owned team folder under its folder name, else its folder id', () => {
		useFolderStore().folders = [{ id: 'f-1', name: 'Finance' }]
		teamFolders.owned = [
			{ id: 'tf-1', folderId: 'f-1' },
			{ id: 'tf-2', folderId: 'f-unloaded' },
		]
		const wrapper = mountDialog()

		expect(wrapper.findComponent({ name: 'NcSelect' }).props('options')).toEqual([
			{ id: 'tf-1', folderId: 'f-1', label: 'Finance' },
			{ id: 'tf-2', folderId: 'f-unloaded', label: 'f-unloaded' },
		])
	})

	it('takes the picked team folder as the target and enables the run', async () => {
		const wrapper = mountDialog()
		expect(wrapper.find('[data-testid="bulk-team-folder-run"]').attributes('disabled')).toBeDefined()

		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', { id: 'tf-1', folderId: 'f-1' })
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.target).toEqual({ id: 'tf-1', folderId: 'f-1' })
		expect(wrapper.find('[data-testid="bulk-team-folder-run"]').attributes('disabled')).toBeUndefined()
	})

	it('moves one secret by setting only its folder to the target folder', async () => {
		const update = vi.spyOn(useSecretStore(), 'updateSecret').mockResolvedValue({})
		const wrapper = mountDialog()
		wrapper.vm.target = { id: 'tf-1', folderId: 'f-1' }

		await expect(wrapper.vm.moveOne('s-9')).resolves.toEqual({ status: 'ok' })
		expect(update).toHaveBeenCalledWith('s-9', { folderId: 'f-1' })
	})

	it('still mounts when loading the team folders fails', async () => {
		teamFolders.fetchTeamFolders = vi.fn().mockRejectedValue(new Error('offline'))
		const wrapper = mountDialog()
		await flushPromises()

		expect(teamFolders.fetchTeamFolders).toHaveBeenCalledTimes(1)
		expect(wrapper.find('[data-testid="bulk-team-folder-close"]').exists()).toBe(true)
	})

	it('emits close from the Close button and from dismissing the dialog', async () => {
		const wrapper = mountDialog()

		await wrapper.find('[data-testid="bulk-team-folder-close"]').trigger('click')
		await wrapper.find('[data-testid="dismiss"]').trigger('click')

		expect(wrapper.emitted('close')).toHaveLength(2)
	})
})
