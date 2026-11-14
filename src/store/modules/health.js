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

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import { rsaDecrypt } from '../../crypto/index.js'
import { isPasswordBearing } from '../../health/classify.js'
import { duplicateGroups } from '../../health/duplicates.js'
import { analyse, sha256Hex } from '../../health/engine.js'
import { checkValue } from '../../health/hibp.js'
import { useSecretTypeStore } from './secretType.js'
import { onVaultLock, useSessionStore } from './session.js'

/** The server's per-page cap on the secrets list. */
const HEALTH_PAGE_SIZE = 100

/**
 * Fetch every secret the user can read, page by page. The server caps a page
 * at 100, and one page left every secret past the first 100 out of the
 * report (keepiq#745). Stops on a short page, or when the total is reached.
 *
 * @return {Promise<Array<object>>}
 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-client-side-health-analysis
 */
async function fetchEverySecret() {
	const all = []
	// Defensive bound; 100 * 100000 covers any realistic vault.
	for (let page = 1; page <= 100000; page++) {
		const response = await axios.get(
			generateUrl('/apps/keepiq/api/v1/secrets'),
			{ params: { page, limit: HEALTH_PAGE_SIZE } },
		)
		const batch = response?.data?.items ?? []
		all.push(...batch)
		const total = response?.data?.total
		if (
			batch.length < HEALTH_PAGE_SIZE
			|| (typeof total === 'number' && all.length >= total)
		) {
			break
		}
	}
	return all
}

/**
 * The secret types, fetched when none are loaded yet. A failed fetch is
 * non-fatal: without types no row can be excluded by type.
 *
 * @return {Promise<Array<object>>}
 * @spec openspec/changes/add-totp-secrets/specs/secrets/spec.md#requirement-client-side-totp-code-generation
 */
async function loadedTypes() {
	const typeStore = useSecretTypeStore()
	if (!Array.isArray(typeStore.types) || typeStore.types.length === 0) {
		try {
			await typeStore.fetchTypes()
		} catch {
			// Non-fatal: without types we simply cannot exclude totp rows.
		}
	}
	return Array.isArray(typeStore.types) ? typeStore.types : []
}

/**
 * The ids of the types whose secrets hold machine material: `totp` seeds and
 * `passkey` private keys are high-entropy, so scoring, reuse-matching or
 * breach-checking them is meaningless noise (add-totp-secrets D7,
 * passkey-item-type D6).
 *
 * @param {Array<object>} types The secret types.
 * @return {Set<string|number>}
 * @spec openspec/changes/add-totp-secrets/specs/secrets/spec.md#requirement-client-side-totp-code-generation
 */
function machineMaterialTypeIds(types) {
	return new Set(types.filter(isMachineMaterialType).map((type) => type.id))
}

/**
 * @param {object|null} type A secret type.
 * @return {boolean} Whether it is the totp or the passkey type.
 */
function isMachineMaterialType(type) {
	return Boolean(type && (type.name === 'totp' || type.name === 'passkey'))
}

/**
 * Whether a secret is analysed: not blocked, with a key, and of no type
 * holding machine material.
 *
 * @param {object} secret A listed secret.
 * @param {Set<string|number>} excludedTypeIds The machine-material type ids.
 * @return {boolean}
 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-client-side-health-analysis
 */
function isAnalysable(secret, excludedTypeIds) {
	if (secret.blocked || !secret.key) {
		return false
	}
	return !excludedTypeIds.has(secret.typeId)
}

/**
 * A secret's health row with its value decrypted in the browser, or null
 * when the value does not decrypt.
 *
 * @param {object} secret A listed secret.
 * @param {object} session The session store (holds the CryptoKey).
 * @return {Promise<object|null>}
 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-client-side-health-analysis
 */
async function decryptedRow(secret, session) {
	let value
	try {
		value = await rsaDecrypt(secret.key, session.cryptoKey)
	} catch {
		return null
	}
	return healthRow(secret, value, await decryptedLogin(secret, session))
}

/**
 * The decrypted username, or null when there is none or it does not decrypt.
 *
 * @param {object} secret A listed secret.
 * @param {object} session The session store (holds the CryptoKey).
 * @return {Promise<string|null>}
 * @spec openspec/changes/vault-duplicate-finder/specs/vault-duplicates/spec.md#requirement-detect-duplicates-in-the-browser
 */
