/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests that designating an emergency contact carries a vault-key proof
 * (keepiq#800). A designation names who a later rotation escrows the private
 * key to and overwrites an existing contact's envelope, so a session alone must
 * not be enough.
 *
 * @spec openspec/changes/harden-vault-key-material-guards/specs/emergency-access/spec.md#requirement-designate-emergency-contact
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { buildKeyProofHeaders, PROOF_PURPOSE } from '../../src/crypto/keyProof.js'
import { useEmergencyAccessStore } from '../../src/store/modules/emergencyAccess.js'
import { useSessionStore } from '../../src/store/modules/session.js'

vi.mock('../../src/crypto/index.js', async (importOriginal) => ({
	...(await importOriginal()),
	decryptPrivateKey: vi.fn(async () => 'PRIVATE-PEM'),
}))

vi.mock('../../src/crypto/emergencyEnvelope.js', async (importOriginal) => ({
	...(await importOriginal()),
	buildRecoveryEnvelope: vi.fn(async () => 'ENVELOPE-JSON'),
}))

vi.mock('../../src/crypto/keyProof.js', async (importOriginal) => ({
	...(await importOriginal()),
	buildKeyProofHeaders: vi.fn(async () => ({ 'X-Keepiq-Key-Proof': 'SIG' })),
}))

describe('useEmergencyAccessStore — designate', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		buildKeyProofHeaders.mockClear()
	})

	it('posts the designation with a proof bound to grantee, wait period and envelope', async () => {
		const session = useSessionStore()
		session.suiteId = 'suite-1'
		session.encryptedPrivateKey = 'AES-ENVELOPE'
		session.cryptoKey = {}

		vi.spyOn(axios, 'get').mockImplementation(async (url) => {
			if (url.endsWith('/emergency-access/grantee-certificate')) {
				return { data: { certificate: 'BOB-CERT', suiteId: 'bob-suite' } }
			}
			return { data: [] }
		})
		const post = vi
			.spyOn(axios, 'post')
			.mockResolvedValue({ data: { id: 'rel-1' } })

		const store = useEmergencyAccessStore()
		await store.designate({
			granteeUserId: 'bob',
			waitPeriodDays: 7,
			masterPassword: 'master-pw',
		})

		expect(buildKeyProofHeaders).toHaveBeenCalledWith({
			suiteId: 'suite-1',
			purpose: PROOF_PURPOSE.EMERGENCY_DESIGNATE,
			encryptedPrivateKey: 'AES-ENVELOPE',
			masterPassword: 'master-pw',
			// The server hashes (string)getParam(), so the number is bound as '7'.
			boundValues: ['bob', '7', 'ENVELOPE-JSON'],
		})
		expect(post).toHaveBeenCalledWith(
			expect.stringContaining('/emergency-access/contacts'),
			{
				granteeUserId: 'bob',
				waitPeriodDays: 7,
				accessLevel: 'view',
				recoveryEnvelope: 'ENVELOPE-JSON',
			},
			{ headers: { 'X-Keepiq-Key-Proof': 'SIG' } },
		)
		expect(JSON.stringify(post.mock.calls)).not.toContain('master-pw')
	})
})
