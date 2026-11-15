/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Run one request while the component shows it busy. The host provides
 * `busy` and `error`.
 */
export const busyRequest = {
	methods: {
		/**
		 * Mark the component busy and clear the last error, run the request,
		 * and on a failure show the server's message, else the error's own;
		 * the component is not busy afterwards either way.
		 *
		 * @param {function(): Promise<void>} request The request and its state update.
		 * @return {Promise<void>}
		 * @spec exclude Busy-state plumbing shared by the admin and ownership requests.
		 */
		async runBusy(request) {
			this.busy = true
			this.error = null
			try {
				await request()
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			} finally {
				this.busy = false
			}
		},
	},
}
