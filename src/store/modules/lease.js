/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Pinia store for machine leases (machine-secret-leases §6).
 *
 * Session-authenticated admin/registrant surface only — the bearer-side
 * lease API belongs to machine clients. Identifiers and lifetimes only.
 *
 * @spec openspec/specs/machine-secret-leases/spec.md#requirement-lease-revocation-by-admin-owner-or-application
 */

import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export const useLeaseStore = defineStore('lease', {
	state: () => ({
		/** @type {Array<object>} The focused application's leases. */
		leases: [],
		/** @type {boolean} Whether a request is in flight. */
		loading: false,
	}),

	actions: {
		/**
		 * Load an application's leases (admin/registrant only).
		 *
		 * @param {string} applicationId The application id.
		 * @return {Promise<void>}
		 */
		async fetchForApplication(applicationId) {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/doriath/api/v1/applications/${applicationId}/leases`,
					),
				)
				this.leases = response.data || []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Revoke a lease; the row flips to revoked in place.
		 *
		 * @param {string} leaseId The lease id.
		 * @return {Promise<void>}
		 */
		async revoke(leaseId) {
			const response = await axios.delete(
				generateUrl(`/apps/doriath/api/v1/leases/${leaseId}`),
			)
			this.leases = this.leases.map((lease) =>
				lease.id === leaseId ? response.data : lease,
			)
		},
	},
})
