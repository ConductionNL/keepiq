/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { useBulkStore } from '../store/modules/bulk.js'

/**
 * Run one per-item step for a secret, skipping a secret that is already
 * gone (404) instead of failing it; any other failure is passed on.
 *
 * @param {function(): Promise<void>} step The step's write.
 * @return {Promise<object>} The per-item outcome.
 */
export async function skippingGone(step) {
	try {
		await step()
		return { status: 'ok' }
	} catch (e) {
		if (e?.response?.status === 404) {
			return { status: 'skipped', reason: 'already deleted' }
		}
		throw e
	}
}

/**
 * What the bulk dialogs share: the open prop and the close and done
 * events, the shared selection and run state, whether a run started HERE
 * has ended (the store's report outlives the dialog, so the phase cannot be
 * read from the report alone), the outcome counts for a finished title, and
 * handing a run or a retry to the store.
 */
export default {
	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'done'],

	data() {
		return {
			/** Whether a run was started FROM THIS DIALOG. */
			ran: false,
		}
	},

	computed: {
		/**
		 * The shared selection and run state.
		 *
		 * @return {object}
		 * @spec exclude Store-ref passthrough: returns a Pinia store with no domain logic.
		 */
		bulk() {
			return useBulkStore()
		},

		/**
		 * Whether the dialog has switched from asking to reporting: a run
		 * happened here and is no longer in flight.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/bulk-actions/spec.md#requirement-chunked-execution-with-a-per-item-report
		 */
		finished() {
			return this.ran && !this.bulk.progress.running
		},

		/**
		 * The outcome counts of the last run: the items that went through,
		 * of all the report holds — counted off the report, never off the
		 * selection, which the host's post-run reload empties.
		 *
		 * @return {{ok: number, total: number}}
		 * @spec openspec/specs/bulk-actions/spec.md#requirement-chunked-execution-with-a-per-item-report
		 */
		reportCounts() {
			return {
				ok: this.bulk.report.filter((r) => r.status === 'ok').length,
				total: this.bulk.report.length,
			}
		},
	},

	methods: {
		/**
		 * Run a per-item step over the selection, then tell the host.
		 *
		 * @param {function(string): Promise<object>} step The per-item step.
		 * @param {string} label The progress label.
		 * @return {Promise<void>}
		 * @spec openspec/specs/bulk-actions/spec.md#requirement-chunked-execution-with-a-per-item-report
		 */
		async runOverSelection(step, label) {
			this.ran = true
			await this.bulk.run(this.bulk.selectedIds, step, label)
			this.$emit('done')
		},

		/**
		 * Retry the step over only the failed subset, then tell the host.
		 *
		 * @param {function(string): Promise<object>} step The per-item step.
		 * @param {string} label The progress label.
		 * @return {Promise<void>}
		 * @spec openspec/specs/bulk-actions/spec.md#requirement-chunked-execution-with-a-per-item-report
		 */
		async retryFailedWith(step, label) {
			await this.bulk.retryFailed(step, label)
			this.$emit('done')
		},
	},
}
