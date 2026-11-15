/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for `src/offline/cache.js`, the IndexedDB layer under the offline
 * snapshot and the offline edit queue.
 *
 * IndexedDB is the browser boundary, so it is replaced by a small in-memory
 * fake that keeps the event-callback shape the module relies on (request
 * onsuccess / onerror / onupgradeneeded, transaction oncomplete / onerror).
 * What is pinned: the snapshot round-trip under one key, purge evicting the
 * legacy database as well as the snapshot (and surviving a blocked legacy
 * delete), the queue keyed on `entryId`, every database being closed after
 * use, and the behaviour where no IndexedDB exists at all.
 *
 * @spec openspec/specs/offline-readonly-cache/spec.md#requirement-the-app-shell-loads-offline-via-a-service-worker
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	clearQueue,
	deleteQueueEntry,
	isCacheAvailable,
	purge,
	putQueueEntry,
	readQueue,
	readSnapshot,
	writeSnapshot,
} from '../../src/offline/cache.js'

/**
 * An in-memory IndexedDB with just the surface cache.js touches.
 *
 * @return {object} The fake factory plus inspection hooks.
 */
function createFakeIndexedDb() {
	const databases = new Map()
	const fake = {
		databases,
		opened: [],
		deleted: [],
		failOpen: null,
		failTransaction: null,
		deleteOutcome: 'success',
	}

	const later = (fn) => setTimeout(fn, 0)

	/**
	 * @param {object} data The database contents.
	 * @return {object} A fake IDBDatabase.
	 */
	function makeDb(data) {
		const db = {
			closed: false,
			objectStoreNames: {
				contains: (name) => data.stores.has(name),
			},
			createObjectStore(name, options = {}) {
				data.stores.set(name, { keyPath: options.keyPath, rows: new Map() })
			},
			close() {
				db.closed = true
			},
			transaction(name, mode) {
				const tx = { error: null, mode }
				const store = data.stores.get(name)
				const request = (fn) => {
					const req = { result: undefined }
					try {
						req.result = fn()
					} catch (e) {
						tx.error = e
					}
					later(() => req.onsuccess && req.onsuccess())
					return req
				}
				tx.objectStore = () => ({
					put(value, key) {
						if (mode !== 'readwrite') throw new Error('ReadOnlyError')
						const k = store.keyPath ? value[store.keyPath] : key
						return request(() => store.rows.set(k, structuredClone(value)))
					},
					get: (key) => request(() => store.rows.get(key)),
					getAll: () => request(() => [...store.rows.values()]),
					delete: (key) => request(() => store.rows.delete(key)),
					clear: () => request(() => store.rows.clear()),
				})
				later(() => {
					if (fake.failTransaction) {
						tx.error = fake.failTransaction
					}
					if (tx.error) {
						tx.onerror && tx.onerror()
					} else {
						tx.oncomplete && tx.oncomplete()
					}
				})
				return tx
			},
		}
		return db
	}

	fake.factory = {
		open(name, version) {
			const req = {}
			later(() => {
				if (fake.failOpen) {
					req.error = fake.failOpen
					req.onerror()
					return
				}
				let data = databases.get(name)
				const upgrade = !data || data.version < version
				if (!data) {
					data = { version, stores: new Map() }
					databases.set(name, data)
				}
				req.result = makeDb(data)
				fake.opened.push(req.result)
				if (upgrade) {
					data.version = version
					req.onupgradeneeded()
				}
				req.onsuccess()
			})
			return req
		},
		deleteDatabase(name) {
			const req = {}
			fake.deleted.push(name)
			later(() => {
				if (fake.deleteOutcome === 'success') {
					databases.delete(name)
				}
				const handler = req['on' + fake.deleteOutcome]
				handler && handler()
			})
			return req
		},
	}
	return fake
}

