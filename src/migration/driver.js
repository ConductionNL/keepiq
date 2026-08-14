/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Runs the migration pipeline in a worker, falling back to the main thread.
 *
 * `CryptoKey` structured-cloning into a worker has historically been unreliable
 * across engines, so the worker path is feature-detected by actually attempting
 * the handshake rather than by sniffing the user agent. When it fails for any
 * reason — no `Worker` constructor, a `DataCloneError` on postMessage, a worker
 * that never answers — the same pipeline module runs inline in yielding batches.
 * Both paths import `migrateRecord`, so the verification guarantees cannot drift
 * between them.
 *
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-re-encrypted-ciphertext-is-verified-before-the-original-is-discarded
 */

import { migrateRecord } from './pipeline.js'

/**
 * How long to wait for the worker to acknowledge its keys before giving up and
 * running inline. A worker that cannot receive the keys must not stall the
 * migration behind an unresolved promise.
 *
 * @type {number}
 */
const HANDSHAKE_TIMEOUT_MS = 5000

/**
 * How many records the inline fallback processes before yielding to the event
 * loop, so the progress dialog keeps repainting.
 *
 * @type {number}
 */
const INLINE_YIELD_EVERY = 4

/**
 * Yield to the event loop.
 *
 * @return {Promise<void>}
 */
function yieldToEventLoop() {
	return new Promise((resolve) => {
		setTimeout(resolve, 0)
	})
}

/**
 * Try to start a worker and hand it the migration keys.
 *
 * @param {object} keys The migration keys.
 * @return {Promise<Worker|null>} The initialised worker, or null to fall back.
 */
async function tryStartWorker(keys) {
	if (typeof Worker === 'undefined') {
		return null
	}

	let worker = null
	try {
		worker = new Worker(new URL('./worker.js', import.meta.url))
	} catch (e) {
		return null
	}

	const requestId = 'init'

	try {
		const ready = new Promise((resolve, reject) => {
			const onMessage = (event) => {
				if (event.data?.requestId !== requestId) {
					return
				}
				worker.removeEventListener('message', onMessage)
				worker.removeEventListener('error', onError)
				resolve(event.data?.ok === true)
			}
			const onError = () => {
				worker.removeEventListener('message', onMessage)
				worker.removeEventListener('error', onError)
				reject(new Error('Migration worker failed to start'))
			}

			worker.addEventListener('message', onMessage)
			worker.addEventListener('error', onError)

			setTimeout(() => {
				reject(new Error('Migration worker handshake timed out'))
			}, HANDSHAKE_TIMEOUT_MS)
		})

		// A CryptoKey that cannot be cloned throws DataCloneError here,
		// synchronously — this call IS the feature detection.
		worker.postMessage({
			requestId,
			type: 'init',
			oldPrivateKey: keys.oldPrivateKey,
			newPublicKey: keys.newPublicKey,
			newPrivateKey: keys.newPrivateKey,
		})

		const ok = await ready
		if (ok !== true) {
			worker.terminate()
			return null
		}

		return worker
	} catch (e) {
		worker.terminate()
		return null
	}
}

/**
 * Create a runner that re-encrypts batches of records.
 *
 * @param {object} keys The migration keys.
 * @param {CryptoKey} keys.oldPrivateKey Decrypts the existing blobs.
 * @param {CryptoKey} keys.newPublicKey Encrypts the replacements.
 * @param {CryptoKey} keys.newPrivateKey Verifies the replacements.
 * @return {Promise<{usesWorker: boolean, run: Function, dispose: Function}>}
 *   The runner. `run(jobs)` resolves to one result per job, in order.
 */
export async function createMigrationRunner(keys) {
	const worker = await tryStartWorker(keys)

	if (worker === null) {
		return {
			usesWorker: false,

			/**
			 * Re-encrypt a batch inline, yielding periodically.
			 *
			 * @param {Array<object>} jobs The records to process.
			 * @return {Promise<Array<object>>} One result per job.
			 */
			async run(jobs) {
				const results = []
				for (let i = 0; i < jobs.length; i++) {
					results.push(await migrateRecord(jobs[i], keys))
					if ((i + 1) % INLINE_YIELD_EVERY === 0) {
						await yieldToEventLoop()
					}
				}
				return results
			},

			/**
			 * Nothing to tear down on the inline path.
			 *
			 * @return {void}
			 */
			dispose() {},
		}
	}

	let batchCounter = 0

	return {
		usesWorker: true,

		/**
		 * Re-encrypt a batch in the worker.
		 *
		 * @param {Array<object>} jobs The records to process.
		 * @return {Promise<Array<object>>} One result per job.
		 */
		run(jobs) {
			batchCounter += 1
			const requestId = `batch-${batchCounter}`

			return new Promise((resolve, reject) => {
				const onMessage = (event) => {
					if (event.data?.requestId !== requestId) {
						return
					}
					worker.removeEventListener('message', onMessage)
					if (event.data.ok === true) {
						resolve(event.data.results)
					} else {
						reject(
							new Error(event.data.error || 'Migration batch failed'),
						)
					}
				}

				worker.addEventListener('message', onMessage)
				worker.postMessage({ requestId, type: 'records', jobs })
			})
		},

		/**
		 * Terminate the worker, dropping its key references.
		 *
		 * @return {void}
		 */
		dispose() {
			worker.terminate()
		},
	}
}
