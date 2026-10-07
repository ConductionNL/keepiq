/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The create dialog preselects the default item type the user saved, and
 * falls back to Login when that type no longer exists (vault-20).
 *
 * @spec openspec/specs/vault-defaults/spec.md#requirement-default-item-type-and-view
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretCreateDialog from '../../src/dialogs/SecretCreateDialog.vue'
import { useFolderStore } from '../../src/store/modules/folder.js'
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
	NcSelect: {
		props: ['options', 'reduce', 'inputLabel', 'clearable', 'modelValue'],
		template: '<div />',
	},
	NcTextField: {
		props: ['modelValue', 'label', 'placeholder', 'disabled', 'required'],
		template: '<input :value="modelValue" :disabled="disabled" />',
	},
	NcPasswordField: {
		props: ['modelValue', 'label'],
		template: '<input type="password" :value="modelValue" />',
	},
	NcNoteCard: { props: ['type'], template: '<div><slot /></div>' },
	NcLoadingIcon: { template: '<span />' },
	Plus: { template: '<i />' },
	Dice5: { template: '<i />' },
	KeyGeneratorModal: { props: ['open'], template: '<div />' },
}

/**
 * Mount the dialog and let its async mounted hook settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountDialog() {
	const wrapper = mount(SecretCreateDialog, { global: { stubs } })
	for (let i = 0; i < 5; i++) {
		await wrapper.vm.$nextTick()
		await Promise.resolve()
	}
	return wrapper
}

describe('SecretCreateDialog default item type', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()

		const typeStore = useSecretTypeStore()
		typeStore.types = [
			{ id: 'type-login', name: 'login', label: 'Login' },
			{ id: 'type-ssh', name: 'ssh_key', label: 'SSH Key' },
		]
		typeStore.fetchTypes = vi.fn().mockResolvedValue()
		const folderStore = useFolderStore()
		folderStore.folders = []
		folderStore.fetchFolders = vi.fn().mockResolvedValue()
		useSessionStore().cryptoKey = 'UNLOCKED'
	})

	it('opens with the saved default type selected', async () => {
		const prefs = useUserPreferencesStore()
		prefs.ensureLoaded = vi.fn(async () => {
			prefs.defaultSecretType = 'ssh_key'
		})

		const wrapper = await mountDialog()

		expect(prefs.ensureLoaded).toHaveBeenCalled()
		expect(wrapper.vm.typeId).toBe('type-ssh')
	})

	it('preselects Login when the saved type was deleted', async () => {
		const prefs = useUserPreferencesStore()
		prefs.ensureLoaded = vi.fn(async () => {
			prefs.defaultSecretType = 'deleted_type'
		})

		const wrapper = await mountDialog()

		expect(wrapper.vm.typeId).toBe('type-login')
	})
})
