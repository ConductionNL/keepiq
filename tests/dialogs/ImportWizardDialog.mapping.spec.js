/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The import wizard's column mapping step and the per-cell reveal
 * (portability-03).
 *
 * @spec openspec/specs/portability-import-mapping/spec.md#requirement-adjustable-csv-mapping
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import ImportWizardDialog from '../../src/dialogs/ImportWizardDialog.vue'
import { useImportStore } from '../../src/store/modules/import.js'
import { useSessionStore } from '../../src/store/modules/session.js'

const ncStubs = {
	NcDialog: {
		props: ['name', 'open', 'size'],
		template: '<div><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		props: ['variant', 'disabled'],
		// Declared, so the listener is not also bound as a native fallthrough
		// click (which would toggle a reveal twice).
		emits: ['click'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcNoteCard: { props: ['type'], template: '<div class="note"><slot /></div>' },
	NcSelect: {
		props: ['options', 'reduce', 'inputLabel', 'clearable', 'modelValue', 'label'],
		template: '<div class="nc-select" :data-label="inputLabel" />',
	},
	NcPasswordField: { props: ['value', 'label'], template: '<input type="password" />' },
	NcCheckboxRadioSwitch: { props: ['modelValue'], template: '<label><slot /></label>' },
	NcLoadingIcon: { props: ['size'], template: '<div />' },
	NcEmptyContent: { props: ['name'], template: '<div><slot /></div>' },
}

const mountOpts = { global: { stubs: ncStubs, mocks: { t: (app, s) => s } } }
const CSV = 'Title,Web address,User,Secret\nGitHub,https://github.com,alice,hunter2\n'

/**
 * Mount the wizard unlocked, on the mapping step of a parsed CSV.
 *
 * @return {Promise<object>} The wrapper.
 */
async function onMappingStep() {
	useSessionStore().cryptoKey = { fake: true }
	const wrapper = mount(ImportWizardDialog, { propsData: { open: true }, ...mountOpts })
	const store = useImportStore()
	await store.parseFile(CSV, 'csv')
	store.goToStep('mapping')
	await wrapper.vm.$nextTick()
	return wrapper
}

describe('ImportWizardDialog column mapping', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('shows one select per CSV column', async () => {
		const wrapper = await onMappingStep()

		const selects = wrapper.findAll('[data-testid="import-column-mapping"] .nc-select')
		expect(selects.map((s) => s.attributes('data-label'))).toEqual([
			'Title',
			'Web address',
			'User',
			'Secret',
		])
	})

	it('blocks Import until a column is mapped to the name', async () => {
		const wrapper = await onMappingStep()
		const store = useImportStore()

		await store.applyMapping(store.mapping.map((m) => ({ ...m, target: 'ignore' })))
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.canProceed).toBe(false)
		expect(wrapper.find('[data-testid="import-mapping-no-name"]').exists()).toBe(true)
	})

	it('reveals only the cell that was asked for', async () => {
		const wrapper = await onMappingStep()
		const store = useImportStore()
		await store.applyMapping([
			{ column: 'Title', target: 'name' },
			{ column: 'Web address', target: 'url' },
			{ column: 'User', target: 'login' },
			{ column: 'Secret', target: 'password' },
		])
		await wrapper.vm.$nextTick()
		expect(wrapper.text()).not.toContain('hunter2')

		await wrapper.find('[data-testid="import-reveal-1-pass"]').trigger('click')

		expect(wrapper.text()).toContain('hunter2')
		expect(wrapper.text()).not.toContain('alice')
	})
})
