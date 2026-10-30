/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A new login's authenticator key in the create dialog, and the types the
 * dialog no longer offers (keepiq#1326): the key is optional, a key that
 * cannot be used blocks the save, and it is sent only when entered.
 *
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
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
import { valueFieldsSupported } from '../../src/utils/valueFields.js'

vi.mock('../../src/utils/valueFields.js', async (importOriginal) => ({
	...(await importOriginal()),
	valueFieldsSupported: vi.fn(() => true),
}))

const STRONG = 'Xk9#mQ2$vL7@pR4!zT6&'
const LINK = 'otpauth://totp/Bank:alice?secret=JBSWY3DPEHPK3PXP&issuer=Bank'

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
 * Mount the dialog over a complete login form and let mounted() settle.
 *
 * @param {string} typeId The type to select.
 * @return {Promise<object>} The wrapper.
 */
async function mountFilled(typeId = 'type-login') {
	const wrapper = mount(SecretCreateDialog, { global: { stubs } })
	for (let i = 0; i < 5; i++) {
		await wrapper.vm.$nextTick()
		await Promise.resolve()
	}
	wrapper.vm.typeId = typeId
	wrapper.vm.name = 'Bank'
	wrapper.vm.value = STRONG
	wrapper.vm.selectedFolderId = 'folder-1'
	await wrapper.vm.$nextTick()
	return wrapper
}

/**
 * Whether the authenticator key field renders.
 *
 * @param {object} wrapper The dialog wrapper.
 * @return {boolean}
 */
function hasField(wrapper) {
	return wrapper.find('[data-testid="authenticator-key"]').exists()
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.restoreAllMocks()
	vi.mocked(valueFieldsSupported).mockReturnValue(true)

	const typeStore = useSecretTypeStore()
	typeStore.types = [
		{ id: 'type-login', name: 'login', label: 'Login' },
		{ id: 'type-totp', name: 'totp', label: 'Authenticator' },
		{ id: 'type-passkey', name: 'passkey', label: 'Passkey' },
		{ id: 'type-note', name: 'note', label: 'Note' },
	]
	typeStore.fetchTypes = vi.fn().mockResolvedValue()
	const folderStore = useFolderStore()
	folderStore.folders = []
	folderStore.fetchFolders = vi.fn().mockResolvedValue()
	const prefs = useUserPreferencesStore()
	prefs.ensureLoaded = vi.fn().mockResolvedValue()

	const session = useSessionStore()
	session.cryptoKey = 'UNLOCKED'
	session.certificate = 'PEM'
})

describe('types offered for a new secret', () => {
	it('leaves out Authenticator and Passkey', async () => {
		const wrapper = await mountFilled()
		expect(wrapper.vm.typeOptions.map((option) => option.value)).toEqual([
			'type-login',
			'type-note',
		])
	})

	it('opens on Login when the saved default is Authenticator', async () => {
		useUserPreferencesStore().defaultSecretType = 'totp'
		const wrapper = mount(SecretCreateDialog, { global: { stubs } })
		for (let i = 0; i < 5; i++) {
			await wrapper.vm.$nextTick()
			await Promise.resolve()
		}
		expect(wrapper.vm.typeId).toBe('type-login')
	})
})

describe('the authenticator key of a new login', () => {
	it('shows on a login only, and only when the server stores it', async () => {
		const wrapper = await mountFilled()
		expect(hasField(wrapper)).toBe(true)

		wrapper.vm.typeId = 'type-note'
		await wrapper.vm.$nextTick()
		expect(hasField(wrapper)).toBe(false)

		vi.mocked(valueFieldsSupported).mockReturnValue(false)
		const older = await mountFilled()
		expect(hasField(older)).toBe(false)
	})

	it('sends no totp when none was entered', async () => {
		const wrapper = await mountFilled()
		const create = vi
			.spyOn(useSecretStore(), 'createSecret')
			.mockResolvedValue({ id: 's1' })

		await wrapper.vm.submit()
		expect('totp' in create.mock.calls[0][0]).toBe(false)
	})

	it('sends the entered key, a bare secret without its spacing', async () => {
		const wrapper = await mountFilled()
		const create = vi
			.spyOn(useSecretStore(), 'createSecret')
			.mockResolvedValue({ id: 's1' })

		wrapper.vm.totpInput = ' jbsw y3dp ehpk 3pxp '
		await wrapper.vm.submit()
		expect(create.mock.calls[0][0].totp).toBe('JBSWY3DPEHPK3PXP')
	})

	it('sends an otpauth link as entered', async () => {
		const wrapper = await mountFilled()
		const create = vi
			.spyOn(useSecretStore(), 'createSecret')
			.mockResolvedValue({ id: 's1' })

		wrapper.vm.totpInput = LINK
		await wrapper.vm.submit()
		expect(create.mock.calls[0][0].totp).toBe(LINK)
	})

	it('blocks the save with an inline error for a key it cannot use', async () => {
		const wrapper = await mountFilled()
		const create = vi
			.spyOn(useSecretStore(), 'createSecret')
			.mockResolvedValue({ id: 's1' })

		wrapper.vm.totpInput = 'https://example.org'
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canSubmit).toBe(false)
		expect(
			wrapper.find('[data-testid="authenticator-key-error"]').exists(),
		).toBe(true)
		await wrapper.vm.submit()
		expect(create).not.toHaveBeenCalled()
	})

	it('sends the key into a team folder the user contributes to', async () => {
		const wrapper = await mountFilled()
		const create = vi.spyOn(useSecretStore(), 'createSecret')
		const contribute = vi
			.spyOn(useSecretStore(), 'contributeSecret')
			.mockResolvedValue({ secret: { id: 's1' } })

		wrapper.vm.contributeTo = { teamFolderId: 'tf-1', folderName: 'Ops' }
		wrapper.vm.totpInput = LINK
		await wrapper.vm.submit()

		expect(create).not.toHaveBeenCalled()
		expect(contribute.mock.calls[0][0]).toBe('tf-1')
		expect(contribute.mock.calls[0][1].totp).toBe(LINK)
	})

	it('does not send a key typed before switching away from Login', async () => {
		const wrapper = await mountFilled()
		const create = vi
			.spyOn(useSecretStore(), 'createSecret')
			.mockResolvedValue({ id: 's1' })

		wrapper.vm.totpInput = LINK
		wrapper.vm.typeId = 'type-note'
		await wrapper.vm.submit()
		expect('totp' in create.mock.calls[0][0]).toBe(false)
	})
})
