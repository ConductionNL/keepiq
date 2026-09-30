/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The bulk trash and archive dialog (vault-trash-and-archive) runs its one
 * action over the selection, and only deleting for good warns.
 *
 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-restoring-and-purging-trashed-secrets
 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-archiving-a-secret
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import BulkStateDialog from '../../src/dialogs/BulkStateDialog.vue'
import { useBulkStore } from '../../src/store/modules/bulk.js'
import { useSecretStore } from '../../src/store/modules/secret.js'

/**
 * Mount the dialog for one action.
 *
 * @param {string} action The action.
 * @return {object} The wrapper.
 */
function mountFor(action) {
	return mount(BulkStateDialog, {
		propsData: { open: true, action },
		global: {
			stubs: {
				NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
				NcNoteCard: {
					props: ['type'],
					template: '<div class="note" :data-type="type"><slot /></div>',
				},
				NcButton: { template: '<button v-bind="$attrs"><slot /></button>' },
				BulkRunPanel: true,
			},
		},
	})
}

describe('BulkStateDialog', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		useBulkStore().setSelection(['a', 'b'])
	})

	it('restores every selected secret', async () => {
		const change = vi.spyOn(useSecretStore(), 'changeSecretState').mockResolvedValue()
		const wrapper = mountFor('restore')

		await wrapper.find('[data-testid="bulk-state-run"]').trigger('click')
		await vi.waitFor(() => expect(change).toHaveBeenCalledTimes(2))

		expect(change.mock.calls.map((c) => c[1])).toEqual(['restore', 'restore'])
		await vi.waitFor(() => expect(wrapper.emitted('done')).toBeTruthy())
	})

	it('warns only before deleting for good', () => {
		expect(mountFor('purge').find('.note').attributes('data-type')).toBe('warning')
		expect(mountFor('archive').find('.note').attributes('data-type')).toBe('info')
		expect(mountFor('archive').text()).toContain('keep their shares')
	})
})
