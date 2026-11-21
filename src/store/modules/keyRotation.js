/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The steps the encryption suite store takes to set up a vault key and to
 * rotate it after a compromise: a new key pair wrapped under a password,
 * the session bound to a suite, the offline queue and snapshot around a
 * rotation, the guarded start request, and what the migration and the
 * emergency-contact carry-over are given. A rotation object lives only for
 * the call of the store action that built it.
 *
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	encryptPrivateKey,
	generateKeyPair,
	importPrivateKey,
} from '../../crypto/index.js'
import { buildKeyProofHeaders, PROOF_PURPOSE } from '../../crypto/keyProof.js'

/**
 * A private key as a PKCS#8 PEM, its base64 wrapped at 64 characters.
 *
 * @param {CryptoKey} privateKey The private key.
 * @return {Promise<string>}
 */
async function exportPrivateKeyPem(privateKey) {
	const pkcs8 = await crypto.subtle.exportKey('pkcs8', privateKey)
	return (
		'-----BEGIN PRIVATE KEY-----\n'
		+ btoa(String.fromCharCode(...new Uint8Array(pkcs8)))
			.match(/.{1,64}/g)
			.join('\n')
		+ '\n-----END PRIVATE KEY-----'
	)
}

/**
 * A new key pair with its private key exported and wrapped under the
 * password.
 *
 * @param {string} password The master password that protects the private key.
 * @return {Promise<{publicKeyPem: string, privateKeyPem: string, encryptedPrivateKey: string}>}
 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
 */
export async function wrappedKeyPair(password) {
	const { publicKeyPem, privateKey } = await generateKeyPair()
	const privateKeyPem = await exportPrivateKeyPem(privateKey)
	const encryptedPrivateKey = await encryptPrivateKey(privateKeyPem, password)
	return { publicKeyPem, privateKeyPem, encryptedPrivateKey }
}

/**
 * Unlock the session with a suite's key material.
 *
 * @param {object} session The session store.
 * @param {{privateKeyPem: string, encryptedPrivateKey: string, certificate: ?string, suiteId: string}} binding The suite's key material.
 * @return {Promise<void>}
 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
 */
export async function bindSessionToSuite(session, binding) {
	session.cryptoKey = await importPrivateKey(binding.privateKeyPem)
	session.encryptedPrivateKey = binding.encryptedPrivateKey
	session.certificate = binding.certificate
	session.suiteId = binding.suiteId
	session.lastActivity = Date.now()
}

/**
 * Offline changes are sealed to the current certificate: a rotation waits
 * until they are synced or discarded (offline-edit-queue D6).
 *
 * @return {Promise<void>} Rejects while changes are pending.
 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-pending-changes-block-logout-and-rotation
 */
export async function assertNoPendingOfflineChanges() {
	const { useOfflineStore: offlineStoreOf } = await import('./offline.js')
	const offline = offlineStoreOf()
	await offline.loadQueue()
	offline.assertNoPendingChanges()
}

/**
 * Suite rotation invalidates every cached ciphertext (encrypted to the
 * now-dead key) — evict the offline snapshot (offline-readonly-cache §D4).
 * Lazy import avoids a static store cycle.
 *
 * @return {Promise<void>}
 * @spec openspec/specs/offline-readonly-cache/spec.md#requirement-online-sessions-write-through-an-encrypted-local-snapshot
 */
export async function evictOfflineSnapshot() {
	try {
		const { useOfflineStore } = await import('./offline.js')
		await useOfflineStore().evict()
	} catch {
		// Offline cache absent — nothing to evict.
	}
}

/**
 * Show a rotation as starting: no progress, failures or dropped versions yet.
 *
 * @param {object} store The encryption suite store.
 * @return {void}
 */
export function showRotationStarting(store) {
	store.migrationProgress = { done: 0, total: 0, phase: 'starting' }
	store.migrationFailures = []
	store.migrationDroppedVersions = 0
}

/**
 * Start the rotation. Starting is guarded: prove possession of the OLD suite
 * key (i.e. the old master password) over the submitted new key material, so
 * a stolen session cannot begin a hostile rotation.
 *
 * @param {object} rotation The rotation.
 * @param {object} session The session store, still bound to the old suite.
 * @return {Promise<object>} The started migration, the new suite and the old wrapped key.
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
 */
export async function postCompromiseRecovery(rotation, session) {
	const startProof = await buildKeyProofHeaders({
		suiteId: session.suiteId,
		purpose: PROOF_PURPOSE.COMPROMISE_RECOVERY,
		encryptedPrivateKey: session.encryptedPrivateKey,
		masterPassword: rotation.oldPassword,
		boundValues: [rotation.publicKeyPem, rotation.encryptedPrivateKey],
	})
	const response = await axios.post(
		generateUrl('/apps/keepiq/api/v1/suites/compromise-recovery'),
		{
			publicKey: rotation.publicKeyPem,
			encryptedPrivateKey: rotation.encryptedPrivateKey,
		},
		{ headers: startProof },
	)
	return response.data
}

/**
 * Rebind the session to the NEW suite, the way createSuite does. Without
 * this the session still names the old suite, and resumeMigration's "is this
 * session unlocked against the migration's new suite?" check refused — so
 * "Try these again", the only in-dialog escape from a partly-failed run,
 * raised an error every time and pushed the user at "Finish anyway" instead.
 *
 * The key material is the pair generated for the rotation, so this is the
 * same binding createSuite performs on first-time setup, not a re-derivation
 * from anything the server sent.
 *
 * @param {object} rotation The started rotation.
 * @param {object} session The session store.
 * @return {Promise<void>}
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
 */
