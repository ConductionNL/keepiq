/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Dedicated web worker for compromise-recovery re-encryption.
 *
 * RSA-4096 OAEP over thousands of 446-byte chunks is CPU-bound; run on the main
 * thread it freezes the very dialog that is supposed to be showing progress.
 * The driver posts the three keys once in an `init` message, then posts one
 * `records` batch per page of work and receives one result per record.
 *
 * The keys arrive as structured-cloned `CryptoKey` objects. Cloning preserves
 * `extractable: false`, so the raw key material is never exposed to JavaScript
 * in either context — the worker can use the keys and cannot read them. The
 * master password is deliberately NOT sent: deriving the key here would put it
 * into a second execution context for no benefit.
 *
 * The store terminates this worker on vault lock, so no key reference survives
 * a locked vault.
 *
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-re-encrypted-ciphertext-is-verified-before-the-original-is-discarded
 */

import { migrateRecord } from './pipeline.js'

/**
 * The migration keys, held for the worker's lifetime.
 *
 * @type {{oldPrivateKey: CryptoKey, newPublicKey: CryptoKey, newPrivateKey: CryptoKey}|null}
 */
let keys = null

self.addEventListener('message', async (event) => {
	const data = event.data || {}
	const requestId = data.requestId

	if (data.type === 'init') {
		// Held rather than acknowledged-and-discarded: one clone per migration,
		// not one per batch.
		keys = {
			oldPrivateKey: data.oldPrivateKey,
			newPublicKey: data.newPublicKey,
			newPrivateKey: data.newPrivateKey,
		}
		self.postMessage({ requestId, type: 'ready', ok: true })
		return
	}

	if (data.type === 'records') {
		if (keys === null) {
			self.postMessage({
				requestId,
				type: 'batch',
				ok: false,
				error: 'Migration worker received records before its keys',
			})
			return
		}

		let jobs = data.jobs || []
		try {
			const results = []
			for (const job of jobs) {
				// Sequential on purpose: this worker is already off the main
				// thread and the concurrency that matters (overlapping the
				// network) is managed by the driver's in-flight window.
				// eslint-disable-next-line no-await-in-loop
				results.push(await migrateRecord(job, keys))
			}

			self.postMessage({ requestId, type: 'batch', ok: true, results })
		} catch (e) {
			self.postMessage({
				requestId,
				type: 'batch',
				ok: false,
				error: String(e?.message || e),
			})
		} finally {
			// Drop this message scope's ciphertext references. The per-record
			// plaintext is already nulled inside the pipeline's finally block.
			jobs = null
		}

		return
	}

	if (data.type === 'dispose') {
		keys = null
		self.postMessage({ requestId, type: 'disposed', ok: true })
	}
})
