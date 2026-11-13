/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for the message protocol of `src/migration/worker.js`.
 *
 * The worker is loaded fresh per test with a stand-in `self` that records the
 * message listener and every reply. The pipeline is stubbed: what is under
 * test is the protocol (init, records, dispose), not the crypto.
 *
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-re-encrypted-ciphertext-is-verified-before-the-original-is-discarded
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const migrateRecord = vi.fn()
vi.mock('../../src/migration/pipeline.js', () => ({
	migrateRecord: (...args) => migrateRecord(...args),
}))

const KEYS = { oldPrivateKey: 'old', newPublicKey: 'pub', newPrivateKey: 'new' }

/** The `self` global as it was before a test replaced it. */
let originalSelf

/** Replies the worker posted. */
let posted

/** The worker's message listener. */
let onMessage

/**
 * Import a fresh copy of the worker against a recording `self`.
 *
 * @return {Promise<void>}
 */
async function loadWorker() {
	vi.resetModules()
	posted = []
	onMessage = null
	globalThis.self = {
		addEventListener: (type, fn) => {
			if (type === 'message') {
				onMessage = fn
			}
		},
		postMessage: (msg) => posted.push(msg),
	}
	await import('../../src/migration/worker.js')
}

/**
 * Deliver one message to the worker and wait for its handler.
 *
 * @param {object|undefined} data The message payload.
 * @return {Promise<void>}
 */
function send(data) {
	return onMessage({ data })
}

beforeEach(() => {
	originalSelf = globalThis.self
	migrateRecord.mockReset()
})

afterEach(() => {
	if (originalSelf === undefined) {
		delete globalThis.self
	} else {
		globalThis.self = originalSelf
	}
})

describe('migration worker protocol', () => {
	it('acknowledges init with ready under the same request id', async () => {
		await loadWorker()

		await send({ requestId: 'init', type: 'init', ...KEYS })

		expect(posted).toEqual([{ requestId: 'init', type: 'ready', ok: true }])
	})

	it('refuses records that arrive before the keys', async () => {
		await loadWorker()

		await send({ requestId: 'batch-1', type: 'records', jobs: [{ id: 's-1' }] })

		expect(posted).toEqual([{
			requestId: 'batch-1',
			type: 'batch',
			ok: false,
			error: 'Migration worker received records before its keys',
		}])
		expect(migrateRecord).not.toHaveBeenCalled()
	})

	it('migrates each job in order with the keys from init', async () => {
		await loadWorker()
		migrateRecord.mockImplementation(async (job) => ({ id: job.id, ok: true }))

		await send({ requestId: 'init', type: 'init', ...KEYS, extra: 'ignored' })
		await send({
			requestId: 'batch-1',
			type: 'records',
			jobs: [{ id: 's-1' }, { id: 's-2' }],
		})

		expect(migrateRecord).toHaveBeenCalledTimes(2)
		expect(migrateRecord.mock.calls[0][0]).toEqual({ id: 's-1' })
		expect(migrateRecord.mock.calls[1][0]).toEqual({ id: 's-2' })
		// Only the three keys are held, nothing else from the init message.
		expect(migrateRecord.mock.calls[0][1]).toEqual(KEYS)
		expect(posted[1]).toEqual({
			requestId: 'batch-1',
			type: 'batch',
			ok: true,
			results: [{ id: 's-1', ok: true }, { id: 's-2', ok: true }],
		})
	})

	it('answers a records message without jobs with an empty result', async () => {
		await loadWorker()

		await send({ requestId: 'init', type: 'init', ...KEYS })
		await send({ requestId: 'batch-1', type: 'records' })

		expect(posted[1]).toEqual({ requestId: 'batch-1', type: 'batch', ok: true, results: [] })
	})

	it('reports a pipeline exception as a failed batch with its message', async () => {
		await loadWorker()
		migrateRecord.mockRejectedValueOnce(new Error('boom'))

		await send({ requestId: 'init', type: 'init', ...KEYS })
		await send({ requestId: 'batch-1', type: 'records', jobs: [{ id: 's-1' }] })

		expect(posted[1]).toEqual({ requestId: 'batch-1', type: 'batch', ok: false, error: 'boom' })
	})

	it('stringifies a thrown value that is not an Error', async () => {
		await loadWorker()
		migrateRecord.mockRejectedValueOnce('plain failure')

		await send({ requestId: 'init', type: 'init', ...KEYS })
		await send({ requestId: 'batch-2', type: 'records', jobs: [{ id: 's-1' }] })

		expect(posted[1]).toEqual({ requestId: 'batch-2', type: 'batch', ok: false, error: 'plain failure' })
	})

	it('drops the keys on dispose, so later records are refused', async () => {
		await loadWorker()

		await send({ requestId: 'init', type: 'init', ...KEYS })
		await send({ requestId: 'bye', type: 'dispose' })
		await send({ requestId: 'batch-1', type: 'records', jobs: [{ id: 's-1' }] })

		expect(posted[1]).toEqual({ requestId: 'bye', type: 'disposed', ok: true })
		expect(posted[2]).toMatchObject({ requestId: 'batch-1', ok: false })
		expect(migrateRecord).not.toHaveBeenCalled()
	})

	it('ignores unknown and empty messages', async () => {
		await loadWorker()

		await send({ requestId: 'x', type: 'unknown' })
		await send(undefined)

		expect(posted).toEqual([])
	})
})
