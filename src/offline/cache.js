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

const DB_NAME = 'keepiq-offline'

/* The pre-rename database name. A browser that ran the doriath build still
   holds an encrypted vault snapshot under it, and renaming the database
   alone would strand that data: purge() would evict the new database while
   the old one survives every lock, logout and rotation, unreachable by this
   code. purge() therefore deletes it too. Remove this once no client can
   still be carrying a pre-rename snapshot. */
const LEGACY_DB_NAME = 'doriath-offline'

// Version 2 adds the offline edit queue (offline-edit-queue).
const DB_VERSION = 2
const STORE = 'snapshot'
const QUEUE_STORE = 'queue'
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
			if (!db.objectStoreNames.contains(QUEUE_STORE)) {
				db.createObjectStore(QUEUE_STORE, { keyPath: 'entryId' })
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

	/* Drop the pre-rename database first, and never let its failure stop the
	   real eviction below: a browser with no legacy database is the normal
	   case, and a blocked delete (another tab holding it open) must not leave
	   the current snapshot in place. */
	try {
		await new Promise((resolve) => {
			const req = indexedDB.deleteDatabase(LEGACY_DB_NAME)
			req.onsuccess = () => resolve()
			req.onerror = () => resolve()
			req.onblocked = () => resolve()
		})
	} catch {
		// Legacy eviction is best-effort; the current snapshot still evicts.
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

/**
 * Run one transaction on the queue store.
 *
 * @param {string} mode readonly or readwrite.
 * @param {function(IDBObjectStore): (IDBRequest|void)} work The work; a returned request's result resolves.
 * @return {Promise<*>}
 */
async function onQueue(mode, work) {
	const db = await openDb()
	try {
		return await new Promise((resolve, reject) => {
			const tx = db.transaction(QUEUE_STORE, mode)
			const request = work(tx.objectStore(QUEUE_STORE))
			tx.oncomplete = () => resolve(request ? request.result : undefined)
			tx.onerror = () => reject(tx.error)
			tx.onabort = () => reject(tx.error)
		})
	} finally {
		db.close()
	}
}

/**
 * Every stored queue entry (sealed), in no particular order.
 *
 * @return {Promise<Array<object>>}
 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-offline-changes-go-into-a-sealed-local-queue
 */
export async function readQueue() {
	if (!isCacheAvailable()) {
		return []
	}
	return (await onQueue('readonly', (store) => store.getAll())) || []
}

/**
 * Store or replace one sealed queue entry.
 *
 * @param {object} entry The sealed entry (see src/offline/queue.js).
 * @return {Promise<void>}
 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-offline-changes-go-into-a-sealed-local-queue
 */
export async function putQueueEntry(entry) {
	if (!isCacheAvailable()) {
		throw new Error('Offline storage is not available in this browser')
	}
	await onQueue('readwrite', (store) => {
		store.put(entry)
	})
}

/**
 * Remove one queue entry.
 *
 * @param {string} entryId The entry id.
 * @return {Promise<void>}
 */
export async function deleteQueueEntry(entryId) {
	if (!isCacheAvailable()) {
		return
	}
	await onQueue('readwrite', (store) => {
		store.delete(entryId)
	})
}

/**
 * Remove every queue entry (offline caching switched off, after replay).
 *
 * @return {Promise<void>}
 */
export async function clearQueue() {
	if (!isCacheAvailable()) {
		return
	}
	await onQueue('readwrite', (store) => {
		store.clear()
	})
}
