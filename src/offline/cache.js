/**
 * IndexedDB persistence for the offline read-only cache
 * (offline-readonly-cache §2.1). A thin, feature-detected wrapper around
 * one per-user object store holding a single at-rest snapshot (assembled
 * and encrypted by src/offline/snapshot.js). Where IndexedDB is
 * unavailable (e.g. private-browsing modes that disable it) every call
 * degrades to a no-op / null so the app falls back to online-only —
 * never a hard error.
 *
 * @spec openspec/specs/offline-readonly-cache/spec.md#requirement-online-sessions-write-through-an-encrypted-local-snapshot
 * @spec openspec/specs/offline-readonly-cache/spec.md#requirement-the-cache-is-evicted-on-lock-logout-and-suite-rotation
 */

const DB_NAME = 'doriath-offline'
const DB_VERSION = 1
const STORE = 'snapshot'
const SNAPSHOT_KEY = 'current'

/**
 * Whether IndexedDB is usable in this context.
 *
 * @return {boolean}
 */
export function isCacheAvailable() {
	return typeof indexedDB !== 'undefined' && indexedDB !== null
}

/**
 * Open (and lazily create) the per-user snapshot database.
 *
 * @return {Promise<IDBDatabase>}
 */
function openDb() {
	return new Promise((resolve, reject) => {
		const request = indexedDB.open(DB_NAME, DB_VERSION)
		request.onupgradeneeded = () => {
			const db = request.result
			if (!db.objectStoreNames.contains(STORE)) {
				db.createObjectStore(STORE)
			}
		}
		request.onsuccess = () => resolve(request.result)
		request.onerror = () => reject(request.error)
	})
}

/**
 * Atomically replace the stored snapshot in a single transaction.
 *
 * @param {object} snapshot The at-rest snapshot from encryptSnapshot().
 * @return {Promise<boolean>} True on success; false when caching is unavailable.
 */
export async function writeSnapshot(snapshot) {
	if (!isCacheAvailable()) {
		return false
	}
	const db = await openDb()
	try {
		await new Promise((resolve, reject) => {
			const tx = db.transaction(STORE, 'readwrite')
			const store = tx.objectStore(STORE)
			store.put(snapshot, SNAPSHOT_KEY)
			tx.oncomplete = () => resolve()
			tx.onerror = () => reject(tx.error)
			tx.onabort = () => reject(tx.error)
		})
		return true
	} finally {
		db.close()
	}
}

/**
 * Read the last committed snapshot, or null when none/unavailable.
 *
 * @return {Promise<object|null>}
 */
export async function readSnapshot() {
	if (!isCacheAvailable()) {
		return null
	}
	const db = await openDb()
	try {
		return await new Promise((resolve, reject) => {
			const tx = db.transaction(STORE, 'readonly')
			const request = tx.objectStore(STORE).get(SNAPSHOT_KEY)
			request.onsuccess = () => resolve(request.result ?? null)
			request.onerror = () => reject(request.error)
		})
	} finally {
		db.close()
	}
}

/**
 * Evict the snapshot (lock / logout / rotation / admin-disable, D4).
 *
 * @return {Promise<void>}
 */
export async function purge() {
	if (!isCacheAvailable()) {
		return
	}
	const db = await openDb()
	try {
		await new Promise((resolve, reject) => {
			const tx = db.transaction(STORE, 'readwrite')
			tx.objectStore(STORE).delete(SNAPSHOT_KEY)
			tx.oncomplete = () => resolve()
			tx.onerror = () => reject(tx.error)
		})
	} finally {
		db.close()
	}
}
