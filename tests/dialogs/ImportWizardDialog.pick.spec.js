/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component tests for the import wizard's file pick and step advance: no
 * file picked, a text file parsed into the mapping step, a parse failure
 * that stays on the pick step, the change event of the file input, moving
 * from mapping to folders to duplicates, a failed commit that emits
 * nothing, and the one-folder name falling back to the format.
 *
 * Runs under jsdom with lightweight @nextcloud/vue stubs and the real
 * import and session stores; only the store's parse, duplicate-detection
 * and commit actions are spied.
 *
 * @spec openspec/specs/secret-import/spec.md
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import ImportWizardDialog from '../../src/dialogs/ImportWizardDialog.vue'
import { listParsers } from '../../src/import/parserRegistry.js'
import { useImportStore } from '../../src/store/modules/import.js'
import { useSessionStore } from '../../src/store/modules/session.js'

import '../../src/import/parsers/index.js'

const ncStubs = {
	NcDialog: {
		props: ['name', 'open', 'size'],
		template: '<div><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		props: ['variant', 'disabled'],
		emits: ['click'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcNoteCard: { props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
	NcSelect: { props: ['options', 'reduce', 'inputLabel', 'clearable', 'modelValue'], template: '<div />' },
	NcPasswordField: {
		name: 'NcPasswordField',
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template: '<input type="password" class="passphrase" />',
	},
	NcCheckboxRadioSwitch: { props: ['modelValue'], template: '<label><slot /></label>' },
	NcLoadingIcon: { props: ['size'], template: '<div />' },
	NcEmptyContent: { props: ['name'], template: '<div class="empty">{{ name }}</div>' },
}

/**
 * Mount an open wizard over an unlocked vault.
 *
 * @return {object} The wrapper and the import store.
 */
function openWizard() {
	useSessionStore().cryptoKey = { fake: true }
	const wrapper = mount(ImportWizardDialog, { props: { open: true }, global: { stubs: ncStubs } })
	return { wrapper, store: useImportStore() }
}

/**
 * A picked-file change event over a text file that is not KDBX.
 *
 * @param {string} name The file name.
 * @param {string} text The file contents.
 * @return {object} The event.
 */
function pickEvent(name, text) {
	const head = new Uint8Array([0x6e, 0x61, 0x6d, 0x65]).buffer
	return {
		target: {
			files: [{
				name,
				slice: () => ({ arrayBuffer: async () => head }),
				text: async () => text,
			}],
		},
	}
}

describe('ImportWizardDialog file pick and advance', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('does nothing when the change event carries no file', async () => {
		const { wrapper, store } = openWizard()
		const parse = vi.spyOn(store, 'parseFile')
		wrapper.vm.kdbxDetected = true

		await wrapper.vm.onFilePicked({ target: { files: [] } })

		expect(wrapper.vm.kdbxDetected).toBe(false)
		expect(parse).not.toHaveBeenCalled()
		expect(store.step).toBe('pick')
	})

	it('parses a picked text file with the passphrase and moves to mapping', async () => {
		const { wrapper, store } = openWizard()
		const parse = vi.spyOn(store, 'parseFile').mockResolvedValue()
		wrapper.vm.passphrase = 'secret phrase'

		await wrapper.vm.onFilePicked(pickEvent('vault.csv', 'name,login\nBank,alice'))

		expect(parse).toHaveBeenCalledWith('name,login\nBank,alice', wrapper.vm.format, { passphrase: 'secret phrase' })
		expect(wrapper.vm.sourceName).toBe('vault.csv')
		expect(store.step).toBe('mapping')
	})

	it('stays on the pick step when the parse fails', async () => {
		const { wrapper, store } = openWizard()
		vi.spyOn(store, 'parseFile').mockRejectedValue(new Error('bad file'))

		await wrapper.vm.onFilePicked(pickEvent('broken.csv', 'x'))

		expect(wrapper.vm.sourceName).toBe('broken.csv')
		expect(store.step).toBe('pick')
	})

	it('reads the file when the file input changes', async () => {
		const { wrapper, store } = openWizard()
		const parse = vi.spyOn(store, 'parseFile').mockResolvedValue()
		const input = wrapper.get('[data-testid="import-file"]')
		const file = pickEvent('picked.csv', 'a').target.files[0]
		Object.defineProperty(input.element, 'files', { value: [file] })

		await input.trigger('change')
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(parse).toHaveBeenCalledWith('a', wrapper.vm.format, { passphrase: '' })
		expect(wrapper.vm.sourceName).toBe('picked.csv')
	})

	it('binds the backup passphrase field to the passphrase', async () => {
		const { wrapper } = openWizard()
		wrapper.vm.format = listParsers().find((p) => p.requiresPassphrase).id
		await wrapper.vm.$nextTick()

		wrapper.findComponent({ name: 'NcPasswordField' }).vm.$emit('update:modelValue', 'typed')

		expect(wrapper.vm.passphrase).toBe('typed')
	})

	it('advances from mapping to folders, then detects duplicates', async () => {
		const { wrapper, store } = openWizard()
		const detect = vi.spyOn(store, 'detectDuplicates').mockResolvedValue()
		store.goToStep('mapping')

		await wrapper.vm.next()
		expect(store.step).toBe('folders')
		expect(detect).not.toHaveBeenCalled()

		await wrapper.vm.next()
		expect(detect).toHaveBeenCalledTimes(1)
		expect(store.step).toBe('duplicates')
	})

	it('emits nothing when the commit fails', async () => {
		const { wrapper, store } = openWizard()
		vi.spyOn(store, 'commit').mockRejectedValue(new Error('server down'))
		store.goToStep('duplicates')

		await wrapper.vm.next()

		expect(wrapper.emitted('imported')).toBeUndefined()
	})

	it('emits imported after a successful commit', async () => {
		const { wrapper, store } = openWizard()
		vi.spyOn(store, 'commit').mockResolvedValue()
		store.goToStep('duplicates')

		await wrapper.vm.next()

		expect(wrapper.emitted('imported')).toHaveLength(1)
	})

	it('names the one folder after the format when there was no file', () => {
		const { wrapper, store } = openWizard()
		store.format = 'bitwarden'
		const today = new Date().toISOString().slice(0, 10)

		expect(wrapper.vm.oneFolderName()).toBe(`bitwarden ${today}`)

		wrapper.vm.sourceName = 'export.v2.json'
		expect(wrapper.vm.oneFolderName()).toBe(`export.v2 ${today}`)
	})
})
