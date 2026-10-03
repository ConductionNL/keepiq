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

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'

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
		 * @spec openspec/specs/machine-secret-leases/spec.md#requirement-lease-aware-audit-trail
		 */
		async fetchForApplication(applicationId) {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/keepiq/api/v1/applications/${applicationId}/leases`,
					),
				)
				this.leases = response.data || []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Read an application's lease policy: effective, stored override,
		 * instance values and whether the caller may change it (keepiq#753).
		 *
		 * @param {string} applicationId The application id.
		 * @return {Promise<object>} The policy view.
		 * @spec openspec/specs/machine-secret-leases/spec.md#requirement-admin-lease-ttl-policy
		 */
		async fetchPolicy(applicationId) {
			const response = await axios.get(
				generateUrl(
					`/apps/keepiq/api/v1/applications/${applicationId}/lease-policy`,
				),
			)
			return response.data
		},

		/**
		 * Store an application's lease-policy override (admin only). A null
		 * field inherits the instance value.
		 *
		 * @param {string} applicationId The application id.
		 * @param {{defaultTtl: ?number, maxTtl: ?number, renewable: ?boolean}} override The override.
		 * @return {Promise<object>} The effective policy now in force.
		 * @spec openspec/specs/machine-secret-leases/spec.md#requirement-admin-lease-ttl-policy
		 */
		async savePolicy(applicationId, override) {
			const response = await axios.put(
				generateUrl(
					`/apps/keepiq/api/v1/applications/${applicationId}/lease-policy`,
				),
				override,
			)
			return response.data
		},

		/**
		 * Revoke a lease; the row flips to revoked in place.
		 *
		 * @param {string} leaseId The lease id.
		 * @return {Promise<void>}
		 * @spec openspec/specs/machine-secret-leases/spec.md#requirement-lease-revocation-by-admin-owner-or-application
		 */
		async revoke(leaseId) {
			const response = await axios.delete(
				generateUrl(`/apps/keepiq/api/v1/leases/${leaseId}`),
			)
			this.leases = this.leases.map((lease) =>
				lease.id === leaseId ? response.data : lease,
			)
		},
	},
})
