import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Delegation store — secret ownership delegation.
 *
 * @spec openspec/changes/implement-user-sharing/specs/ownership-delegation/spec.md
 */
export const useDelegationStore = defineStore('delegation', {
	state: () => ({
		/** @type {object[]} Delegations for the current secret */
		delegations: [],
		/** @type {boolean} */
		loading: false,
	}),

	actions: {
		/**
		 * Fetch delegations for a secret (owner/delegate only).
		 *
		 * @param {string} secretId The secret ID
		 */
		async fetchDelegations(secretId) {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/doriath/api/v1/delegations'),
					{ params: { secretId } },
				)
				this.delegations = response.data
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create a delegation (owner self-delegation or vault-admin power grab).
		 *
		 * @param {string} secretId The secret ID
		 * @param {string} delegateTo The delegate user ID
		 */
		async createDelegation(secretId, delegateTo) {
			const response = await axios.post(
				generateUrl('/apps/doriath/api/v1/delegations'),
				{ secretId, delegateTo },
			)
			this.delegations.push(response.data)
			return response.data
		},

		/**
		 * Reclaim (delete) all temporary delegations for a secret.
		 *
		 * @param {string} secretId The secret ID
		 */
		async reclaimDelegation(secretId) {
			await axios.delete(generateUrl(`/apps/doriath/api/v1/delegations/${secretId}`))
			this.delegations = this.delegations.filter(d => d.isPermanent === true)
		},
	},
})
