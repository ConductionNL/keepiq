/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component tests for the import wizard's steps beyond the lock guard and
 * commit: the surfaced error, the backup passphrase field, revealing one
 * masked preview cell, the one-folder switch, bulk and per-row duplicate
 * resolutions, the rejected rows in the summary and their client-side CSV
 * download, and stepping back.
 *
 * Runs under jsdom with lightweight @nextcloud/vue stubs and the real
 * import and session stores.
 *
 * @spec openspec/specs/secret-import/spec.md
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useImportStore } from '../../src/store/modules/import.js'
import { useSessionStore } from '../../src/store/modules/session.js'

// The suite-wide t() stub returns the key unfilled. The dialog binds the
// global t when its module loads, so fill the placeholders before the import:
// these tests read the counts the dialog passes in.
vi.hoisted(() => {
	globalThis.t = (app, s, vars = {}) => s.replace(/{(\w+)}/g, (m, k) => (k in vars ? vars[k] : m))
})

const { default: ImportWizardDialog } = await import('../../src/dialogs/ImportWizardDialog.vue')

const ncStubs = {
	NcDialog: {
		props: ['name', 'open', 'size'],
		template: '<div><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		props: ['variant', 'disabled'],
		template: '<button :disabled="disabled"><slot /></button>',
	},
	NcNoteCard: { props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
	NcSelect: {
		name: 'NcSelect',
		props: ['options', 'reduce', 'inputLabel', 'clearable', 'modelValue'],
		emits: ['update:modelValue'],
		template: '<div class="select" />',
	},
	NcPasswordField: {
		props: ['modelValue', 'label'],
		template: '<input type="password" class="passphrase" :aria-label="label" />',
	},
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template: '<label class="switch"><slot /></label>',
	},
	NcLoadingIcon: { props: ['size'], template: '<div />' },
	NcEmptyContent: { props: ['name'], template: '<div class="empty">{{ name }}</div>' },
}

const mountOpts = { global: { stubs: ncStubs } }

/**
 * Mount an open wizard over an unlocked vault.
 *
 * @return {object} The wrapper and the import store.
 */
function openWizard() {
	useSessionStore().cryptoKey = { fake: true }
	const wrapper = mount(ImportWizardDialog, { props: { open: true }, ...mountOpts })
	return { wrapper, store: useImportStore() }
}

/**
 * Read a Blob as text (jsdom's Blob has no text()).
 *
 * @param {Blob} blob The blob.
 * @return {Promise<string>} Its text.
 */
function blobText(blob) {
	return new Promise((resolve) => {
		const reader = new FileReader()
		reader.onload = () => resolve(reader.result)
		reader.readAsText(blob)
	})
}

/**
 * The button whose text is the given label.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} label The button text.
 * @return {object} The button wrapper.
 */
const button = (wrapper, label) => wrapper.findAll('button').find((b) => b.text() === label)

