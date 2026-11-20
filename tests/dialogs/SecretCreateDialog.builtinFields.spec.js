/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A built-in type with its own form: a certificate is entered in a text area
 * that keeps its line breaks, with no login field, and its private key goes
 * into the additional fields under its fixed name.
 *
 * @spec openspec/changes/vault-builtin-type-fields/specs/builtin-type-fields/spec.md#requirement-built-in-types-carry-their-own-fields
 * @spec openspec/changes/vault-builtin-type-fields/specs/builtin-type-fields/spec.md#requirement-built-in-field-values-keep-a-fixed-name
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

const field = {
	props: ['modelValue', 'label', 'required', 'error', 'helperText', 'disabled'],
	template: '<label>{{ label }}<input :value="modelValue" /></label>',
}

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
	NcTextField: field,
	NcPasswordField: field,
	NcTextArea: {
		props: ['modelValue', 'label', 'rows', 'required', 'error', 'helperText'],
		template: '<label>{{ label }}<textarea :value="modelValue" /></label>',
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

const PEM = '-----BEGIN CERTIFICATE-----\nMIIB\nAAAA\n-----END CERTIFICATE-----'

/**
 * Mount the dialog on a type and settle its mounted hook.
 *
 * @param {string} typeId The type to pick.
 * @return {Promise<object>} The wrapper.
 */
async function mountOn(typeId) {
	const wrapper = mount(SecretCreateDialog, { global: { stubs } })
	for (let i = 0; i < 5; i++) {
		await wrapper.vm.$nextTick()
		await Promise.resolve()
	}
	wrapper.vm.typeId = typeId
	wrapper.vm.name = 'example.org'
	wrapper.vm.selectedFolderId = 'folder-1'
	await wrapper.vm.$nextTick()
	return wrapper
}

describe('SecretCreateDialog built-in type fields', () => {
	let createSecret

	beforeEach(() => {
		setActivePinia(createPinia())
		const typeStore = useSecretTypeStore()
		typeStore.types = [
			{ id: 'type-login', name: 'login', label: 'Login', fields: null },
			{
				id: 'type-certificate',
				name: 'certificate',
				label: 'Certificate',
				fields: null,
			},
			{
				id: 'type-database',
				name: 'database',
				label: 'Database',
				fields: null,
			},
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

	it('asks for a certificate in a text area, with its extra fields and no login', async () => {
		const wrapper = await mountOn('type-certificate')

		const value = wrapper.find('[data-testid="secret-value-multiline"]')
		expect(value.text()).toContain('Certificate')
		expect(value.find('textarea').exists()).toBe(true)
		expect(wrapper.text()).not.toContain('Secret value')
		expect(wrapper.text()).not.toContain('Login (optional)')
		expect(
			wrapper
				.find('[data-testid="typed-field-private-key"] textarea')
				.exists(),
		).toBe(true)
		expect(wrapper.text()).toContain('Passphrase')
	})

	it('needs the certificate before it can be created', async () => {
		const wrapper = await mountOn('type-certificate')
		expect(wrapper.vm.canSubmit).toBe(false)

		wrapper.vm.value = PEM
		expect(wrapper.vm.canSubmit).toBe(true)
	})

	it('saves the certificate as the value and the private key under its fixed name', async () => {
		const wrapper = await mountOn('type-certificate')
		wrapper.vm.login = 'typed before switching type'
		wrapper.vm.value = PEM
		wrapper.vm.typedValues = { 'private-key': 'KEY', passphrase: '' }

		await wrapper.vm.submit()

		const payload = createSecret.mock.calls[0][0]
		expect(payload.key).toBe(PEM)
		expect(payload.login).toBe('')
		expect(payload.additionalFields).toEqual({ 'Private key': 'KEY' })
	})

	it('labels a database login as the username and leaves the URL out', async () => {
		const wrapper = await mountOn('type-database')
		wrapper.vm.url = 'https://typed-before-switching.example'
		wrapper.vm.value = 'hunter2'

		expect(wrapper.text()).toContain('Username (optional)')
		expect(wrapper.text()).not.toContain('URL (optional)')
		expect(wrapper.text()).toContain('Password')

		await wrapper.vm.submit()
		expect(createSecret.mock.calls[0][0].url).toBeNull()
	})

	// @spec openspec/changes/vault-builtin-type-fields/specs/builtin-type-fields/spec.md#requirement-the-create-dialog-marks-the-value-as-required
	it('asks a login for its password, marked as required', async () => {
		const wrapper = await mountOn('type-login')

		expect(wrapper.find('[data-testid="secret-value-multiline"]').exists()).toBe(
			false,
		)
		expect(wrapper.text()).toContain('Password (required)')
		expect(wrapper.text()).not.toContain('Secret value')
		expect(wrapper.text()).toContain('Login (optional)')
		expect(wrapper.text()).toContain('URL (optional)')
		expect(wrapper.find('[data-testid="typed-fields"]').exists()).toBe(false)
		expect(wrapper.vm.canSubmit).toBe(false)

		wrapper.vm.value = 'hunter2'
		expect(wrapper.vm.canSubmit).toBe(true)
	})

	// @spec openspec/changes/vault-builtin-type-fields/specs/builtin-type-fields/spec.md#requirement-the-create-dialog-marks-the-value-as-required
	it('marks a multi-line value as required too', async () => {
		const wrapper = await mountOn('type-certificate')

		expect(
			wrapper.find('[data-testid="secret-value-multiline"]').text(),
		).toContain('Certificate (required)')
	})
})
