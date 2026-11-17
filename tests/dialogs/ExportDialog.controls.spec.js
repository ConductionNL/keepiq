/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The controls of `src/dialogs/ExportDialog.vue`, driven through the
 * rendered inputs rather than by setting component state: the format
 * radios, the scope select, the passphrase strength, the plaintext
 * acknowledgement, the cancel button and the CXF type-name map.
 *
 * @spec openspec/specs/secret-export/spec.md
 * @spec openspec/specs/cxf-import-export/spec.md#requirement-cxf-entity-to-keepiq-type-mapping
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import ExportDialog from '../../src/dialogs/ExportDialog.vue'
import * as reauth from '../../src/crypto/reauth.js'
import { resetPolicyCache } from '../../src/policy/policy.js'
import { useExportStore } from '../../src/store/modules/export.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'

const stubs = {
	NcDialog: {
		props: ['name', 'open', 'size'],
		template: '<div><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		props: ['variant', 'disabled'],
		emits: ['click'],
		template:
			'<button :class="variant" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcNoteCard: {
		props: ['type'],
		template: '<div class="note" v-bind="$attrs"><slot /></div>',
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'reduce', 'inputLabel', 'clearable'],
		emits: ['update:modelValue'],
		template: '<div class="scope" />',
	},
	NcPasswordField: {
		name: 'NcPasswordField',
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template:
			'<input type="password" :aria-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
	NcCheckboxRadioSwitch: {
		props: ['modelValue', 'value', 'name', 'type'],
		emits: ['update:modelValue'],
		template:
			'<label class="switch" v-bind="$attrs" :data-value="value" @click="$emit(\'update:modelValue\', value === undefined ? !modelValue : value)"><slot /></label>',
	},
	RecoveryKitPanel: { template: '<div class="recovery-kit" />' },
	SkippedSecretsNotice: { template: '<div />' },
}

const folders = [
	{ id: 'f-1', name: 'Work' },
	{ id: 'f-2', name: 'Home' },
]

/**
 * Mount the dialog open, with no export ban.
 *
 * @return {Promise<import('@vue/test-utils').VueWrapper>}
 */
async function mountDialog() {
	vi.spyOn(axios, 'get').mockResolvedValue({
		data: { vault_export_disabled: false },
	})
	const wrapper = mount(ExportDialog, {
		propsData: { open: true, secrets: [], folders },
		global: { stubs },
	})
	await flushPromises()
	return wrapper
}

/**
 * The format radio for a mode.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper The dialog
 * @param {string} mode The export mode
 * @return {import('@vue/test-utils').DOMWrapper}
 */
function radio(wrapper, mode) {
	return wrapper.find(`.export-dialog__modes [data-value="${mode}"]`)
}

