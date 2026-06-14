/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Pinia store for client-side password-health analysis.
 *
 * Holds findings, the vault score, and analysis status in MEMORY ONLY — never
 * localStorage / sessionStorage / IndexedDB / the server. The store fetches the
 * user's own secrets, decrypts them in-browser, runs the {@link analyse} engine
 * (in a web worker when available, otherwise inline), optionally runs the
 * opt-in HIBP breach check, and discards every byte of derived health state plus
 * terminates the worker on lock (password-health design D2/D6). No score,
 * digest, hash (beyond the 5-char prefix the proxy sees), or verdict is ever
 * transmitted or persisted.
 *
 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-client-side-health-analysis
 */

import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { useSessionStore, onVaultLock } from './session.js'
import { rsaDecrypt } from '../../crypto/index.js'
import { analyse, sha256Hex } from '../../health/engine.js'
import { isPasswordBearing } from '../../health/classify.js'
import { checkValue } from '../../health/hibp.js'

export const useHealthStore = defineStore('health', {
	state: () => ({
		/** @type {Array<object>} Per-secret findings (memory only). */
		findings: [],
		/** @type {object|null} Finding-count summary (memory only). */
		summary: null,
		/** @type {number|null} Vault health score 0–100 (memory only). */
		score: null,
		/** @type {string} Analysis status: idle | analysing | ready | error. */
		status: 'idle',
		/** @type {string} Breach-check status: off | ready | unavailable. */
		breachStatus: 'off',
		/** @type {string|null} Last error message. */
		error: null,
		/** @type {Worker|null} The analysis worker (never persisted). */
		worker: null,
	}),

	getters: {
		/** @return {Array<object>} Weak findings. */
		weakFindings: (state) => state.findings.filter((f) => f.flags.includes('weak')),
		/** @return {Array<object>} Reused findings. */
		reusedFindings: (state) => state.findings.filter((f) => f.flags.includes('reused')),
		/** @return {Array<object>} Stale findings. */
		staleFindings: (state) => state.findings.filter((f) => f.flags.includes('stale')),
		/** @return {Array<object>} Breached findings. */
		breachedFindings: (state) => state.findings.filter((f) => f.flags.includes('breached')),
		/** @return {Array<object>} Possibly-compromised findings. */
		compromisedFindings: (state) => state.findings.filter((f) => f.flags.includes('compromised')),
		/**
		 * Map of secretId -> zxcvbn score for the StrengthBadge component.
		 *
		 * @param {object} state The store state.
		 * @return {Object<string, number>}
		 */
		scoreById: (state) => {
			const out = {}
			for (const f of state.findings) {
				if (f.score !== null && f.score !== undefined) {
					out[f.id] = f.score
				}
			}
			return out
		},
	},

	actions: {
		/**
		 * Run a full health analysis over the user's vault.
		 *
		 * Fetches the owner-scoped secret list, decrypts each value in-browser,
		 * runs the engine, and (when both breach gates are on) the HIBP check.
		 * Aborts cleanly when the vault is locked.
		 *
		 * @param {object} [opts] Options.
		 * @param {string|number} [opts.stalenessThreshold] User threshold.
		 * @param {boolean} [opts.breachEnabled] Whether breach checking is active.
		 * @return {Promise<void>}
		 */
		async analyseVault(opts = {}) {
			const session = useSessionStore()
			if (session.isLocked) {
				this.reset()
				return
			}
			this.status = 'analysing'
			this.error = null

			let rows = []
			try {
				rows = await this.loadDecryptedRows(session)
			} catch (e) {
				this.status = 'error'
				this.error = e?.message || 'Failed to load vault'
				return
			}

			let breachResults = null
			if (opts.breachEnabled) {
				breachResults = await this.runBreachChecks(rows)
			}

			try {
				const result = await this.runEngine(rows, {
					stalenessThreshold: opts.stalenessThreshold ?? '365',
					breachResults,
				})
				this.findings = result.findings
				this.summary = result.summary
				this.score = result.score
				this.status = 'ready'
			} catch (e) {
				this.status = 'error'
				this.error = e?.message || 'Analysis failed'
			} finally {
				// Drop plaintext references held in this scope after the pass.
				rows.length = 0
			}
		},

		/**
		 * Fetch the user's secrets and decrypt their values in the browser.
		 *
		 * @param {object} session The session store (holds the CryptoKey).
		 * @return {Promise<Array<object>>} Decrypted rows.
		 */
		async loadDecryptedRows(session) {
			const response = await axios.get(
				generateUrl('/apps/doriath/api/v1/secrets'),
				{ params: { limit: 100 } },
			)
			const items = response?.data?.items ?? []
			const rows = []
			for (const secret of items) {
				if (secret.blocked || !secret.key) {
					continue
				}
				let value = ''
				try {
					// eslint-disable-next-line no-await-in-loop
					value = await rsaDecrypt(secret.key, session.cryptoKey)
				} catch {
					continue
				}
				rows.push({
					id: secret.id,
					name: secret.name,
					url: secret.url ?? null,
					folderPath: secret.folderId ?? null,
					value,
					keyUpdatedAt: secret.keyUpdatedAt ?? secret.updatedAt ?? null,
					possiblyCompromisedAt: secret.possiblyCompromisedAt ?? null,
				})
			}
			return rows
		},

		/**
		 * Run the opt-in HIBP breach check over password-bearing rows.
		 *
		 * Returns a Map of value-digest -> verdict so the engine can flag without
		 * re-seeing the value. Only the 5-char prefix leaves the browser.
		 *
		 * @param {Array<object>} rows Decrypted rows.
		 * @return {Promise<Map<string, object>>}
		 */
		async runBreachChecks(rows) {
			const results = new Map()
			let anyUnavailable = false
			for (const row of rows) {
				if (!isPasswordBearing(row.value)) {
					continue
				}
				// eslint-disable-next-line no-await-in-loop
				const digest = await sha256Hex(row.value)
				if (results.has(digest)) {
					continue
				}
				// eslint-disable-next-line no-await-in-loop
				const verdict = await checkValue(row.value)
				if (verdict.status === 'unavailable') {
					anyUnavailable = true
				}
				results.set(digest, verdict)
			}
			this.breachStatus = anyUnavailable ? 'unavailable' : 'ready'
			return results
		},

		/**
		 * Run the analysis engine, preferring a web worker and falling back to
		 * an inline pass when the Worker API is unavailable (e.g. jsdom tests).
		 *
		 * @param {Array<object>} rows    Decrypted rows.
		 * @param {object}        options Engine options.
		 * @return {Promise<object>} The engine result.
		 */
		async runEngine(rows, options) {
			if (typeof Worker === 'undefined') {
				return analyse(rows, options)
			}
			if (!this.worker) {
				this.worker = new Worker(new URL('../../health/worker.js', import.meta.url))
			}
			const worker = this.worker
			const requestId = Math.random().toString(36).slice(2)
			const payload = {
				requestId,
				rows,
				options: {
					stalenessThreshold: options.stalenessThreshold,
					breachResults: options.breachResults ? Array.from(options.breachResults.entries()) : null,
				},
			}
			return new Promise((resolve, reject) => {
				const onMessage = (event) => {
					if (event.data?.requestId !== requestId) {
						return
					}
					worker.removeEventListener('message', onMessage)
					if (event.data.ok) {
						resolve(event.data.result)
					} else {
						reject(new Error(event.data.error || 'Worker analysis failed'))
					}
				}
				worker.addEventListener('message', onMessage)
				worker.postMessage(payload)
			})
		},

		/**
		 * Discard all health state and terminate the worker. Called on vault
		 * lock and session timeout so no derived signal survives a locked vault.
		 *
		 * @return {void}
		 */
		reset() {
			this.findings = []
			this.summary = null
			this.score = null
			this.status = 'idle'
			this.breachStatus = 'off'
			this.error = null
			if (this.worker) {
				this.worker.terminate()
				this.worker = null
			}
		},

		/**
		 * Register this store's reset with the session lock lifecycle. Called
		 * once when the store is first used so locking the vault discards health
		 * state and terminates the worker.
		 *
		 * @return {void}
		 */
		registerLockReset() {
			onVaultLock(() => this.reset())
		},
	},
})
