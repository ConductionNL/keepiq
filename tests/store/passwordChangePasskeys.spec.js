/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A master password change shows the passkeys as stale right away.
 *
 * The server marks every passkey unlock envelope stale when the private key
 * is re-wrapped (passkey-vault-login D4). Found live on 2 Oct 2026: the
 * settings kept listing both passkeys as "active", without the re-enroll
 * note, until the page was reloaded, while the lock screen had already
 * stopped offering them. The change now reloads the passkey list.
 *
 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkeys-are-manageable-revocable-and-owner-scoped
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/crypto/index.js', async (importOriginal) => ({
	...(await importOriginal()),
	decryptPrivateKey: vi.fn(async () => 'PEM'),
	encryptPrivateKey: vi.fn(async () => 'NEW-ENVELOPE'),
}))

vi.mock('../../src/crypto/keyProof.js', () => ({
	PROOF_PURPOSE: { UPDATE_PRIVATE_KEY: 'update-private-key' },
	buildKeyProofHeaders: vi.fn(async () => ({ 'X-Keepiq-Key-Proof': 'sig' })),
}))

import { useEncryptionSuiteStore } from '../../src/store/modules/encryptionSuite.js'
import { usePasskeyStore } from '../../src/store/modules/passkey.js'
import { useSessionStore } from '../../src/store/modules/session.js'

describe('master password change and passkeys', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('reloads the passkey list, so stale passkeys show as stale', async () => {
		const session = useSessionStore()
		session.suiteId = 'suite-1'
		session.encryptedPrivateKey = 'OLD-ENVELOPE'

		const passkeys = usePasskeyStore()
		passkeys.credentials = [{ id: 'p1', status: 'active' }]
		passkeys.hasActive = true

		vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [{ id: 'p1', status: 'stale' }],
		})

		await useEncryptionSuiteStore().changePassword('old', 'new')

		expect(session.encryptedPrivateKey).toBe('NEW-ENVELOPE')
		expect(axios.get).toHaveBeenCalledWith(
			expect.stringContaining('/apps/keepiq/api/v1/passkeys'),
		)
		expect(passkeys.credentials).toEqual([{ id: 'p1', status: 'stale' }])
		expect(passkeys.hasActive).toBe(false)
	})
})
