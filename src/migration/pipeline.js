/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The per-record cryptographic pipeline of compromise-recovery migration.
 *
 * One record at a time: decrypt with the old private key, re-encrypt under the
 * new public key, then decrypt the fresh blob again with the NEW private key
 * and byte-compare it against the original plaintext. Only a record that
 * survives that comparison is handed back for the server to commit.
 *
 * The verify step is not belt-and-braces. On 2026-07-18 OpenSSL minted a
 * throwaway key pair for a public-key-only CSR and produced ciphertext nobody
 * could decrypt: encryption reported success and the data was already lost. A
 * successful encrypt call proves nothing about whether anyone can read the
 * result — the only acceptable evidence is a successful decrypt.
 *
 * This module is imported by both the worker and the main-thread fallback, so
 * the two paths cannot drift (ADR-003: all crypto is client-side; the server
 * only ever sees ciphertext).
 *
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-re-encrypted-ciphertext-is-verified-before-the-original-is-discarded
 */

import { rsaDecrypt, rsaEncrypt } from '../crypto/rsa.js'

/**
 * The three stores that carry their own ciphertext and are re-encrypted here.
 *
 * `secret_requests` holds no ciphertext (its suite pointer is re-pointed on
 * completion), `link_shares` are revoked and `emergency_contacts` invalidated
 * — none of those three is re-encryptable by the rotating user, so none has a
 * pipeline.
 *
 * @type {{SECRETS: string, VERSIONS: string, ATTACHMENT_GRANTS: string}}
 */
export const MIGRATION_STORES = {
	SECRETS: 'secrets',
	VERSIONS: 'versions',
	ATTACHMENT_GRANTS: 'attachmentGrants',
}

/**
 * Raised when a re-encrypted blob does not decrypt back to its original.
 *
 * Carries no plaintext and no ciphertext — the message is surfaced to the user
 * and persisted server-side in `migration_error`.
 */
export class RoundTripMismatchError extends Error {
	/**
	 * @param {string} field The field whose round-trip failed.
	 */
	constructor(field) {
		super(`Re-encrypted ${field} did not decrypt back to the original value`)
		this.name = 'RoundTripMismatchError'
		// The OLD ciphertext decrypted fine to get here, so the stored value is
		// readable and must never be treated as unrecoverable. The fault is on
		// the new side — key material or the crypto path — and it will repeat on
		// every record, so the run halts rather than logging a loss per secret.
		this.phase = 'verify'
		this.permanent = false
		this.halt = true
	}
}

/**
 * Raised when the EXISTING ciphertext cannot be decrypted with the old key.
 *
 * This is the only failure that means the value is genuinely not recoverable
 * here: we hold the old private key and it does not open the blob. Causes are
 * corruption, or ciphertext belonging to a different suite generation than the
 * one this migration is walking. The stored bytes are kept either way — an
 * older master password may still open it — so the wording stays "with this
 * key", never "gone".
 */
export class OldKeyDecryptError extends Error {
	/**
	 * @param {string} field The field that could not be decrypted.
	 * @param {string} detail The underlying crypto error message.
	 */
	constructor(field, detail) {
		super(
			`Existing ${field} could not be decrypted with the previous key (${detail})`,
		)
		this.name = 'OldKeyDecryptError'
		this.phase = 'decrypt-old'
		this.permanent = true
		this.halt = false
	}
}

/**
 * Compare two strings by their UTF-8 bytes.
 *
 * Byte comparison rather than `===` because the guarantee being enforced is
 * about the bytes that will be stored: two strings that differ only in
 * normalisation or in a lone surrogate must not be treated as a successful
 * round-trip.
 *
 * @param {string} a First value.
 * @param {string} b Second value.
 * @return {boolean} True when the UTF-8 encodings are identical.
 */
function bytesEqual(a, b) {
	const encoder = new TextEncoder()
	const left = encoder.encode(a)
	const right = encoder.encode(b)

	if (left.length !== right.length) {
		return false
	}

	for (let i = 0; i < left.length; i++) {
		if (left[i] !== right[i]) {
			return false
		}
	}

	return true
}

/**
 * Re-encrypt one ciphertext field and prove the result is readable.
 *
 * @param {string} ciphertext The existing blob, encrypted under the old suite.
 * @param {object} keys The migration keys.
 * @param {CryptoKey} keys.oldPrivateKey Decrypts the existing blob.
 * @param {CryptoKey} keys.newPublicKey Encrypts the replacement.
 * @param {CryptoKey} keys.newPrivateKey Verifies the replacement.
 * @param {string} field The field name, for the error message only.
 * @return {Promise<string>} The verified replacement ciphertext.
 * @throws {RoundTripMismatchError} When the round-trip is not byte-identical.
 */
export async function verifiedReEncrypt(ciphertext, keys, field = 'value') {
	let plaintext = null
	let roundTripped = null

	try {
		// Which SIDE fails decides everything downstream, so the two decrypts
		// are deliberately not interchangeable. Failing here means the existing
		// blob will not open with the key we hold — the value is unrecoverable in
		// this migration. Failing the verify decrypt below means the opposite:
		// the stored value is fine and the NEW key is the problem.
		try {
			plaintext = await rsaDecrypt(ciphertext, keys.oldPrivateKey)
		} catch (e) {
			throw new OldKeyDecryptError(field, String(e?.message || e))
		}

		const reEncrypted = await rsaEncrypt(plaintext, keys.newPublicKey)

		// Decrypt with the NEW private key — the key the user will actually
		// hold after the rotation. Verifying with anything else would prove
		// only that the ciphertext is well-formed, not that it is readable.
		roundTripped = await rsaDecrypt(reEncrypted, keys.newPrivateKey)

		if (bytesEqual(plaintext, roundTripped) === false) {
			throw new RoundTripMismatchError(field)
		}

		return reEncrypted
	} finally {
		// Drop this scope's plaintext references immediately, mirroring
		// src/health/worker.js. Strings cannot be zeroed in JavaScript, so
		// releasing them to the collector as early as possible is the whole
		// of the available mitigation.
		plaintext = null
		roundTripped = null
	}
}

