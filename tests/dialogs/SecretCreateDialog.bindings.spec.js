/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The create dialog's form wiring: each field writes what its child
 * component emits into the value the save reads (type, login, URL,
 * additional URLs, additional fields, tags, the reprompt switch and the
 * folder), and Cancel closes the dialog.
 *
 * @spec openspec/specs/secrets-write-ui/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretCreateDialog from '../../src/dialogs/SecretCreateDialog.vue'
import { useFolderStore } from '../../src/store/modules/folder.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'
import { useSessionStore } from '../../src/store/modules/session.js'
import { useUserPreferencesStore } from '../../src/store/modules/userPreferences.js'

/**
 * A stub that emits whatever the test asks it to.
 *
 * @param {string} name The component name.
 * @param {Array<string>} props Its props.
 * @return {object} The stub.
 */
const emitter = (name, props) => ({ name, props, template: '<div><slot /></div>' })

const stubs = {
	NcDialog: {
		props: ['name', 'open', 'size'],
		template: '<div><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		props: ['disabled', 'variant', 'ariaLabel', 'title'],
		emits: ['click'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcSelect: emitter('NcSelect', ['options', 'reduce', 'inputLabel', 'clearable', 'modelValue', 'label']),
	NcTextField: emitter('NcTextField', ['modelValue', 'label', 'placeholder', 'disabled', 'required']),
	NcPasswordField: { props: ['modelValue', 'label'], template: '<input type="password" />' },
	NcNoteCard: { props: ['type'], template: '<div><slot /></div>' },
	NcLoadingIcon: { template: '<span />' },
	NcCheckboxRadioSwitch: emitter('NcCheckboxRadioSwitch', ['modelValue', 'disabled']),
	Plus: { template: '<i />' },
	Dice5: { template: '<i />' },
	KeyGeneratorModal: { props: ['open'], template: '<div />' },
	AdditionalUrlsField: emitter('AdditionalUrlsField', ['modelValue', 'disabled']),
	AdditionalFieldsEditor: emitter('AdditionalFieldsEditor', ['members', 'disabled']),
	SecretTagsField: emitter('SecretTagsField', ['modelValue', 'disabled']),
	DestinationSelect: emitter('DestinationSelect', ['modelValue', 'onlyIds', 'label', 'mode']),
}

describe('SecretCreateDialog form wiring', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		const typeStore = useSecretTypeStore()
		typeStore.types = [
			{ id: 'type-login', name: 'login', label: 'Login' },
			{ id: 'type-note', name: 'note', label: 'Note' },
		]
		typeStore.fetchTypes = vi.fn().mockResolvedValue()
		const folderStore = useFolderStore()
		folderStore.folders = [{ id: 'f1', parentId: null }]
		folderStore.fetchFolders = vi.fn().mockResolvedValue()
		const prefs = useUserPreferencesStore()
		prefs.ensureLoaded = vi.fn().mockResolvedValue()
		prefs.defaultSecretType = 'login'
		useSessionStore().cryptoKey = 'UNLOCKED'
	})

	/**
	 * Mount the dialog and let mounted() settle.
	 *
	 * @return {Promise<object>} The wrapper.
	 */
	async function open() {
		const wrapper = mount(SecretCreateDialog, { global: { stubs } })
		await flushPromises()
		return wrapper
	}

	it('writes the type select and its reduced option into typeId', async () => {
		const wrapper = await open()
		const select = wrapper.findAllComponents({ name: 'NcSelect' })
			.find((s) => s.props('inputLabel') === 'Type')

		expect(select.props('reduce')({ value: 'type-note', label: 'Note' })).toBe('type-note')
		select.vm.$emit('update:modelValue', 'type-note')

		expect(wrapper.vm.typeId).toBe('type-note')
	})

	it('writes login and URL from their text fields', async () => {
		const wrapper = await open()
		const fields = wrapper.findAllComponents({ name: 'NcTextField' })
		const byLabel = (label) => fields.find((f) => f.props('label') === label)

		byLabel('Login (optional)').vm.$emit('update:modelValue', 'alice')
		byLabel('URL (optional)').vm.$emit('update:modelValue', 'https://bank.test')

		expect(wrapper.vm.login).toBe('alice')
		expect(wrapper.vm.url).toBe('https://bank.test')
	})

	it('writes additional URLs, additional fields, tags, reprompt and folder', async () => {
		const wrapper = await open()
		const members = [{ key: 'pin', value: '1234' }]

		wrapper.findComponent({ name: 'AdditionalUrlsField' }).vm.$emit('update:modelValue', ['https://b.test'])
		wrapper.findComponent({ name: 'AdditionalFieldsEditor' }).vm.$emit('update:members', members)
		wrapper.findComponent({ name: 'SecretTagsField' }).vm.$emit('update:modelValue', ['bank'])
		wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
			.find((s) => s.attributes('data-testid') === 'secret-reprompt')
			.vm.$emit('update:modelValue', true)
		wrapper.findComponent({ name: 'DestinationSelect' }).vm.$emit('update:modelValue', 'f1')

		expect(wrapper.vm.additionalUrls).toEqual(['https://b.test'])
		expect(wrapper.vm.additionalFields).toEqual(members)
		expect(wrapper.vm.tags).toEqual(['bank'])
		expect(wrapper.vm.reprompt).toBe(true)
		expect(wrapper.vm.selectedFolderId).toBe('f1')
	})

	it('closes on Cancel', async () => {
		const wrapper = await open()

		await wrapper.findAll('button').find((b) => b.text() === 'Cancel').trigger('click')

		expect(wrapper.vm.open).toBe(false)
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})
