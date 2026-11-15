import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import { buildRecoveryEnvelope } from '../../crypto/emergencyEnvelope.js'
import {
	decryptPrivateKey,
	encryptPrivateKey,
	generateKeyPair,
	importPrivateKey,
} from '../../crypto/index.js'
import { buildKeyProofHeaders, PROOF_PURPOSE } from '../../crypto/keyProof.js'
import { createMigrationRunner } from '../../migration/driver.js'
import { MIGRATION_STORES } from '../../migration/pipeline.js'
import { confirmPasswordFirst } from '../../utils/passwordConfirmation.js'
import {
	assertNoPendingOfflineChanges,
	assertResumable,
	assertSessionOnSuite,
	bindSessionToNewSuite,
	bindSessionToSuite,
	carriedContacts,
	evictOfflineSnapshot,
	migrationSuites,
	postCompromiseRecovery,
	resumeCompletion,
	resumedMigration,
	rotationCompletion,
	rotationMigration,
	showRotationStarting,
	wrappedKeyPair,
} from './keyRotation.js'
import { usePasskeyStore } from './passkey.js'
import { onVaultLock, useSessionStore } from './session.js'
import {
	finishRun,
	migratePages,
	postWindow,
	unnamedJobs,
	unwrapMigrationKeys,
} from './suiteMigrationRun.js'

/**
 * How many re-encryption POSTs are in flight at once. Small on purpose: the
 * point is to hide network latency, not to flood a shared instance. Provisional
 * — untuned against a large seeded vault.
 *
 * @type {number}
 */
const MIGRATION_CONCURRENCY = 4

/**
 * Serialise a request parameter for a vault-key proof's bound values exactly as
 * the server's middleware does — a PHP `(string)` cast of `getParam(name, '')`:
 * `true` → `'1'`, `false`/`null`/`undefined` (an absent param) → `''`, anything
 * else its string form. The proof commits to the request, so a bound value that
 * serialised differently on the two sides would fail every verification.
 *
 * @param {boolean|number|string|null|undefined} value The parameter value being bound.
 * @return {string} The server-matching string form.
 */
function boundParam(value) {
	if (value === true) {
		return '1'
	}
	if (value === false || value === null || value === undefined) {
		return ''
	}
	return String(value)
}

/**
 * The completion proof of a migration run that finalises without an
 * acknowledged loss. Completion is guarded, and its proof is over the OLD
 * key — the suite being retired — not the new one. That is the key both the
 * initiate path and the resume path already hold the password for
 * (oldPassword), so completion needs no extra prompt on either. It binds
 * every parameter finaliseMigration will send to complete — the id,
 * hasErrors and the (here absent) acceptUnrecoverable — so a captured proof
 * cannot be replayed to finalise with an acknowledged loss it never carried.
 *
 * @param {{suiteId: string, encryptedPrivateKey: string, masterPassword: string, migrationId: string}} completion The old suite, its wrapped key, the old password and the migration.
 * @param {{failed: number}} outcome The migration outcome.
 * @return {Promise<object>} The proof headers.
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
 */
async function completionProof(completion, outcome) {
	return buildKeyProofHeaders({
		suiteId: completion.suiteId,
		purpose: PROOF_PURPOSE.COMPLETE_MIGRATION,
		encryptedPrivateKey: completion.encryptedPrivateKey,
		masterPassword: completion.masterPassword,
		boundValues: [
			completion.migrationId,
			boundParam(outcome.failed > 0),
			boundParam(null),
		],
	})
}

/**
 * The owner's emergency contacts, or null when they cannot be listed.
 *
 * @return {Promise<Array<object>|null>}
 */
async function listEmergencyContacts() {
	try {
		const response = await axios.get(
			generateUrl('/apps/keepiq/api/v1/emergency-access/contacts'),
		)
		return Array.isArray(response.data) ? response.data : []
	} catch {
		return null
	}
}

/**
 * Whether a contact was put at stake by this rotation. An invalidated contact
 * has no envelope to carry, and a contact stranded on a prior suite was not
 * lost in this rotation, so neither is reported as a residual.
 *
 * @param {object} contact The emergency contact.
 * @param {string} oldSuiteId The rotating old suite.
 * @return {boolean}
 */
function belongsToRotation(contact, oldSuiteId) {
	return contact.state !== 'invalidated' && contact.grantorSuiteId === oldSuiteId
}

/**
 * Why a contact of this rotation is not carried, or null when it is.
 *
 * A break-glass in flight, or not confirmed by the owner: never escrow the new
 * key to it. Each gets its own reason, because the form must not nudge the
 * owner to re-add either the way it does for an unreachable grantee: that is
 * what a planted contact is after (#804 review).
 *
 * @param {object} contact The emergency contact.
 * @param {string[]} carryContactIds The contact ids the owner confirmed.
 * @return {string|null}
 */
function residualReason(contact, carryContactIds) {
	if (['requested', 'approved'].includes(contact.state)) {
		return 'break_glass_in_flight'
	}
	if (contact.state !== 'granted' || !carryContactIds.includes(contact.id)) {
		return 'not_confirmed'
	}
	return null
}

/**
 * Build a fresh recovery envelope escrowing the new private key to the
 * grantee's current certificate (a build, not a re-wrap).
 *
 * @param {object} contact The confirmed, granted contact.
 * @param {object} rotation The rotation; see reEnvelopeContact.
 * @return {Promise<{contactId: string, recoveryEnvelope: string, granteeSuiteId: string}>}
 */
async function freshRecoveryEnvelope(contact, rotation) {
	const certResponse = await axios.get(
		generateUrl('/apps/keepiq/api/v1/emergency-access/grantee-certificate'),
		{ params: { granteeUserId: contact.granteeUserId } },
	)
	const recoveryEnvelope = await buildRecoveryEnvelope(
		rotation.newPrivateKeyPem,
		certResponse.data.certificate,
	)
	return {
		contactId: contact.id,
		recoveryEnvelope,
		granteeSuiteId: certResponse.data.suiteId,
	}
}

/**
 * Re-point a contact to its fresh envelope, with a vault-key proof made with
 * the new key and password.
 *
 * @param {object} envelope The contactId, recoveryEnvelope and granteeSuiteId.
 * @param {object} rotation The rotation; see reEnvelopeContact.
 * @return {Promise<void>}
 */
