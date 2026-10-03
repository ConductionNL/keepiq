/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The export ban hides every file mode, and a refused report offers no
 * download (admin-vault-policies §2.2).
 *
 * @spec openspec/changes/admin-vault-policies/tasks.md#2.2
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import ExportDialog from '../../src/dialogs/ExportDialog.vue'
import { resetPolicyCache } from '../../src/policy/policy.js'
import { useExportStore } from '../../src/store/modules/export.js'

const stubs = {
	NcDialog: {
		props: ['name', 'open', 'size'],
		template: '<div><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		props: ['disabled'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcNoteCard: { props: ['type'], template: '<div class="note"><slot /></div>' },
	NcSelect: {
		props: ['options', 'reduce', 'inputLabel', 'clearable', 'value'],
		template: '<div />',
	},
	NcTextField: { props: ['value', 'label'], template: '<input />' },
	NcPasswordField: {
		props: ['value', 'label'],
		template: '<input type="password" />',
	},
	NcCheckboxRadioSwitch: {
		props: ['modelValue', 'value', 'name', 'type'],
		template: '<label class="mode"><slot /></label>',
	},
}

/**
 * Mount the dialog with the policy the server returns.
 *
 * @param {boolean} banned Whether the export ban applies.
 * @return {Promise<object>} The wrapper.
 */
async function mountWith(banned) {
	vi.spyOn(axios, 'get').mockResolvedValue({
		data: { vault_export_disabled: banned },
	})
	const wrapper = mount(ExportDialog, {
		propsData: { open: true, secrets: [], folders: [] },
		global: { stubs },
	})
	await flushPromises()
	return wrapper
}

describe('ExportDialog under the export ban', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		resetPolicyCache()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('hides every export mode and cannot submit', async () => {
		const wrapper = await mountWith(true)

		expect(
			wrapper.find('[data-testid="export-blocked-by-policy"]').exists(),
		).toBe(true)
		expect(wrapper.find('.export-dialog__modes').exists()).toBe(false)
		expect(wrapper.vm.canSubmit).toBe(false)
	})

	it('shows the modes when the ban does not apply', async () => {
		const wrapper = await mountWith(false)

		expect(
			wrapper.find('[data-testid="export-blocked-by-policy"]').exists(),
		).toBe(false)
		expect(wrapper.find('.export-dialog__modes').exists()).toBe(true)
	})

	it('offers no file when the server refuses the report', async () => {
		const wrapper = await mountWith(false)
		const refusal = Object.assign(new Error('403'), {
			response: { status: 403, data: { code: 'export_disabled_by_policy' } },
		})
		vi.spyOn(axios, 'post').mockRejectedValue(refusal)
		const createUrl = vi.fn()
		globalThis.URL.createObjectURL = createUrl
		wrapper.vm.mode = 'encrypted-backup'
		wrapper.vm.passphrase = 'a-much-longer-unpredictable-passphrase'
		wrapper.vm.passphraseScore = 4

		await wrapper.vm.onExport()
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(
			'/apps/keepiq/api/v1/export/events',
			expect.anything(),
		)
		expect(createUrl).not.toHaveBeenCalled()
		expect(useExportStore().loading).toBe(false)
		expect(wrapper.vm.exportBlocked).toBe(true)
	})
})
