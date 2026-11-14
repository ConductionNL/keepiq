/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The create dialog prefilled from a secret being cloned
 * (vault-item-clone-preview-and-print, task 1.1): it starts from the
 * source's type, name with " (copy)", URL, login, value, additional fields
 * and folder, says what is not copied, and saves through the ordinary create.
 *
 * @spec openspec/changes/vault-item-clone-preview-and-print/specs/secret-item-actions/spec.md#requirement-clone-a-secret
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import SecretCreateDialog, { cloneName } from '../../src/dialogs/SecretCreateDialog.vue'
import { useFolderStore } from '../../src/store/modules/folder.js'
import { useSecretStore } from '../../src/store/modules/secret.js'
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
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcSelect: { props: ['options', 'reduce', 'inputLabel', 'clearable', 'modelValue'], template: '<div />' },
	NcTextField: {
		props: ['modelValue', 'label', 'placeholder', 'disabled', 'required'],
		emits: ['update:modelValue'],
		template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	NcPasswordField: { props: ['modelValue', 'label'], template: '<input type="password" :value="modelValue" />' },
	NcNoteCard: { props: ['type'], template: '<div><slot /></div>' },
	NcLoadingIcon: { template: '<span />' },
	Plus: { template: '<i />' },
	Dice5: { template: '<i />' },
	KeyGeneratorModal: { props: ['open'], template: '<div />' },
}

const GRAFANA = {
	typeId: 'login',
	name: 'Grafana staging',
	url: 'https://grafana.staging.example',
	login: 'ops',
	key: 'Xk9#mQ2$vL7@pR4!zT6&',
	additionalFields: { team: 'platform' },
	folderId: 'folder-ops',
}

const originalT = globalThis.t

async function mountDialog(props) {
	const wrapper = mount(SecretCreateDialog, { props, global: { stubs } })
	// mounted() awaits the policy, types and preferences before the prefill lands.
	await new Promise((resolve) => setTimeout(resolve, 0))
	await wrapper.vm.$nextTick()
	return wrapper
}

describe('SecretCreateDialog — clone', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		globalThis.t = (_app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_m, k) => vars[k] ?? '')
		const typeStore = useSecretTypeStore()
		typeStore.types = [
			{ id: 'login', name: 'login', label: 'Login' },
			{ id: 'card', name: 'card', label: 'Card' },
		]
		typeStore.fetchTypes = vi.fn().mockResolvedValue()
		const prefs = useUserPreferencesStore()
		prefs.ensureLoaded = vi.fn().mockResolvedValue()
		const folderStore = useFolderStore()
		folderStore.folders = [{ id: 'folder-ops', parentId: null }]
		folderStore.fetchFolders = vi.fn().mockResolvedValue()
		const session = useSessionStore()
		session.cryptoKey = 'UNLOCKED'
		session.certificate = 'PEM'
	})

	afterEach(() => {
		globalThis.t = originalT
	})

	it('names the copy after its source', () => {
		expect(cloneName('  Grafana staging ')).toBe('Grafana staging (copy)')
	})

	it('starts from the source and says what is not copied', async () => {
		const wrapper = await mountDialog({ prefill: GRAFANA })

		expect(wrapper.vm.name).toBe('Grafana staging (copy)')
		expect(wrapper.vm.typeId).toBe('login')
		expect(wrapper.vm.url).toBe('https://grafana.staging.example')
		expect(wrapper.vm.login).toBe('ops')
		expect(wrapper.vm.value).toBe('Xk9#mQ2$vL7@pR4!zT6&')
		expect(wrapper.vm.additionalFields).toEqual([{ name: 'team', value: 'platform' }])
		expect(wrapper.vm.selectedFolderId).toBe('folder-ops')
		expect(wrapper.find('[data-testid="secret-create-clone-note"]').text()).toContain(
			'Attachments, shares, history, passkeys and the one-time code are not copied.',
		)
	})

	it('saves a new secret through the ordinary create, with the changed address', async () => {
		const wrapper = await mountDialog({ prefill: GRAFANA })
		vi.spyOn(wrapper.vm, 'breachBlocked').mockResolvedValue(false)
		const create = vi.spyOn(useSecretStore(), 'createSecret').mockResolvedValue({ id: 'new-id' })
		const setTags = vi.spyOn(useSecretStore(), 'setTags').mockResolvedValue()

		wrapper.vm.url = 'https://grafana.prod.example'
		await wrapper.vm.submit()

		expect(create).toHaveBeenCalledTimes(1)
		expect(create.mock.calls[0][0]).toEqual({
			name: 'Grafana staging (copy)',
			typeId: 'login',
			folderId: 'folder-ops',
			url: 'https://grafana.prod.example',
			login: 'ops',
			key: 'Xk9#mQ2$vL7@pR4!zT6&',
			additionalFields: { team: 'platform' },
		})
		expect(setTags).not.toHaveBeenCalled()
		expect(wrapper.emitted('saved')[0][0]).toEqual({ id: 'new-id' })
	})

	it('seeds a card from its decrypted payload', async () => {
		const card = { number: '4111111111111111', expiry: '12/29', cvv: '123', pin: '', cardholder: 'J. Jansen' }
		const wrapper = await mountDialog({
			prefill: { typeId: 'card', name: 'Company card', key: JSON.stringify(card), folderId: 'folder-ops' },
		})
		expect(wrapper.vm.card).toEqual(card)
	})

	it('a folder passed by the caller wins over the source folder, and without a prefill nothing is seeded', async () => {
		const cloned = await mountDialog({ prefill: GRAFANA, folderId: 'folder-other' })
		expect(cloned.vm.selectedFolderId).toBe('folder-other')

		const fresh = await mountDialog({ folderId: 'folder-ops' })
		expect(fresh.vm.name).toBe('')
		expect(fresh.vm.value).toBe('')
		expect(fresh.find('[data-testid="secret-create-clone-note"]').exists()).toBe(false)
	})
})