export async function bindSessionToNewSuite(rotation, session) {
	const newSuite = rotation.started.newSuite
	await bindSessionToSuite(session, {
		privateKeyPem: rotation.privateKeyPem,
		encryptedPrivateKey: rotation.encryptedPrivateKey,
		certificate: newSuite?.certificate ?? null,
		suiteId: newSuite?.id ?? session.suiteId,
	})
}

/**
 * The re-encryption loop's parameters. The new private key is re-imported
 * rather than reusing the generated CryptoKey, so the verification key is
 * non-extractable like every other key here.
 *
 * @param {object} rotation The started rotation.
 * @return {Promise<object>}
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
 */
export async function rotationMigration(rotation) {
	return {
		migrationId: rotation.started.migration.id,
		oldEncryptedPrivateKey: rotation.started.oldEncryptedPrivateKey,
		oldPassword: rotation.oldPassword,
		newPublicKeyPem: rotation.publicKeyPem,
		newPrivateKey: await importPrivateKey(rotation.privateKeyPem),
	}
}

/**
 * What the emergency-contact carry-over is given for this rotation.
 *
 * @param {object} rotation The started rotation.
 * @return {object}
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
 */
export function carriedContacts(rotation) {
	return {
		migrationId: rotation.started.migration.id,
		oldSuiteId: rotation.started.migration.oldSuiteId,
		newPrivateKeyPem: rotation.privateKeyPem,
		newSuiteId: rotation.started.newSuite?.id,
		newEncryptedPrivateKey: rotation.encryptedPrivateKey,
		newPassword: rotation.newPassword,
		carryContactIds: rotation.carryContactIds,
	}
}

/**
 * The old and the new suite of a migration, both resolved from the
 * MIGRATION, never from the session.
 *
 * @param {{oldSuiteId: string, newSuiteId: string}} migration The migration status.
 * @return {Promise<{oldSuite: object, newSuite: object}>}
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
 */
export async function migrationSuites(migration) {
	const { data: oldSuite } = await axios.get(
		generateUrl(`/apps/keepiq/api/v1/suites/${migration.oldSuiteId}`),
	)
	const { data: newSuite } = await axios.get(
		generateUrl(`/apps/keepiq/api/v1/suites/${migration.newSuiteId}`),
	)
	return { oldSuite, newSuite }
}

/**
 * The session must be bound to the migration's NEW suite, because
 * session.cryptoKey is the verification key the pipeline will use. Bound to
 * the old suite it would verify new ciphertext against the OLD private key:
 * every record would fail its round-trip and the run would look like a vault
 * full of corrupt data rather than one mis-wired resume. Suite resolution
 * deterministically prefers the newest active suite, so this is a transient
 * state at worst — say so instead of guessing at a key.
 *
 * @param {object} session The session store.
 * @param {{id: string}} newSuite The migration's new suite.
 * @return {void}
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
 */
export function assertSessionOnSuite(session, newSuite) {
	if (session.suiteId !== newSuite.id) {
		throw new Error(
			'Your session is unlocked against the previous encryption suite. '
				+ 'Lock and unlock the vault, then resume the migration.',
		)
	}
}

/**
 * The re-encryption loop's parameters for a resumed run: the old key from the
 * migration's old suite, the new public key from its new suite, not from the
 * session, and the session's key, bound to that new suite, to verify with.
 *
 * @param {object} resume The resumed run.
 * @param {object} session The session store.
 * @return {object}
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
 */
export function resumedMigration(resume, session) {
	return {
		migrationId: resume.migrationId,
		oldEncryptedPrivateKey: resume.oldSuite.privateKey,
		oldPassword: resume.oldPassword,
		newPublicKeyPem: resume.newSuite.certificate ?? resume.newSuite.publicKey,
		newPrivateKey: session.cryptoKey,
	}
}

/**
 * What a rotation's completion proof is made over: the old suite, its wrapped
 * key and the old password.
 *
 * @param {object} rotation The started rotation.
 * @return {{suiteId: string, encryptedPrivateKey: string, masterPassword: string, migrationId: string}}
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
 */
export function rotationCompletion(rotation) {
	return {
		suiteId: rotation.started.migration.oldSuiteId,
		encryptedPrivateKey: rotation.started.oldEncryptedPrivateKey,
		masterPassword: rotation.oldPassword,
		migrationId: rotation.started.migration.id,
	}
}

/**
 * What a resumed run's completion proof is made over: the migration's old
 * suite as the status names it now, its wrapped key and the old password.
 *
 * @param {object} resume The resumed run.
 * @param {{oldSuiteId: string}} migration The migration status.
 * @return {{suiteId: string, encryptedPrivateKey: string, masterPassword: string, migrationId: string}}
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
 */
export function resumeCompletion(resume, migration) {
	return {
		suiteId: migration.oldSuiteId,
		encryptedPrivateKey: resume.oldSuite.privateKey,
		masterPassword: resume.oldPassword,
		migrationId: resume.migrationId,
	}
}

/**
 * Refuse to resume without a migration, or while the vault is locked.
 *
 * @param {object|null} migration The migration status.
 * @param {object} session The session store.
 * @return {void}
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
 */
export function assertResumable(migration, session) {
	if (migration === null) {
		throw new Error('There is no migration to resume')
	}
	if (session.cryptoKey === null) {
		throw new Error('Unlock the vault before resuming the migration')
	}
}
