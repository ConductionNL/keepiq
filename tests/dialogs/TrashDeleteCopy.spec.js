/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The delete dialogs tell the truth (vault-trash-and-archive): a delete moves
 * secrets to the trash and ends their shares now; nothing says there is no
 * trash any more.
 *
 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-deleting-a-secret-moves-it-to-the-trash
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import BulkDeleteDialog from '../../src/dialogs/BulkDeleteDialog.vue'
import SecretDeleteConfirmDialog from '../../src/dialogs/SecretDeleteConfirmDialog.vue'

const stubs = {
	NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
	NcNoteCard: { template: '<div class="note"><slot /></div>' },
	NcButton: { template: '<button><slot /></button>' },
	NcLoadingIcon: true,
	TrashCanOutline: true,
	BulkRunPanel: true,
}

describe('delete dialog copy', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('says one secret goes to the trash and its shares end now', () => {
		const text = mount(SecretDeleteConfirmDialog, {
			propsData: { secretId: 's-1', open: true },
			global: { stubs },
		}).text()
		expect(text).toContain('moves the secret to the trash')
		expect(text).toContain('ends its shares now')
		expect(text).not.toContain('There is no trash')
	})

	it('says a selection goes to the trash', () => {
		const text = mount(BulkDeleteDialog, {
			propsData: { open: true },
			global: { stubs },
		}).text()
		expect(text).toContain('to the trash')
		expect(text).not.toContain('There is no trash')
	})
})
