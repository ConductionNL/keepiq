/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for suite revocation in `useEncryptionSuiteStore`
 * (`src/store/modules/encryptionSuite.js`).
 *
 * WHY THIS FILE EXISTS.
 *
 * openspec/specs/encryption-suites/spec.md "Scenario: Revoke suite" was waived
 * with "No suite-revocation UI is built in v0.1; revocation is an API-only
 * action verified by PHPUnit and the Postman collection." Two of those three
 * claims were false when checked against the tree:
 *
 *   - The UI IS built and shipping. src/App.vue renders a "Revoke encryption
 *     suite" button, a confirmation NcNoteCard, and a reason field whose value
 *     gates the submit button (`:disabled="!revokeReason || revoking"`).
 *   - The Postman collection does NOT verify it. The only occurrence of
 *     "revoke" in tests/integration/keepiq.postman_collection.json is inside
 *     an error-message string; there is no revoke request at all.
 *
 * Only the PHPUnit half held (EncryptionSuiteServiceTest::testRevokeSuiteSuccess
 * and EncryptionSuiteControllerTest::testRevokeReturnsSuite).
 *
 * So the client half of a destructive, shipping flow had no test anywhere.
 * These tests cover it at the store boundary, which is where the security
 * invariant lives: revoking must not leave a readable offline copy behind.
 * That eviction is the kind of defect no API-shape assertion can see — the
 * HTTP call succeeds identically whether or not the local cache is cleared,
 * and the leftover plaintext would sit in the browser indefinitely.
 *
 * @spec openspec/specs/encryption-suites/spec.md#requirement-revocation
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { buildKeyProofHeaders } from '../../src/crypto/keyProof.js'
import { useEncryptionSuiteStore } from '../../src/store/modules/encryptionSuite.js'

const evict = vi.fn(async () => {})

vi.mock('../../src/store/modules/offline.js', () => ({
	useOfflineStore: () => ({ evict }),
}))

// The guarded flows sign a vault-key proof; stub the helper so these tests
// assert the store's wiring (headers attached, reason bound) without real crypto.
vi.mock('../../src/crypto/keyProof.js', () => ({
	HEADER_NONCE: 'X-Keepiq-Key-Proof-Nonce',
	HEADER_PROOF: 'X-Keepiq-Key-Proof',
	PROOF_PURPOSE: {
		COMPROMISE_RECOVERY: 'compromise-recovery',
		UPDATE_PRIVATE_KEY: 'update-private-key',
		COMPLETE_MIGRATION: 'complete-migration',
		EMERGENCY_DESTROY: 'emergency-access-destroy',
		REVOKE_SUITE: 'revoke-suite',
	},
	buildKeyProofHeaders: vi.fn(async () => ({
		'X-Keepiq-Key-Proof-Nonce': 'test-nonce',
		'X-Keepiq-Key-Proof': 'test-sig',
	})),
}))

