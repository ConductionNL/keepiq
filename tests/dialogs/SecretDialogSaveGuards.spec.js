/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins the save guards the create and edit secret dialogs share: what they
 * load when they open (the org policy, and the secret types only when none
 * are cached), the Save guard and the required typed fields, and the
 * breached-value block — refused with its reason and nothing written, or
 * allowed and written — plus the cases where the block does not run.
 *
 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-secret-from-the-ui
 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-edit-a-secret-from-the-ui
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretCreateDialog from '../../src/dialogs/SecretCreateDialog.vue'
import SecretEditDialog from '../../src/dialogs/SecretEditDialog.vue'
import { evaluateHibp, fetchPolicy } from '../../src/policy/policy.js'
import { useFolderStore } from '../../src/store/modules/folder.js'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'
import { useSessionStore } from '../../src/store/modules/session.js'
import { useShareStore } from '../../src/store/modules/share.js'
import { useUserPreferencesStore } from '../../src/store/modules/userPreferences.js'

vi.mock('../../src/policy/policy.js', () => ({
	fetchPolicy: vi.fn(),
	evaluateScore: vi.fn(() => ({ compliant: true, reason: null })),
	evaluateHibp: vi.fn(),
}))

const POLICY = { hibp: 'block' }
const BREACHED = 'This value appears in known breaches 3 times — choose another'

