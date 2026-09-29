/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for how a RESUMED key rotation reports the emergency contacts it
 * removed (#804 review, round 4).
 *
 * The initiate path builds its residual list in the browser while it carries
 * the ticked contacts. A resumed run never reaches that step: it goes from the
 * record loop straight to completion, and the completion sweep invalidates
 * every contact still on the old suite. So after completing, the resume path
 * asks the server which of the owner's contacts that rotation invalidated, and
 * names them neutrally (in-flight ones as a warning), never as a prompt to
 * re-establish.
 *
 * @spec openspec/changes/migrate-emergency-access-on-rotation/specs/emergency-access/spec.md#requirement-envelope-invalidation-on-key-change
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useEncryptionSuiteStore } from '../../src/store/modules/encryptionSuite.js'
import { useSessionStore } from '../../src/store/modules/session.js'

vi.mock('../../src/store/modules/offline.js', () => ({
	useOfflineStore: () => ({ evict: async () => {} }),
}))

vi.mock('../../src/crypto/keyProof.js', async (importOriginal) => ({
	...(await importOriginal()),
	buildKeyProofHeaders: vi.fn(async () => ({ 'X-Keepiq-Key-Proof': 'SIG' })),
}))

const CONTACTS = [
	// Removed by this rotation: named.
	{
		id: 'rel-1',
		granteeUserId: 'bob',
		state: 'invalidated',
		invalidatedReason: 'grantor_rotation',
		grantorSuiteId: 'old-suite',
	},
	// Removed by this rotation with a break-glass in flight: a warning.
	{
		id: 'rel-2',
		granteeUserId: 'mallory',
		state: 'invalidated',
		invalidatedReason: 'grantor_rotation_in_flight',
		grantorSuiteId: 'old-suite',
	},
	// Carried: now on the new suite, not removed.
	{
		id: 'rel-3',
		granteeUserId: 'carol',
		state: 'granted',
		invalidatedReason: null,
		grantorSuiteId: 'new-suite',
	},
	// Invalidated for another reason, before this rotation: not this rotation's.
	{
		id: 'rel-4',
		granteeUserId: 'dave',
		state: 'invalidated',
		invalidatedReason: 'grantee_revocation',
		grantorSuiteId: 'old-suite',
	},
	// Removed by an EARLIER rotation of another suite: not this one's.
	{
		id: 'rel-5',
		granteeUserId: 'erin',
		state: 'invalidated',
		invalidatedReason: 'grantor_rotation',
		grantorSuiteId: 'older-suite',
	},
]

/**
 * Mock axios.get for the contacts list and the two migration suites.
 *
 * @param {Array<object>|'throw'} contacts The contacts, or 'throw' to fail.
 * @return {void}
 */
function mockGets(contacts) {
	vi.spyOn(axios, 'get').mockImplementation(async (url) => {
		if (url.endsWith('/emergency-access/contacts')) {
			if (contacts === 'throw') {
				throw new Error('network down')
			}
			return { data: contacts }
		}
		if (url.endsWith('/suites/old-suite')) {
			return { data: { id: 'old-suite', privateKey: 'OLD-ENV' } }
		}
		if (url.endsWith('/suites/new-suite')) {
			return { data: { id: 'new-suite', certificate: 'NEW-CERT' } }
		}
		return { data: {} }
	})
}

