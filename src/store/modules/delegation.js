import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export const useDelegationStore = defineStore('delegation', {
	state: () => ({
		/** @type {object[]} List of delegations for the current secret */
		delegations: [],
		/** @type {boolean} Loading state */
		loading: false,
	}),

	actions: {
		/**
		 * Fetch all delegations for a secret.
		 *
		 * @param {string} secretId Secret ID
		 * @return {Promise<void>}
		 */
		async fetchDelegations(secretId) {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/doriath/api/v1/delegations'),
					{ params: { secretId } },
				)
				this.delegations = response.data ?? []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create a delegation for a secret to another user.
		 *
		 * @param {string} secretId The secret ID to delegate
		 * @param {string} delegateTo The user ID to delegate to
		 * @return {Promise<object>} The created delegation
		 */
		async createDelegation(secretId, delegateTo) {
			const response = await axios.post(
				generateUrl('/apps/doriath/api/v1/delegations'),
				{ secretId, delegateTo },
			)
			const delegation = response.data
			this.delegations.push(delegation)
			return delegation
		},

		/**
		 * Reclaim a delegated secret (owner takes back control).
		 *
		 * @param {string} secretId The secret ID to reclaim
		 * @return {Promise<object>} The reclaim result
		 */
		async reclaimDelegation(secretId) {
			const response = await axios.post(
				generateUrl(`/apps/doriath/api/v1/delegations/reclaim/${secretId}`),
			)
			this.delegations = this.delegations.filter(d => d.secretId !== secretId)
			return response.data
		},
	},
})