const stubs = {
	NcDialog: {
		props: ['name', 'open', 'size', 'noClose'],
		template: '<div><slot /><footer><slot name="actions" /></footer></div>',
	},
	NcButton: {
		props: ['disabled', 'variant', 'ariaLabel', 'title'],
		emits: ['click'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcSelect: { props: ['options', 'modelValue'], template: '<div />' },
	NcTextField: { props: ['modelValue', 'label'], template: '<input />' },
	NcPasswordField: { props: ['modelValue', 'label'], template: '<input />' },
	NcNoteCard: { props: ['type'], template: '<div class="note"><slot /></div>' },
	NcLoadingIcon: { template: '<span />' },
	Plus: { template: '<i />' },
	ContentSave: { template: '<i />' },
	Dice5: { template: '<i />' },
	KeyGeneratorModal: { props: ['open'], template: '<div />' },
	AuthenticatorKeyField: { props: ['value'], template: '<div />' },
	TypedFieldsForm: { props: ['fields', 'values', 'missing'], template: '<div />' },
	AdditionalFieldsEditor: { props: ['members'], template: '<div />' },
	SecretTagsField: { props: ['modelValue'], template: '<div />' },
	DestinationSelect: { props: ['modelValue'], template: '<div />' },
}

const TYPES = [
	{ id: 'type-login', name: 'login', label: 'Login', fields: [] },
	{ id: 'type-card', name: 'card', label: 'Card', fields: [] },
	{
		id: 'type-server',
		name: 'server',
		label: 'Server',
		fields: [{ key: 'host', label: 'Host', type: 'text', required: true }],
	},
]

/**
 * @param {object} wrapper The mounted dialog.
 * @return {Promise<void>}
 */
async function flush(wrapper) {
	for (let i = 0; i < 6; i++) {
		await wrapper.vm.$nextTick()
		await new Promise((resolve) => setTimeout(resolve, 0))
	}
}

/**
 * @param {string} typeId The type to pick.
 * @return {Promise<object>} A create dialog, named, placed and given a value.
 */
async function create(typeId) {
	const wrapper = mount(SecretCreateDialog, { global: { stubs } })
	await flush(wrapper)
	Object.assign(wrapper.vm, {
		typeId,
		selectedFolderId: 'folder-1',
		name: 'Item',
		value: 'typed-value',
	})
	await wrapper.vm.$nextTick()
	return wrapper
}

/**
 * @param {object} secret The stored secret, decrypted.
 * @return {Promise<object>} An edit dialog after load.
 */
async function edit(secret) {
	useSecretStore().fetchSecret = vi.fn().mockResolvedValue({
		id: 's1',
		name: 'Item',
		url: null,
		login: '',
		updatedAt: '2026-10-01T10:00:00+00:00',
		...secret,
	})
	const wrapper = mount(SecretEditDialog, {
		props: { secretId: 's1' },
		global: { stubs },
	})
	await flush(wrapper)
	return wrapper
}

beforeEach(() => {
	setActivePinia(createPinia())
	fetchPolicy.mockReset().mockResolvedValue(POLICY)
	evaluateHibp.mockReset().mockResolvedValue(null)
	const typeStore = useSecretTypeStore()
	typeStore.types = TYPES.map((type) => ({ ...type }))
	typeStore.fetchTypes = vi.fn().mockResolvedValue()
	const folderStore = useFolderStore()
	folderStore.folders = [{ id: 'folder-1', name: 'Vault', parentId: null }]
	folderStore.fetchFolders = vi.fn().mockResolvedValue()
	useSessionStore().cryptoKey = 'UNLOCKED'
	useUserPreferencesStore().ensureLoaded = vi.fn().mockResolvedValue()
	const store = useSecretStore()
	store.createSecret = vi.fn().mockResolvedValue({ id: 'secret-1' })
	store.updateSecret = vi.fn().mockResolvedValue({ id: 's1' })
	store.setTags = vi.fn().mockResolvedValue()
	store.writeBackSettled = vi.fn().mockResolvedValue(null)
	useShareStore().fetchWriteContext = vi
		.fn()
		.mockResolvedValue({ sourceSecretId: 's1' })
})

describe('create dialog save guards', () => {
	it('loads the policy, and the types only when none are cached', async () => {
		const cached = await create('type-login')
		expect(fetchPolicy).toHaveBeenCalledTimes(1)
		expect(cached.vm.policy).toEqual(POLICY)
		expect(useSecretTypeStore().fetchTypes).not.toHaveBeenCalled()

		useSecretTypeStore().types = []
		const empty = mount(SecretCreateDialog, { global: { stubs } })
		await flush(empty)
		expect(useSecretTypeStore().fetchTypes).toHaveBeenCalledTimes(1)
	})

	it('refuses a breached value with the reason and writes nothing', async () => {
		evaluateHibp.mockResolvedValue(BREACHED)
		const wrapper = await create('type-login')
		await wrapper.vm.submit()
		expect(evaluateHibp).toHaveBeenCalledWith(POLICY, 'login', 'typed-value')
		expect(wrapper.vm.error).toBe(BREACHED)
		expect(wrapper.vm.saving).toBe(false)
		expect(useSecretStore().createSecret).not.toHaveBeenCalled()
		expect(wrapper.emitted('saved')).toBeUndefined()
	})

	it('writes a value the breach check allows', async () => {
		const wrapper = await create('type-login')
		await wrapper.vm.submit()
		expect(evaluateHibp).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.error).toBe('')
		expect(useSecretStore().createSecret).toHaveBeenCalledTimes(1)
		expect(wrapper.emitted('saved')).toEqual([[{ id: 'secret-1' }]])
	})

	it('runs no breach check for a card', async () => {
		const wrapper = await create('type-card')
		wrapper.vm.card.number = '4111111111111111'
		await wrapper.vm.submit()
		expect(evaluateHibp).not.toHaveBeenCalled()
		expect(useSecretStore().createSecret).toHaveBeenCalledTimes(1)
	})

	it('does nothing while Save is not allowed', async () => {
		const wrapper = await create('type-login')
		wrapper.vm.name = '   '
		await wrapper.vm.submit()
		expect(wrapper.vm.typedMissing).toEqual([])
		expect(evaluateHibp).not.toHaveBeenCalled()
		expect(useSecretStore().createSecret).not.toHaveBeenCalled()
	})

	it('marks a missing required field and stops before the breach check', async () => {
		const wrapper = await create('type-server')
		await wrapper.vm.submit()
		expect(wrapper.vm.typedMissing).toEqual(['host'])
		expect(wrapper.vm.saving).toBe(false)
		expect(evaluateHibp).not.toHaveBeenCalled()
		expect(useSecretStore().createSecret).not.toHaveBeenCalled()
	})
})

describe('create dialog payload', () => {
	/**
	 * @param {object} wrapper The filled create dialog.
	 * @return {Promise<void>}
	 */
	async function fillExtras(wrapper) {
		wrapper.vm.url = 'https://example.org'
		wrapper.vm.login = 'ada'
		wrapper.vm.additionalFields = [{ name: 'Pin', value: '1234' }]
		await wrapper.vm.$nextTick()
	}

	it('sends the plain fields, in order, into a folder', async () => {
		const wrapper = await create('type-login')
		await wrapper.vm.submit()
		const body = useSecretStore().createSecret.mock.calls[0][0]
		expect(Object.keys(body)).toEqual(['name', 'typeId', 'folderId', 'url', 'login', 'key'])
		expect(body).toEqual({
			name: 'Item',
			typeId: 'type-login',
			folderId: 'folder-1',
			url: null,
			login: '',
			key: 'typed-value',
		})
	})

	it('adds the additional fields when there are any', async () => {
		const wrapper = await create('type-login')
		await fillExtras(wrapper)
		await wrapper.vm.submit()
		const body = useSecretStore().createSecret.mock.calls[0][0]
		expect(Object.keys(body)).toEqual(['name', 'typeId', 'folderId', 'url', 'login', 'key', 'additionalFields'])
		expect(body.url).toBe('https://example.org')
		expect(body.login).toBe('ada')
		expect(body.additionalFields).toEqual({ Pin: '1234' })
	})

	it('sends the same fields, without a folder, into a team folder', async () => {
		const contribute = vi
			.spyOn(useSecretStore(), 'contributeSecret')
			.mockResolvedValue({ secret: { id: 's2' } })
		const wrapper = await create('type-login')
		await fillExtras(wrapper)
		wrapper.vm.contributeTo = { teamFolderId: 'tf-ops' }
		await wrapper.vm.submit()
		const [teamFolderId, body] = contribute.mock.calls[0]
		expect(teamFolderId).toBe('tf-ops')
		expect(Object.keys(body)).toEqual(['name', 'typeId', 'url', 'login', 'key', 'additionalFields'])
		expect(body).toEqual({
			name: 'Item',
			typeId: 'type-login',
			url: 'https://example.org',
			login: 'ada',
			key: 'typed-value',
			additionalFields: { Pin: '1234' },
		})
		expect(useSecretStore().createSecret).not.toHaveBeenCalled()
		expect(wrapper.emitted('saved')).toEqual([[{ id: 's2' }]])
	})
})

describe('edit dialog save guards', () => {
	it('loads the policy, and the types only when none are cached', async () => {
		const cached = await edit({ typeId: 'type-login', key: 'stored' })
		expect(fetchPolicy).toHaveBeenCalledTimes(1)
		expect(cached.vm.policy).toEqual(POLICY)
		expect(useSecretTypeStore().fetchTypes).not.toHaveBeenCalled()

		useSecretTypeStore().types = []
		await edit({ typeId: 'type-login', key: 'stored' })
		expect(useSecretTypeStore().fetchTypes).toHaveBeenCalledTimes(1)
	})

	it('refuses a changed breached value with the reason and writes nothing', async () => {
		evaluateHibp.mockResolvedValue(BREACHED)
		const wrapper = await edit({ typeId: 'type-login', key: 'stored' })
		wrapper.vm.value = 'changed-value'
		await wrapper.vm.submit()
		expect(evaluateHibp).toHaveBeenCalledWith(POLICY, 'login', 'changed-value')
		expect(wrapper.vm.error).toBe(BREACHED)
		expect(wrapper.vm.saving).toBe(false)
		expect(useSecretStore().updateSecret).not.toHaveBeenCalled()
	})

	it('writes a changed value the breach check allows', async () => {
		const wrapper = await edit({ typeId: 'type-login', key: 'stored' })
		wrapper.vm.value = 'changed-value'
		await wrapper.vm.submit()
		expect(evaluateHibp).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.error).toBe('')
		expect(useSecretStore().updateSecret).toHaveBeenCalledTimes(1)
	})

	it('runs no breach check for an unchanged value', async () => {
		evaluateHibp.mockResolvedValue(BREACHED)
		const wrapper = await edit({ typeId: 'type-login', key: 'stored' })
		wrapper.vm.name = 'Renamed'
		await wrapper.vm.submit()
		expect(evaluateHibp).not.toHaveBeenCalled()
		expect(useSecretStore().updateSecret).toHaveBeenCalledTimes(1)
	})

	it('does nothing while Save is not allowed', async () => {
		const wrapper = await edit({ typeId: 'type-login', key: 'stored' })
		wrapper.vm.name = ''
		await wrapper.vm.submit()
		expect(evaluateHibp).not.toHaveBeenCalled()
		expect(useSecretStore().updateSecret).not.toHaveBeenCalled()
	})

	it('marks a missing required field and stops before the breach check', async () => {
		const wrapper = await edit({ typeId: 'type-server', key: 'stored' })
		wrapper.vm.value = 'changed-value'
		await wrapper.vm.submit()
		expect(wrapper.vm.typedMissing).toEqual(['host'])
		expect(evaluateHibp).not.toHaveBeenCalled()
		expect(useSecretStore().updateSecret).not.toHaveBeenCalled()
	})
})
