/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Pinia store for SecretDelegation rows.
 *
 * Backs the §12.5 DelegationManager UI and the share-flow authorization
 * check that consults whether a user is the active delegate before
 * letting them issue further shares. State is per-secret — the store is
 * reset between detail-view mounts.
 *
 * @spec openspec/changes/implement-user-sharing/tasks.md#11.2
 */

import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export const useDelegationStore = defineStore('delegation', {
	state: () => ({
		/** @type {Array<object>} Delegations for the currently focused secret. */
		delegations: [],
		/** @type {boolean} Whether a request is in flight. */
		loading: false,
		/** @type {string|null} The last error message. */
		error: null,
	}),

	getters: {
		/**
		 * Number of delegations currently displayed.
		 *
		 * @param {object} state The store state.
		 * @return {number}
		 */
		count: (state) => state.delegations.length,

		/**
		 * Whether the secret has any PERMANENT delegations. Permanent
		 * delegations cannot be reclaimed — the UI disables the reclaim
		 * button when only permanent rows remain.
		 *
		 * @param {object} state The store state.
		 * @return {boolean}
		 */
		hasPermanent: (state) =>
			state.delegations.some((row) => row.isPermanent === true),

		/**
		 * Whether the secret has any TEMPORARY delegations — drives the
		 * reclaim button's enabled state.
		 *
		 * @param {object} state The store state.
		 * @return {boolean}
		 */
		hasTemporary: (state) =>
			state.delegations.some((row) => row.isPermanent === false),
	},

	actions: {
		/**
		 * Hydrate the delegation list for a secret.
		 *
		 * @param {string} secretId The Secret ID
		 * @return {Promise<void>}
		 */
		async fetchDelegations(secretId) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(
					generateUrl(`/apps/doriath/api/v1/secrets/${secretId}/delegations`),
				)
				this.delegations = response.data || []
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message || 'Failed to load delegations'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create a temporary delegation.
		 *
		 * @param {string} secretId    The Secret ID
		 * @param {string} delegatedTo The Nextcloud UID of the delegate
		 * @return {Promise<object>} The created delegation
		 */
		async createDelegation(secretId, delegatedTo) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.post(
					generateUrl(`/apps/doriath/api/v1/secrets/${secretId}/delegations`),
					{ delegatedTo },
				)
				this.delegations.push(response.data)
				return response.data
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message || 'Failed to delegate'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Reclaim every temporary delegation for a secret.
		 *
		 * @param {string} secretId The Secret ID
		 * @return {Promise<number>} Removed count
		 */
		async reclaimDelegation(secretId) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.post(
					generateUrl(`/apps/doriath/api/v1/secrets/${secretId}/delegations/reclaim`),
				)
				// Drop temporary rows locally to match the server-side
				// behaviour without a refetch.
				this.delegations = this.delegations.filter((row) => row.isPermanent === true)
				return response.data?.removed ?? 0
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message || 'Failed to reclaim'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Reset the store between detail-view mounts.
		 *
		 * @return {void}
		 */
		reset() {
			this.delegations = []
			this.error = null
		},
	},
})