describe('ExportDialog controls', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		resetPolicyCache()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('switches between the backup, CSV and CXF paths through the radios', async () => {
		const wrapper = await mountDialog()
		expect(wrapper.find('.export-dialog__backup').exists()).toBe(true)

		await radio(wrapper, 'plaintext-csv').trigger('click')
		expect(wrapper.vm.mode).toBe('plaintext-csv')
		expect(wrapper.find('.export-dialog__backup').exists()).toBe(false)
		expect(
			wrapper.find('[data-testid="export-plaintext-warning"]').text(),
		).toContain('A CSV export is UNENCRYPTED')

		await radio(wrapper, 'cxf').trigger('click')
		expect(wrapper.vm.mode).toBe('cxf')
		expect(
			wrapper.find('[data-testid="export-plaintext-warning"]').text(),
		).toContain('A CXF export is UNENCRYPTED')

		await radio(wrapper, 'encrypted-backup').trigger('click')
		expect(wrapper.vm.mode).toBe('encrypted-backup')
		expect(wrapper.find('.export-dialog__backup').exists()).toBe(true)
	})

	it('offers the whole vault and each folder as scope, and exports the picked folder', async () => {
		const wrapper = await mountDialog()
		const select = wrapper.findComponent({ name: 'NcSelect' })

		expect(select.props('options')).toEqual([
			{ label: 'Entire vault', value: 'vault' },
			{ label: 'Work', value: 'f-1' },
			{ label: 'Home', value: 'f-2' },
		])
		expect(select.props('reduce')({ label: 'Work', value: 'f-1' })).toBe('f-1')
		expect(select.props('modelValue')).toBe('vault')

		select.vm.$emit('update:modelValue', 'f-2')
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.buildScope()).toEqual({
			mode: 'folders',
			folderIds: ['f-2'],
		})
	})

	it('scores the typed passphrase and shows whether it is strong enough', async () => {
		const wrapper = await mountDialog()
		const field = wrapper.find('.export-dialog__backup input[type="password"]')
		expect(wrapper.find('.export-dialog__strength').exists()).toBe(false)

		await field.setValue('abc')
		expect(wrapper.vm.passphraseScore).toBeLessThan(3)
		expect(wrapper.find('.export-dialog__strength').text()).toBe(
			'Passphrase too weak — choose a longer, less predictable passphrase',
		)
		expect(wrapper.vm.canSubmit).toBe(false)

		await field.setValue('correct horse battery staple violet harbour')
		expect(wrapper.vm.passphraseScore).toBe(4)
		expect(wrapper.find('.export-dialog__strength').classes()).toContain(
			'export-dialog__strength--4',
		)
		expect(wrapper.find('.export-dialog__strength').text()).toBe(
			'Passphrase strength: strong enough',
		)
		expect(wrapper.vm.canSubmit).toBe(true)

		await field.setValue('')
		expect(wrapper.vm.passphraseScore).toBe(0)
	})

	it('asks for the master password only once the plaintext warning is acknowledged', async () => {
		const wrapper = await mountDialog()
		await radio(wrapper, 'plaintext-csv').trigger('click')
		const csv = () => wrapper.find('.export-dialog__csv')

		expect(csv().findAll('input[type="password"]')).toHaveLength(0)

		await csv().find('label.switch').trigger('click')
		expect(wrapper.vm.warningAcknowledged).toBe(true)
		const master = csv().find('input[type="password"]')
		expect(master.attributes('aria-label')).toBe('Re-enter your master password')
		expect(wrapper.vm.canSubmit).toBe(false)

		await master.setValue('hunter2')
		expect(wrapper.vm.masterPassword).toBe('hunter2')
		expect(wrapper.vm.canSubmit).toBe(true)
	})

	it('clears what was entered and closes from the cancel button', async () => {
		const wrapper = await mountDialog()
		await radio(wrapper, 'plaintext-csv').trigger('click')
		await wrapper.find('.export-dialog__csv label.switch').trigger('click')

		const cancel = wrapper
			.findAll('button')
			.find((button) => button.text() === 'Cancel')
		await cancel.trigger('click')

		expect(wrapper.emitted('update:open')).toEqual([[false]])
		expect(wrapper.vm.mode).toBe('encrypted-backup')
		expect(wrapper.vm.warningAcknowledged).toBe(false)
	})

	it('does not read the policy again when the dialog closes', async () => {
		const wrapper = await mountDialog()
		expect(axios.get).toHaveBeenCalledTimes(1)

		await wrapper.setProps({ open: false })
		await flushPromises()

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.exportBlocked).toBe(false)
	})

	it('hands the CXF export a map of type id to type name', async () => {
		const wrapper = await mountDialog()
		useSecretTypeStore().types = [
			{ id: 't-login', name: 'Login' },
			{ id: 't-note', name: 'Secure note' },
		]
		vi.spyOn(reauth, 'verifyMasterPassword').mockResolvedValue(true)
		const cxf = vi
			.spyOn(useExportStore(), 'exportCxf')
			.mockResolvedValue({ unmapped: [] })
		wrapper.vm.mode = 'cxf'
		wrapper.vm.warningAcknowledged = true
		wrapper.vm.masterPassword = 'hunter2'

		await wrapper.vm.onExport()

		expect(cxf).toHaveBeenLastCalledWith(
			[],
			folders,
			{ mode: 'vault' },
			{
				typeNamesById: { 't-login': 'Login', 't-note': 'Secure note' },
			},
		)
	})
})
