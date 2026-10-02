import axios from '@nextcloud/axios'
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Offline read-only cache store (offline-readonly-cache §2/§4).
 *
 * Orchestrates the encrypted IndexedDB snapshot: a write-through refresh on
 * each online unlock, an offline unlock + read served entirely from cache,
 * the stale-data banner state, and deterministic eviction on lock / logout /
 * rotation / admin-disable. The master password never leaves the browser;
 * cached ciphertext is as safe as the server copy and plaintext metadata is
 * encrypted at rest under the vault unlock key.
 */
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import { decryptPrivateKey } from '../../crypto/aes.js'
import { importPrivateKey, rsaDecrypt } from '../../crypto/rsa.js'
import {
	clearQueue,
	deleteQueueEntry,
	isCacheAvailable,
	purge,
	putQueueEntry,
	readQueue,
	readSnapshot,
	writeSnapshot,
} from '../../offline/cache.js'
import {
	applyQueue,
	classifyReplayError,
	coalesce,
	openEntry,
	replayOrder,
	sealEntry,
} from '../../offline/queue.js'
import { decryptSnapshot, encryptSnapshot } from '../../offline/snapshot.js'
import { onVaultLock, useSessionStore } from './session.js'

/** The fields whose change fans out to recipients. */
const SENSITIVE = ['key', 'login', 'additionalFields']

let lockHookRegistered = false