/**
 * Re-encrypt a secret or a secret version.
 *
 * `key` is required; `login` and `additionalFields` are nullable and are left
 * null rather than being encrypted as empty strings — an absent field must
 * stay absent, not become a decryptable empty value.
 *
 * @param {object} record The record's current ciphertext.
 * @param {string} record.key The key blob.
 * @param {string|null} [record.login] The login blob.
 * @param {string|null} [record.additionalFields] The additional-fields blob.
 * @param {object} keys The migration keys (see verifiedReEncrypt).
 * @return {Promise<{key: string, login: string|null, additionalFields: string|null}>}
 *   The verified replacement ciphertext.
 */
export async function reEncryptSecretFields(record, keys) {
	const key = await verifiedReEncrypt(record.key, keys, 'key')

	let login = null
	if (record.login !== null && record.login !== undefined && record.login !== '') {
		login = await verifiedReEncrypt(record.login, keys, 'login')
	}

	let additionalFields = null
	if (
		record.additionalFields !== null
		&& record.additionalFields !== undefined
		&& record.additionalFields !== ''
	) {
		additionalFields = await verifiedReEncrypt(
			record.additionalFields,
			keys,
			'additionalFields',
		)
	}

	return { key, login, additionalFields }
}

/**
 * Re-wrap an attachment grant's file key.
 *
 * The wrapped file key is the raw AES key base64-encoded and then RSA-wrapped
 * (see src/store/modules/attachment.js), so it round-trips through the same
 * verified path as any other field. The extra decode-and-compare below is the
 * design's explicit requirement to compare the FILE-KEY bytes: it catches a
 * base64 that re-encodes differently while still decoding to the same string.
 *
 * @param {object} record The grant's current state.
 * @param {string} record.wrappedFileKey The RSA-wrapped base64 file key.
 * @param {object} keys The migration keys (see verifiedReEncrypt).
 * @return {Promise<{wrappedFileKey: string}>} The verified replacement wrapper.
 * @throws {RoundTripMismatchError} When the recovered file key differs.
 */
export async function reEncryptAttachmentGrant(record, keys) {
	const wrappedFileKey = await verifiedReEncrypt(
		record.wrappedFileKey,
		keys,
		'wrappedFileKey',
	)

	let originalKey = null
	let recoveredKey = null
	try {
		originalKey = await rsaDecrypt(record.wrappedFileKey, keys.oldPrivateKey)
		recoveredKey = await rsaDecrypt(wrappedFileKey, keys.newPrivateKey)

		if (bytesEqual(atob(originalKey), atob(recoveredKey)) === false) {
			throw new RoundTripMismatchError('attachment file key')
		}
	} finally {
		originalKey = null
		recoveredKey = null
	}

	return { wrappedFileKey }
}

/**
 * Re-encrypt one record of any store, reporting failure rather than throwing.
 *
 * A per-record failure MUST NOT stop the run: the caller records it against the
 * owning secret and moves to the next record, leaving this record's original
 * ciphertext and `encryption_suite_id` exactly as they were.
 *
 * @param {object} job The unit of work.
 * @param {string} job.store One of MIGRATION_STORES.
 * @param {string} job.id The record's ID.
 * @param {object} job.record The record's current ciphertext.
 * The outcome carries the classification the caller MUST respect:
 *
 * - `permanent: true` — the existing ciphertext will not open with the old key.
 *   Only these may be reported to the server as a failure, and only these may
 *   ever be finalised as unrecoverable.
 * - `halt: true` — the stored value decrypted fine but the re-encryption does
 *   not round-trip. The new key material or the crypto path is broken, so the
 *   run must stop. Reporting this as a per-record failure would mark a
 *   perfectly readable secret unrecoverable.
 * - neither — transient (network, worker). Retry; report nothing.
 *
 * @param {object} keys The migration keys (see verifiedReEncrypt).
 * @return {Promise<{store: string, id: string, ok: boolean, payload?: object,
 *   error?: string, permanent?: boolean, halt?: boolean, phase?: string}>}
 *   The outcome for this one record.
 */
export async function migrateRecord(job, keys) {
	try {
		if (job.store === MIGRATION_STORES.ATTACHMENT_GRANTS) {
			return {
				store: job.store,
				id: job.id,
				ok: true,
				payload: await reEncryptAttachmentGrant(job.record, keys),
			}
		}

		if (
			job.store === MIGRATION_STORES.SECRETS
			|| job.store === MIGRATION_STORES.VERSIONS
		) {
			return {
				store: job.store,
				id: job.id,
				ok: true,
				payload: await reEncryptSecretFields(job.record, keys),
			}
		}

		return {
			store: job.store,
			id: job.id,
			ok: false,
			permanent: false,
			halt: false,
			phase: 'unknown-store',
			error: `Unknown migration store: ${job.store}`,
		}
	} catch (e) {
		// The message is persisted and shown to the user, so it must describe
		// the failure without carrying any part of the value.
		return {
			store: job.store,
			id: job.id,
			ok: false,
			// Default to NOT permanent. An unclassified error must never cost the
			// user access to a secret, so anything we cannot positively identify
			// as an old-key decrypt failure is treated as retryable.
			permanent: e?.permanent === true,
			halt: e?.halt === true,
			phase: e?.phase ?? 'unclassified',
			error: String(e?.message || e),
		}
	}
}