describe('offline cache', () => {
	let fake

	beforeEach(() => {
		fake = createFakeIndexedDb()
		vi.stubGlobal('indexedDB', fake.factory)
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('reports availability by the presence of IndexedDB', () => {
		expect(isCacheAvailable()).toBe(true)

		vi.stubGlobal('indexedDB', null)
		expect(isCacheAvailable()).toBe(false)
	})

	it('creates both stores on first open, at version 2', async () => {
		await readSnapshot()

		const data = fake.databases.get('keepiq-offline')
		expect(data.version).toBe(2)
		expect([...data.stores.keys()]).toEqual(['snapshot', 'queue'])
		expect(data.stores.get('queue').keyPath).toBe('entryId')
	})

	it('reads null before anything is written', async () => {
		await expect(readSnapshot()).resolves.toBeNull()
	})

	it('round-trips one snapshot, replacing the previous one', async () => {
		await expect(writeSnapshot({ secrets: ['a'] })).resolves.toBe(true)
		await writeSnapshot({ secrets: ['b'] })

		await expect(readSnapshot()).resolves.toEqual({ secrets: ['b'] })
		expect([
			...fake.databases.get('keepiq-offline').stores.get('snapshot').rows.keys(),
		]).toEqual(['current'])
	})

	it('closes every database it opens', async () => {
		await writeSnapshot({})
		await readSnapshot()
		await readQueue()

		expect(fake.opened).toHaveLength(3)
		expect(fake.opened.every((db) => db.closed)).toBe(true)
	})

	it('rejects and still closes when a write transaction fails', async () => {
		fake.failTransaction = new Error('QuotaExceededError')

		await expect(writeSnapshot({})).rejects.toThrow('QuotaExceededError')
		expect(fake.opened[0].closed).toBe(true)
	})

	it('rejects when the database cannot be opened', async () => {
		fake.failOpen = new Error('VersionError')

		await expect(readSnapshot()).rejects.toThrow('VersionError')
	})

	it('purges the snapshot and the pre-rename database', async () => {
		await writeSnapshot({ secrets: ['a'] })
		fake.databases.set('doriath-offline', { version: 1, stores: new Map() })

		await purge()

		expect(fake.deleted).toEqual(['doriath-offline'])
		expect(fake.databases.has('doriath-offline')).toBe(false)
		await expect(readSnapshot()).resolves.toBeNull()
	})

	it.each(['error', 'blocked'])(
		'still evicts the snapshot when the legacy delete is %s',
		async (outcome) => {
			await writeSnapshot({ secrets: ['a'] })
			fake.deleteOutcome = outcome

			await purge()

			await expect(readSnapshot()).resolves.toBeNull()
		},
	)

	it('stores queue entries by entryId and replaces on the same id', async () => {
		await putQueueEntry({ entryId: 'e1', op: 'update', v: 1 })
		await putQueueEntry({ entryId: 'e2', op: 'delete' })
		await putQueueEntry({ entryId: 'e1', op: 'update', v: 2 })

		await expect(readQueue()).resolves.toEqual([
			{ entryId: 'e1', op: 'update', v: 2 },
			{ entryId: 'e2', op: 'delete' },
		])
	})

	it('deletes one queue entry and clears the rest', async () => {
		await putQueueEntry({ entryId: 'e1' })
		await putQueueEntry({ entryId: 'e2' })
		await putQueueEntry({ entryId: 'e3' })

		await deleteQueueEntry('e2')
		await expect(readQueue()).resolves.toEqual([
			{ entryId: 'e1' },
			{ entryId: 'e3' },
		])

		await clearQueue()
		await expect(readQueue()).resolves.toEqual([])
	})

	it('rejects a queue write when the transaction aborts', async () => {
		fake.failTransaction = new Error('AbortError')

		await expect(putQueueEntry({ entryId: 'e1' })).rejects.toThrow('AbortError')
	})

	describe('without IndexedDB', () => {
		beforeEach(() => {
			vi.stubGlobal('indexedDB', undefined)
		})

		it('degrades every read and write to a no-op', async () => {
			await expect(writeSnapshot({})).resolves.toBe(false)
			await expect(readSnapshot()).resolves.toBeNull()
			await expect(purge()).resolves.toBeUndefined()
			await expect(readQueue()).resolves.toEqual([])
			await expect(deleteQueueEntry('e1')).resolves.toBeUndefined()
			await expect(clearQueue()).resolves.toBeUndefined()
		})

		it('refuses to queue an edit it cannot keep', async () => {
			await expect(putQueueEntry({ entryId: 'e1' })).rejects.toThrow(
				'Offline storage is not available in this browser',
			)
		})
	})
})
