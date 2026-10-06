/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for administrator force-revocation and reinstate in
 * `useEncryptionSuiteStore` (`src/store/modules/encryptionSuite.js`).
 *
 * WHY THIS FILE EXISTS.
 *
 * The admin force-revoke endpoint carries `#[PasswordConfirmationRequired]`, so
 * the browser MUST complete Nextcloud sudo (password-confirmation) BEFORE the
 * request is sent — the middleware rejects a request whose sudo has not been
 * re-confirmed. That ordering is the security invariant of the client half: a
 * request fired before, or instead of, the confirmation is either a 401 the
 * administrator never asked for, or (worse) a destructive revoke issued without
 * the re-authentication the endpoint demands. An API-shape assertion cannot see
 * it — the POST looks identical either way — so it is asserted here at the store
 * boundary, where the sudo call and the axios POST are both observable.
 *
 * These tests also pin the response surfacing the design (D3/D4) requires: the
 * destroyed-usable emergency-contact count is always returned, and when
 * `markCompromised` was left off the server's rotation-may-be-warranted warning
 * is surfaced too. Contact identities never cross the wire (count only).
 *
 * @spec openspec/specs/encryption-suites/spec.md#requirement-administrator-force-revocation
 */

import axios from '@nextcloud/axios'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useEncryptionSuiteStore } from '../../src/store/modules/encryptionSuite.js'

// The force-revoke path completes Nextcloud sudo before the request; mock the
// helper so these tests assert the ordering (sudo before POST) without a real
// password-confirmation dialog.
vi.mock('@nextcloud/password-confirmation', () => ({
	confirmPassword: vi.fn(async () => {}),
}))

// The store statically imports the vault-key-proof helper (used by the OWNER
// path, not the admin path); stub it so loading the module needs no real crypto.
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
	buildKeyProofHeaders: vi.fn(async () => ({})),
}))

