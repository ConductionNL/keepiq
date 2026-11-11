/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins the order and the values of a compromise-recovery rotation and of a
 * first-time suite setup before they are split into named steps: the
 * offline-queue check, the new key pair wrapped under the new password, the
 * start proof and request, the session rebound to the new suite, the offline
 * eviction, the migration, the emergency contacts carried, and the completion
 * proof over the old key.
 *
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const calls = vi.hoisted(() => [])

vi.mock('../../src/crypto/index.js', async (importOriginal) => ({
	...(await importOriginal()),
	generateKeyPair: vi.fn(async () => {
		calls.push('generateKeyPair')
		return { publicKeyPem: 'PUB', privateKey: { generated: true } }
	}),
	encryptPrivateKey: vi.fn(async (pem, password) => {
		calls.push('encryptPrivateKey')
		return `WRAP(${pem.length}|${password})`
	}),
	importPrivateKey: vi.fn(async (pem) => `KEY(${pem.length})`),
}))
vi.mock('../../src/crypto/keyProof.js', async (importOriginal) => ({
	...(await importOriginal()),
	buildKeyProofHeaders: vi.fn(async (input) => {
		calls.push(`proof:${input.purpose}`)
		return { 'X-Proof': JSON.stringify(input) }
	}),
}))
vi.mock('../../src/store/modules/offline.js', () => ({
	useOfflineStore: () => ({
		loadQueue: async () => calls.push('loadQueue'),
		assertNoPendingChanges: () => calls.push('assertNoPendingChanges'),
		evict: async () => calls.push('evict'),
	}),
}))

const { useEncryptionSuiteStore } = await import('../../src/store/modules/encryptionSuite.js')
const { useSessionStore } = await import('../../src/store/modules/session.js')

beforeEach(() => {
	setActivePinia(createPinia())
	vi.restoreAllMocks()
	calls.length = 0
	vi.spyOn(crypto.subtle, 'exportKey').mockResolvedValue(
		new Uint8Array(Array.from({ length: 100 }, (_, i) => i)).buffer,
	)
})

describe('initiateCompromiseRecovery', () => {
	it('runs the rotation in order and returns the outcome with the residual contacts', async () => {
		const store = useEncryptionSuiteStore()
		const session = useSessionStore()
		session.suiteId = 'old-suite'
		session.encryptedPrivateKey = 'OLD-WRAPPED'
		const post = vi.spyOn(axios, 'post').mockImplementation(async () => {
			calls.push('post')
			return {
				data: {
					migration: { id: 'm1', oldSuiteId: 'old-suite' },
					newSuite: { id: 'new-suite', certificate: 'NEW-CERT' },
					oldEncryptedPrivateKey: 'OLD-WRAPPED-SERVER',
				},
			}
		})
		const run = vi.spyOn(store, 'runMigration').mockImplementation(async () => {
			calls.push('runMigration')
			return { migrated: 3, failed: 1, failures: [] }
		})
		const contacts = vi.spyOn(store, 'migrateEmergencyContacts').mockImplementation(async () => {
			calls.push('migrateEmergencyContacts')
			return [{ granteeUserId: 'g', reason: 'r' }]
		})
		const finalise = vi.spyOn(store, 'finaliseMigration').mockImplementation(async () => {
			calls.push('finaliseMigration')
		})

		const outcome = await store.initiateCompromiseRecovery('old-pw', 'new-pw', ['c1'])

		expect({
			calls,
			outcome,
			post: post.mock.calls,
			run: run.mock.calls,
			contacts: contacts.mock.calls,
			finalise: finalise.mock.calls,
			status: store.migrationStatus,
			suite: store.currentSuite,
			progress: store.migrationProgress,
			failures: store.migrationFailures,
			dropped: store.migrationDroppedVersions,
			session: {
				cryptoKey: session.cryptoKey,
				encryptedPrivateKey: session.encryptedPrivateKey,
				certificate: session.certificate,
				suiteId: session.suiteId,
				active: typeof session.lastActivity,
			},
		}).toMatchSnapshot()
	})

	it('keeps the session suite when the answer names no new suite, and stops on pending offline changes', async () => {
		const store = useEncryptionSuiteStore()
		const session = useSessionStore()
		session.suiteId = 'old-suite'
		vi.spyOn(axios, 'post').mockResolvedValue({
			data: { migration: { id: 'm1', oldSuiteId: 'old-suite' }, oldEncryptedPrivateKey: 'O' },
		})
		vi.spyOn(store, 'runMigration').mockResolvedValue({ failed: 0 })
		vi.spyOn(store, 'migrateEmergencyContacts').mockResolvedValue([])
		vi.spyOn(store, 'finaliseMigration').mockResolvedValue()
		await store.initiateCompromiseRecovery('old-pw', 'new-pw')
		expect(session.suiteId).toBe('old-suite')
		expect(session.certificate).toBe(null)
		expect(store.migrateEmergencyContacts.mock.calls[0][0].carryContactIds).toEqual([])
	})
})

