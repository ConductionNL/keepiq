/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The per-item outcome of the bulk share: a restricted copy is skipped
 * before anything is encrypted, the registration is sent with the run's
 * password and keeps the one it hands back, and each registration status
 * maps to its report entry.
 *
 * @spec openspec/specs/bulk-actions/spec.md#requirement-ownership-and-authorization-preserved
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import BulkShareDialog from '../../src/dialogs/BulkShareDialog.vue'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useShareStore } from '../../src/store/modules/share.js'

const STUBS = {
	NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
	NcButton: { template: '<button><slot /></button>' },
	RecipientPicker: { template: '<div />' },
	ShareRestrictionFields: { template: '<div />' },
	BulkRunPanel: { template: '<div />' },
}

describe('BulkShareDialog per-item outcome', () => {
	let wrapper
	let registerBatch
	let encrypt

	beforeEach(() => {
		setActivePinia(createPinia())
		wrapper = mount(BulkShareDialog, { propsData: { open: true }, global: { stubs: STUBS } })
		wrapper.vm.targetUserId = 'bob'
		wrapper.vm.certificate = 'PEM'
		wrapper.vm.runPassword = 'pw'
		vi.spyOn(useSecretStore(), 'fetchSecret').mockImplementation(async (id) => ({ id, key: 'plain', login: 'u', ...(id === 'use-only' ? { shareRestriction: { useOnly: true } } : {}), ...(id === 'expiring' ? { accessExpiresAt: '2030-01-01' } : {}) }))
		encrypt = vi.spyOn(useShareStore(), 'encryptForRecipient').mockResolvedValue({ encryptedKey: 'EK' })
		registerBatch = vi.spyOn(useShareStore(), 'registerBatch')
	})

	it('skips a restricted copy before encrypting anything', async () => {
		expect(await wrapper.vm.shareOne('expiring')).toEqual({ status: 'skipped', reason: 'restricted' })
		expect(encrypt).not.toHaveBeenCalled()
		expect(registerBatch).not.toHaveBeenCalled()
	})

	it('maps each registration status to its report entry and keeps the returned password', async () => {
		const answers = [
			[{ status: 'created' }, { status: 'ok', reason: undefined }],
			[{ status: 'exists' }, { status: 'ok', reason: 'already shared' }],
			[{ status: 'copy_fields_mismatch' }, { status: 'skipped', reason: 'This login holds a one-time code or passkeys that this version of Keepiq cannot share yet, so it was not shared.' }],
			[{ status: 'no_suite' }, { status: 'skipped', reason: 'no_suite' }],
			[undefined, { status: 'skipped', reason: 'not registered' }],
			[{}, { status: 'skipped', reason: 'not registered' }],
		]
		for (const [item, outcome] of answers) {
			registerBatch.mockResolvedValueOnce({ items: [item], masterPassword: 'kept' })
			expect(await wrapper.vm.shareOne('s-1')).toEqual(outcome)
		}
		expect(wrapper.vm.runPassword).toBe('kept')
		const [rows, options] = registerBatch.mock.calls[0]
		expect(rows).toHaveLength(1)
		expect(rows[0].sourceSecretId).toBe('s-1')
		expect(rows[0].targetUserId).toBe('bob')
		expect(options).toEqual({ masterPassword: 'pw' })
		expect(encrypt.mock.calls[0][1]).toBe('PEM')
	})
})