describe('useEncryptionSuiteStore — administrator force-revocation', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		confirmPassword.mockClear()
		confirmPassword.mockResolvedValue()
	})

	it('completes sudo BEFORE posting the force-revoke, carrying reason + markCompromised', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({
			data: {
				id: 'suite-1',
				ownerType: 'user',
				ownerId: 'alice',
				status: 'revoked',
				emergencyContactsDestroyed: 0,
			},
		})
		const store = useEncryptionSuiteStore()

		await store.forceRevokeSuite({
			id: 'suite-1',
			reason: 'offboarding',
			markCompromised: true,
			confirmSuiteId: 'suite-1',
		})

		// THE SECURITY INVARIANT. The endpoint's #[PasswordConfirmationRequired]
		// middleware rejects a request whose sudo has not been re-confirmed, so the
		// confirmation MUST resolve before the POST is issued.
		expect(confirmPassword).toHaveBeenCalledTimes(1)
		expect(post).toHaveBeenCalledTimes(1)
		expect(confirmPassword.mock.invocationCallOrder[0]).toBeLessThan(
			post.mock.invocationCallOrder[0],
		)

		const [url, body] = post.mock.calls[0]
		// The suite id must be in the path — force-revoking the wrong suite locks
		// a user out of the wrong vault.
		expect(url).toContain('/apps/keepiq/api/v1/suites/suite-1/force-revoke')
		// The administrator's collected inputs: a required reason and the explicit,
		// transient compromise decision. No vault-key proof (the admin holds none).
		// The typed suite id is echoed for the backend-independent confirmation
		// (keepiq#871).
		expect(body).toEqual({
			reason: 'offboarding',
			markCompromised: true,
			confirmSuiteId: 'suite-1',
		})
	})

	it('does NOT post when the administrator cancels the sudo prompt', async () => {
		confirmPassword.mockRejectedValueOnce(new Error('Dialog closed'))
		const post = vi.spyOn(axios, 'post')
		const store = useEncryptionSuiteStore()

		// A cancelled confirmation must abort the revoke entirely — never fall
		// through to a request the endpoint would reject.
		await expect(
			store.forceRevokeSuite({ id: 'suite-1', reason: 'offboarding' }),
		).rejects.toThrow(/Dialog closed/)
		expect(post).not.toHaveBeenCalled()
	})

	it('surfaces the destroyed-usable emergency-contact count', async () => {
		vi.spyOn(axios, 'post').mockResolvedValue({
			data: {
				id: 'suite-1',
				ownerType: 'user',
				ownerId: 'alice',
				status: 'revoked',
				emergencyContactsDestroyed: 2,
			},
		})
		const store = useEncryptionSuiteStore()

		const outcome = await store.forceRevokeSuite({
			id: 'suite-1',
			reason: 'compromised laptop',
			markCompromised: true,
		})

		// The count is informational (never a gate) but must reach the UI so the
		// administrator sees that break-glass access was destroyed.
		expect(outcome.emergencyContactsDestroyed).toBe(2)
		expect(outcome.suite.status).toBe('revoked')
	})

	it('surfaces the rotation warning when markCompromised was left off', async () => {
		vi.spyOn(axios, 'post').mockResolvedValue({
			data: {
				id: 'suite-1',
				ownerType: 'user',
				ownerId: 'alice',
				status: 'revoked',
				emergencyContactsDestroyed: 0,
				warning:
					'The revoked user may still know these secrets; consider rotating them.',
			},
		})
		const store = useEncryptionSuiteStore()

		const outcome = await store.forceRevokeSuite({
			id: 'suite-1',
			reason: 'amicable departure',
			markCompromised: false,
		})

		// D2/D4: with no compromise cascade the server returns the warning that the
		// revoked user may still know the secrets, which the UI must render.
		expect(outcome.warning).toMatch(/may still know these secrets/)
	})

	it('defaults markCompromised to false and reports no warning/count when absent', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({
			data: { id: 'suite-1', status: 'revoked' },
		})
		const store = useEncryptionSuiteStore()

		const outcome = await store.forceRevokeSuite({
			id: 'suite-1',
			reason: 'lost password',
		})

		expect(post.mock.calls[0][1]).toEqual({
			reason: 'lost password',
			markCompromised: false,
			confirmSuiteId: '',
		})
		// A response without the extra keys must not throw — count falls back to 0
		// and warning to null.
		expect(outcome.emergencyContactsDestroyed).toBe(0)
		expect(outcome.warning).toBeNull()
	})

	it('refuses to force-revoke without an id, before any sudo or request', async () => {
		const post = vi.spyOn(axios, 'post')
		const store = useEncryptionSuiteStore()

		await expect(
			store.forceRevokeSuite({ id: '', reason: 'offboarding' }),
		).rejects.toThrow(/No suite id/)
		expect(confirmPassword).not.toHaveBeenCalled()
		expect(post).not.toHaveBeenCalled()
	})

	it('refuses to force-revoke without a reason, before any sudo or request', async () => {
		const post = vi.spyOn(axios, 'post')
		const store = useEncryptionSuiteStore()

		// The reason is REQUIRED (GDPR: the specific "why" must be recordable). The
		// guard fires before the sudo prompt so the administrator is not asked to
		// re-authenticate for a request the server would reject anyway.
		await expect(
			store.forceRevokeSuite({ id: 'suite-1', reason: '' }),
		).rejects.toThrow(/reason is required/)
		expect(confirmPassword).not.toHaveBeenCalled()
		expect(post).not.toHaveBeenCalled()
	})

	it('returns the second suite, the ended migration and an incomplete cascade', async () => {
		vi.spyOn(axios, 'post').mockResolvedValue({
			data: {
				id: 'suite-1',
				status: 'revoked',
				emergencyContactsDestroyed: 1,
				alsoRevokedSuite: 'suite-2',
				terminatedMigration: 'migration-1',
				alsoRevokedEmergencyContactsDestroyed: 2,
				cascade: { stamped: 3, notified: 1, failed: 1 },
				cascadeIncomplete: true,
			},
		})
		const store = useEncryptionSuiteStore()

		const outcome = await store.forceRevokeSuite({
			id: 'suite-1',
			reason: 'account taken over',
			markCompromised: true,
		})

		// keepiq#877: the administrator must see the second revoked suite.
		expect(outcome.alsoRevokedSuite).toBe('suite-2')
		expect(outcome.terminatedMigration).toBe('migration-1')
		expect(outcome.alsoRevokedEmergencyContactsDestroyed).toBe(2)
		// keepiq#863: a partial cascade must reach the UI.
		expect(outcome.cascadeIncomplete).toBe(true)
		expect(outcome.cascadeFailed).toBe(1)
	})

	it('propagates a server refusal for the caller to surface', async () => {
		vi.spyOn(axios, 'post').mockRejectedValue({
			response: {
				status: 400,
				data: { message: 'A non-empty reason is required' },
			},
		})
		const store = useEncryptionSuiteStore()

		await expect(
			store.forceRevokeSuite({ id: 'suite-1', reason: 'x' }),
		).rejects.toMatchObject({
			response: { data: { message: 'A non-empty reason is required' } },
		})
	})
})

