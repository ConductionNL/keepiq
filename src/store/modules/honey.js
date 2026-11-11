import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Honey credential store (honey-credentials §5): decoy flag state,
 * alert listing (owner-scoped; instance-wide for admins), acknowledge
 * and per-accessor snooze. The flag is only ever visible through these
 * owner/admin endpoints — never on a secret response.
 */
import { defineStore } from 'pinia'

/**
 * Run one honey write: clear the last error first, then report whether the
 * write succeeded, keeping the server's message (else the error's own) when
 * it did not.
 *
 * @param {object} store The honey store.
 * @param {function(): Promise<void>} write The request and its state update.
 * @return {Promise<boolean>} Whether the write succeeded.
 */
async function succeeded(store, write) {
	store.error = null
	try {
		await write()
		return true
	} catch (e) {
		store.error = e?.response?.data?.message || e?.message
		return false
	}
}

export const useHoneyStore = defineStore('honey', {
	state: () => ({
		/** @type {object|null} {flagged, flag} of the inspected secret. */
		status: null,
		/** @type {Array<object>} Alerts visible to the caller. */
		alerts: [],
		/** @type {boolean} Whether a request is in flight. */
		loading: false,
		/** @type {string|null} Last error message. */
		error: null,
	}),

	actions: {
		/**
		 * Load the flag state of a secret (owner/admin only).
		 *
		 * @param {string} secretId The secret ID.
		 * @return {Promise<object|null>} {flagged, flag}.
		 * @spec openspec/specs/honey-credentials/spec.md#requirement-honey-flag-is-owner-admin-only-and-invisible-to-others
		 */
		async fetchStatus(secretId) {
			this.error = null
			try {
				const response = await axios.get(
					generateUrl(`/apps/keepiq/api/v1/secrets/${secretId}/honey`),
				)
				this.status = response.data
				return this.status
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
				return null
			}
		},

		/**
		 * Flag a secret as a decoy.
		 *
		 * @param {string} secretId The secret ID.
		 * @param {string} note Optional placement note.
		 * @return {Promise<boolean>} Whether flagging succeeded.
		 * @spec openspec/specs/honey-credentials/spec.md#requirement-honey-flag-is-owner-admin-only-and-invisible-to-others
		 */
		async flag(secretId, note = '') {
			return succeeded(this, async () => {
				const response = await axios.post(
					generateUrl(`/apps/keepiq/api/v1/secrets/${secretId}/honey`),
					{ note },
				)
				this.status = { flagged: true, flag: response.data }
			})
		},

		/**
		 * Remove the decoy flag (alerts are kept server-side).
		 *
		 * @param {string} secretId The secret ID.
		 * @return {Promise<boolean>} Whether unflagging succeeded.
		 * @spec openspec/specs/honey-credentials/spec.md#requirement-honey-flag-is-owner-admin-only-and-invisible-to-others
		 */
		async unflag(secretId) {
			return succeeded(this, async () => {
				await axios.delete(
					generateUrl(`/apps/keepiq/api/v1/secrets/${secretId}/honey`),
				)
				this.status = { flagged: false, flag: null }
			})
		},

		/**
		 * Load alerts (owner: own decoys; admin: instance-wide).
		 *
		 * @return {Promise<Array<object>>} The alerts.
		 * @spec openspec/specs/honey-credentials/spec.md#requirement-any-access-to-a-honey-secret-raises-a-high-severity-alert
		 * @spec openspec/specs/honey-credentials/spec.md#requirement-honey-access-never-records-secret-material
		 */
		async fetchAlerts() {
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(
					generateUrl('/apps/keepiq/api/v1/honey/alerts'),
				)
				this.alerts = response.data ?? []
				return this.alerts
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
				return []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Acknowledge an alert.
		 *
		 * @param {string} alertId The alert ID.
		 * @return {Promise<boolean>} Whether it succeeded.
		 * @spec openspec/specs/honey-credentials/spec.md#requirement-any-access-to-a-honey-secret-raises-a-high-severity-alert
		 */
		async acknowledge(alertId) {
			return succeeded(this, async () => {
				const response = await axios.post(
					generateUrl(
						`/apps/keepiq/api/v1/honey/alerts/${alertId}/acknowledge`,
					),
				)
				this.alerts = this.alerts.map((a) =>
					a.id === alertId ? response.data : a,
				)
			})
		},

		/**
		 * Snooze future paging for the alert's accessor.
		 *
		 * @param {string} alertId The alert ID.
		 * @param {number} hours Snooze duration in hours.
		 * @return {Promise<boolean>} Whether it succeeded.
		 * @spec openspec/specs/honey-credentials/spec.md#requirement-alert-storms-are-rate-limited-and-per-accessor-snoozable
		 */
		async snooze(alertId, hours = 24) {
			return succeeded(this, async () => {
				const response = await axios.post(
					generateUrl(
						`/apps/keepiq/api/v1/honey/alerts/${alertId}/snooze`,
					),
					{ hours },
				)
				this.alerts = this.alerts.map((a) =>
					a.id === alertId ? response.data : a,
				)
			})
		},
	},
})