export const useOfflineStore = defineStore('offline', {
	state: () => ({
		/** @type {boolean} Whether the browser reports a network connection. */
		online: typeof navigator === 'undefined' ? true : navigator.onLine,
		/** @type {boolean} Whether the current data was served from the cache. */
		servedFromCache: false,
		/** @type {string|null} syncedAt of the served snapshot (stale banner). */
		syncedAt: null,
		/** @type {object|null} The decrypted offline vault {secrets, folders}. */
		vault: null,
		/** @type {boolean} Whether offline caching is available + enabled. */
		available: isCacheAvailable(),
		/** @type {boolean} Whether the administrator allows offline edits (from the snapshot). */
		editsEnabled: false,
		/** @type {Array<object>} The opened queue entries (offline-edit-queue). */
		entries: [],
		/** @type {Array<object>} Stored entries sealed under another suite, waiting for the previous password. */
		foreignEntries: [],
		/** @type {Object<string, object>} entryId → the server row a 409 returned (memory only). */
		conflicts: {},
		/** @type {boolean} Whether a replay is running. */
		replaying: false,
	}),

	getters: {
		/**
		 * Read-only whenever data is served from the offline cache.
		 *
		 * @param state
		 */
		readOnly: (state) => state.servedFromCache,

		/**
		 * Whether secret creates, edits, moves and deletes go into the queue:
		 * served from the cache AND the administrator allows offline edits.
		 *
		 * @param state
		 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-offline-changes-go-into-a-sealed-local-queue
		 */
		editsQueued: (state) => state.servedFromCache && state.editsEnabled,

		/**
		 * Entries not yet on the server (every state but failed).
		 *
		 * @param state
		 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-pending-changes-block-logout-and-rotation
		 */
		pendingCount: (state) =>
			state.entries.filter((e) => e.status !== 'failed').length
			+ state.foreignEntries.length,

		/**
		 * Entries the server refused (403/404), kept for copy or discard.
		 *
		 * @param state
		 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-failed-entries-are-kept-never-dropped-silently
		 */
		failedEntries: (state) => state.entries.filter((e) => e.status === 'failed'),

		/**
		 * Entries waiting for the user's choice after a 409.
		 *
		 * @param state
		 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
		 */
		conflictEntries: (state) =>
			state.entries.filter((e) => e.status === 'conflict'),
	},

	actions: {
		/**
		 * Register the lock hook once. On vault lock (explicit, timeout, or
		 * tab-close) the in-memory decrypted vault + banner state are cleared
		 * for security — but the ENCRYPTED at-rest snapshot is deliberately
		 * NOT purged here.
		 *
		 * Design note (divergence from D4, decided under live verification):
		 * a literal "purge on every lock" makes offline unlock impossible,
		 * because `beforeunload`/timeout both call `session.lock()` — so a
		 * routine tab close would wipe the snapshot before the user could ever
		 * reopen offline. The snapshot is safe at rest (secret values are RSA
		 * ciphertext; metadata is AES-GCM-encrypted under the master-password
		 * key), so it persists across lock/reload and is evicted only on the
		 * key-lifecycle events that truly invalidate it: logout, suite
		 * rotation / compromise recovery, and admin-disable (see `evict()`).
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/offline-readonly-cache/spec.md#requirement-the-cache-is-evicted-on-lock-logout-and-suite-rotation
		 */
		ensureLockHook() {
			if (lockHookRegistered) {
				return
			}
			lockHookRegistered = true
			onVaultLock(() => {
				// Clear only the in-memory view; the at-rest snapshot survives so
				// the user can unlock offline on the next (possibly offline) load.
				this.servedFromCache = false
				this.vault = null
			})
		},

		/**
		 * Track online/offline transitions.
		 *
		 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-sharing-and-membership-actions-stay-online-only
		 */
		bindConnectivity() {
			if (typeof window === 'undefined') {
				return
			}
			window.addEventListener('online', () => {
				this.online = true
				// Replay pending offline changes as soon as the connection is back
				// (offline-edit-queue D6); a no-op unless unlocked online.
				this.replayQueue().catch(() => {})
			})
			window.addEventListener('offline', () => {
				this.online = false
			})
		},

		/**
		 * Write-through refresh: fetch the consolidated manifest and commit an
		 * encrypted snapshot atomically. Called after a successful ONLINE
		 * unlock. Fail-soft — a cache write failure never breaks the session.
		 *
		 * @return {Promise<boolean>} Whether a snapshot was written.
		 * @spec openspec/specs/offline-readonly-cache/spec.md#requirement-online-sessions-write-through-an-encrypted-local-snapshot
		 */
		async syncNow() {
			this.ensureLockHook()
			const session = useSessionStore()
			if (!this.available || session.aesKey === null) {
				return false
			}
			// Pending offline changes go first; the snapshot is only replaced
			// once no entry still needs the old suite envelope (D6).
			await this.replayQueue().catch(() => {})
			if (this.foreignEntries.length > 0) {
				return false
			}
			try {
				const response = await axios.get(
					generateUrl('/apps/keepiq/api/v1/offline/manifest'),
				)
				const snapshot = await encryptSnapshot(session.aesKey, response.data)
				const written = await writeSnapshot(snapshot)
				if (written) {
					this.servedFromCache = false
					this.syncedAt = response.data.syncedAt
				}
				return written
			} catch (e) {
				if (e?.response?.status === 403) {
					// Admin disabled offline caching org-wide: the queue was
					// replayed above; purge the snapshot and what is left.
					await purge().catch(() => {})
					if (this.pendingCount === 0) {
						await clearQueue().catch(() => {})
					}
				}
				return false
			}
		},

		/**
		 * Offline unlock: read the cached suite blob, derive the key from the
		 * master password + cached KDF params, and open the vault read-only
		 * with NO network request. Then decrypt the cached metadata for listing.
		 *
		 * @param {string} masterPassword The master password (never leaves the browser).
		 * @return {Promise<boolean>} Whether the offline vault opened.
		 *
		 * @spec openspec/specs/offline-readonly-cache/spec.md#requirement-offline-unlock-re-derives-the-master-key-locally
		 */
		async unlockOffline(masterPassword) {
			this.ensureLockHook()
			const snapshot = await readSnapshot()
			if (!snapshot || !snapshot.suite?.privateKey) {
				throw new Error('No offline snapshot available')
			}
			const session = useSessionStore()
			await session.unlockFromBlob({
				privateKeyEnvelope: snapshot.suite.privateKey,
				certificate: snapshot.suite.certificate,
				suiteId: snapshot.suite.id,
				masterPassword,
			})
			this.vault = await decryptSnapshot(session.aesKey, snapshot)
			this.editsEnabled = this.vault.offlineEditsEnabled === true
			this.servedFromCache = true
			this.syncedAt = snapshot.syncedAt
			await this.loadQueue()
			this.vault.secrets = applyQueue(this.vault.secrets, this.entries)
			return true
		},

		/**
		 * Open the stored queue with the in-memory private key. Entries sealed
		 * under another suite (a rotation ran elsewhere) are kept apart until
		 * the previous master password reopens them.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-offline-changes-go-into-a-sealed-local-queue
		 */
		async loadQueue() {
			const session = useSessionStore()
			const stored = await readQueue().catch(() => [])
			const entries = []
			const foreign = []
			for (const entry of stored) {
				if (entry.suiteId !== session.suiteId) {
					foreign.push(entry)
					continue
				}
				entries.push(await openEntry(entry, session.cryptoKey))
			}
			this.entries = replayOrder(entries)
			this.foreignEntries = foreign
		},

		/**
		 * Queue one change made offline (offline-edit-queue D1/D4). The body
		 * holds what an online save would send: own-certificate ciphertext and
		 * the index fields. It is sealed before it is stored.
		 *
		 * @param {object} change { op, secretId, body, baseUpdatedAt }
		 * @return {Promise<object>} The kept entry, or null when it cancelled out.
		 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-offline-changes-go-into-a-sealed-local-queue
		 */
		async enqueue(change) {
			if (!this.editsQueued) {
				throw new Error(
					t(
						'keepiq',
						'Keepiq is read-only offline. Your administrator has not turned on offline edits.',
					),
				)
			}
			const session = useSessionStore()
			const existing = this.entries.find(
				(e) => e.secretId === change.secretId && e.status !== 'failed',
			)
			const incoming = {
				op: change.op,
				secretId: change.secretId,
				baseUpdatedAt: change.baseUpdatedAt ?? null,
				suiteId: session.suiteId,
				status: 'queued',
				body: change.body || {},
			}
			const kept = coalesce(existing || null, incoming)
			if (existing && (!kept || kept.entryId !== existing.entryId)) {
				await deleteQueueEntry(existing.entryId)
			}
			this.entries = this.entries.filter((e) => e !== existing)
			if (kept) {
				const stored = await sealEntry(kept, session.certificate)
				await putQueueEntry(stored)
				this.entries.push({
					...kept,
					entryId: stored.entryId,
					queuedAt: stored.queuedAt,
				})
			}
			if (this.vault) {
				const base = this.vault.secrets.filter(
					(s) => s.id !== change.secretId,
				)
				const current = this.vault.secrets.find(
					(s) => s.id === change.secretId,
				)
				const withThis = kept
					? applyQueue(current ? [current] : [], [kept])
					: []
				this.vault.secrets = [...base, ...withThis]
			}
			return kept
		},

		/**
		 * Decrypt a queued body's sensitive fields with the private key, for
		 * the recipient fan-out, the conflict dialog and copying.
		 *
		 * @param {object} body The entry body (ciphertext).
		 * @return {Promise<object>} { key, login, additionalFields } in plain, only those present.
		 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-replay-runs-through-the-online-paths-with-a-fresh-fan-out
		 */
		async plaintextOf(body) {
			const session = useSessionStore()
			const out = {}
			for (const field of SENSITIVE) {
				if (
					body[field] !== undefined
					&& body[field] !== null
					&& body[field] !== ''
				) {
					out[field] = await rsaDecrypt(body[field], session.cryptoKey)
				} else if (body[field] !== undefined) {
					out[field] = body[field]
				}
			}
			return out
		},

		/**
		 * Replay the queue oldest first through the online paths (D2, D5).
		 * Runs only online with the vault unlocked online. A 409 stops that
		 * entry for the user's choice, 403 and 404 move it to the failed
		 * list, a 423 or a network error stops the replay and keeps the rest.
		 *
		 * @return {Promise<{synced: number, stopped: boolean}>}
		 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-replay-runs-through-the-online-paths-with-a-fresh-fan-out
		 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-failed-entries-are-kept-never-dropped-silently
		 */
		async replayQueue() {
			const session = useSessionStore()
			if (
				this.replaying
				|| !this.online
				|| this.servedFromCache
				|| session.isLocked
			) {
				return { synced: 0, stopped: true }
			}
			this.replaying = true
			let synced = 0
			try {
				await this.loadQueue()
				for (const entry of replayOrder(this.entries)) {
					if (entry.status === 'failed' || entry.status === 'conflict') {
						continue
					}
					const outcome = await this.replayOne(entry)
					if (outcome === 'done') {
						synced++
						continue
					}
					if (outcome === 'retry') {
						return { synced, stopped: true }
					}
				}
				return { synced, stopped: false }
			} finally {
				this.replaying = false
			}
		},

		/**
		 * Replay one entry. Returns done, conflict, failed or retry.
		 *
		 * @param {object} entry The opened entry.
		 * @return {Promise<string>}
		 */
		async replayOne(entry) {
			const base = `/apps/keepiq/api/v1/secrets`
			let saved = null
			if (entry.status !== 'sync') {
				try {
					if (entry.op === 'create') {
						saved = (await axios.post(generateUrl(base), entry.body))
							.data
					} else if (entry.op === 'update') {
						saved = (
							await axios.put(
								generateUrl(`${base}/${entry.secretId}`),
								{
									...entry.body,
									baseUpdatedAt: entry.baseUpdatedAt ?? undefined,
								},
							)
						).data
					} else {
						await axios.delete(
							generateUrl(`${base}/${entry.secretId}`),
							{
								params: entry.baseUpdatedAt
									? { baseUpdatedAt: entry.baseUpdatedAt }
									: {},
							},
						)
					}
				} catch (e) {
					const kind = classifyReplayError(e)
					if (kind === 'conflict') {
						this.conflicts = {
							...this.conflicts,
							[entry.entryId]: e.response?.data?.current ?? null,
						}
						await this.setStatus(entry, 'conflict')
						return 'conflict'
					}
					if (kind === 'failed') {
						await this.setStatus(entry, 'failed')
						return 'failed'
					}
					return 'retry'
				}
			}

			// The recipients, fetched now (D2): only for an update that changed
			// a value.
			if (
				entry.op === 'update'
				&& SENSITIVE.some((f) => entry.body[f] !== undefined)
			) {
				try {
					await this.fanOut(entry, saved?.updatedAt ?? null)
				} catch (e) {
					// The owner row is saved; retry only the recipient step.
					await this.setStatus(entry, 'sync')
					return classifyReplayError(e) === 'failed' ? 'failed' : 'retry'
				}
			}
			await this.drop(entry)
			return 'done'
		},

		/**
		 * Run the normal sync-on-update fan-out for a replayed update, with
		 * the recipient list and certificates fetched at this moment.
		 *
		 * @param {object} entry The opened entry.
		 * @param {string|null} updatedAt The owner row's new updatedAt.
		 * @return {Promise<void>}
		 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-replay-runs-through-the-online-paths-with-a-fresh-fan-out
		 */
		async fanOut(entry, updatedAt) {
			const plaintext = await this.plaintextOf(entry.body)
			const { useShareStore } = await import('./share.js')
			const shares = useShareStore()
			// Never reuse a share list loaded for another view.
			shares.reset()
			await shares.syncUpdate(entry.secretId, plaintext, updatedAt)
			await shares.syncAsTeamWriter(entry.secretId, plaintext)
		},

		async setStatus(entry, status) {
			const session = useSessionStore()
			const updated = { ...entry, status }
			await putQueueEntry(await sealEntry(updated, session.certificate))
			this.entries = this.entries.map((e) =>
				e.entryId === entry.entryId ? updated : e,
			)
		},

		async drop(entry) {
			await deleteQueueEntry(entry.entryId)
			this.entries = this.entries.filter((e) => e.entryId !== entry.entryId)
			const rest = { ...this.conflicts }
			delete rest[entry.entryId]
			this.conflicts = rest
		},

		/**
		 * Settle a conflict: keep the offline change (replayed again on the
		 * server's new version) or keep the server version (entry dropped).
		 *
		 * @param {string} entryId The entry.
		 * @param {'mine'|'server'} choice The user's choice.
		 * @return {Promise<void>}
		 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
		 */
		async resolveConflict(entryId, choice) {
			const entry = this.entries.find((e) => e.entryId === entryId)
			if (!entry) return
			if (choice === 'server') {
				await this.drop(entry)
				return
			}
			const current = this.conflicts[entryId]
			const updated = {
				...entry,
				baseUpdatedAt: current?.updatedAt ?? null,
				status: 'queued',
			}
			const session = useSessionStore()
			await putQueueEntry(await sealEntry(updated, session.certificate))
			this.entries = this.entries.map((e) =>
				e.entryId === entryId ? updated : e,
			)
			await this.replayQueue()
		},

		/**
		 * Discard one entry (failed list, or the user gave up on it).
		 *
		 * @param {string} entryId The entry.
		 * @return {Promise<void>}
		 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-failed-entries-are-kept-never-dropped-silently
		 */
		async discardEntry(entryId) {
			const entry = this.entries.find((e) => e.entryId === entryId)
			if (entry) await this.drop(entry)
		},

		/**
		 * Reopen entries sealed under a previous suite with the previous master
		 * password and the cached old envelope, and reseal them to the current
		 * certificate (D6).
		 *
		 * @param {string} previousPassword The master password before the rotation.
		 * @return {Promise<number>} How many entries were reopened.
		 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-pending-changes-block-logout-and-rotation
		 */
		async reopenWithPreviousPassword(previousPassword) {
			const snapshot = await readSnapshot()
			if (!snapshot?.suite?.privateKey) {
				throw new Error(
					t(
						'keepiq',
						'The previous vault copy is gone, so these changes cannot be opened.',
					),
				)
			}
			const pem = await decryptPrivateKey(
				snapshot.suite.privateKey,
				previousPassword,
			)
			const oldKey = await importPrivateKey(pem)
			const session = useSessionStore()
			let count = 0
			for (const stored of this.foreignEntries) {
				const opened = await openEntry(stored, oldKey)
				// Values were encrypted to the old certificate: re-encrypt them
				// to the current one before resealing.
				const { importPublicKey, rsaEncrypt } =
					await import('../../crypto/rsa.js')
				const publicKey = await importPublicKey(session.certificate)
				for (const field of SENSITIVE) {
					if (opened.body[field]) {
						opened.body[field] = await rsaEncrypt(
							await rsaDecrypt(opened.body[field], oldKey),
							publicKey,
						)
					}
				}
				await putQueueEntry(
					await sealEntry(
						{ ...opened, suiteId: session.suiteId },
						session.certificate,
					),
				)
				count++
			}
			this.foreignEntries = []
			await this.loadQueue()
			return count
		},

		/**
		 * Drop every entry sealed under a previous suite.
		 *
		 * @return {Promise<void>}
		 */
		async discardForeignEntries() {
			for (const stored of this.foreignEntries) {
				await deleteQueueEntry(stored.entryId)
			}
			this.foreignEntries = []
		},

		/**
		 * Refuse a suite rotation while changes are pending (D6).
		 *
		 * @return {void}
		 * @throws {Error} When entries are pending.
		 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-pending-changes-block-logout-and-rotation
		 */
		assertNoPendingChanges() {
			if (this.pendingCount > 0) {
				throw new Error(
					t(
						'keepiq',
						'Sync or discard your offline changes before you rotate your keys.',
					),
				)
			}
		},

		/**
		 * Purge the snapshot on logout / suite rotation / compromise recovery.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/offline-readonly-cache/spec.md#requirement-the-cache-is-evicted-on-lock-logout-and-suite-rotation
		 */
		async evict() {
			this.servedFromCache = false
			this.vault = null
			this.syncedAt = null
			await purge().catch(() => {})
		},
	},
})