describe('useEncryptionSuiteStore — contacts removed by a resumed rotation', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('names only the contacts this rotation invalidated', async () => {
		mockGets(CONTACTS)
		const store = useEncryptionSuiteStore()

		expect(await store.rotationRemovedContacts('old-suite')).toEqual([
			{ granteeUserId: 'bob', reason: 'removed_by_rotation' },
			{ granteeUserId: 'mallory', reason: 'break_glass_in_flight' },
		])
	})

	it('names nothing when the contacts cannot be listed', async () => {
		mockGets('throw')
		const store = useEncryptionSuiteStore()

		expect(await store.rotationRemovedContacts('old-suite')).toEqual([])
	})

	it('reports the removed contacts from resumeMigration, after completion', async () => {
		mockGets(CONTACTS)
		const store = useEncryptionSuiteStore()
		const session = useSessionStore()
		session.cryptoKey = {}
		session.suiteId = 'new-suite'

		const calls = []
		vi.spyOn(store, 'fetchMigrationStatus').mockImplementation(async () => {
			store.migrationStatus = {
				id: 'migr-1',
				oldSuiteId: 'old-suite',
				newSuiteId: 'new-suite',
			}
		})
		vi.spyOn(store, 'runMigration').mockResolvedValue({
			migrated: 3,
			failed: 0,
			droppedVersions: 0,
			failures: [],
			usedWorker: false,
		})
		vi.spyOn(store, 'finaliseMigration').mockImplementation(async () => {
			calls.push('finalise')
			return { finalised: true, needsAcknowledgement: false, message: null }
		})
		const removed = vi
			.spyOn(store, 'rotationRemovedContacts')
			.mockImplementation(async (oldSuiteId) => {
				calls.push('removed:' + oldSuiteId)
				return [{ granteeUserId: 'bob', reason: 'removed_by_rotation' }]
			})

		const outcome = await store.resumeMigration('old-pw')

		// Only after completion has the server's sweep invalidated them.
		expect(calls).toEqual(['finalise', 'removed:old-suite'])
		expect(removed).toHaveBeenCalledTimes(1)
		expect(outcome.residualContacts).toEqual([
			{ granteeUserId: 'bob', reason: 'removed_by_rotation' },
		])
	})

	// #804 review, round 5: while the server still wants a loss acknowledged,
	// completion has not happened and the sweep has not run.
	it('reads back nothing from resumeMigration while a loss needs acknowledging', async () => {
		mockGets(CONTACTS)
		const store = useEncryptionSuiteStore()
		const session = useSessionStore()
		session.cryptoKey = {}
		session.suiteId = 'new-suite'

		vi.spyOn(store, 'fetchMigrationStatus').mockImplementation(async () => {
			store.migrationStatus = {
				id: 'migr-1',
				oldSuiteId: 'old-suite',
				newSuiteId: 'new-suite',
			}
		})
		vi.spyOn(store, 'runMigration').mockResolvedValue({
			migrated: 2,
			failed: 1,
			droppedVersions: 0,
			failures: [{ store: 'secrets', id: 'secret-1' }],
			usedWorker: false,
		})
		vi.spyOn(store, 'finaliseMigration').mockResolvedValue({
			finalised: false,
			needsAcknowledgement: true,
			requiredAcknowledgement: 1,
			message: 'acknowledge',
		})
		const removed = vi.spyOn(store, 'rotationRemovedContacts')

		const outcome = await store.resumeMigration('old-pw')

		expect(removed).not.toHaveBeenCalled()
		expect(outcome.residualContacts).toEqual([])
	})

	it('reports the removed contacts from acceptMigrationLosses, after completion', async () => {
		mockGets(CONTACTS)
		vi.spyOn(axios, 'post').mockResolvedValue({
			data: { droppedVersions: 0, unrecoverable: [] },
		})
		const store = useEncryptionSuiteStore()
		store.migrationStatus = {
			id: 'migr-1',
			oldSuiteId: 'old-suite',
			newSuiteId: 'new-suite',
		}
		store.migrationRequiredAcknowledgement = 1

		const calls = []
		// As the real call does once the migration has ended.
		vi.spyOn(store, 'fetchMigrationStatus').mockImplementation(async () => {
			calls.push('status')
			store.migrationStatus = null
		})
		vi.spyOn(store, 'rotationRemovedContacts').mockImplementation(
			async (oldSuiteId) => {
				calls.push('removed:' + oldSuiteId)
				return [{ granteeUserId: 'bob', reason: 'removed_by_rotation' }]
			},
		)

		const data = await store.acceptMigrationLosses('migr-1', 'old-pw')

		// Only after the completion call, and for the suite the migration left.
		expect(calls).toEqual(['status', 'removed:old-suite'])
		expect(data.residualContacts).toEqual([
			{ granteeUserId: 'bob', reason: 'removed_by_rotation' },
		])
	})
})