describe('useEncryptionSuiteStore — administrator reinstate', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		confirmPassword.mockClear()
	})

	it('completes sudo BEFORE posting the reinstate, and returns the reinstated suite', async () => {
		confirmPassword.mockResolvedValue()
		const post = vi.spyOn(axios, 'post').mockResolvedValue({
			data: { id: 'suite-1', status: 'active' },
		})
		const store = useEncryptionSuiteStore()

		const suite = await store.reinstateSuiteAdmin('suite-1')

		expect(post).toHaveBeenCalledTimes(1)
		expect(post.mock.calls[0][0]).toContain(
			'/apps/keepiq/api/v1/suites/suite-1/reinstate',
		)
		// Reinstating re-opens every secret under the key, so it carries sudo
		// like force-revoke does (keepiq#865).
		expect(confirmPassword).toHaveBeenCalledTimes(1)
		expect(confirmPassword.mock.invocationCallOrder[0]).toBeLessThan(
			post.mock.invocationCallOrder[0],
		)
		expect(suite.status).toBe('active')
	})

	it('does NOT post the reinstate when the sudo prompt is cancelled', async () => {
		confirmPassword.mockRejectedValueOnce(new Error('Dialog closed'))
		const post = vi.spyOn(axios, 'post')
		const store = useEncryptionSuiteStore()

		await expect(store.reinstateSuiteAdmin('suite-1')).rejects.toThrow(
			/Dialog closed/,
		)
		expect(post).not.toHaveBeenCalled()
	})

	it('refuses to reinstate without an id, before any request', async () => {
		const post = vi.spyOn(axios, 'post')
		const store = useEncryptionSuiteStore()

		await expect(store.reinstateSuiteAdmin('')).rejects.toThrow(/No suite id/)
		expect(post).not.toHaveBeenCalled()
	})
})

