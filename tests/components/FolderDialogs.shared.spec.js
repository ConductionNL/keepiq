/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the folder create and edit dialogs share: the saved and close events,
 * the create callback, the fallback error texts, the submit guards, the
 * rename that sends no unchanged name and the translated picker labels.
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

const NESTED = { id: 'f-2', name: 'Docs', parentId: 'v-1', customIcon: 'x', customColor: 'y' }

describe('folder dialogs, shared behaviour', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
	})

	it('create: emits saved, calls back, then closes', async () => {
		const created = { id: 'f-9', name: 'New', parentId: 'v-1' }
		vi.spyOn(axios, 'post').mockResolvedValue({ data: created })
		const onSaved = vi.fn()
		const wrapper = mount(FolderCreateDialog, { propsData: { parentId: 'v-1', onSaved } })
		await flush()

		wrapper.vm.name = '  New  '
		await wrapper.vm.submit()
		await flush()

		expect(axios.post.mock.calls[0][1]).toEqual({ name: 'New', parentId: 'v-1' })
		expect(wrapper.emitted('saved')).toHaveLength(1)
		// Called by the dialog itself and, as a Vue `onSaved` listener, by the `saved` event.
		expect(onSaved).toHaveBeenCalledTimes(2)
		expect(onSaved.mock.calls[0][0]).toEqual(wrapper.emitted('saved')[0][0])
		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(wrapper.vm.open).toBe(false)
		expect(wrapper.vm.saving).toBe(false)
	})

	it('create: a failure without a message shows the fallback text and stays open', async () => {
		vi.spyOn(axios, 'post').mockRejectedValue({})
		const wrapper = mount(FolderCreateDialog)
		await flush()

		wrapper.vm.name = 'Vault'
		await wrapper.vm.submit()
		await flush()

		expect(wrapper.vm.error).toBe('Failed to create folder')
		expect(wrapper.vm.saving).toBe(false)
		expect(wrapper.vm.open).toBe(true)
		expect(wrapper.emitted('saved')).toBeFalsy()
	})

	it('create: an error message wins over the fallback', async () => {
		vi.spyOn(axios, 'post').mockRejectedValue(new Error('Network down'))
		const wrapper = mount(FolderCreateDialog)
		await flush()
		wrapper.vm.name = 'Vault'
		await wrapper.vm.submit()
		await flush()
		expect(wrapper.vm.error).toBe('Network down')
	})

	it('edit: a nested rename sends only the changed name, none when unchanged', async () => {
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: { ...NESTED, name: 'Papers' } })
		const wrapper = mount(FolderEditDialog, { propsData: { folder: NESTED } })

		wrapper.vm.name = ' Papers '
		await wrapper.vm.submit()
		await flush()
		expect(put.mock.calls[0][1]).toEqual({ name: 'Papers' })
		expect(wrapper.emitted('saved')).toHaveLength(1)
		expect(wrapper.emitted('close')).toHaveLength(1)

		const again = mount(FolderEditDialog, { propsData: { folder: NESTED } })
		await again.vm.submit()
		await flush()
		expect(put.mock.calls[1][1]).toEqual({})
	})

	it('edit: a failure without a message shows the fallback text', async () => {
		vi.spyOn(axios, 'put').mockRejectedValue({})
		const wrapper = mount(FolderEditDialog, { propsData: { folder: NESTED } })
		await wrapper.vm.submit()
		await flush()
		expect(wrapper.vm.error).toBe('Failed to update folder')
		expect(wrapper.vm.saving).toBe(false)
	})

	it('both: no submit without a name or while saving; dismiss emits close', async () => {
		const post = vi.spyOn(axios, 'post')
		const put = vi.spyOn(axios, 'put')
		const create = mount(FolderCreateDialog)
		await flush()
		expect(create.vm.canSubmit).toBe(false)
		await create.vm.submit()
		create.vm.name = 'X'
		create.vm.saving = true
		expect(create.vm.canSubmit).toBe(false)
		await create.vm.submit()
		expect(post).not.toHaveBeenCalled()

		const edit = mount(FolderEditDialog, { propsData: { folder: { ...NESTED, name: '' } } })
		expect(edit.vm.canSubmit).toBe(false)
		await edit.vm.submit()
		expect(put).not.toHaveBeenCalled()

		for (const wrapper of [create, edit]) {
			wrapper.vm.onUpdateOpen(true)
			expect(wrapper.emitted('close')).toBeFalsy()
			wrapper.vm.onUpdateOpen(false)
			expect(wrapper.emitted('close')).toHaveLength(1)
			expect(wrapper.vm.translateLabel('Default')).toBe('Default')
			expect(wrapper.vm.safeIcon).toBeTruthy()
		}
	})
})