async function rePointContact(envelope, rotation) {
	// Bound in the order the server hashes them:
	// id, contactId, recoveryEnvelope, granteeSuiteId.
	const headers = await buildKeyProofHeaders({
		suiteId: rotation.newSuiteId,
		purpose: PROOF_PURPOSE.EMERGENCY_RE_ENVELOPE,
		encryptedPrivateKey: rotation.newEncryptedPrivateKey,
		masterPassword: rotation.newPassword,
		boundValues: [
			rotation.migrationId,
			envelope.contactId,
			envelope.recoveryEnvelope,
			envelope.granteeSuiteId,
		],
	})
	await axios.post(
		generateUrl(
			`/apps/keepiq/api/v1/migrations/${rotation.migrationId}/emergency-contacts/${envelope.contactId}`,
		),
		{
			recoveryEnvelope: envelope.recoveryEnvelope,
			granteeSuiteId: envelope.granteeSuiteId,
		},
		{ headers },
	)
}

/**
 * Carry a contact onto the new suite.
 *
 * A grantee without an active certificate, a refused proof or a failed
 * re-point leaves the contact on the old suite for the completion sweep to
 * invalidate. That is never fatal: contacts are outside the gate.
 *
 * @param {object} contact The confirmed, granted contact.
 * @param {object} rotation The migrationId, newPrivateKeyPem, newSuiteId,
 *  newEncryptedPrivateKey and newPassword.
 * @return {Promise<string|null>} `unreachable` on failure, else null.
 */
async function reEnvelopeContact(contact, rotation) {
	try {
		await rePointContact(
			await freshRecoveryEnvelope(contact, rotation),
			rotation,
		)
		return null
	} catch {
		return 'unreachable'
	}
}

/**
 * Why a contact of this rotation is left behind, or null once it is carried.
 *
 * @param {object} contact The emergency contact.
 * @param {object} run The carryContactIds and the rotation.
 * @return {Promise<string|null>}
 */
async function contactResidual(contact, run) {
	return (
		residualReason(contact, run.carryContactIds)
		?? reEnvelopeContact(contact, run.rotation)
	)
}

/**
 * Refuse a force-revocation without a suite id or a reason, before any sudo
 * prompt or request.
 *
 * @param {string} id The suite id to force-revoke.
 * @param {string} reason The revocation reason.
 * @return {void}
 * @spec openspec/specs/encryption-suites/spec.md#requirement-administrator-force-revocation
 */
function assertForceRevocable(id, reason) {
	if (!id) {
		throw new Error('No suite id to revoke')
	}
	if (!reason) {
		throw new Error('A reason is required to force-revoke a suite')
	}
}

/**
 * Whether a force-revoke failed only at ending the suite's key migration:
 * the suite was revoked all the same (keepiq#1189).
 *
 * @param {object} error The axios error.
 * @return {boolean}
 * @spec openspec/specs/encryption-suites/spec.md#requirement-administrator-force-revocation
 */
function isMigrationEndFailure(error) {
	return (
		error?.response?.status === 500
		&& error.response.data?.error === 'migration_end_failed'
	)
}

/**
 * POST the force-revoke; a failed migration end still answers with the
 * revocation's body, any other failure rejects.
 *
 * @param {string} id The suite id.
 * @param {{reason: string, markCompromised: boolean, confirmSuiteId: string}} body The request body.
 * @return {Promise<object>} The response body.
 * @spec openspec/specs/encryption-suites/spec.md#requirement-administrator-force-revocation
 */
async function postForceRevoke(id, body) {
	try {
		const response = await axios.post(
			generateUrl(`/apps/keepiq/api/v1/suites/${id}/force-revoke`),
			body,
		)
		return response.data
	} catch (error) {
		if (!isMigrationEndFailure(error)) {
			throw error
		}
		return error.response.data
	}
}

/**
 * @param {object} data The force-revoke's response body.
 * @return {object} The revoked suite, the emergency contacts destroyed, the
 *   rotation warning, and the unfinished message when the suite was revoked
 *   but its key migration could not be ended; force-revoking again
 *   finishes it (keepiq#1189).
 * @spec openspec/specs/encryption-suites/spec.md#requirement-administrator-force-revocation
 */
function revokedOutcome(data) {
	return {
		unfinished: data.error === 'migration_end_failed' ? data.message : null,
		suite: data,
		emergencyContactsDestroyed: data.emergencyContactsDestroyed ?? 0,
		warning: data.warning ?? null,
	}
}

/**
 * A compromise force-revoke during a key migration also revokes the
 * migration's other suite and ends the migration (keepiq#877), and reports
 * the steps of the compromise response that failed (keepiq#863).
 *
 * @param {object} data The force-revoke's response body.
 * @return {object}
 * @spec openspec/specs/encryption-suites/spec.md#requirement-administrator-force-revocation
 */
function compromiseCascadeOutcome(data) {
	return {
		alsoRevokedSuite: data.alsoRevokedSuite ?? null,
		terminatedMigration: data.terminatedMigration ?? null,
		alsoRevokedEmergencyContactsDestroyed:
			data.alsoRevokedEmergencyContactsDestroyed ?? 0,
		cascadeIncomplete: data.cascadeIncomplete === true,
		cascadeFailed: data.cascade?.failed ?? 0,
	}
}

