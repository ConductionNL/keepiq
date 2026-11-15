/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for the typed confirmation of `src/dialogs/BulkDeleteDialog.vue`.
 *
 * Above ten secrets the run button stays disabled until the exact word
 * `DELETE <count>` is typed; at ten or fewer no word is asked. The Close
 * button and the dialog's own dismissal both emit `close`.
 *
 * @spec openspec/specs/bulk-actions/spec.md#requirement-the-four-bulk-operations
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import BulkDeleteDialog from '../../src/dialogs/BulkDeleteDialog.vue'
import { useBulkStore } from '../../src/store/modules/bulk.js'
import { useSecretStore } from '../../src/store/modules/secret.js'

/**
 * Mount the dialog open with light stubs that keep `disabled` on the button.
 *
 * @return {object} The wrapper.
 */
function mountDialog() {
	return mount(BulkDeleteDialog, {
		propsData: { open: true },
		global: {
			stubs: {
				NcDialog: {
					emits: ['update:open'],
					template: '<div><button data-testid="dismiss" @click="$emit(\'update:open\', false)" /><slot /><slot name="actions" /></div>',
				},
				NcNoteCard: { template: '<div><slot /></div>' },
				NcButton: { template: '<button v-bind="$attrs"><slot /></button>' },
			},
		},
	})
}

/**
 * The given number of distinct secret ids.
 *
 * @param {number} count How many.
 * @return {Array<string>} The ids.
 */
function ids(count) {
	return Array.from({ length: count }, (_, i) => `s-${i}`)
}

const runButton = (wrapper) => wrapper.find('[data-testid="bulk-delete-run"]')

describe('BulkDeleteDialog typed confirmation', () => {
	let bulk

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		bulk = useBulkStore()
		vi.spyOn(useSecretStore(), 'deleteSecret').mockResolvedValue(undefined)
	})

	it('asks no word for ten secrets and leaves the run button enabled', () => {
		bulk.setSelection(ids(10))
		const wrapper = mountDialog()

		expect(wrapper.find('[data-testid="bulk-delete-typed"]').exists()).toBe(false)
		expect(wrapper.vm.confirmed).toBe(true)
		expect(runButton(wrapper).attributes('disabled')).toBeUndefined()
	})

	it('holds the run for eleven secrets until DELETE 11 is typed exactly', async () => {
		bulk.setSelection(ids(11))
		const wrapper = mountDialog()
		const typed = wrapper.find('[data-testid="bulk-delete-typed"]')

		expect(typed.exists()).toBe(true)
		expect(wrapper.vm.confirmWord).toBe('DELETE 11')
		expect(runButton(wrapper).attributes('disabled')).toBeDefined()

		await typed.setValue('delete 11')
		expect(wrapper.vm.confirmed).toBe(false)
		expect(runButton(wrapper).attributes('disabled')).toBeDefined()

		await typed.setValue('DELETE 11')
		expect(wrapper.vm.confirmed).toBe(true)
		expect(runButton(wrapper).attributes('disabled')).toBeUndefined()
	})

	it('emits close from the Close button and from dismissing the dialog', async () => {
		bulk.setSelection(ids(2))
		const wrapper = mountDialog()

		await wrapper.find('[data-testid="bulk-delete-close"]').trigger('click')
		await wrapper.find('[data-testid="dismiss"]').trigger('click')

		expect(wrapper.emitted('close')).toHaveLength(2)
		expect(useSecretStore().deleteSecret).not.toHaveBeenCalled()
	})
})
