/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for the emergency-access re-envelope step of compromise-recovery
 * rotation (migrate-emergency-access-on-rotation, tasks 2.x / 4.3 / 4.5).
 *
 * For each contact the owner CONFIRMED, and only while it is `granted`, the
 * browser fetches the grantee's current certificate, builds a fresh envelope
 * escrowing the NEW private key, and posts it to the migration re-point endpoint
 * with a vault-key proof made with the OLD key (keepiq#800, #801). Everything
 * else on the old suite (unconfirmed, a break-glass in flight, an unreachable
 * grantee, a failed post) is left for the completion sweep and returned as
 * residual.
 *
 * @spec openspec/changes/migrate-emergency-access-on-rotation/specs/emergency-access/spec.md#requirement-envelope-invalidation-on-key-change
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { buildRecoveryEnvelope } from '../../src/crypto/emergencyEnvelope.js'
import { buildKeyProofHeaders, PROOF_PURPOSE } from '../../src/crypto/keyProof.js'
import { useEncryptionSuiteStore } from '../../src/store/modules/encryptionSuite.js'

vi.mock('../../src/store/modules/offline.js', () => ({
	useOfflineStore: () => ({ evict: async () => {} }),
}))

vi.mock('../../src/crypto/emergencyEnvelope.js', () => ({
	buildRecoveryEnvelope: vi.fn(async () => 'FRESH-ENVELOPE-JSON'),
}))

vi.mock('../../src/crypto/keyProof.js', async (importOriginal) => ({
	...(await importOriginal()),
	buildKeyProofHeaders: vi.fn(async () => ({ 'X-Keepiq-Key-Proof': 'SIG' })),
}))

/**
 * Run migrateEmergencyContacts with the old-key proof inputs filled in.
 *
 * @param {object} store The encryption-suite store.
 * @param {string[]} carryContactIds The contacts the owner confirmed.
 * @return {Promise<string[]>} The residual grantee ids.
 */
function migrate(store, carryContactIds) {
	return store.migrateEmergencyContacts({
		migrationId: 'migr-1',
		oldSuiteId: 'old-suite',
		newPrivateKeyPem: 'NEW-PEM',
		oldEncryptedPrivateKey: 'OLD-ENVELOPE',
		oldPassword: 'old-pw',
		carryContactIds,
	})
}

/**
 * Mock axios.get so contacts and grantee-certificate resolve as configured.
 *
 * @param {object} opts The options.
 * @param {Array<object>} opts.contacts The owner's emergency contacts.
 * @param {object} opts.certs Map of granteeUserId -> {suiteId, certificate} or 'throw'.
 * @return {void}
 */
function mockGets({ contacts, certs }) {
	vi.spyOn(axios, 'get').mockImplementation(async (url, config) => {
		if (url.endsWith('/emergency-access/contacts')) {
			return { data: contacts }
		}
		if (url.endsWith('/emergency-access/grantee-certificate')) {
			const grantee = config?.params?.granteeUserId
			const cert = certs[grantee]
			if (cert === 'throw' || cert === undefined) {
				throw new Error('no active certificate')
			}
			return { data: cert }
		}
		return { data: {} }
	})
}

describe('useEncryptionSuiteStore — migrateEmergencyContacts', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		buildRecoveryEnvelope.mockClear()
		buildRecoveryEnvelope.mockResolvedValue('FRESH-ENVELOPE-JSON')
		buildKeyProofHeaders.mockClear()
	})

	it('re-envelopes a reachable grantee and reports no residual', async () => {
		mockGets({
			contacts: [
				{
					id: 'rel-1',
					granteeUserId: 'bob',
					state: 'granted',
					grantorSuiteId: 'old-suite',
				},
			],
			certs: { bob: { suiteId: 'bob-suite', certificate: 'BOB-CERT' } },
		})
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })

		const store = useEncryptionSuiteStore()
		const residual = await migrate(store, ['rel-1'])

		expect(residual).toEqual([])
		expect(buildRecoveryEnvelope).toHaveBeenCalledWith('NEW-PEM', 'BOB-CERT')
		expect(post).toHaveBeenCalledWith(
			expect.stringContaining('/migrations/migr-1/emergency-contacts/rel-1'),
			{ recoveryEnvelope: 'FRESH-ENVELOPE-JSON', granteeSuiteId: 'bob-suite' },
			{ headers: { 'X-Keepiq-Key-Proof': 'SIG' } },
		)
		// The proof is made with the OLD key and binds the route's parameters in
		// the order the server hashes them: id, contactId, recoveryEnvelope,
		// granteeSuiteId.
		expect(buildKeyProofHeaders).toHaveBeenCalledWith({
			suiteId: 'old-suite',
			purpose: PROOF_PURPOSE.EMERGENCY_RE_ENVELOPE,
			encryptedPrivateKey: 'OLD-ENVELOPE',
			masterPassword: 'old-pw',
			boundValues: ['migr-1', 'rel-1', 'FRESH-ENVELOPE-JSON', 'bob-suite'],
		})
	})

	it('does not carry a contact the owner did not confirm', async () => {
		mockGets({
			contacts: [
				{ id: 'rel-1', granteeUserId: 'bob', state: 'granted', grantorSuiteId: 'old-suite' },
			],
			certs: { bob: { suiteId: 'bob-suite', certificate: 'BOB-CERT' } },
		})
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })

		const store = useEncryptionSuiteStore()
		const residual = await migrate(store, [])

		expect(residual).toEqual(['bob'])
		expect(buildRecoveryEnvelope).not.toHaveBeenCalled()
		expect(post).not.toHaveBeenCalled()
	})

	it.each(['requested', 'approved'])(
		'never escrows the new key to a %s contact, even when confirmed',
		async (state) => {
			mockGets({
				contacts: [
					{ id: 'rel-1', granteeUserId: 'mallory', state, grantorSuiteId: 'old-suite' },
				],
				certs: { mallory: { suiteId: 'm-suite', certificate: 'M-CERT' } },
			})
			const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })

			const store = useEncryptionSuiteStore()
			const residual = await migrate(store, ['rel-1'])

			expect(residual).toEqual(['mallory'])
			expect(buildRecoveryEnvelope).not.toHaveBeenCalled()
			expect(post).not.toHaveBeenCalled()
		},
	)

	it('lists only granted contacts on the given suite as carriable', async () => {
		mockGets({
			contacts: [
				{ id: 'a', granteeUserId: 'bob', state: 'granted', grantorSuiteId: 'old-suite' },
				{ id: 'b', granteeUserId: 'mallory', state: 'approved', grantorSuiteId: 'old-suite' },
				{ id: 'c', granteeUserId: 'carol', state: 'invalidated', grantorSuiteId: 'old-suite' },
				{ id: 'd', granteeUserId: 'erin', state: 'granted', grantorSuiteId: 'a-prior-suite' },
			],
			certs: {},
		})

		const store = useEncryptionSuiteStore()
		const carriable = await store.listCarriableEmergencyContacts('old-suite')

		expect(carriable.map((c) => c.id)).toEqual(['a'])
	})

	it('reports an unreachable grantee as residual and never posts', async () => {
		mockGets({
			contacts: [
				{
					id: 'rel-1',
					granteeUserId: 'bob',
					state: 'granted',
					grantorSuiteId: 'old-suite',
				},
			],
			certs: { bob: 'throw' },
		})
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })

		const store = useEncryptionSuiteStore()
		const residual = await migrate(store, ['rel-1'])

		expect(residual).toEqual(['bob'])
		expect(post).not.toHaveBeenCalled()
	})

	it('skips an already-invalidated contact entirely', async () => {
		mockGets({
			contacts: [
				{
					id: 'rel-x',
					granteeUserId: 'carol',
					state: 'invalidated',
					grantorSuiteId: 'old-suite',
				},
			],
			certs: {},
		})
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })

		const store = useEncryptionSuiteStore()
		const residual = await migrate(store, ['rel-x'])

		expect(residual).toEqual([])
		expect(buildRecoveryEnvelope).not.toHaveBeenCalled()
		expect(post).not.toHaveBeenCalled()
	})

	it('treats a re-point failure as residual without halting the run', async () => {
		mockGets({
			contacts: [
				{
					id: 'rel-1',
					granteeUserId: 'bob',
					state: 'granted',
					grantorSuiteId: 'old-suite',
				},
				{
					id: 'rel-2',
					granteeUserId: 'dave',
					state: 'granted',
					grantorSuiteId: 'old-suite',
				},
			],
			certs: {
				bob: { suiteId: 'bob-suite', certificate: 'BOB-CERT' },
				dave: { suiteId: 'dave-suite', certificate: 'DAVE-CERT' },
			},
		})
		// bob's re-point fails; dave's succeeds — only bob is residual.
		vi.spyOn(axios, 'post').mockImplementation(async (url) => {
			if (url.includes('/emergency-contacts/rel-1')) {
				throw new Error('transient')
			}
			return { data: {} }
		})

		const store = useEncryptionSuiteStore()
		const residual = await migrate(store, ['rel-1', 'rel-2'])

		expect(residual).toEqual(['bob'])
	})

	it('returns no residual when the contacts cannot be enumerated', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue(new Error('index unavailable'))
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })

		const store = useEncryptionSuiteStore()
		const residual = await migrate(store, ['rel-1'])

		expect(residual).toEqual([])
		expect(post).not.toHaveBeenCalled()
	})

	it('skips a contact stranded on a prior suite — not a residual of this rotation', async () => {
		mockGets({
			contacts: [
				{
					id: 'rel-old',
					granteeUserId: 'erin',
					state: 'granted',
					grantorSuiteId: 'a-prior-suite',
				},
			],
			certs: { erin: { suiteId: 'erin-suite', certificate: 'ERIN-CERT' } },
		})
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })

		const store = useEncryptionSuiteStore()
		const residual = await migrate(store, ['rel-old'])

		// Not on this rotation's old suite: skipped entirely, never posted, and not
		// reported as lost in this rotation.
		expect(residual).toEqual([])
		expect(buildRecoveryEnvelope).not.toHaveBeenCalled()
		expect(post).not.toHaveBeenCalled()
	})
})
