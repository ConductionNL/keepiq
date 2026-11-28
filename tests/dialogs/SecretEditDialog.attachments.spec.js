/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Files added in the edit dialog upload after the save, only for the
 * secret's owner; a refused file keeps the dialog open and a retry never
 * saves the secret a second time.
 *
 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-add-attachments-from-the-create-and-edit-secret-dialogs
 */

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import AttachmentDropZone from '../../src/components/AttachmentDropZone.vue'
import SecretEditDialog from '../../src/dialogs/SecretEditDialog.vue'
import { useAttachmentStore } from '../../src/store/modules/attachment.js'
import { useOfflineStore } from '../../src/store/modules/offline.js'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'
import { useSessionStore } from '../../src/store/modules/session.js'
import { useShareStore } from '../../src/store/modules/share.js'

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
		props: ['options', 'modelValue', 'inputLabel'],
		template: '<div />',
	},
	NcTextField: {
		props: ['modelValue', 'label', 'disabled', 'required'],
		template: '<input :value="modelValue" />',
	},
	NcPasswordField: { props: ['modelValue', 'label'], template: '<input />' },
	NcNoteCard: { props: ['type'], template: '<div class="note"><slot /></div>' },
	NcLoadingIcon: { template: '<span />' },
	KeyGeneratorModal: { props: ['open'], template: '<div />' },
}

const SECRET = {
	id: 's1',
	name: 'db-root',
	typeId: 'login',
	key: 'Xk9#mQ2$vL7@pR4!zT6&',
	ownerId: 'alice',
}

