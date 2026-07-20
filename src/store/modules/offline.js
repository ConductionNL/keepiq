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
import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { useSessionStore, onVaultLock } from './session.js'
import { encryptSnapshot, decryptSnapshot } from '../../offline/snapshot.js'
import { writeSnapshot, readSnapshot, purge, isCacheAvailable } from '../../offline/cache.js'

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
	}),

	getters: {
		/**
		 * Read-only whenever data is served from the offline cache.
		 * @param state
		 */
		readOnly: (state) => state.servedFromCache,
	},

	actions: {
		/**
		 * Register the purge lock-hook once (evicts the cache on vault lock,
		 * D4). Idempotent.
		 */
		ensureLockHook() {
			if (lockHookRegistered) {
				return
			}
			lockHookRegistered = true
			onVaultLock(() => {
				this.servedFromCache = false
				this.vault = null
				// The metadata-decryption key is gone on lock; the cache must
				// not outlive it.
				purge().catch(() => {})
			})
		},

		/**
		 * Track online/offline transitions.
		 */
		bindConnectivity() {
			if (typeof window === 'undefined') {
				return
			}
			window.addEventListener('online', () => { this.online = true })
			window.addEventListener('offline', () => { this.online = false })
		},

		/**
		 * Write-through refresh: fetch the consolidated manifest and commit an
		 * encrypted snapshot atomically. Called after a successful ONLINE
		 * unlock. Fail-soft — a cache write failure never breaks the session.
		 *
		 * @return {Promise<boolean>} Whether a snapshot was written.
		 */
		async syncNow() {
			this.ensureLockHook()
			const session = useSessionStore()
			if (!this.available || session.aesKey === null) {
				return false
			}
			try {
				const response = await axios.get(generateUrl('/apps/doriath/api/v1/offline/manifest'))
				const snapshot = await encryptSnapshot(session.aesKey, response.data)
				const written = await writeSnapshot(snapshot)
				if (written) {
					this.servedFromCache = false
					this.syncedAt = response.data.syncedAt
				}
				return written
			} catch (e) {
				if (e?.response?.status === 403) {
					// Admin disabled offline caching org-wide — purge any prior snapshot.
					await purge().catch(() => {})
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
			this.servedFromCache = true
			this.syncedAt = snapshot.syncedAt
			return true
		},

		/**
		 * Purge the snapshot on logout / suite rotation / compromise recovery.
		 *
		 * @return {Promise<void>}
		 */
		async evict() {
			this.servedFromCache = false
			this.vault = null
			this.syncedAt = null
			await purge().catch(() => {})
		},
	},
})