async function decryptedLogin(secret, session) {
	if (!secret.login) {
		return null
	}
	try {
		return await rsaDecrypt(secret.login, session.cryptoKey)
	} catch {
		return null
	}
}

/**
 * The ids of the user's recipient copies of other people's secrets: rows in
 * the user's vault whose source lives elsewhere. Read from the vault snapshot
 * the offline cache already fetches, so no request names a single secret.
 * Null when it cannot be read: duplicate detection then stays off.
 *
 * @return {Promise<Set<string>|null>}
 * @spec openspec/changes/vault-duplicate-finder/specs/vault-duplicates/spec.md#requirement-detect-duplicates-in-the-browser
 */
async function recipientCopyIds() {
	try {
		const response = await axios.get(
			generateUrl('/apps/keepiq/api/v1/offline/manifest'),
		)
		const secrets = response?.data?.secrets
		if (!Array.isArray(secrets)) {
			return null
		}
		return new Set(
			secrets
				.filter((secret) => secret?.sourceUpdatedAt !== null && secret?.sourceUpdatedAt !== undefined)
				.map((secret) => String(secret.id)),
		)
	} catch {
		return null
	}
}

/**
 * @param {object} secret A listed secret.
 * @param {string} value Its decrypted value.
 * @param {string|null} [login] Its decrypted username.
 * @return {object} The row the engine analyses.
 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-client-side-health-analysis
 */
function healthRow(secret, value, login = null) {
	return {
		id: secret.id,
		name: secret.name,
		url: secret.url ?? null,
		folderPath: secret.folderId ?? null,
		login,
		copy: false,
		value,
		keyUpdatedAt: keyChangedAt(secret),
		possiblyCompromisedAt: secret.possiblyCompromisedAt ?? null,
	}
}

/**
 * @param {object} secret A listed secret.
 * @return {string|null} When its value last changed: its key update, else
 *   its creation.
 */
function keyChangedAt(secret) {
	return secret.keyUpdatedAt ?? secret.createdAt ?? null
}

/**
 * Load the decrypted rows, or record why they could not be loaded.
 *
 * @param {object} store The health store.
 * @param {object} session The session store (holds the CryptoKey).
 * @return {Promise<Array<object>|null>} The rows, or null after a failure.
 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-client-side-health-analysis
 */
async function loadRowsOrFail(store, session) {
	try {
		return await store.loadDecryptedRows(session)
	} catch (e) {
		store.status = 'error'
		store.error = e?.message || 'Failed to load vault'
		return null
	}
}

/**
 * Run the engine over the rows and keep its findings, summary and score, or
 * record why it failed; the plaintext rows are dropped either way.
 *
 * @param {object} store The health store.
 * @param {{rows: Array<object>, stalenessThreshold: string|number, breachResults: ?Map, copiesKnown: boolean}} analysis The rows, the engine's options, and whether recipient copies could be told apart.
 * @return {Promise<void>}
 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-client-side-health-analysis
 */