describe('useEncryptionSuiteStore — re-enrolment after a revocation', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		confirmPassword.mockClear()
		confirmPassword.mockResolvedValue()
	})

	it('posts a plain create when the server accepts it, with no sudo', async () => {
		const post = vi
			.spyOn(axios, 'post')
			.mockResolvedValue({ data: { id: 'suite-1' } })
		const store = useEncryptionSuiteStore()

		await store.postNewSuite({ publicKey: 'pk', encryptedPrivateKey: 'env' })

		expect(post).toHaveBeenCalledTimes(1)
		expect(post.mock.calls[0][0]).toMatch(/\/api\/v1\/suites$/)
		expect(confirmPassword).not.toHaveBeenCalled()
	})

	it('confirms the password and re-enrols when the server asks for it (keepiq#860)', async () => {
		const post = vi
			.spyOn(axios, 'post')
			.mockRejectedValueOnce({
				response: {
					status: 428,
					data: { error: 'reauthentication_required' },
				},
			})
			.mockResolvedValueOnce({ data: { id: 'suite-2' } })
		const store = useEncryptionSuiteStore()

		const response = await store.postNewSuite({
			publicKey: 'pk',
			encryptedPrivateKey: 'env',
		})

		expect(response.data.id).toBe('suite-2')
		expect(post).toHaveBeenCalledTimes(2)
		expect(post.mock.calls[1][0]).toContain('/apps/keepiq/api/v1/suites/reenrol')
		expect(post.mock.calls[1][1]).toEqual({
			publicKey: 'pk',
			encryptedPrivateKey: 'env',
		})
		expect(confirmPassword.mock.invocationCallOrder[0]).toBeLessThan(
			post.mock.invocationCallOrder[1],
		)
	})

	it('does not re-enrol when the password prompt is cancelled', async () => {
		confirmPassword.mockRejectedValueOnce(new Error('Dialog closed'))
		const post = vi.spyOn(axios, 'post').mockRejectedValueOnce({
			response: { status: 428, data: { error: 'reauthentication_required' } },
		})
		const store = useEncryptionSuiteStore()

		await expect(
			store.postNewSuite({ publicKey: 'pk', encryptedPrivateKey: 'env' }),
		).rejects.toThrow(/Dialog closed/)
		expect(post).toHaveBeenCalledTimes(1)
	})

	it('passes any other refusal through untouched', async () => {
		vi.spyOn(axios, 'post').mockRejectedValueOnce({
			response: { status: 409, data: { error: 'suite_already_exists' } },
		})
		const store = useEncryptionSuiteStore()

		await expect(
			store.postNewSuite({ publicKey: 'pk', encryptedPrivateKey: 'env' }),
		).rejects.toMatchObject({ response: { status: 409 } })
		expect(confirmPassword).not.toHaveBeenCalled()
	})

	it('resolves a failed migration end with the result and the unfinished message (keepiq#1189)', async () => {
		vi.spyOn(axios, 'post').mockRejectedValue({
			response: {
				status: 500,
				data: {
					id: 'suite-1',
					status: 'revoked',
					emergencyContactsDestroyed: 2,
					cascade: { stamped: 3, notified: 1, failed: 1 },
					cascadeIncomplete: true,
					error: 'migration_end_failed',
					message: 'Force-revoke it again to finish.',
				},
			},
		})

		const outcome = await useEncryptionSuiteStore().forceRevokeSuite({
			id: 'suite-1',
			reason: 'taken over',
			markCompromised: true,
			confirmSuiteId: 'suite-1',
		})

		expect(outcome.unfinished).toBe('Force-revoke it again to finish.')
		expect(outcome.suite.id).toBe('suite-1')
		expect(outcome.emergencyContactsDestroyed).toBe(2)
		expect(outcome.cascadeIncomplete).toBe(true)
		expect(outcome.cascadeFailed).toBe(1)
	})

	it('rejects a migration_end_failed body that is not a 500', async () => {
		const failure = {
			response: {
				status: 403,
				data: { error: 'migration_end_failed', message: 'x' },
			},
		}
		vi.spyOn(axios, 'post').mockRejectedValue(failure)

		await expect(
			useEncryptionSuiteStore().forceRevokeSuite({
				id: 'suite-1',
				reason: 'taken over',
				markCompromised: true,
				confirmSuiteId: 'suite-1',
			}),
		).rejects.toBe(failure)
	})

	it('still rejects any other server failure', async () => {
		const failure = { response: { status: 403, data: { message: 'refused' } } }
		vi.spyOn(axios, 'post').mockRejectedValue(failure)

		await expect(
			useEncryptionSuiteStore().forceRevokeSuite({
				id: 'suite-1',
				reason: 'taken over',
				markCompromised: true,
				confirmSuiteId: 'suite-1',
			}),
		).rejects.toBe(failure)
	})
})
