/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the create dialog does after the create call: tags are stored apart
 * from the value, the caller's onSaved callback is called with the created
 * secret, and a failed create shows the server's message (or a fallback)
 * and keeps the dialog open. Also: a cloned identity seeds its fields, and a
 * failed read of the writable team folders offers none.
 *
 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-secret-from-the-ui
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import SecretCreateDialog from '../../src/dialogs/SecretCreateDialog.vue'
import { resetPolicyCache } from '../../src/policy/policy.js'
import { useFolderStore } from '../../src/store/modules/folder.js'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'
import { useSessionStore } from '../../src/store/modules/session.js'
import { useTeamFolderStore } from '../../src/store/modules/teamFolder.js'
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
	NcSelect: {
		name: 'NcSelect',
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
		props: ['modelValue', 'label', 'placeholder', 'disabled', 'required'],
		template: '<input :value="modelValue" />',
	},
	NcPasswordField: {
		props: ['modelValue', 'label'],
		template: '<input type="password" :value="modelValue" />',
	},
	NcNoteCard: { props: ['type'], template: '<div class="note"><slot /></div>' },
	NcLoadingIcon: { template: '<span />' },
	Plus: { template: '<i />' },
	Dice5: { template: '<i />' },
	KeyGeneratorModal: { props: ['open'], template: '<div />' },
	DestinationSelect: {
		name: 'DestinationSelect',
		props: ['modelValue', 'onlyIds', 'label', 'mode'],
		template: '<div class="destination" />',
	},
}

let policy

describe('SecretCreateDialog after the create call', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		resetPolicyCache()
		policy = {}
		const typeStore = useSecretTypeStore()
		typeStore.types = [
			{ id: 'type-login', name: 'login', label: 'Login' },
			{ id: 'type-identity', name: 'identity', label: 'Identity' },
		]
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
				return { data: policy }
			}
			throw new Error('unreachable')
		})
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	/**
	 * Mount the dialog with a name and value filled in.
	 *
	 * @param {object} props The props.
	 * @return {Promise<object>} The wrapper.
	 */
	async function filled(props = {}) {
		const wrapper = mount(SecretCreateDialog, {
			props: { folderId: 'f1', ...props },
			global: { stubs },
		})
		await flushPromises()
		wrapper.vm.name = '  db-root '
		wrapper.vm.value = 'secret-value'
		return wrapper
	}

	it('stores the tags on the created secret and calls onSaved with it', async () => {
		const created = { id: 's1', name: 'db-root' }
		const create = vi
			.spyOn(useSecretStore(), 'createSecret')
			.mockResolvedValue(created)
		const setTags = vi.spyOn(useSecretStore(), 'setTags').mockResolvedValue()
		const onSaved = vi.fn()
		const wrapper = await filled({ onSaved })
		wrapper.vm.tags = ['prod', 'db']

		await wrapper.vm.submit()

		expect(create).toHaveBeenCalledWith(
			expect.objectContaining({
				name: 'db-root',
				typeId: 'type-login',
				folderId: 'f1',
				key: 'secret-value',
				login: '',
				url: null,
			}),
		)
		expect(create.mock.calls[0][0]).not.toHaveProperty('additionalFields')
		expect(setTags).toHaveBeenCalledWith('s1', ['prod', 'db'])
		// Vue also routes the `saved` emit to an `onSaved` prop, so it may run twice; every call carries the created secret.
		expect(onSaved).toHaveBeenCalled()
		expect(onSaved.mock.calls.every(([secret]) => secret === created)).toBe(true)
		expect(wrapper.emitted('saved')).toEqual([[created]])
		expect(wrapper.vm.saving).toBe(false)
	})

	it('stores no tags when none were chosen', async () => {
		vi.spyOn(useSecretStore(), 'createSecret').mockResolvedValue({ id: 's1' })
		const setTags = vi.spyOn(useSecretStore(), 'setTags').mockResolvedValue()
		const wrapper = await filled()

		await wrapper.vm.submit()

		expect(setTags).not.toHaveBeenCalled()
		expect(wrapper.emitted('saved')).toHaveLength(1)
	})

	it('shows the server message when the create fails and emits nothing', async () => {
		vi.spyOn(useSecretStore(), 'createSecret').mockRejectedValue({
			response: { data: { message: 'Folder is read-only' } },
		})
		const wrapper = await filled()

		await wrapper.vm.submit()

		expect(wrapper.vm.error).toBe('Folder is read-only')
		expect(wrapper.emitted('saved')).toBeUndefined()
		expect(wrapper.vm.saving).toBe(false)
	})

	it('falls back to the error message, then to a fixed sentence', async () => {
		const create = vi
			.spyOn(useSecretStore(), 'createSecret')
			.mockRejectedValueOnce(new Error('Network Error'))
		const wrapper = await filled()

		await wrapper.vm.submit()
		expect(wrapper.vm.error).toBe('Network Error')

		create.mockRejectedValueOnce({})
		await wrapper.vm.submit()
		expect(wrapper.vm.error).toBe('Failed to create secret')
	})

	it('does not call the store while the name is empty', async () => {
		const create = vi.spyOn(useSecretStore(), 'createSecret')
		const wrapper = await filled()
		wrapper.vm.name = '   '

		await wrapper.vm.submit()

		expect(create).not.toHaveBeenCalled()
	})

	it('seeds the identity fields from a cloned identity, blanking what it lacks', async () => {
		const wrapper = mount(SecretCreateDialog, {
			props: {
				folderId: 'f1',
				prefill: {
					name: 'Jan',
					typeId: 'type-identity',
					key: JSON.stringify({
						firstName: 'Jan',
						lastName: 'Jansen',
						phone: null,
					}),
				},
			},
			global: { stubs },
		})
		await flushPromises()

		expect(wrapper.vm.identity).toEqual({
			firstName: 'Jan',
			lastName: 'Jansen',
			address: '',
			phone: '',
			email: '',
			bsn: '',
		})
	})

	it('offers no writable team folders when that read fails', async () => {
		policy = { vault_org_ownership: true, vault_org_ownership_types: ['login'] }
		const teamFolderStore = useTeamFolderStore()
		teamFolderStore.fetchTeamFolders = vi.fn(async () => {
			teamFolderStore.owned = [{ id: 'tf', folderId: 'f1' }]
		})
		const wrapper = mount(SecretCreateDialog, {
			props: { folderId: 'f1' },
			global: { stubs },
		})
		await flushPromises()

		expect(wrapper.vm.ownershipApplies).toBe(true)
		expect(wrapper.vm.contributable).toEqual([])
		expect(
			wrapper.findComponent({ name: 'DestinationSelect' }).props('onlyIds'),
		).toEqual(['f1'])
	})
})
