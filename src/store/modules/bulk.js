/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Pinia store for bulk actions (bulk-actions §1/§2): the vault-list
 * selection (client-only, cleared on lock, never persisted) and the
 * shared chunked runner with cancel, per-item report, and retry-failed.
 *
 * @spec openspec/specs/bulk-actions/spec.md#requirement-multi-select-and-bulk-action-bar
 * @spec openspec/specs/bulk-actions/spec.md#requirement-chunked-execution-with-a-per-item-report
 */

import { defineStore } from 'pinia'
import { onVaultLock } from './session.js'

const CHUNK_SIZE = 25

export const useBulkStore = defineStore('bulk', {
	state: () => ({
		/** @type {Array<string>} Selected secret ids (client-only). */
		selectedIds: [],
		/** @type {{running: boolean, done: number, total: number, label: string, cancelled: boolean}} Runner progress. */
		progress: { running: false, done: 0, total: 0, label: '', cancelled: false },
		/** @type {Array<{secretId: string, status: string, reason?: string}>} Per-item report of the last run. */
		report: [],
	}),

	getters: {
		/**
		 * How many secrets are selected.
		 *
		 * @param {object} state The store state.
		 * @return {number}
		 */
		selectionCount(state) {
			return state.selectedIds.length
		},
		/**
		 * Failed items of the last run (retry input).
		 *
		 * @param {object} state The store state.
		 * @return {Array<object>}
		 */
		failedItems(state) {
			return state.report.filter((r) => r.status === 'failed')
		},
	},

	actions: {
		/**
		 * Register the vault-lock reset: the selection is client-only
		 * and MUST NOT survive a lock (bulk-actions §1.2).
		 *
		 * @return {void}
		 */
		registerLockReset() {
			onVaultLock(() => this.clearSelection())
		},

		/**
		 * Replace the selection (CnIndexPage selection-change).
		 *
		 * @param {Array<string>} ids The selected ids.
		 * @return {void}
		 */
		setSelection(ids) {
			this.selectedIds = [...new Set(ids)]
		},

		/**
		 * Clear the selection and the last report.
		 *
		 * @return {void}
		 */
		clearSelection() {
			this.selectedIds = []
			this.report = []
			this.progress = { running: false, done: 0, total: 0, label: '', cancelled: false }
		},

		/**
		 * Request cancellation — the runner stops after the current chunk.
		 *
		 * @return {void}
		 */
		cancel() {
			if (this.progress.running) {
				this.progress.cancelled = true
			}
		},

		/**
		 * Run one action over a set of secret ids in fixed chunks.
		 *
		 * Guarantees every id appears in the report exactly once: items
		 * in chunks after a cancellation are reported as skipped, and a
		 * thrown per-item error becomes a failed row — nothing is
		 * dropped silently (bulk-actions §2.1).
		 *
		 * @param {Array<string>} ids The secret ids to process.
		 * @param {Function} perItem Async (secretId) => {status, reason?}; a throw = failed.
		 * @param {string} label Progress label for the UI.
		 * @return {Promise<Array<object>>} The per-item report.
		 */
		async run(ids, perItem, label) {
			const unique = [...new Set(ids)]
			this.progress = { running: true, done: 0, total: unique.length, label, cancelled: false }
			this.report = []

			for (let offset = 0; offset < unique.length; offset += CHUNK_SIZE) {
				if (this.progress.cancelled) {
					for (const secretId of unique.slice(offset)) {
						this.report.push({ secretId, status: 'skipped', reason: 'cancelled' })
					}
					break
				}

				const chunk = unique.slice(offset, offset + CHUNK_SIZE)
				for (const secretId of chunk) {
					try {
						// Sequential within the chunk keeps server load and
						// progress feedback predictable.
						// eslint-disable-next-line no-await-in-loop
						const result = await perItem(secretId)
						this.report.push({ secretId, ...(result ?? { status: 'ok' }) })
					} catch (e) {
						this.report.push({
							secretId,
							status: 'failed',
							reason: e?.response?.data?.message || e?.message || 'failed',
						})
					}
					this.progress.done++
				}
			}

			this.progress.running = false
			return this.report
		},

		/**
		 * Re-run ONLY the failed subset of the last report (safe because
		 * all server writes are idempotent; bulk-actions §2.2).
		 *
		 * @param {Function} perItem The same per-item function.
		 * @param {string} label Progress label.
		 * @return {Promise<Array<object>>} The merged report.
		 */
		async retryFailed(perItem, label) {
			const failedIds = this.failedItems.map((r) => r.secretId)
			if (failedIds.length === 0) {
				return this.report
			}
			const untouched = this.report.filter((r) => r.status !== 'failed')
			const retried = await this.run(failedIds, perItem, label)
			this.report = [...untouched, ...retried]
			return this.report
		},
	},
})
