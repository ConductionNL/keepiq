/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Files added in the create dialog upload after the secret is created; a
 * refused file keeps the dialog open, and a retry never creates the secret
 * a second time.
 *
 * @spec openspec/changes/attachments-in-secret-modals/specs/encrypted-attachments/spec.md#requirement-add-attachments-from-the-create-and-edit-secret-dialogs
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import AttachmentDropZone from '../../src/components/AttachmentDropZone.vue'
import SecretCreateDialog from '../../src/dialogs/SecretCreateDialog.vue'
import { resetPolicyCache } from '../../src/policy/policy.js'
import { useAttachmentStore } from '../../src/store/modules/attachment.js'
import { useFolderStore } from '../../src/store/modules/folder.js'
import { useOfflineStore } from '../../src/store/modules/offline.js'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'
import { useSessionStore } from '../../src/store/modules/session.js'
import { useUserPreferencesStore } from '../../src/store/modules/userPreferences.js'

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
	NcSelect: { props: ['options', 'modelValue', 'inputLabel'], template: '<div />' },
	NcTextField: {
		props: ['modelValue', 'label', 'disabled', 'required'],
		template: '<input :value="modelValue" />',
	},
	NcPasswordField: { props: ['modelValue', 'label'], template: '<input />' },
	NcNoteCard: { props: ['type'], template: '<div class="note"><slot /></div>' },
	NcLoadingIcon: { template: '<span />' },
	KeyGeneratorModal: { props: ['open'], template: '<div />' },
	DestinationSelect: {
		props: ['modelValue', 'onlyIds', 'label', 'mode'],
		template: '<div />',
	},
}

describe('SecretCreateDialog attachments', () => {
	let create
	let uploadFile

	beforeEach(() => {
		setActivePinia(createPinia())
		resetPolicyCache()
		const typeStore = useSecretTypeStore()
		typeStore.types = [{ id: 'type-login', name: 'login', label: 'Login' }]
		typeStore.fetchTypes = vi.fn().mockResolvedValue()
		const folderStore = useFolderStore()
		folderStore.folders = [{ id: 'f1', parentId: null }]
		folderStore.fetchFolders = vi.fn().mockResolvedValue()
		const prefs = useUserPreferencesStore()
		prefs.ensureLoaded = vi.fn().mockResolvedValue()
		prefs.defaultSecretType = 'login'
		useSessionStore().cryptoKey = 'UNLOCKED'
		vi.spyOn(axios, 'get').mockImplementation(async (url) => {
			if (url.includes('/settings/policy')) {
				return { data: {} }
			}
			throw new Error('unreachable')
		})
		create = vi
			.spyOn(useSecretStore(), 'createSecret')
			.mockResolvedValue({ id: 's1', name: 'db-root' })
		uploadFile = vi.fn().mockResolvedValue()
		useAttachmentStore().uploadFile = uploadFile
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	/**
	 * @param {Array<string>} names The files to add before submitting.
	 * @return {Promise<object>} The filled-in dialog.
	 */
	async function withFiles(names) {
		const wrapper = mount(SecretCreateDialog, {
			props: { folderId: 'f1' },
			global: { stubs },
		})
		await flushPromises()
		wrapper.vm.name = 'db-root'
		wrapper.vm.value = 'secret-value'
		wrapper
			.findComponent(AttachmentDropZone)
			.vm.add(names.map((name) => new File(['x'], name)))
		await flushPromises()
		return wrapper
	}

	it('uploads the files to the created secret and closes', async () => {
		const wrapper = await withFiles(['codes.png'])

		await wrapper.vm.submit()
		await flushPromises()

		expect(create).toHaveBeenCalledTimes(1)
		expect(uploadFile).toHaveBeenCalledWith('s1', expect.any(File))
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('stays open on a refused file and only retries the upload', async () => {
		uploadFile.mockRejectedValueOnce(new Error('Attachment quota exceeded'))
		const wrapper = await withFiles(['big.zip'])

		await wrapper.vm.submit()
		await flushPromises()

		expect(wrapper.emitted('saved')).toHaveLength(1)
		expect(wrapper.emitted('close')).toBeUndefined()
		expect(wrapper.text()).toContain('Attachment quota exceeded')
		expect(wrapper.vm.canSubmit).toBe(true)

		await wrapper.vm.submit()
		await flushPromises()

		expect(create).toHaveBeenCalledTimes(1)
		expect(uploadFile).toHaveBeenCalledTimes(2)
		expect(wrapper.emitted('saved')).toHaveLength(1)
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('closes when the refused file is removed', async () => {
		uploadFile.mockRejectedValueOnce(new Error('Too large'))
		const wrapper = await withFiles(['big.zip'])
		await wrapper.vm.submit()
		await flushPromises()

		await wrapper
			.find('[data-testid="attachment-pending-remove-0"]')
			.trigger('click')
		await flushPromises()

		expect(create).toHaveBeenCalledTimes(1)
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('offers no drop zone when contributing to a team folder', async () => {
		const wrapper = await withFiles([])
		wrapper.vm.contributeTo = { teamFolderId: 't1', folderName: 'Ops' }
		await flushPromises()

		expect(wrapper.findComponent(AttachmentDropZone).exists()).toBe(false)
	})

	it('offers no drop zone while served from the offline cache', async () => {
		useOfflineStore().servedFromCache = true
		const wrapper = mount(SecretCreateDialog, {
			props: { folderId: 'f1' },
			global: { stubs },
		})
		await flushPromises()

		expect(wrapper.findComponent(AttachmentDropZone).exists()).toBe(false)
	})
})
