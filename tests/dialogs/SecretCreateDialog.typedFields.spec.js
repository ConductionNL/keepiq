/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A type an administrator defined renders its fields in the create dialog;
 * a required field blocks the save, and the values go into the additional
 * fields the secret store encrypts, never as plain request fields (admin-18).
 *
 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
 * @spec openspec/specs/admin-secret-types/spec.md#requirement-typed-fields-storage
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretCreateDialog from '../../src/dialogs/SecretCreateDialog.vue'
import { useFolderStore } from '../../src/store/modules/folder.js'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'
import { useSessionStore } from '../../src/store/modules/session.js'
import { useUserPreferencesStore } from '../../src/store/modules/userPreferences.js'

vi.mock('../../src/policy/policy.js', () => ({
	fetchPolicy: vi.fn().mockResolvedValue(null),
	evaluateScore: vi.fn(() => ({ compliant: true, reason: null })),
	evaluateHibp: vi.fn().mockResolvedValue(null),
}))

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
		props: [
			'modelValue',
			'label',
			'required',
			'error',
			'helperText',
			'type',
			'disabled',
		],
		template:
			'<label :data-error="error">{{ label }}<input :value="modelValue" /></label>',
	},
	NcPasswordField: {
		props: [
			'modelValue',
			'label',
			'required',
			'error',
			'helperText',
			'disabled',
		],
		template:
			'<label :data-error="error">{{ label }}<input type="password" :value="modelValue" /></label>',
	},
	NcNoteCard: { props: ['type'], template: '<div><slot /></div>' },
	NcLoadingIcon: { template: '<span />' },
	Plus: { template: '<i />' },
	Dice5: { template: '<i />' },
	KeyGeneratorModal: { props: ['open'], template: '<div />' },
	AdditionalFieldsEditor: { props: ['members', 'disabled'], template: '<div />' },
	DestinationSelect: {
		props: ['modelValue', 'mode', 'label'],
		template: '<div />',
	},
}

const SERVER_ACCESS = {
	id: 'type-server',
	name: 'server-access',
	label: 'Server access',
	scope: 'global',
	fields: [
		{ key: 'host', label: 'Host', kind: 'url', required: true },
		{ key: 'port', label: 'Port', kind: 'text', required: false },
		{
			key: 'root-password',
			label: 'Root password',
			kind: 'hidden',
			required: false,
		},
	],
}

/**
 * Mount the dialog on the Server access type and settle its mounted hook.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountOnServerAccess() {
	const wrapper = mount(SecretCreateDialog, { global: { stubs } })
	for (let i = 0; i < 5; i++) {
		await wrapper.vm.$nextTick()
		await Promise.resolve()
	}
	wrapper.vm.typeId = 'type-server'
	wrapper.vm.name = 'db-01'
	wrapper.vm.value = 'correct horse battery staple'
	wrapper.vm.selectedFolderId = 'folder-1'
	await wrapper.vm.$nextTick()
	return wrapper
}

describe('SecretCreateDialog typed fields', () => {
	let createSecret

	beforeEach(() => {
		setActivePinia(createPinia())
		const typeStore = useSecretTypeStore()
		typeStore.types = [
			{ id: 'type-login', name: 'login', label: 'Login', fields: [] },
			SERVER_ACCESS,
		]
		typeStore.fetchTypes = vi.fn().mockResolvedValue()
		const folderStore = useFolderStore()
		folderStore.folders = [{ id: 'folder-1', name: 'Vault', parentId: null }]
		folderStore.fetchFolders = vi.fn().mockResolvedValue()
		useSessionStore().cryptoKey = 'UNLOCKED'
		useUserPreferencesStore().ensureLoaded = vi.fn().mockResolvedValue()
		createSecret = vi.fn().mockResolvedValue({ id: 'secret-1' })
		useSecretStore().createSecret = createSecret
	})

	it('renders the fields of the chosen type by kind', async () => {
		const wrapper = await mountOnServerAccess()

		expect(wrapper.find('[data-testid="typed-fields"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('Host (required)')
		expect(wrapper.text()).toContain('Port')
		const hidden = wrapper.find('[data-testid="typed-field-root-password"]')
		expect(hidden.find('input').attributes('type')).toBe('password')
	})

	it('blocks the save and marks Host when a required field is empty', async () => {
		const wrapper = await mountOnServerAccess()

		await wrapper.vm.submit()
		await wrapper.vm.$nextTick()

		expect(createSecret).not.toHaveBeenCalled()
		expect(wrapper.vm.typedMissing).toEqual(['host'])
		expect(
			wrapper
				.find('[data-testid="typed-field-host"]')
				.attributes('data-error'),
		).toBe('true')
	})

	it('sends the typed values inside the additional fields the store encrypts', async () => {
		const wrapper = await mountOnServerAccess()
		wrapper.vm.typedValues = {
			host: 'https://db-01.example.org',
			'root-password': 's3cret',
		}

		await wrapper.vm.submit()

		expect(createSecret).toHaveBeenCalledTimes(1)
		const payload = createSecret.mock.calls[0][0]
		expect(payload.additionalFields).toEqual({
			Host: 'https://db-01.example.org',
			'Root password': 's3cret',
		})
		expect(JSON.stringify({ ...payload, additionalFields: null })).not.toContain(
			's3cret',
		)
	})

	it('shows no typed form for a built-in type', async () => {
		const wrapper = await mountOnServerAccess()
		wrapper.vm.typeId = 'type-login'
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="typed-fields"]').exists()).toBe(false)
	})
})
