/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests that designating an emergency contact carries a vault-key proof
 * (keepiq#800). A designation names who a later rotation escrows the private
 * key to and overwrites an existing contact's envelope, so a session alone must
 * not be enough.
 *
 * @spec openspec/specs/emergency-access/spec.md#requirement-designate-emergency-contact
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

	it('refuses while locked or without a wrapped key, before any request', async () => {
		const get = vi.spyOn(axios, 'get')
		const session = useSessionStore()
		session.cryptoKey = null
		session.encryptedPrivateKey = 'AES-ENVELOPE'
		const store = useEmergencyAccessStore()
		const params = { granteeUserId: 'bob', waitPeriodDays: 7, masterPassword: 'pw' }
		await expect(store.designate(params)).rejects.toThrow(
			'Unlock your vault before designating an emergency contact',
		)
		session.cryptoKey = {}
		session.encryptedPrivateKey = ''
		await expect(store.designate(params)).rejects.toThrow(
			'Unlock your vault before designating an emergency contact',
		)
		expect(get).not.toHaveBeenCalled()
	})

	it('asks for the grantee certificate, reloads the contacts and returns the contact', async () => {
		const session = useSessionStore()
		session.suiteId = 'suite-1'
		session.encryptedPrivateKey = 'AES-ENVELOPE'
		session.cryptoKey = {}
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { certificate: 'BOB-CERT' } })
		vi.spyOn(axios, 'post').mockResolvedValue({ data: { id: 'rel-1' } })
		const store = useEmergencyAccessStore()
		const fetch = vi.spyOn(store, 'fetchContacts').mockResolvedValue()
		const { buildRecoveryEnvelope } = await import('../../src/crypto/emergencyEnvelope.js')
		const { decryptPrivateKey } = await import('../../src/crypto/index.js')
		const result = await store.designate({ granteeUserId: 'bob', waitPeriodDays: 3, masterPassword: 'pw' })
		expect(get.mock.calls[0]).toEqual([
			'/apps/keepiq/api/v1/emergency-access/grantee-certificate',
			{ params: { granteeUserId: 'bob' } },
		])
		expect(decryptPrivateKey).toHaveBeenLastCalledWith('AES-ENVELOPE', 'pw')
		expect(buildRecoveryEnvelope).toHaveBeenLastCalledWith('PRIVATE-PEM', 'BOB-CERT')
		expect(fetch).toHaveBeenCalledTimes(1)
		expect(result).toEqual({ id: 'rel-1' })
	})
})