describe('ImportWizardDialog steps', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('shows the surfaced error on an unlocked vault', async () => {
		const { wrapper, store } = openWizard()

		store.error = 'The file could not be parsed.'
		await wrapper.vm.$nextTick()

		const note = wrapper.find('.note')
		expect(note.attributes('data-type')).toBe('error')
		expect(note.text()).toBe('The file could not be parsed.')
	})

	it('asks for a passphrase only for the encrypted backup format', async () => {
		const { wrapper } = openWizard()
		expect(wrapper.find('.passphrase').exists()).toBe(false)

		const select = wrapper.findComponent({ name: 'NcSelect' })
		const backup = select.props('options').find((o) => /backup/i.test(o.label))
		expect(select.props('reduce')(backup)).toBe(backup.value)
		select.vm.$emit('update:modelValue', backup.value)
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.passphrase').attributes('aria-label')).toBe('Backup passphrase')
	})

	it('reveals one masked login cell at a time', async () => {
		const { wrapper, store } = openWizard()
		store.rows = [{ sourceRow: 3, name: 'Bank', login: 'alice', password: 'pw', errors: [] }]
		store.goToStep('mapping')
		await wrapper.vm.$nextTick()

		const cells = () => wrapper.findAll('tbody td').map((td) => td.text())
		expect(cells()[2]).toBe('•••••••• Show')

		await wrapper.get('[data-testid="import-reveal-3-login"]').trigger('click')

		expect(cells()[2]).toBe('alice Hide')
		expect(cells()[3]).toBe('•••••••• Show')
		expect(wrapper.get('[data-testid="import-reveal-3-login"]').attributes('aria-label')).toBe('Hide this value')
	})

	it('follows the one-folder switch on the folders step', async () => {
		const { wrapper, store } = openWizard()
		store.goToStep('folders')
		await wrapper.vm.$nextTick()

		const toggle = wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' })
		toggle.vm.$emit('update:modelValue', true)
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.underOneFolder).toBe(true)
		expect(toggle.props('modelValue')).toBe(true)
	})

	it('resolves all duplicates at once and one row from its select', async () => {
		const { wrapper, store } = openWizard()
		store.duplicates = [
			{ sourceRow: 1, name: 'Bank', errors: [] },
			{ sourceRow: 2, name: 'Mail', errors: [] },
		]
		store.goToStep('duplicates')
		await wrapper.vm.$nextTick()

		expect(wrapper.text()).toContain('2 rows match an existing secret.')
		await button(wrapper, 'Import all as copies').trigger('click')
		expect(store.duplicateResolutions).toEqual({ 1: 'copy', 2: 'copy' })

		await button(wrapper, 'Skip all').trigger('click')
		expect(store.duplicateResolutions).toEqual({ 1: 'skip', 2: 'skip' })

		const rowSelect = wrapper.findAllComponents({ name: 'NcSelect' }).at(-1)
		expect(rowSelect.props('modelValue')).toBe('skip')
		expect(rowSelect.props('reduce')({ value: 'copy' })).toBe('copy')
		rowSelect.vm.$emit('update:modelValue', 'copy')
		expect(store.duplicateResolutions).toEqual({ 1: 'skip', 2: 'copy' })
	})

	it('shows the empty state when there are no duplicates', async () => {
		const { wrapper, store } = openWizard()
		store.goToStep('duplicates')
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.empty').text()).toBe('No duplicates found')
	})

	it('lists the rejected rows and downloads them as CSV without uploading', async () => {
		const { wrapper, store } = openWizard()
		store.rejected = [{ sourceRow: 4, name: 'Bad, row', reason: 'missing name' }]
		store.summary = {
			imported: 5,
			skippedDuplicates: 1,
			rejected: 1,
			foldersCreated: 2,
			rejectedRows: [{ sourceRow: 4, name: 'Bad, row', reason: 'missing name' }],
		}
		store.goToStep('summary')
		await wrapper.vm.$nextTick()

		expect(wrapper.findAll('li').map((li) => li.text())).toEqual([
			'Imported: 5',
			'Skipped duplicates: 1',
			'Rejected: 1',
			'Folders created: 2',
		])
		expect(wrapper.findAll('tbody td').map((td) => td.text())).toEqual(['4', 'Bad, row', 'missing name'])

		const blobs = []
		URL.createObjectURL = vi.fn((blob) => {
			blobs.push(blob)
			return 'blob:rejected'
		})
		URL.revokeObjectURL = vi.fn()
		const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function () {
			expect(this.download).toBe('keepiq-import-rejected.csv')
			expect(this.href).toBe('blob:rejected')
		})

		await button(wrapper, 'Download rejected rows').trigger('click')

		expect(click).toHaveBeenCalledTimes(1)
		expect(blobs[0].type).toBe('text/csv')
		expect(await blobText(blobs[0])).toBe('row,name,reason\r\n4,"Bad, row",missing name')
		expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:rejected')
		expect(document.querySelector('a[download]')).toBeNull()
		expect(button(wrapper, 'Back')).toBeUndefined()
		expect(button(wrapper, 'Close')).toBeDefined()
	})

	it('steps back through duplicates, folders and mapping to the pick step', async () => {
		const { wrapper, store } = openWizard()
		store.rows = [{ sourceRow: 1, name: 'X', errors: [] }]
		store.goToStep('duplicates')
		await wrapper.vm.$nextTick()

		const steps = []
		for (let i = 0; i < 3; i++) {
			await button(wrapper, 'Back').trigger('click')
			steps.push(store.step)
		}
		expect(steps).toEqual(['folders', 'mapping', 'pick'])
		expect(button(wrapper, 'Back')).toBeUndefined()

		wrapper.vm.back()
		expect(store.step).toBe('pick')
	})
})
