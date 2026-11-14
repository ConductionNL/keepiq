/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The SSH key item in the create and edit dialogs: the SSH key section
 * replaces the generic value field, the private key is stored as the value,
 * the public key and the DERIVED fingerprint go into the encrypted
 * additional fields, and a malformed key is refused on its field with
 * nothing saved.
 *
 * Only the stores and Nextcloud globals are mocked; key parsing and
 * generation are real WebCrypto.
 *
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-ssh-key-item/spec.md#requirement-ssh-key-item-form
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretCreateDialog from '../../src/dialogs/SecretCreateDialog.vue'
import SecretEditDialog from '../../src/dialogs/SecretEditDialog.vue'
import { generateKeyPair } from '../../src/ssh/sshKey.js'
import { useFolderStore } from '../../src/store/modules/folder.js'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'
import { useSessionStore } from '../../src/store/modules/session.js'

const field = {
	props: ['modelValue', 'label', 'disabled', 'error', 'helperText'],
	emits: ['update:modelValue'],
	template:
		'<label><span>{{ label }}</span><input :value="modelValue" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)" /><small v-if="helperText">{{ helperText }}</small></label>',
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
	NcTextArea: field,
	NcPasswordField: {
		props: ['modelValue', 'label'],
		template:
			'<input type="password" class="generic-value" :value="modelValue" />',
	},
	NcNoteCard: { props: ['type'], template: '<div><slot /></div>' },
	NcLoadingIcon: { template: '<span />' },
	Plus: { template: '<i />' },
	Dice5: { template: '<i />' },
	KeyGeneratorModal: { props: ['open'], template: '<div />' },
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/** A text that is not a key. */
const NOT_A_KEY = 'hunter2 is my password'

beforeEach(() => {
	setActivePinia(createPinia())
	vi.restoreAllMocks()

	const typeStore = useSecretTypeStore()
	typeStore.types = [
		{ id: 'login', name: 'login', label: 'Login' },
		{ id: 'ssh', name: 'ssh_key', label: 'SSH Key' },
	]
	typeStore.fetchTypes = vi.fn().mockResolvedValue()
	const folderStore = useFolderStore()
	folderStore.folders = []
	folderStore.fetchFolders = vi.fn().mockResolvedValue()
	const session = useSessionStore()
	session.cryptoKey = 'UNLOCKED'
	session.certificate = 'PEM'
})

/**
 * Mount the create dialog with the SSH Key type chosen.
 *
 * @return {Promise<object>}
 */
async function mountCreate() {
	const wrapper = mount(SecretCreateDialog, {
		propsData: { folderId: 'folder-1' },
		global: { stubs },
	})
	await flush()
	wrapper.vm.typeId = 'ssh'
	wrapper.vm.name = 'Deploy key'
	await wrapper.vm.$nextTick()
	return wrapper
}

describe('SecretCreateDialog — SSH key', () => {
	it('shows the SSH key section instead of the generic value field', async () => {
		const wrapper = await mountCreate()

		expect(wrapper.find('[data-testid="ssh-key-fields"]').exists()).toBe(true)
		expect(wrapper.find('.generic-value').exists()).toBe(false)
		expect(wrapper.text()).toContain('Private key')
		expect(wrapper.text()).toContain('Public key')
		expect(wrapper.text()).toContain('Fingerprint')
	})

	it('stores the private key as the value and the derived public key and fingerprint in the blob', async () => {
		const pair = await generateKeyPair({ comment: 'ci' })
		const wrapper = await mountCreate()
		const create = vi
			.spyOn(useSecretStore(), 'createSecret')
			.mockResolvedValue({ id: 's1' })

		wrapper.vm.value = pair.privateKey
		// A fingerprint left in the form is not trusted; it is derived again.
		wrapper.vm.sshKey = { publicKey: '', fingerprint: 'SHA256:typed' }
		await wrapper.vm.submit()

		expect(create).toHaveBeenCalledTimes(1)
		const body = create.mock.calls[0][0]
		expect(body.key).toBe(pair.privateKey)
		expect(body.additionalFields.fingerprint).toBe(pair.fingerprint)
		expect(body.additionalFields.publicKey.split(' ').slice(0, 2)).toEqual(
			pair.publicKey.split(' ').slice(0, 2),
		)
	})

	it('refuses a malformed private key on its field and saves nothing', async () => {
		const wrapper = await mountCreate()
		const create = vi.spyOn(useSecretStore(), 'createSecret')

		wrapper.vm.value = NOT_A_KEY
		await wrapper.vm.submit()
		await wrapper.vm.$nextTick()

		expect(create).not.toHaveBeenCalled()
		expect(wrapper.vm.saving).toBe(false)
		expect(wrapper.vm.sshErrors.privateKey).not.toBe('')
		expect(wrapper.find('[data-testid="ssh-private-key"]').text()).toContain(
			'This is not a private key.',
		)
	})

	it('refuses a public key that belongs to another key', async () => {
		const [pair, other] = await Promise.all([
			generateKeyPair(),
			generateKeyPair(),
		])
		const wrapper = await mountCreate()
		const create = vi.spyOn(useSecretStore(), 'createSecret')

		wrapper.vm.value = pair.privateKey
		wrapper.vm.sshKey = { publicKey: other.publicKey, fingerprint: '' }
		await wrapper.vm.submit()

		expect(create).not.toHaveBeenCalled()
		expect(wrapper.vm.sshErrors.publicKey).toBe(
			'This public key does not belong to the private key.',
		)
	})

	it('fills all three fields from Generate key pair', async () => {
		const wrapper = await mountCreate()

		await wrapper.find('[data-testid="ssh-generate"]').trigger('click')
		for (let i = 0; i < 5; i++) {
			await flush()
		}

		expect(wrapper.vm.value).toMatch(/^-----BEGIN OPENSSH PRIVATE KEY-----/)
		expect(wrapper.vm.sshKey.publicKey).toMatch(/^ssh-(ed25519|rsa) /)
		expect(wrapper.vm.sshKey.fingerprint).toMatch(/^SHA256:/)
	})
})

describe('SecretEditDialog — SSH key', () => {
	/**
	 * Mount the edit dialog over a stored SSH key.
	 *
	 * @param {object} secret The decrypted secret.
	 * @return {Promise<object>}
	 */
	async function mountEdit(secret) {
		useSecretStore().fetchSecret = vi.fn().mockResolvedValue({
			id: 's1',
			name: 'Deploy key',
			typeId: 'ssh',
			...secret,
		})
		const wrapper = mount(SecretEditDialog, {
			propsData: { secretId: 's1' },
			global: { stubs },
		})
		await flush()
		await flush()
		return wrapper
	}

	it('loads the public key and fingerprint into the SSH section, not the free fields', async () => {
		const pair = await generateKeyPair()
		const wrapper = await mountEdit({
			key: pair.privateKey,
			additionalFields: {
				publicKey: pair.publicKey,
				fingerprint: pair.fingerprint,
				Host: 'git.example',
			},
		})

		expect(wrapper.vm.sshKey).toEqual({
			publicKey: pair.publicKey,
			fingerprint: pair.fingerprint,
		})
		expect(wrapper.vm.additionalFields).toEqual([
			{ name: 'Host', value: 'git.example', kind: 'text' },
		])
	})

	it('does not rewrite the blob when nothing about the key changed', async () => {
		const pair = await generateKeyPair()
		const wrapper = await mountEdit({
			key: pair.privateKey,
			additionalFields: {
				Host: 'git.example',
				publicKey: pair.publicKey,
				fingerprint: pair.fingerprint,
			},
		})
		const update = vi
			.spyOn(useSecretStore(), 'updateSecret')
			.mockResolvedValue({ id: 's1' })

		wrapper.vm.name = 'Renamed'
		await wrapper.vm.submit()

		const diff = update.mock.calls[0][1]
		expect('additionalFields' in diff).toBe(false)
		expect('key' in diff).toBe(false)
	})

	it('refuses a malformed replacement key and saves nothing', async () => {
		const pair = await generateKeyPair()
		const wrapper = await mountEdit({
			key: pair.privateKey,
			additionalFields: {
				publicKey: pair.publicKey,
				fingerprint: pair.fingerprint,
			},
		})
		const update = vi.spyOn(useSecretStore(), 'updateSecret')

		wrapper.vm.value = NOT_A_KEY
		await wrapper.vm.submit()

		expect(update).not.toHaveBeenCalled()
		expect(wrapper.vm.sshErrors.privateKey).not.toBe('')
	})

	it('saves a replaced key with the new public key and fingerprint', async () => {
		const [old, next] = await Promise.all([generateKeyPair(), generateKeyPair()])
		const wrapper = await mountEdit({
			key: old.privateKey,
			additionalFields: {
				publicKey: old.publicKey,
				fingerprint: old.fingerprint,
			},
		})
		const update = vi
			.spyOn(useSecretStore(), 'updateSecret')
			.mockResolvedValue({ id: 's1' })

		wrapper.vm.value = next.privateKey
		wrapper.vm.sshKey = {
			publicKey: next.publicKey,
			fingerprint: next.fingerprint,
		}
		await wrapper.vm.submit()

		const diff = update.mock.calls[0][1]
		expect(diff.key).toBe(next.privateKey)
		expect(diff.additionalFields).toEqual({
			publicKey: next.publicKey,
			fingerprint: next.fingerprint,
		})
	})
})