async function scoreRows(store, analysis) {
	try {
		const result = await store.runEngine(analysis.rows, {
			stalenessThreshold: analysis.stalenessThreshold,
			breachResults: analysis.breachResults,
		})
		store.findings = result.findings
		store.summary = result.summary
		store.score = result.score
		store.duplicates = analysis.copiesKnown
			? (result.duplicates ?? { exact: [], likely: [] })
			: { exact: [], likely: [] }
		store.duplicatesAvailable = analysis.copiesKnown === true
		store.status = 'ready'
	} catch (e) {
		store.status = 'error'
		store.error = e?.message || 'Analysis failed'
	} finally {
		// Drop plaintext references held in this scope after the pass.
		analysis.rows.length = 0
	}
}

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
		/** @type {{exact: Array<object>, likely: Array<object>}} Duplicate groups (memory only, vault-duplicate-finder). */
		duplicates: { exact: [], likely: [] },
		/** @type {boolean} Whether duplicate detection ran: recipient copies could be told apart. */
		duplicatesAvailable: false,
		/** @type {Worker|null} The analysis worker (never persisted). */
		worker: null,
	}),

	getters: {
		/**
		 * @param state
		 * @return {Array<object>} Weak findings.
		 */
		weakFindings: (state) =>
			state.findings.filter((f) => f.flags.includes('weak')),
		/**
		 * @param state
		 * @return {Array<object>} Reused findings.
		 */
		reusedFindings: (state) =>
			state.findings.filter((f) => f.flags.includes('reused')),
		/**
		 * @param state
		 * @return {Array<object>} Stale findings.
		 */
		staleFindings: (state) =>
			state.findings.filter((f) => f.flags.includes('stale')),
		/**
		 * @param state
		 * @return {Array<object>} Breached findings.
		 */
		breachedFindings: (state) =>
			state.findings.filter((f) => f.flags.includes('breached')),
		/**
		 * @param state
		 * @return {Array<object>} Possibly-compromised findings.
		 */
		compromisedFindings: (state) =>
			state.findings.filter((f) => f.flags.includes('compromised')),
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
		 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-client-side-health-analysis
		 */
		async analyseVault(opts = {}) {
			const session = useSessionStore()
			if (session.isLocked) {
				this.reset()
				return
			}
			this.status = 'analysing'
			this.error = null
			const rows = await loadRowsOrFail(this, session)
			if (rows === null) {
				return
			}
			const breachResults = opts.breachEnabled
				? await this.runBreachChecks(rows)
				: null
			await scoreRows(this, {
				rows,
				stalenessThreshold: opts.stalenessThreshold ?? '365',
				breachResults,
				copiesKnown: rows.copiesKnown === true,
			})
		},

		/**
		 * Fetch the user's secrets and decrypt their values in the browser.
		 *
		 * @param {object} session The session store (holds the CryptoKey).
		 * @return {Promise<Array<object>>} Decrypted rows.
		 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-client-side-health-analysis
		 * @spec openspec/changes/add-totp-secrets/specs/secrets/spec.md#requirement-client-side-totp-code-generation
		 * @spec openspec/changes/vault-duplicate-finder/specs/vault-duplicates/spec.md#requirement-detect-duplicates-in-the-browser
		 */
		async loadDecryptedRows(session) {
			const excludedTypeIds = machineMaterialTypeIds(await loadedTypes())
			const items = await fetchEverySecret()
			const copies = await recipientCopyIds()
			const rows = []
			for (const secret of items) {
				if (!isAnalysable(secret, excludedTypeIds)) {
					continue
				}
				const row = await decryptedRow(secret, session)
				if (row !== null) {
					row.copy = copies === null || copies.has(String(secret.id))
					rows.push(row)
				}
			}
			// Not a row: whether copies could be told apart (duplicate detection needs it).
			Object.defineProperty(rows, 'copiesKnown', { value: copies !== null })
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
		 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-opt-in-breach-checking-via-k-anonymity
		 */
		async runBreachChecks(rows) {
			const results = new Map()
			let anyUnavailable = false
			for (const row of rows) {
				if (!isPasswordBearing(row.value)) {
					continue
				}

				const digest = await sha256Hex(row.value)
				if (results.has(digest)) {
					continue
				}

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
		 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-client-side-health-analysis
		 */
		async runEngine(rows, options) {
			if (typeof Worker === 'undefined') {
				return { ...(await analyse(rows, options)), duplicates: duplicateGroups(rows) }
			}
			if (!this.worker) {
				this.worker = new Worker(
					new URL('../../health/worker.js', import.meta.url),
				)
			}
			const worker = this.worker
			const requestId = Math.random().toString(36).slice(2)
			const payload = {
				requestId,
				rows,
				options: {
					stalenessThreshold: options.stalenessThreshold,
					breachResults: options.breachResults
						? Array.from(options.breachResults.entries())
						: null,
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
						reject(
							new Error(event.data.error || 'Worker analysis failed'),
						)
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
		 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-client-side-health-analysis
		 */
		reset() {
			this.findings = []
			this.summary = null
			this.score = null
			this.duplicates = { exact: [], likely: [] }
			this.duplicatesAvailable = false
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
		 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-client-side-health-analysis
		 */
		registerLockReset() {
			onVaultLock(() => this.reset())
		},
	},
})