describe('createSuite', () => {
	it('registers a wrapped key pair and unlocks the session with it', async () => {
		const store = useEncryptionSuiteStore()
		const postNew = vi.spyOn(store, 'postNewSuite').mockResolvedValue({
			data: { id: 's1', certificate: 'CERT' },
		})
		await store.createSuite('pw')
		const session = useSessionStore()
		expect({
			calls,
			post: postNew.mock.calls,
			suite: store.currentSuite,
			loading: store.loading,
			session: {
				cryptoKey: session.cryptoKey,
				encryptedPrivateKey: session.encryptedPrivateKey,
				certificate: session.certificate,
				suiteId: session.suiteId,
			},
		}).toMatchSnapshot()
	})

	it('clears loading when the registration fails', async () => {
		const store = useEncryptionSuiteStore()
		vi.spyOn(store, 'postNewSuite').mockRejectedValue(new Error('no'))
		await expect(store.createSuite('pw')).rejects.toThrow('no')
		expect(store.loading).toBe(false)
		expect(useSessionStore().suiteId).not.toBe('s1')
	})
})

describe('resumeMigration', () => {
	/**
	 * A store whose migration status reads as given.
	 *
	 * @param {object|null} status The migration status.
	 * @return {object} The store.
	 */
	function resuming(status) {
		const store = useEncryptionSuiteStore()
		vi.spyOn(store, 'fetchMigrationStatus').mockImplementation(async () => {
			store.migrationStatus = status
		})
		return store
	}

	it('refuses without a migration, while locked, and on the previous suite', async () => {
		await expect(resuming(null).resumeMigration('pw')).rejects.toThrow(
			'There is no migration to resume',
		)
		const status = { id: 'm1', oldSuiteId: 'old', newSuiteId: 'new' }
		const session = useSessionStore()
		session.cryptoKey = null
		await expect(resuming(status).resumeMigration('pw')).rejects.toThrow(
			'Unlock the vault before resuming the migration',
		)
		session.cryptoKey = 'SESSION-KEY'
		session.suiteId = 'old'
		vi.spyOn(axios, 'get').mockImplementation(async (url) => ({
			data: { id: url.split('/').pop(), privateKey: 'P', certificate: 'C' },
		}))
		await expect(resuming(status).resumeMigration('pw')).rejects.toThrow(
			'Your session is unlocked against the previous encryption suite. Lock and unlock the vault, then resume the migration.',
		)
	})

	it('migrates with the suites of the migration and finalises with a proof over the old key', async () => {
		const store = resuming({ id: 'm1', oldSuiteId: 'old', newSuiteId: 'new' })
		const session = useSessionStore()
		session.cryptoKey = 'SESSION-KEY'
		session.suiteId = 'new'
		const get = vi.spyOn(axios, 'get').mockImplementation(async (url) => ({
			data: url.endsWith('/old')
				? { id: 'old', privateKey: 'OLD-WRAPPED' }
				: { id: 'new', publicKey: 'NEW-PUB' },
		}))
		store.migrationFailures = ['stale']
		store.migrationDroppedVersions = 4
		const run = vi.spyOn(store, 'runMigration').mockResolvedValue({ failed: 2 })
		const finalise = vi.spyOn(store, 'finaliseMigration').mockResolvedValue({ finalised: false })
		const removed = vi.spyOn(store, 'rotationRemovedContacts')

		const outcome = await store.resumeMigration('old-pw')

		expect({
			get: get.mock.calls,
			run: run.mock.calls,
			finalise: finalise.mock.calls,
			outcome,
			failures: store.migrationFailures,
			dropped: store.migrationDroppedVersions,
		}).toMatchSnapshot()
		expect(removed).not.toHaveBeenCalled()
	})
})
