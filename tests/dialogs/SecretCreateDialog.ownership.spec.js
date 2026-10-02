/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Under the team folder ownership policy the create dialog offers only the
 * user's own team folders and the team folders they can write to, and a
 * chosen writable team folder saves through the contribution path
 * (admin-vault-policies §4.3).
 *
 * @spec openspec/changes/admin-vault-policies/tasks.md#4.3
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
	NcDialog: { props: ['name', 'open', 'size'], template: '<div><slot /><slot name="actions" /></div>' },
	NcButton: { props: ['disabled', 'variant', 'ariaLabel', 'title'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
	NcSelect: { name: 'NcSelect', props: ['options', 'reduce', 'inputLabel', 'clearable', 'modelValue', 'label'], template: '<div />' },
	NcTextField: { props: ['modelValue', 'label', 'placeholder', 'disabled', 'required'], template: '<input :value="modelValue" />' },
	NcPasswordField: { props: ['modelValue', 'label'], template: '<input type="password" :value="modelValue" />' },
	NcNoteCard: { props: ['type'], template: '<div><slot /></div>' },
	NcLoadingIcon: { template: '<span />' },
	Plus: { template: '<i />' },
	Dice5: { template: '<i />' },
	KeyGeneratorModal: { props: ['open'], template: '<div />' },
	DestinationSelect: { name: 'DestinationSelect', props: ['modelValue', 'onlyIds', 'label', 'mode'], template: '<div class="destination" />' },
}

describe('SecretCreateDialog under the ownership policy', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		resetPolicyCache()
		const typeStore = useSecretTypeStore()
		typeStore.types = [{ id: 'type-login', name: 'login', label: 'Login' }, { id: 'type-card', name: 'card', label: 'Card' }]
		typeStore.fetchTypes = vi.fn().mockResolvedValue()
		const folderStore = useFolderStore()
		folderStore.folders = [
			{ id: 'private', parentId: null },
			{ id: 'team', parentId: null },
			{ id: 'team-sub', parentId: 'team' },
		]
		folderStore.fetchFolders = vi.fn().mockResolvedValue()
		const teamFolderStore = useTeamFolderStore()
		teamFolderStore.fetchTeamFolders = vi.fn(async () => {
			teamFolderStore.owned = [{ id: 'tf', folderId: 'team' }]
		})
		const prefs = useUserPreferencesStore()
		prefs.ensureLoaded = vi.fn().mockResolvedValue()
		prefs.defaultSecretType = 'login'
		useSessionStore().cryptoKey = 'UNLOCKED'
		vi.spyOn(axios, 'get').mockImplementation(async (url) => (url.includes('/settings/policy')
			? { data: { vault_org_ownership: true, vault_org_ownership_types: ['login'] } }
			: { data: [{ teamFolderId: 'tf-ops', folderId: 'folder-ops', folderName: 'Ops' }] }))
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('offers only own team folders and the writable team folders for a login', async () => {
		const wrapper = mount(SecretCreateDialog, { global: { stubs } })
		await flushPromises()

		expect(wrapper.vm.ownershipApplies).toBe(true)
		expect(wrapper.findComponent({ name: 'DestinationSelect' }).props('onlyIds').sort()).toEqual(['team', 'team-sub'])
		expect(wrapper.vm.contributable).toEqual([{ teamFolderId: 'tf-ops', folderId: 'folder-ops', folderName: 'Ops' }])
	})

	it('saves into a writable team folder through the contribution path', async () => {
		const contribute = vi.spyOn(useSecretStore(), 'contributeSecret').mockResolvedValue({ secret: { id: 's1' } })
		const create = vi.spyOn(useSecretStore(), 'createSecret')
		const wrapper = mount(SecretCreateDialog, { global: { stubs } })
		await flushPromises()

		wrapper.vm.name = 'db-root'
		wrapper.vm.value = 'secret-value'
		wrapper.vm.contributeTo = { teamFolderId: 'tf-ops' }
		await wrapper.vm.submit()

		expect(contribute).toHaveBeenCalledWith('tf-ops', expect.objectContaining({ name: 'db-root', key: 'secret-value' }))
		expect(create).not.toHaveBeenCalled()
	})

	it('does not restrict a card', async () => {
		const wrapper = mount(SecretCreateDialog, { global: { stubs } })
		await flushPromises()
		wrapper.vm.typeId = 'type-card'
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.ownershipApplies).toBe(false)
		expect(wrapper.findComponent({ name: 'DestinationSelect' }).props('onlyIds')).toBe(null)
	})
})