export const useEncryptionSuiteStore = defineStore('encryptionSuite', {
	state: () => ({
		/** @type {object|null} Current active suite */
		currentSuite: null,
		/** @type {object|null} Migration status */
		migrationStatus: null,
		/** @type {boolean} */
		loading: false,
		/** @type {{done: number, total: number, phase: string}|null} Live migration progress */
		migrationProgress: null,
		/** @type {Array<{store: string, id: string, name: string|null, error: string}>} Per-record failures */
		migrationFailures: [],
		/** @type {number} Version rows dropped for falling outside the window */
		migrationDroppedVersions: 0,
		/** @type {Array<{id: string, name: string, error: string}>} Secrets that lost access */
		migrationUnrecoverable: [],
		/** @type {number|null} Records still on the old suite, for the resume banner */
		migrationRemaining: null,
		/** @type {boolean} Server wants the loss acknowledged before finalising */
		migrationNeedsAcknowledgement: false,
		/**
		 * The number the server will accept as the acknowledgement.
		 *
		 * NEVER derived from `migrationFailures.length`. That list holds one
		 * entry per failed RECORD accumulated across every pass of the loop,
		 * while the server counts the distinct records currently recorded as
		 * failed — and it compares with a strict `===`. The two agreed only
		 * when every failure was exactly one secret head failing exactly once,
		 * so any run where a secret failed alongside its own version, or the
		 * same record failed twice, made "Finish anyway" permanently
		 * unsatisfiable and left the vault write-locked.
		 *
		 * @type {number|null}
		 */
		migrationRequiredAcknowledgement: null,
		/** @type {string|null} Why the server refused to finalise */
		migrationBlockedMessage: null,
		/** @type {object|null} The active runner, so vault lock can dispose it */
		migrationRunner: null,
	}),

	actions: {
		/**
		 * Fetch the current user's active suite.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		async fetchSuite() {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/keepiq/api/v1/suites'),
				)
				const suites = response.data
				this.currentSuite = suites.find((s) => s.status === 'active') || null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create a new EncryptionSuite (first-time setup).
		 *
		 * @param {string} masterPassword The master password that protects the new private key.
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		async createSuite(masterPassword) {
			this.loading = true
			try {
				const keys = await wrappedKeyPair(masterPassword)
				const response = await this.postNewSuite({
					publicKey: keys.publicKeyPem,
					encryptedPrivateKey: keys.encryptedPrivateKey,
				})

				this.currentSuite = response.data

				// Immediately unlock the session.
				await bindSessionToSuite(useSessionStore(), {
					privateKeyPem: keys.privateKeyPem,
					encryptedPrivateKey: keys.encryptedPrivateKey,
					certificate: response.data.certificate,
					suiteId: response.data.id,
				})
			} finally {
				this.loading = false
			}
		},

		/**
		 * Change master password (routine — re-wrap private key only).
		 *
		 * @param {string} oldPassword The current master password.
		 * @param {string} newPassword The new master password.
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		async changePassword(oldPassword, newPassword) {
			const session = useSessionStore()

			// Decrypt private key with old password.
			const privateKeyPem = await decryptPrivateKey(
				session.encryptedPrivateKey,
				oldPassword,
			)

			// Re-encrypt with new password.
			const newEncryptedPk = await encryptPrivateKey(
				privateKeyPem,
				newPassword,
			)

			// Replacing the envelope is guarded: prove possession of the current
			// key (the old master password) over the new envelope. The old key is
			// already materialised above, so this adds no extra prompt.
			const proof = await buildKeyProofHeaders({
				suiteId: session.suiteId,
				purpose: PROOF_PURPOSE.UPDATE_PRIVATE_KEY,
				encryptedPrivateKey: session.encryptedPrivateKey,
				masterPassword: oldPassword,
				boundValues: [newEncryptedPk],
			})

			// Update on server.
			await axios.put(
				generateUrl(
					`/apps/keepiq/api/v1/suites/${session.suiteId}/private-key`,
				),
				{ encryptedPrivateKey: newEncryptedPk },
				{ headers: proof },
			)

			session.encryptedPrivateKey = newEncryptedPk

			// The server marks every passkey unlock envelope stale on this
			// change (passkey-vault-login D4). Reload the list so the settings
			// show them as stale and ask to re-enroll, instead of still
			// reading "active" until the page is reloaded.
			await usePasskeyStore().fetchCredentials()
		},

		/**
		 * Initiate compromise recovery and run the migration to completion.
		 *
		 * Rotates the key pair, then actually migrates the vault: every secret,
		 * every in-window version and every attachment grant the user holds is
		 * decrypted with the old private key, re-encrypted under the new one,
		 * verified by decrypting it back, and committed one record per request.
		 * Only when nothing remains bound to the old suite is the migration
		 * completed — the server refuses earlier.
		 *
		 * The old suite stays `active` throughout, so the vault stays readable;
		 * this is what makes the migration possible at all, since it has to read
		 * the old ciphertext.
		 *
		 * @param {string} oldPassword The current master password.
		 * @param {string} newPassword The new master password.
		 * @param {string[]} [carryContactIds] The emergency contacts the owner confirmed
		 *   to carry to the new key (keepiq#800); none are carried by default.
		 * @return {Promise<{migrated: number, failed: number, droppedVersions: number,
		 *   failures: Array<object>, usedWorker: boolean,
		 *   residualContacts: Array<{granteeUserId: string, reason: string}>}>}
		 *   The migration outcome, including the emergency contacts that were not
		 *   re-enveloped, each with why (see migrateEmergencyContacts).
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
		 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-pending-changes-block-logout-and-rotation
		 */
		async initiateCompromiseRecovery(
			oldPassword,
			newPassword,
			carryContactIds = [],
		) {
			await assertNoPendingOfflineChanges()
			const rotation = {
				oldPassword,
				newPassword,
				carryContactIds,
				...(await wrappedKeyPair(newPassword)),
			}

			showRotationStarting(this)
			const session = useSessionStore()
			rotation.started = await postCompromiseRecovery(rotation, session)
			this.migrationStatus = rotation.started.migration
			this.currentSuite = rotation.started.newSuite
			await bindSessionToNewSuite(rotation, session)
			await evictOfflineSnapshot()

			const outcome = await this.runMigration(await rotationMigration(rotation))

			// Migrate emergency-access recovery envelopes BEFORE completion. The
			// completion sweep (SuiteMigrationCompletedEvent) invalidates every
			// contact still bound to the old suite, so any contact re-enveloped
			// here has already left the old suite and survives; the ones that
			// were not carried stay behind for the sweep to invalidate and are
			// returned as residual with a reason: the form prompts re-establishment
			// only for an unreachable one, and warns about a break-glass in flight.
			// Emergency contacts are outside the completion gate, so this never
			// blocks completion (design D2).
			outcome.residualContacts = await this.migrateEmergencyContacts(
				carriedContacts(rotation),
			)

			// Only now, with nothing left on the old suite, is the vault ready
			// for the terminal step. The premature complete() that used to sit
			// here reported success five lines after initiating, before a single
			// record had been touched.
			await this.finaliseMigration(
				rotation.started.migration.id,
				outcome,
				await completionProof(rotationCompletion(rotation), outcome),
			)

			return outcome
		},

		/**
		 * The owner's emergency contacts that a rotation of `suiteId` may carry:
		 * those in state `granted` bound to that suite. The compromise-recovery
		 * form lists these for the owner to confirm before the rotation starts
		 * (keepiq#800). A contact with a break-glass `requested` or `approved` is
		 * never offered: carrying it would release the new key with the wait
		 * already served.
		 *
		 * @param {string} suiteId The suite about to be rotated away from.
		 * @return {Promise<Array<object>>} The carriable contacts; empty when they cannot be listed.
		 * @spec openspec/changes/migrate-emergency-access-on-rotation/specs/emergency-access/spec.md#requirement-envelope-invalidation-on-key-change
		 */
		async listCarriableEmergencyContacts(suiteId) {
			try {
				const response = await axios.get(
					generateUrl('/apps/keepiq/api/v1/emergency-access/contacts'),
				)
				const contacts = Array.isArray(response.data) ? response.data : []
				return contacts.filter(
					(contact) =>
						contact.state === 'granted'
						&& contact.grantorSuiteId === suiteId,
				)
			} catch {
				return []
			}
		},

		/**
		 * Migrate the owner's confirmed emergency-access recovery envelopes onto
		 * the new suite during a compromise-recovery rotation.
		 *
		 * Only a contact the owner confirmed (`carryContactIds`) and that is still
		 * `granted` is carried (keepiq#800): the browser fetches the grantee's
		 * CURRENT certificate, mints a fresh recovery envelope escrowing the new
		 * private key (a build, not a re-wrap), and posts it to the migration
		 * re-point endpoint with a vault-key proof made with the NEW key and password
		 * (keepiq#801, #804 review): the old password may be the leaked one. The
		 * new key is held by whoever started this migration; see
		 * MigrationController::reEnvelopeEmergencyContact for what that does and
		 * does not rule out.
		 *
		 * Every other contact on the old suite is left for the completion sweep to
		 * invalidate and returned as residual with its reason:
		 * `break_glass_in_flight` (requested or approved, warned about, never
		 * prompted), `not_confirmed` (unticked or declined, no prompt) or
		 * `unreachable` (no active grantee certificate, or the post failed, the
		 * only one the form prompts to re-establish). None of these is fatal;
		 * emergency contacts are outside the gate.
		 *
		 * The raw new private key PEM stays in this rotation scope: it only ever
		 * leaves as envelope ciphertext, never logged or persisted (ADR-003).
		 *
		 * @param {object} params The parameters.
		 * @param {string} params.migrationId The migration id.
		 * @param {string} params.oldSuiteId The rotating old suite; only contacts bound to it are considered.
		 * @param {string} params.newPrivateKeyPem The freshly generated private key PEM.
		 * @param {string} params.newSuiteId The new suite, whose key the proof is made with.
		 * @param {string} params.newEncryptedPrivateKey The new suite's AES envelope, for the proof.
		 * @param {string} params.newPassword The new master password, for the proof.
		 * @param {string[]} [params.carryContactIds] The contact ids the owner confirmed.
		 * @return {Promise<Array<{granteeUserId: string, reason: string}>>} The contacts
		 *   that were not re-enveloped, each with why: `break_glass_in_flight`,
		 *   `not_confirmed` or `unreachable`.
		 * @spec openspec/changes/migrate-emergency-access-on-rotation/specs/emergency-access/spec.md#requirement-envelope-invalidation-on-key-change
		 */
		async migrateEmergencyContacts(params) {
			const { oldSuiteId, carryContactIds = [], ...rotation } = params
			const residualContacts = []

			const contacts = await listEmergencyContacts()
			if (contacts === null) {
				// Could not enumerate the contacts: none are re-enveloped and the
				// completion sweep invalidates them all, the pre-change behaviour.
				return residualContacts
			}

			for (const contact of contacts) {
				if (!belongsToRotation(contact, oldSuiteId)) {
					continue
				}
				const reason = await contactResidual(contact, {
					carryContactIds,
					rotation,
				})
				if (reason !== null) {
					residualContacts.push({
						granteeUserId: contact.granteeUserId,
						reason,
					})
				}
			}

			return residualContacts
		},

		/**
		 * Drive the re-encryption loop until no work remains.
		 *
		 * Resumable by construction: the work list is re-derived server-side from
		 * the rows themselves on every pass, so a run that resumes after a closed
		 * tab picks up exactly the records that did not make it.
		 *
		 * @param {object} params The migration parameters.
		 * @param {string} params.migrationId The migration ID.
		 * @param {string} params.oldEncryptedPrivateKey The AES-wrapped old private key.
		 * @param {string} params.oldPassword The old master password.
		 * @param {string} params.newPublicKeyPem The new public key or certificate, PEM.
		 * @param {CryptoKey} params.newPrivateKey The new private key, for verification.
		 * @return {Promise<{migrated: number, failed: number, droppedVersions: number,
		 *   failures: Array<object>, usedWorker: boolean}>} The migration outcome.
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
		 */
		async runMigration(params) {
			const runner = await createMigrationRunner(
				await unwrapMigrationKeys(params),
			)
			this.migrationRunner = runner
			const run = {
				suiteStore: this,
				migrationId: params.migrationId,
				runner,
				migrated: 0,
				halted: null,
				failures: [],
			}
			try {
				await migratePages(run)
			} finally {
				runner.dispose()
				this.migrationRunner = null
			}
			return finishRun(run)
		},

		/**
		 * Flatten one work response into per-record jobs.
		 *
		 * @param {object} work The GET /work response body.
		 * @return {Array<object>} The jobs for this page.
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
		 */
		buildJobs(work) {
			const secrets = (work.secrets?.records || []).map((record) => ({
				store: MIGRATION_STORES.SECRETS,
				id: record.id,
				name: record.name,
				record,
			}))
			return [
				...secrets,
				...unnamedJobs(work.versions, MIGRATION_STORES.VERSIONS),
				...unnamedJobs(
					work.attachmentGrants,
					MIGRATION_STORES.ATTACHMENT_GRANTS,
				),
			]
		},

		/**
		 * POST one page of pipeline results, at most MIGRATION_CONCURRENCY at once.
		 *
		 * A record that failed its round-trip is reported to the server as an
		 * `error` so it lands in `migration_error` on the owning secret; its
		 * original ciphertext is left untouched and the run continues. A
		 * window that halts is the last one posted.
		 *
		 * @param {string} migrationId The migration ID.
		 * @param {Array<object>} results The pipeline results.
		 * @param {Array<object>} jobs The jobs the results correspond to.
		 * @return {Promise<{committed: number, failures: Array<object>}>} The page outcome.
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-re-encrypted-ciphertext-is-verified-before-the-original-is-discarded
		 */
		async postResults(migrationId, results, jobs) {
			const run = {
				suiteStore: this,
				migrationId,
				namesById: new Map(jobs.map((job) => [job.id, job.name])),
				committed: 0,
				failures: [],
				retryable: [],
				halt: null,
			}
			for (
				let start = 0;
				start < results.length && run.halt === null;
				start += MIGRATION_CONCURRENCY
			) {
				await postWindow(
					run,
					results.slice(start, start + MIGRATION_CONCURRENCY),
				)
			}
			const { committed, failures, retryable, halt } = run
			return { committed, failures, retryable, halt }
		},

		/**
		 * The per-record endpoint for one store.
		 *
		 * Store-specific paths, not a generic `{store, id}` — see the change's
		 * design note on why a store-name parameter on a per-object write path
		 * is an IDOR footgun.
		 *
		 * @param {string} migrationId The migration ID.
		 * @param {string} store One of MIGRATION_STORES.
		 * @param {string} id The record ID.
		 * @return {string|null} The URL, or null for an unknown store.
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
		 */
		recordUrl(migrationId, store, id) {
			const base = `/apps/keepiq/api/v1/migrations/${migrationId}`

			if (store === MIGRATION_STORES.SECRETS) {
				return generateUrl(`${base}/secrets/${id}`)
			}
			if (store === MIGRATION_STORES.VERSIONS) {
				return generateUrl(`${base}/versions/${id}`)
			}
			if (store === MIGRATION_STORES.ATTACHMENT_GRANTS) {
				return generateUrl(`${base}/attachment-grants/${id}`)
			}

			return null
		},

		/**
		 * Ask the server to terminate the migration.
		 *
		 * The server refuses while any row still points at the old suite, so a
		 * rejection here means work remains — the resume banner picks it up on
		 * the next unlock rather than the migration being lost.
		 *
		 * @param {string} migrationId The migration ID.
		 * @param {boolean} hasErrors Whether any record failed.
		 * @return {Promise<void>}
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
		 */
		/**
		 * Try to finalise a run, and surface — rather than throw — the case where
		 * the server wants a loss acknowledged.
		 *
		 * A run with no failures finalises straight away. A run with failures is
		 * refused by the server with `migration_incomplete` until the user has
		 * seen which secrets would lose access and agreed, so this records that
		 * pending decision in store state for the recovery dialog to render. The
		 * migration stays `in_progress` and resumable in the meantime, which is
		 * the safe place to be parked.
		 *
		 * @param {string} migrationId The migration ID.
		 * @param {object} outcome The run outcome from runMigration.
		 * @param {Record<string,string>|null} proofHeaders Vault-key-proof headers for completion.
		 * @return {Promise<{finalised: boolean, needsAcknowledgement: boolean, message: string|null}>}
		 *   Whether the migration terminated, and why not if it did not.
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
		 */
		async finaliseMigration(migrationId, outcome, proofHeaders = null) {
			this.migrationNeedsAcknowledgement = false
			this.migrationBlockedMessage = null

			try {
				await this.completeMigration(
					migrationId,
					outcome.failed > 0,
					null,
					proofHeaders,
				)
				return {
					finalised: true,
					needsAcknowledgement: false,
					message: null,
				}
			} catch (e) {
				const status = e?.response?.status
				const code = e?.response?.data?.error
				const message = e?.response?.data?.message || String(e?.message || e)

				if (status === 409 && code === 'migration_incomplete') {
					// Expected, not exceptional: either work remains (resume) or a
					// loss needs acknowledging (the dialog asks). Either way the
					// migration is intact.
					//
					// `requiredAcknowledgement` is present only in the
					// acknowledgement case, and it is the ONLY number that will
					// satisfy the server. Absent means rows nobody has attempted
					// yet, which resuming fixes rather than acknowledging.
					const required =
						e?.response?.data?.requiredAcknowledgement ?? null
					this.migrationRequiredAcknowledgement = required

					const needsAcknowledgement = required !== null
					this.migrationNeedsAcknowledgement = needsAcknowledgement
					this.migrationBlockedMessage = message
					return {
						finalised: false,
						needsAcknowledgement,
						requiredAcknowledgement: required,
						message,
					}
				}

				throw e
			}
		},

		/**
		 * Finalise a run whose losses the user has explicitly accepted.
		 *
		 * Locks the old suite, so the accepted secrets lose access. Only ever
		 * called from an affirmative user action.
		 *
		 * @param {string} migrationId The migration ID.
		 * @param {string} oldPassword The old master password, to prove the retiring key.
		 * @param {number} acceptUnrecoverable How many losses the user accepted.
		 * @return {Promise<object>} The completion response, plus `residualContacts`:
		 *   the emergency contacts the completion sweep removed (see
		 *   rotationRemovedContacts).
		 * @spec openspec/changes/restore-suite-migration-loop/specs/secrets/spec.md#requirement-possibly-compromised-flag-lifecycle
		 * @spec openspec/changes/migrate-emergency-access-on-rotation/specs/emergency-access/spec.md#requirement-envelope-invalidation-on-key-change
		 */
		async acceptMigrationLosses(
			migrationId,
			oldPassword,
			acceptUnrecoverable = null,
		) {
			// Defaults to the server's own number. A caller may still pass one
			// explicitly, but the stored value is what the server asked for and
			// is therefore what it will accept.
			const accepted =
				acceptUnrecoverable ?? this.migrationRequiredAcknowledgement

			if (accepted === null) {
				throw new Error(
					'Cannot accept losses before the server has stated how many there are.',
				)
			}

			// Completing is guarded, and the proof is over the OLD (retiring)
			// key. Without the old password we cannot build it, so ask for it
			// rather than sending a request the server will refuse.
			if (!oldPassword) {
				const err = new Error(
					'Re-enter your master password to finish the rotation.',
				)
				err.code = 'key_proof_required'
				throw err
			}
			// Read before completing: completeMigration refreshes the status,
			// which is null once the migration has ended.
			const oldSuiteId = this.migrationStatus.oldSuiteId
			const { data: oldSuite } = await axios.get(
				generateUrl(`/apps/keepiq/api/v1/suites/${oldSuiteId}`),
			)
			// Bind the acknowledged count: this is the exact replay Wilco flagged —
			// a proof that committed only to the id could be captured on a clean
			// completion and re-presented here to finalise with an unacknowledged
			// permanent loss. Binding hasErrors (always true on this path) and the
			// accepted count closes it.
			const proof = await buildKeyProofHeaders({
				suiteId: oldSuiteId,
				purpose: PROOF_PURPOSE.COMPLETE_MIGRATION,
				encryptedPrivateKey: oldSuite.privateKey,
				masterPassword: oldPassword,
				boundValues: [migrationId, boundParam(true), boundParam(accepted)],
			})

			const data = await this.completeMigration(
				migrationId,
				true,
				accepted,
				proof,
			)
			this.migrationNeedsAcknowledgement = false
			this.migrationRequiredAcknowledgement = null
			this.migrationBlockedMessage = null

			// Accepting losses is a normal way to end a resumed rotation, and the
			// completion sweep has only now run. Read back what it removed, so the
			// completion screen can name those contacts (#804 review, round 5).
			return {
				...data,
				residualContacts: await this.rotationRemovedContacts(oldSuiteId),
			}
		},

		/**
		 * Ask the server to terminate the migration.
		 *
		 * Thin wrapper over the endpoint: the decision-making lives in
		 * finaliseMigration and acceptMigrationLosses, and the server owns the
		 * gate. Always refreshes migration status afterwards, including on
		 * failure, so a refused completion leaves the UI showing the truth.
		 *
		 * @param {string} migrationId The migration ID.
		 * @param {boolean} hasErrors Whether any record failed.
		 * @param {number|null} acceptUnrecoverable Losses the user has accepted.
		 * @param {Record<string,string>|null} proofHeaders Vault-key-proof headers for completion.
		 * @return {Promise<object>} The completion response body.
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-a-migration-always-has-a-way-to-terminate
		 */
		async completeMigration(
			migrationId,
			hasErrors,
			acceptUnrecoverable = null,
			proofHeaders = null,
		) {
			try {
				const body = { hasErrors }
				// The server refuses to finalise a migration that would cost the
				// user access to a secret unless the count is acknowledged. Passing
				// it is therefore a deliberate act, never a default: callers only
				// supply it once the user has seen the list and agreed.
				if (acceptUnrecoverable !== null) {
					body.acceptUnrecoverable = acceptUnrecoverable
				}

				// Completing marks the old suite compromised, so it carries a
				// vault-key proof (defence in depth alongside the acknowledgement).
				const config = proofHeaders ? { headers: proofHeaders } : {}
				const { data } = await axios.post(
					generateUrl(
						`/apps/keepiq/api/v1/migrations/${migrationId}/complete`,
					),
					body,
					config,
				)
				this.migrationDroppedVersions =
					data.droppedVersions ?? this.migrationDroppedVersions
				this.migrationUnrecoverable = data.unrecoverable ?? []
				return data
			} finally {
				await this.fetchMigrationStatus()
			}
		},

		/**
		 * How many records an interrupted migration still has to move.
		 *
		 * The resume banner needs a number, and the only trustworthy one is
		 * derived server-side from the rows themselves — a client-side count
		 * would be lost with the tab that produced it. Asks for the smallest
		 * possible page because only the totals are wanted.
		 *
		 * @return {Promise<number|null>} Records remaining, or null when there is
		 *   no migration to resume.
		 * @spec openspec/specs/encryption-suites/spec.md#requirement-suite-migration
		 */
		async fetchMigrationRemaining() {
			if (this.migrationStatus === null) {
				this.migrationRemaining = null
				return null
			}

			try {
				const { data } = await axios.get(
					generateUrl(
						`/apps/keepiq/api/v1/migrations/${this.migrationStatus.id}/work`,
					),
					{ params: { limit: 1 } },
				)
				this.migrationRemaining = data.totalRemaining ?? 0
			} catch (e) {
				// A banner that cannot count is still worth showing, so leave the
				// count null rather than hiding the migration entirely.
				this.migrationRemaining = null
			}

			return this.migrationRemaining
		},

		/**
		 * Resume an interrupted migration, given the old master password.
		 *
		 * Driven by the resume banner. The vault is already unlocked under the
		 * NEW password, so the session holds the new private key and certificate
		 * and the only thing missing is the old private key — which is why this
		 * asks for the old master password and nothing else.
		 *
		 * @param {string} oldPassword The old master password.
		 * @return {Promise<{migrated: number, failed: number, droppedVersions: number,
		 *   failures: Array<object>, usedWorker: boolean,
		 *   residualContacts: Array<{granteeUserId: string, reason: string}>}>}
		 *   The migration outcome, including the emergency contacts the rotation
		 *   removed (see rotationRemovedContacts). Empty while a loss still needs
		 *   acknowledging: acceptMigrationLosses reports them then.
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
		 */
		async resumeMigration(oldPassword) {
			await this.fetchMigrationStatus()
			const session = useSessionStore()
			assertResumable(this.migrationStatus, session)
			// The old suite is read now: completion refreshes the status, which
			// is null once the migration has ended, and the read-back below
			// needs it.
			const resume = {
				oldPassword,
				migrationId: this.migrationStatus.id,
				oldSuiteId: this.migrationStatus.oldSuiteId,
			}
			// Both suites are resolved from the MIGRATION, never from the session.
			// During a migration two suites are active, and the session binds to
			// whichever one the suite list yielded first — historically the OLD
			// one. Taking the new key from `session.cryptoKey` therefore risked
			// handing the pipeline the old private key as its verification key, so
			// every single record would fail its round-trip and the run would look
			// like a vault full of corrupt data instead of one mis-wired resume.
			Object.assign(resume, await migrationSuites(this.migrationStatus))
			assertSessionOnSuite(session, resume.newSuite)

			this.migrationFailures = []
			this.migrationDroppedVersions = 0
			const outcome = await this.runMigration(resumedMigration(resume, session))

			// A resumed run finalises without any extra prompt, exactly like the
			// initiate path: completion's proof is over the OLD key, which resume
			// already holds the password for.
			const completion = await this.finaliseMigration(
				resume.migrationId,
				outcome,
				await completionProof(
					resumeCompletion(resume, this.migrationStatus),
					outcome,
				),
			)

			// A resumed run never carries an emergency contact: that step belongs
			// to the initiate path, which knows what the owner ticked. Once the
			// server has accepted completion, its sweep has invalidated every
			// contact left on the old suite, so name them for the completion
			// screen instead of removing the owner's break-glass path without a
			// word (#804 review). While a loss still needs acknowledging the sweep
			// has not run, so there is nothing to read back yet:
			// acceptMigrationLosses reads back once the owner finishes.
			outcome.residualContacts =
				completion?.finalised === true
					? await this.rotationRemovedContacts(resume.oldSuiteId)
					: []

			return outcome
		},

		/**
		 * The owner's emergency contacts that the rotation away from a suite
		 * invalidated, for the completion screen after a resumed run.
		 *
		 * Read back from the server, because a resumed run has no residual list
		 * of its own. A contact with a break-glass in flight is reported as such,
		 * so the screen can warn about it; every other one as
		 * `removed_by_rotation`, which the screen names neutrally, never as a
		 * prompt to re-establish (#804 review).
		 *
		 * @param {string} oldSuiteId The suite the rotation moved away from.
		 * @return {Promise<Array<{granteeUserId: string, reason: string}>>} The
		 *   removed contacts; empty (and logged) when they cannot be listed.
		 * @spec openspec/changes/migrate-emergency-access-on-rotation/specs/emergency-access/spec.md#requirement-envelope-invalidation-on-key-change
		 */
		async rotationRemovedContacts(oldSuiteId) {
			let contacts
			try {
				const response = await axios.get(
					generateUrl('/apps/keepiq/api/v1/emergency-access/contacts'),
				)
				contacts = Array.isArray(response.data) ? response.data : []
			} catch (e) {
				// The standing Emergency Access view still shows each removed
				// contact, so the owner is not left without a notice; log it so a
				// silent completion screen can be traced (#804 review, round 5).
				// eslint-disable-next-line no-console
				console.warn(
					'Keepiq: could not list the emergency contacts a rotation removed',
					e,
				)
				return []
			}

			return contacts
				.filter(
					(contact) =>
						contact.state === 'invalidated'
						&& contact.grantorSuiteId === oldSuiteId
						&& String(contact.invalidatedReason ?? '').startsWith(
							'grantor_rotation',
						),
				)
				.map((contact) => ({
					granteeUserId: contact.granteeUserId,
					reason:
						contact.invalidatedReason === 'grantor_rotation_in_flight'
							? 'break_glass_in_flight'
							: 'removed_by_rotation',
				}))
		},

		/**
		 * Discard migration progress state and terminate any running worker.
		 *
		 * @return {void}
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
		 */
		resetMigrationState() {
			this.migrationProgress = null
			this.migrationFailures = []
			this.migrationDroppedVersions = 0
			this.migrationUnrecoverable = []
			this.migrationNeedsAcknowledgement = false
			this.migrationRequiredAcknowledgement = null
			this.migrationBlockedMessage = null
			if (this.migrationRunner) {
				this.migrationRunner.dispose()
				this.migrationRunner = null
			}
		},

		/**
		 * Register this store's migration teardown with the vault-lock lifecycle,
		 * so a locked vault leaves no worker holding key clones.
		 *
		 * @return {void}
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
		 */
		registerLockReset() {
			onVaultLock(() => this.resetMigrationState())
		},

		/**
		 * Revoke the current user's active encryption suite.
		 *
		 * When the suite still has a usable emergency contact the server refuses
		 * with 409 `emergency_access_present` and the count of usable contacts,
		 * unless `acceptEmergencyLoss` is set — revocation permanently deletes that
		 * emergency access. The refusal propagates as a rejected request for the
		 * caller to surface and re-confirm.
		 *
		 * @param {string} reason The reason for revocation
		 * @param {string} masterPassword The master password, to sign the vault-key proof
		 * @param {boolean} acceptEmergencyLoss Proceed even though emergency access will be deleted
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 * @spec openspec/specs/vault-key-proof/spec.md#requirement-irreversible-operations-require-a-verified-key-proof
		 * @spec openspec/changes/migrate-emergency-access-on-rotation/specs/emergency-access/spec.md#requirement-envelope-invalidation-on-key-change
		 */
		async revokeSuite(reason, masterPassword, acceptEmergencyLoss = false) {
			if (!this.currentSuite) {
				throw new Error('No active suite to revoke')
			}

			// Revocation is guarded by a vault-key proof: prove possession of the
			// master password over this suite's private key, binding the reason AND
			// the emergency-loss flag so a proof captured on a no-override revoke
			// cannot be replayed with acceptEmergencyLoss=true to force emergency
			// deletion. A stolen session has no master password and cannot revoke.
			const session = useSessionStore()
			const proof = await buildKeyProofHeaders({
				suiteId: this.currentSuite.id,
				purpose: PROOF_PURPOSE.REVOKE_SUITE,
				encryptedPrivateKey: session.encryptedPrivateKey,
				masterPassword,
				boundValues: [reason, boundParam(acceptEmergencyLoss)],
			})

			const response = await axios.post(
				generateUrl(
					`/apps/keepiq/api/v1/suites/${this.currentSuite.id}/revoke`,
				),
				{ reason, acceptEmergencyLoss },
				{ headers: proof },
			)

			this.currentSuite = response.data

			// A revoked suite must not leave a readable offline copy behind.
			try {
				const { useOfflineStore } = await import('./offline.js')
				await useOfflineStore().evict()
			} catch (e) {
				// Offline cache absent — nothing to evict.
			}
		},

		/**
		 * Administrator force-revoke of any suite by id (admin settings surface).
		 *
		 * The administrator counterpart to the owner path's `revokeSuite()`. The
		 * vault is zero-knowledge (ADR-003), so an administrator holds no vault key
		 * to sign the revoke challenge; authorisation is the admin guard plus
		 * Nextcloud sudo. The endpoint carries `#[PasswordConfirmationRequired]`, so
		 * the password-confirmation (sudo) flow MUST complete BEFORE the request —
		 * the middleware rejects a request whose sudo has not been re-confirmed.
		 *
		 * `@nextcloud/password-confirmation` is imported lazily (like the offline
		 * store below) so its `@nextcloud/vue` dialog dependency stays off the
		 * store's static load path; `confirmPassword()` resolves immediately when
		 * sudo is not currently required and otherwise prompts, resolving only once
		 * the administrator has re-confirmed and rejecting if they cancel.
		 *
		 * The offline cache is deliberately NOT evicted here: it holds the acting
		 * administrator's OWN vault, not the (cross-owner) target suite's secrets,
		 * so evicting it on an unrelated admin action would be wrong. The owner
		 * `revokeSuite()` evicts because there the revoked suite IS the caller's own.
		 *
		 * @param {object} params The parameters.
		 * @param {string} params.id The suite id to force-revoke.
		 * @param {string} params.reason The required, free-form revocation reason.
		 * @param {boolean} params.markCompromised Treat the suite's secrets as compromised (default false).
		 * @param {string} params.confirmSuiteId The suite id the administrator typed to
		 *   confirm; the server refuses the request unless it equals `id` (keepiq#871).
		 * @return {Promise<object>} The revoked `suite`, `emergencyContactsDestroyed`,
		 *   `warning` (only when `markCompromised` was false), and for a compromise
		 *   revoke `alsoRevokedSuite`, `terminatedMigration`,
		 *   `alsoRevokedEmergencyContactsDestroyed`, `cascadeIncomplete` and `cascadeFailed`.
		 *   A compromise revoke whose migration end failed answers 500 with
		 *   `migration_end_failed`; it still resolves, with the server's message
		 *   in `unfinished`, because the suite was revoked (its containment
		 *   ran; see `cascade`) (keepiq#1189). Any other failure rejects.
		 * @spec openspec/specs/encryption-suites/spec.md#requirement-administrator-force-revocation
		 */
		async forceRevokeSuite({
			id,
			reason,
			markCompromised = false,
			confirmSuiteId = '',
		}) {
			assertForceRevocable(id, reason)

			// Complete Nextcloud sudo BEFORE issuing the request — the endpoint's
			// #[PasswordConfirmationRequired] middleware rejects it otherwise.
			await confirmPasswordFirst()

			const data = await postForceRevoke(id, {
				reason,
				markCompromised,
				confirmSuiteId,
			})
			return { ...revokedOutcome(data), ...compromiseCascadeOutcome(data) }
		},

		/**
		 * Register a new suite, confirming the Nextcloud password when the server asks.
		 *
		 * After a revocation the server refuses a plain create with
		 * `reauthentication_required` (keepiq#860): a session alone may not
		 * replace a revoked vault key. The user then confirms their password
		 * and the same key material goes to the sudo-guarded re-enrol route.
		 *
		 * @param {object} body The publicKey and encryptedPrivateKey to register.
		 * @return {Promise<object>} The axios response with the created suite.
		 * @spec openspec/specs/encryption-suites/spec.md#requirement-re-enrolment-after-a-revocation-requires-a-fresh-password-confirmation
		 */
		async postNewSuite(body) {
			try {
				return await axios.post(
					generateUrl('/apps/keepiq/api/v1/suites'),
					body,
				)
			} catch (e) {
				if (e?.response?.data?.error !== 'reauthentication_required') {
					throw e
				}
			}

			await confirmPasswordFirst()
			return axios.post(
				generateUrl('/apps/keepiq/api/v1/suites/reenrol'),
				body,
			)
		},

		/**
		 * Reinstate a revoked suite by id from the admin settings surface.
		 *
		 * Wired to the admin-only `reinstate()` endpoint. Reinstating re-opens
		 * every secret under the key, so the endpoint carries
		 * `#[PasswordConfirmationRequired]` like force-revoke does, and the sudo
		 * flow runs before the request (keepiq#865).
		 *
		 * @param {string} id The suite id to reinstate.
		 * @return {Promise<object>} The reinstated suite JSON.
		 * @spec openspec/specs/encryption-suites/spec.md#requirement-administrator-force-revocation
		 */
		async reinstateSuiteAdmin(id) {
			if (!id) {
				throw new Error('No suite id to reinstate')
			}

			await confirmPasswordFirst()

			const response = await axios.post(
				generateUrl(`/apps/keepiq/api/v1/suites/${id}/reinstate`),
			)

			return response.data
		},

		/**
		 * Check migration status.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		async fetchMigrationStatus() {
			try {
				const response = await axios.get(
					generateUrl('/apps/keepiq/api/v1/migrations/status'),
				)
				this.migrationStatus =
					response.data.status === 'none' ? null : response.data
			} catch {
				this.migrationStatus = null
			}
		},

		/**
		 * Abort an interrupted migration, returning the vault to the old suite.
		 *
		 * The non-destructive escape from a rotation the user does not want to
		 * finish: it discards the unused new key and unlocks the vault under the
		 * old key, which never stopped being valid. The server refuses (409) if
		 * any record has already moved to the new suite — at that point resuming
		 * is the only safe route — so this surfaces that as an error for the
		 * banner rather than pretending it succeeded.
		 *
		 * The server requires a vault-key proof over the NEW suite's key
		 * (keepiq#859), so a stolen session cannot call the owner's recovery
		 * off. That is the key the session is unlocked with, so the password
		 * asked for is the current master password.
		 *
		 * @param {string} masterPassword The current (new) master password.
		 * @return {Promise<object>} The server's terminal result.
		 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-migration-can-be-aborted-before-any-record-moves
		 */
		async abortMigration(masterPassword) {
			await this.fetchMigrationStatus()
			if (this.migrationStatus === null) {
				throw new Error('There is no migration to abort')
			}

			const migrationId = this.migrationStatus.id
			try {
				const { data: newSuite } = await axios.get(
					generateUrl(
						`/apps/keepiq/api/v1/suites/${this.migrationStatus.newSuiteId}`,
					),
				)
				const headers = await buildKeyProofHeaders({
					suiteId: newSuite.id,
					purpose: PROOF_PURPOSE.ABORT_MIGRATION,
					encryptedPrivateKey: newSuite.privateKey,
					masterPassword,
					boundValues: [migrationId],
				})
				const { data } = await axios.post(
					generateUrl(
						`/apps/keepiq/api/v1/migrations/${migrationId}/abort`,
					),
					{},
					{ headers },
				)
				return data
			} finally {
				// Whether it aborted or was refused, re-read the authoritative
				// state so the banner reflects reality (cleared on success, still
				// present with its remaining count on a refusal).
				await this.fetchMigrationStatus()
				await this.fetchMigrationRemaining()
			}
		},
	},
})
