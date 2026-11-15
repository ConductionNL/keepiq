/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Edge cases of `src/migration/driver.js` not covered by
 * migration-driver.spec.js: a Worker constructor that throws, a handshake that
 * answers not-ok or errors, failed batches, message routing by request id and
 * the inline path's periodic yield.
 *
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-re-encrypted-ciphertext-is-verified-before-the-original-is-discarded
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const migrateRecord = vi.fn(async (job) => ({ id: job.id, ok: true }))
vi.mock('../../src/migration/pipeline.js', () => ({
	migrateRecord: (...args) => migrateRecord(...args),
}))

const { createMigrationRunner } = await import('../../src/migration/driver.js')

const KEYS = { oldPrivateKey: 'old', newPublicKey: 'pub', newPrivateKey: 'new' }

/** Whatever `Worker` was before a test replaced it. */
let originalWorker

/**
 * Install a fake Worker whose replies come from `reply(msg, worker)`.
 *
 * @param {Function} reply Called per posted message; may emit events.
 * @return {{terminate: Function, instances: Array<object>}} Recorders.
 */
function installWorker(reply) {
	const terminate = vi.fn()
	const instances = []
	globalThis.Worker = class {
		constructor() {
			this.terminate = terminate
			this.listeners = {}
			this.posted = []
			instances.push(this)
		}

		addEventListener(type, fn) {
			this.listeners[type] = [...(this.listeners[type] || []), fn]
		}

		removeEventListener(type, fn) {
			this.listeners[type] = (this.listeners[type] || []).filter((f) => f !== fn)
		}

		emit(type, event) {
			for (const fn of [...(this.listeners[type] || [])]) {
				fn(event)
			}
		}

		postMessage(msg) {
			this.posted.push(msg)
			setTimeout(() => reply(msg, this), 0)
		}
	}
	return { terminate, instances }
}

/**
 * Reply to init with ok, and to records with whatever `batch` returns.
 *
 * @param {Function} batch Builds the batch reply data from the message.
 * @return {Function} A reply function for installWorker.
 */
function readyThen(batch) {
	return (msg, worker) => {
		if (msg.type === 'init') {
			worker.emit('message', { data: { requestId: msg.requestId, ok: true } })
			return
		}
		worker.emit('message', { data: batch(msg) })
	}
}

beforeEach(() => {
	originalWorker = globalThis.Worker
	migrateRecord.mockClear()
})

afterEach(() => {
	if (originalWorker === undefined) {
		delete globalThis.Worker
	} else {
		globalThis.Worker = originalWorker
	}
})

describe('createMigrationRunner — handshake edges', () => {
	it('falls back inline when the Worker constructor throws', async () => {
		globalThis.Worker = class {
			constructor() {
				throw new Error('blocked by CSP')
			}
		}

		const runner = await createMigrationRunner(KEYS)

		expect(runner.usesWorker).toBe(false)
		expect(await runner.run([{ id: 's-1' }])).toEqual([{ id: 's-1', ok: true }])
	})

	it('falls back and terminates when the handshake answers not ok', async () => {
		const { terminate, instances } = installWorker((msg, worker) => {
			// A message for another request is ignored by the handshake.
			worker.emit('message', { data: { requestId: 'other', ok: true } })
			worker.emit('message', { data: { requestId: msg.requestId, ok: false } })
		})

		const runner = await createMigrationRunner(KEYS)

		expect(runner.usesWorker).toBe(false)
		expect(terminate).toHaveBeenCalledTimes(1)
		expect(instances[0].listeners.message).toEqual([])
		expect(instances[0].listeners.error).toEqual([])
	})

	it('falls back and terminates when the worker raises an error event', async () => {
		const { terminate, instances } = installWorker((msg, worker) => {
			worker.emit('error', new Event('error'))
		})

		const runner = await createMigrationRunner(KEYS)

		expect(runner.usesWorker).toBe(false)
		expect(terminate).toHaveBeenCalledTimes(1)
		expect(instances[0].listeners.message).toEqual([])
		expect(instances[0].listeners.error).toEqual([])
	})
})

describe('createMigrationRunner — worker batches', () => {
	it('numbers batches and resolves each with its own results', async () => {
		const { instances } = installWorker(readyThen((msg) => ({
			requestId: msg.requestId,
			ok: true,
			results: msg.jobs.map((j) => j.id + '@' + msg.requestId),
		})))

		const runner = await createMigrationRunner(KEYS)
		const first = await runner.run([{ id: 'a' }])
		const second = await runner.run([{ id: 'b' }])

		expect(first).toEqual(['a@batch-1'])
		expect(second).toEqual(['b@batch-2'])
		expect(instances[0].posted.slice(1)).toEqual([
			{ requestId: 'batch-1', type: 'records', jobs: [{ id: 'a' }] },
			{ requestId: 'batch-2', type: 'records', jobs: [{ id: 'b' }] },
		])
	})

	it('ignores replies for other requests until its own arrives', async () => {
		installWorker((msg, worker) => {
			if (msg.type === 'init') {
				worker.emit('message', { data: { requestId: msg.requestId, ok: true } })
				return
			}
			worker.emit('message', { data: { requestId: 'batch-99', ok: true, results: ['wrong'] } })
			worker.emit('message', { data: { requestId: msg.requestId, ok: true, results: ['right'] } })
		})

		const runner = await createMigrationRunner(KEYS)

		expect(await runner.run([{ id: 'a' }])).toEqual(['right'])
	})

	it('rejects a failed batch with the worker error', async () => {
		installWorker(readyThen((msg) => ({ requestId: msg.requestId, ok: false, error: 'bad key' })))

		const runner = await createMigrationRunner(KEYS)

		await expect(runner.run([{ id: 'a' }])).rejects.toThrow('bad key')
	})

	it('rejects a failed batch without error text with a generic message', async () => {
		installWorker(readyThen((msg) => ({ requestId: msg.requestId, ok: false })))

		const runner = await createMigrationRunner(KEYS)

		await expect(runner.run([{ id: 'a' }])).rejects.toThrow('Migration batch failed')
	})
})

describe('createMigrationRunner — inline path', () => {
	it('yields to the event loop after every fourth record and keeps order', async () => {
		delete globalThis.Worker
		const timeout = vi.spyOn(globalThis, 'setTimeout')

		try {
			const runner = await createMigrationRunner(KEYS)
			const jobs = Array.from({ length: 9 }, (_, i) => ({ id: 's-' + i }))
			const results = await runner.run(jobs)

			expect(results.map((r) => r.id)).toEqual(jobs.map((j) => j.id))
			const yields = timeout.mock.calls.filter((call) => call[1] === 0)
			expect(yields).toHaveLength(2)
			expect(runner.dispose()).toBeUndefined()
		} finally {
			timeout.mockRestore()
		}
	})
})