describe('useEncryptionSuiteStore — revocation', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		evict.mockClear()
		buildKeyProofHeaders.mockClear()
		buildKeyProofHeaders.mockResolvedValue({
			'X-Keepiq-Key-Proof-Nonce': 'test-nonce',
			'X-Keepiq-Key-Proof': 'test-sig',
		})
	})

	it('signs a vault-key proof and attaches it to the revocation request', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({
			data: {
				id: 'suite-1',
				status: 'revoked',
				revoked_reason: 'laptop stolen',
			},
		})
		const store = useEncryptionSuiteStore()
		store.currentSuite = { id: 'suite-1', status: 'active' }

		await store.revokeSuite('laptop stolen', 'master-pw')

		// The proof is made for THIS suite, with the revoke purpose, binding the
		// reason so a captured proof cannot be replayed against another request.
		expect(buildKeyProofHeaders).toHaveBeenCalledWith(
			expect.objectContaining({
				suiteId: 'suite-1',
				purpose: 'revoke-suite',
				masterPassword: 'master-pw',
				boundValues: ['laptop stolen'],
			}),
		)

		expect(post).toHaveBeenCalledTimes(1)
		const [url, body, config] = post.mock.calls[0]

		// The suite id must be in the path — revoking the wrong suite, or a
		// path built from a stale id, locks a user out of the wrong vault.
		expect(url).toContain('/apps/keepiq/api/v1/suites/suite-1/revoke')

		// The reason is REQUIRED by the spec: status is set alongside
		// revoked_at, revoked_reason and revoked_by. Dropping it here would
		// still return 200 and still revoke, losing only the audit trail.
		expect(body).toEqual({ reason: 'laptop stolen' })

		// The guard is enforced by the middleware, so the proof headers MUST ride
		// the request — a revoke without them is a 401 the user never asked for.
		expect(config.headers).toMatchObject({
			'X-Keepiq-Key-Proof-Nonce': 'test-nonce',
			'X-Keepiq-Key-Proof': 'test-sig',
		})
	})

	it('adopts the revoked suite returned by the server as the current suite', async () => {
		vi.spyOn(axios, 'post').mockResolvedValue({
			data: { id: 'suite-1', status: 'revoked', revoked_reason: 'rotation' },
		})
		const store = useEncryptionSuiteStore()
		store.currentSuite = { id: 'suite-1', status: 'active' }

		await store.revokeSuite('rotation', 'master-pw')

		// Keeping the pre-revocation object would leave the UI showing an
		// active suite that the server has already revoked.
		expect(store.currentSuite.status).toBe('revoked')
	})

	it('evicts the offline cache so a revoked suite leaves no readable copy', async () => {
		vi.spyOn(axios, 'post').mockResolvedValue({
			data: { id: 'suite-1', status: 'revoked' },
		})
		const store = useEncryptionSuiteStore()
		store.currentSuite = { id: 'suite-1', status: 'active' }

		await store.revokeSuite('compromised device', 'master-pw')

		// THE SECURITY INVARIANT. Revocation blocks server-side access, but an
		// offline copy is already decrypted on this device: without the evict
		// the secrets stay readable locally after the user has been told they
		// are inaccessible.
		expect(evict).toHaveBeenCalledTimes(1)
	})

	it('still completes when no offline cache is present', async () => {
		evict.mockRejectedValueOnce(new Error('no cache'))
		vi.spyOn(axios, 'post').mockResolvedValue({
			data: { id: 'suite-1', status: 'revoked' },
		})
		const store = useEncryptionSuiteStore()
		store.currentSuite = { id: 'suite-1', status: 'active' }

		// A missing cache must not turn a completed server-side revocation into
		// a client-side error — the suite IS revoked by this point.
		await expect(
			store.revokeSuite('reason', 'master-pw'),
		).resolves.toBeUndefined()
		expect(store.currentSuite.status).toBe('revoked')
	})

	it('refuses to revoke when there is no active suite', async () => {
		const post = vi.spyOn(axios, 'post')
		const store = useEncryptionSuiteStore()
		store.currentSuite = null

		await expect(store.revokeSuite('reason')).rejects.toThrow(/No active suite/)

		// The guard must fire BEFORE any request: a POST built from a null
		// suite would target .../suites/undefined/revoke.
		expect(post).not.toHaveBeenCalled()
	})
})

// The share-candidate lookup used to live here, reading user ids off
// `GET /suites`. It has moved to `useShareStore` and changed shape with it:
// the server deliberately has no endpoint listing who holds a suite, so
// candidates are named by Nextcloud's sharee search and PROBED
// (tests/store/share.recipients.spec.js).

describe('useEncryptionSuiteStore — abort migration', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('POSTs the abort to the in-progress migration and clears state on success', async () => {
		// status GET first resolves in-progress, then 'none' after the abort.
		const statuses = [
			{ data: { status: 'in_progress', id: 'migr-1', oldSuiteId: 'old' } },
			{ data: { status: 'none' } },
		]
		vi.spyOn(axios, 'get').mockImplementation(async (url) => {
			if (url.endsWith('/migrations/status')) {
				return statuses.shift() ?? { data: { status: 'none' } }
			}
			// fetchMigrationRemaining hits /work
			return { data: { totalRemaining: 0 } }
		})
		const post = vi.spyOn(axios, 'post').mockResolvedValue({
			data: { id: 'migr-1', status: 'aborted', aborted: true },
		})

		const store = useEncryptionSuiteStore()
		const result = await store.abortMigration()

		expect(post).toHaveBeenCalledWith(
			expect.stringContaining('/migrations/migr-1/abort'),
		)
		expect(result.aborted).toBe(true)
		// State re-read afterwards and the banner cleared.
		expect(store.migrationStatus).toBeNull()
	})

	it('surfaces a server refusal (records already moved) as a throw and keeps the banner', async () => {
		vi.spyOn(axios, 'get').mockImplementation(async (url) => {
			if (url.endsWith('/migrations/status')) {
				return {
					data: { status: 'in_progress', id: 'migr-1', oldSuiteId: 'old' },
				}
			}
			return { data: { totalRemaining: 4 } }
		})
		vi.spyOn(axios, 'post').mockRejectedValue({
			response: {
				status: 409,
				data: { error: 'migration_abort_refused', committed: 2 },
			},
		})

		const store = useEncryptionSuiteStore()
		await expect(store.abortMigration()).rejects.toMatchObject({
			response: { data: { committed: 2 } },
		})
		// The migration is still there — abort did not clear it.
		expect(store.migrationStatus).not.toBeNull()
	})

	it('refuses to abort when there is no migration', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { status: 'none' } })
		const post = vi.spyOn(axios, 'post')

		const store = useEncryptionSuiteStore()
		await expect(store.abortMigration()).rejects.toThrow(/no migration to abort/)
		expect(post).not.toHaveBeenCalled()
	})
})
