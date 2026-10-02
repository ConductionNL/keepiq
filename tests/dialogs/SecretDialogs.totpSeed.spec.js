/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Authenticator key field of a login (vault-login-totp-codes task 1.2).
 * The seed is stored under `totp` inside the encrypted additional fields, is
 * never listed as a free field, and a legacy `otp` member is read as the seed.
 *
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretCreateDialog from '../../src/dialogs/SecretCreateDialog.vue'
import SecretEditDialog from '../../src/dialogs/SecretEditDialog.vue'
import { useFolderStore } from '../../src/store/modules/folder.js'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'
import { useSessionStore } from '../../src/store/modules/session.js'

const URI = 'otpauth://totp/rfc?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'

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
		emits: ['update:modelValue'],
		template:
			'<input :value="modelValue" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)" />',
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
 * Mount the edit dialog over a secret of `typeId` whose blob holds `members`.
 *
 * @param {object|null} members The decrypted additional fields.
 * @param {string} typeId The secret's type.
 * @return {Promise<object>} The wrapper.
 */
async function mountEdit(members, typeId = 'login') {
	useSecretStore().fetchSecret = vi.fn().mockResolvedValue({
		id: 's1',
		name: 'example.com',
		typeId,
		key: 'pw',
		url: 'https://example.com',
		login: 'alice',
		additionalFields: members,
	})
	const wrapper = mount(SecretEditDialog, {
		propsData: { secretId: 's1' },
		global: { stubs },
	})
	await wrapper.vm.$nextTick()
	await new Promise((resolve) => setTimeout(resolve, 0))
	await wrapper.vm.$nextTick()
	return wrapper
}

describe('the Authenticator key of a login', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		const typeStore = useSecretTypeStore()
		typeStore.types = [
			{ id: 'login', name: 'login', label: 'Login' },
			{ id: 'note', name: 'note', label: 'Note' },
		]
		typeStore.fetchTypes = vi.fn().mockResolvedValue()
		const folderStore = useFolderStore()
		folderStore.folders = []
		folderStore.fetchFolders = vi.fn().mockResolvedValue()
		const session = useSessionStore()
		session.cryptoKey = 'UNLOCKED'
		session.certificate = 'PEM'
	})

	it('create: stores the seed under totp in the additional fields', async () => {
		const wrapper = mount(SecretCreateDialog, {
			propsData: { folderId: 'folder-1' },
			global: { stubs },
		})
		await wrapper.vm.$nextTick()
		const create = vi
			.spyOn(useSecretStore(), 'createSecret')
			.mockResolvedValue({ id: 's1' })

		wrapper.vm.typeId = 'login'
		wrapper.vm.name = 'example.com'
		wrapper.vm.value = 'pw'
		await wrapper.vm.$nextTick()
		expect(wrapper.find('[data-testid="secret-totp-seed"]').exists()).toBe(true)
		wrapper.vm.totpSeed = URI
		await wrapper.vm.submit()

		expect(create.mock.calls[0][0].additionalFields).toEqual({ totp: URI })
	})

	it('edit: shows the seed in its own field, not among the free fields', async () => {
		const wrapper = await mountEdit({ totp: URI, pin: '1234' })

		expect(wrapper.vm.totpSeed).toBe(URI)
		expect(wrapper.vm.additionalFields).toEqual([{ name: 'pin', value: '1234' }])
		expect(wrapper.find('[data-testid="secret-totp-seed"]').exists()).toBe(true)
	})

	it('edit: a changed seed rewrites the blob with the seed under totp', async () => {
		const wrapper = await mountEdit({ pin: '1234' })
		const update = vi
			.spyOn(useSecretStore(), 'updateSecret')
			.mockResolvedValue({ id: 's1' })

		wrapper.vm.totpSeed = URI
		await wrapper.vm.submit()

		expect(update.mock.calls[0][1].additionalFields).toEqual({
			pin: '1234',
			totp: URI,
		})
	})

	it('edit: a legacy otp member is read as the seed and an untouched save sends no blob', async () => {
		const wrapper = await mountEdit({ otp: URI })
		const update = vi
			.spyOn(useSecretStore(), 'updateSecret')
			.mockResolvedValue({ id: 's1' })

		expect(wrapper.vm.totpSeed).toBe(URI)
		wrapper.vm.name = 'renamed'
		await wrapper.vm.submit()

		expect('additionalFields' in update.mock.calls[0][1]).toBe(false)
	})

	it('edit: a note keeps a member named otp as a free field', async () => {
		const wrapper = await mountEdit({ otp: 'just text' }, 'note')

		expect(wrapper.vm.totpSeed).toBe('')
		expect(wrapper.vm.additionalFields).toEqual([
			{ name: 'otp', value: 'just text' },
		])
		expect(wrapper.find('[data-testid="secret-totp-seed"]').exists()).toBe(false)
	})

	it('a login refuses a free field named totp', async () => {
		const wrapper = await mountEdit({})
		const editor = wrapper.findComponent({ name: 'AdditionalFieldsEditor' })

		expect(editor.props('reservedNames')).toEqual(['totp', 'otp', 'otpauth'])
	})
})