describe('SecretEditDialog attachments', () => {
	let update
	let uploadFile
	let context

	beforeEach(() => {
		setActivePinia(createPinia())
		window.OC = { currentUser: 'alice' }
		const typeStore = useSecretTypeStore()
		typeStore.types = [{ id: 'login', name: 'login', label: 'Login' }]
		typeStore.fetchTypes = vi.fn().mockResolvedValue()
		const session = useSessionStore()
		session.cryptoKey = 'UNLOCKED'
		session.certificate = 'PEM'
		context = { sourceSecretId: 's1' }
		useShareStore().fetchWriteContext = vi.fn(async () => context)
		update = vi
			.spyOn(useSecretStore(), 'updateSecret')
			.mockResolvedValue({ ...SECRET })
		uploadFile = vi.fn().mockResolvedValue()
		useAttachmentStore().uploadFile = uploadFile
	})

	afterEach(() => {
		vi.restoreAllMocks()
		delete window.OC
	})

	/**
	 * @param {object} secret The secret to open.
	 * @return {Promise<object>} The loaded dialog.
	 */
	async function opened(secret = SECRET) {
		useSecretStore().fetchSecret = vi.fn().mockResolvedValue({ ...secret })
		const onSaved = vi.fn()
		const wrapper = mount(SecretEditDialog, {
			props: { secretId: secret.id, onSaved },
			global: { stubs },
		})
		await flushPromises()
		return wrapper
	}

	/**
	 * @param {object} wrapper The dialog.
	 * @param {Array<string>} names The files to add.
	 * @return {Promise<void>}
	 */
	async function addFiles(wrapper, names) {
		wrapper
			.findComponent(AttachmentDropZone)
			.vm.add(names.map((name) => new File(['x'], name)))
		await flushPromises()
	}

	it('uploads a waiting file on a save without field changes, then closes', async () => {
		const wrapper = await opened()
		await addFiles(wrapper, ['cert.pem'])

		await wrapper.vm.submit()
		await flushPromises()

		expect(uploadFile).toHaveBeenCalledWith('s1', expect.any(File))
		expect(wrapper.props('onSaved')).toHaveBeenCalled()
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('stays open on a refused file and retries without saving again', async () => {
		uploadFile.mockRejectedValueOnce(new Error('Attachment quota exceeded'))
		const wrapper = await opened()
		wrapper.vm.name = 'db-root renamed'
		await addFiles(wrapper, ['big.zip'])

		await wrapper.vm.submit()
		await flushPromises()

		expect(update).toHaveBeenCalledTimes(1)
		expect(wrapper.emitted('close')).toBeUndefined()
		expect(wrapper.text()).toContain('Attachment quota exceeded')

		await wrapper.vm.submit()
		await flushPromises()

		expect(update).toHaveBeenCalledTimes(1)
		expect(uploadFile).toHaveBeenCalledTimes(2)
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('holds the retry button while a file uploads on its own Retry', async () => {
		uploadFile.mockRejectedValueOnce(new Error('Too large'))
		const wrapper = await opened()
		await addFiles(wrapper, ['big.zip'])
		await wrapper.vm.submit()
		await flushPromises()
		expect(wrapper.vm.canSubmit).toBe(true)

		wrapper.findComponent(AttachmentDropZone).vm.$emit('update:busy', true)

		expect(wrapper.vm.canSubmit).toBe(false)
	})

	it('keeps a refused file listed with the reason when going offline after the save', async () => {
		uploadFile.mockRejectedValueOnce(new Error('Too large'))
		const wrapper = await opened()
		await addFiles(wrapper, ['big.zip'])
		await wrapper.vm.submit()
		await flushPromises()

		useOfflineStore().servedFromCache = true
		await flushPromises()

		const zone = wrapper.findComponent(AttachmentDropZone)
		expect(zone.text()).toContain('big.zip')
		expect(zone.props('unavailable')).toContain('offline')
		expect(wrapper.emitted('close')).toBeUndefined()
	})

	it('reports the save once, when the dialog closes after the retries', async () => {
		uploadFile
			.mockRejectedValueOnce(new Error('Too large'))
			.mockRejectedValueOnce(new Error('Too large'))
		const wrapper = await opened()
		await addFiles(wrapper, ['big.zip'])

		await wrapper.vm.submit()
		await flushPromises()
		await wrapper.vm.submit()
		await flushPromises()
		expect(wrapper.emitted('saved')).toBeUndefined()

		await wrapper.vm.submit()
		await flushPromises()

		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(wrapper.emitted('saved')).toHaveLength(1)
	})

	it('reports the save when closed with a refused file still listed', async () => {
		uploadFile.mockRejectedValueOnce(new Error('Too large'))
		const wrapper = await opened()
		await addFiles(wrapper, ['big.zip'])
		await wrapper.vm.submit()
		await flushPromises()

		wrapper.vm.onUpdateOpen(false)

		expect(wrapper.emitted('saved')).toHaveLength(1)
	})

	it('keeps added files when going offline and blocks the save until they are removed', async () => {
		const wrapper = await opened()
		await addFiles(wrapper, ['cert.pem'])

		useOfflineStore().servedFromCache = true
		await flushPromises()

		expect(wrapper.findComponent(AttachmentDropZone).text()).toContain(
			'cert.pem',
		)
		expect(wrapper.vm.canSubmit).toBe(false)
	})

	it('uploads nothing on Cancel', async () => {
		const wrapper = await opened()
		await addFiles(wrapper, ['cert.pem'])

		await wrapper.find('[data-testid="secret-edit-cancel"]').trigger('click')

		expect(uploadFile).not.toHaveBeenCalled()
		// The stub button re-fires the native click, so close may come twice.
		expect(wrapper.emitted('close')).toBeTruthy()
	})

	it('offers no drop zone on a shared copy', async () => {
		context = { sourceSecretId: 'source-1', canWrite: true }
		const wrapper = await opened()

		expect(wrapper.findComponent(AttachmentDropZone).exists()).toBe(false)
	})

	it('offers no drop zone on a secret someone else owns', async () => {
		const wrapper = await opened({ ...SECRET, ownerId: 'bob' })

		expect(wrapper.findComponent(AttachmentDropZone).exists()).toBe(false)
	})

	it('offers no drop zone while served from the offline cache', async () => {
		useOfflineStore().servedFromCache = true
		const wrapper = await opened()

		expect(wrapper.findComponent(AttachmentDropZone).exists()).toBe(false)
	})
})
