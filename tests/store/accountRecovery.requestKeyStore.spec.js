/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * `requestKeyStore`: where the one-time recovery request key waits in this
 * browser's IndexedDB between filing the request and completing it. The
 * browser's `indexedDB` is replaced by a small in-memory double with the
 * same request/transaction callback shape.
 *
 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-a-recovery-request-carries-a-one-time-key-and-a-verification-phrase
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { requestKeyStore } from '../../src/store/modules/accountRecovery.js'

/**
 * An in-memory IndexedDB double: one database of named object stores whose
 * requests and transactions finish asynchronously, like the browser's.
 *
 * @param {object} [options] Failure switches.
 * @param {Error|null} [options.openError] Fail every open with this error.
 * @param {Error|null} [options.writeError] Fail every write transaction with this error.
 * @return {object} The double, with `databases` to inspect.
 */
function fakeIndexedDb({ openError = null, writeError = null } = {}) {
	const databases = new Map()
	const later = (fn) => setTimeout(fn, 0)

	return {
		databases,
		opens: [],
		open(name, version) {
			this.opens.push({ name, version })
			const request = { result: null, error: null }
			later(() => {
				if (openError) {
					request.error = openError
					request.onerror?.()
					return
				}
				const isNew = !databases.has(name)
				if (isNew) {
					databases.set(name, new Map())
				}
				const stores = databases.get(name)
				request.result = {
					createObjectStore(storeName) {
						stores.set(storeName, new Map())
					},
					transaction(storeName, mode = 'readonly') {
						const tx = { error: null }
						const finish = () =>
							later(() => {
								if (mode === 'readwrite' && writeError) {
									tx.error = writeError
									tx.onerror?.()
									return
								}
								tx.oncomplete?.()
							})
						tx.objectStore = () => {
							const store = stores.get(storeName)
							return {
								put(value, key) {
									if (!writeError) {
										store.set(key, value)
									}
									finish()
								},
								delete(key) {
									if (!writeError) {
										store.delete(key)
									}
									finish()
								},
								get(key) {
									const read = { result: undefined }
									later(() => {
										read.result = store.get(key)
										read.onsuccess?.()
									})
									return read
								},
							}
						}
						return tx
					},
				}
				if (isNew) {
					request.onupgradeneeded?.()
				}
				request.onsuccess?.()
			})
			return request
		},
	}
}

describe('requestKeyStore', () => {
	let idb

	beforeEach(() => {
		idb = fakeIndexedDb()
		vi.stubGlobal('indexedDB', idb)
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('opens version 1 of the keepiq-account-recovery database and creates its store', async () => {
		const db = await requestKeyStore.open()

		expect(idb.opens).toEqual([{ name: 'keepiq-account-recovery', version: 1 }])
		expect(typeof db.transaction).toBe('function')
		expect(idb.databases.get('keepiq-account-recovery').has('requests')).toBe(true)
	})

	it('keeps a request key under its request id and reads it back', async () => {
		const value = { privateKey: { kind: 'CryptoKey' }, publicKeyRaw: 'AAEC' }

		await requestKeyStore.put('req-1', value)

		expect(await requestKeyStore.get('req-1')).toBe(value)
		expect(await requestKeyStore.get('req-2')).toBeUndefined()
	})

	it('deletes only the named request key', async () => {
		await requestKeyStore.put('req-1', { publicKeyRaw: 'one' })
		await requestKeyStore.put('req-2', { publicKeyRaw: 'two' })

		await requestKeyStore.delete('req-1')

		expect(await requestKeyStore.get('req-1')).toBeUndefined()
		expect(await requestKeyStore.get('req-2')).toEqual({ publicKeyRaw: 'two' })
	})

	it('rejects with the open error when the database cannot be opened', async () => {
		const blocked = new Error('blocked')
		vi.stubGlobal('indexedDB', fakeIndexedDb({ openError: blocked }))

		await expect(requestKeyStore.open()).rejects.toBe(blocked)
		await expect(requestKeyStore.get('req-1')).rejects.toBe(blocked)
	})

	it('rejects a put whose transaction fails', async () => {
		const full = new Error('quota')
		vi.stubGlobal('indexedDB', fakeIndexedDb({ writeError: full }))

		await expect(requestKeyStore.put('req-1', { publicKeyRaw: 'x' })).rejects.toBe(full)
	})

	it('treats a failed delete as done, so cleanup never blocks the flow', async () => {
		vi.stubGlobal('indexedDB', fakeIndexedDb({ writeError: new Error('quota') }))

		await expect(requestKeyStore.delete('req-1')).resolves.toBeUndefined()
	})
})
