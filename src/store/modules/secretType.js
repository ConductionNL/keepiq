import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export const useSecretTypeStore = defineStore('secretType', {
	state: () => ({
		/** @type {object[]} List of secret types */
		types: [],
		/** @type {boolean} */
		loading: false,
	}),

	actions: {
		/**
		 * Fetch all available secret types.
		 *
		 * @return {Promise<void>}
		 */
		async fetchTypes() {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/doriath/api/v1/secret-types'),
				)
				this.types = response.data.results ?? response.data
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create a new secret type.
		 *
		 * @param {string} name Internal type name/slug
		 * @param {string} label Human-readable label
		 * @param {string} scope Type scope (e.g. 'personal', 'shared')
		 * @return {Promise<object>} The created type
		 */
		async createType(name, label, scope) {
			const response = await axios.post(
				generateUrl('/apps/doriath/api/v1/secret-types'),
				{ name, label, scope },
			)
			this.types.push(response.data)
			return response.data
		},

		/**
		 * Update the label of an existing secret type.
		 *
		 * @param {string} id Type ID
		 * @param {string} label Updated human-readable label
		 * @return {Promise<object>} The updated type
		 */
		async updateType(id, label) {
			const response = await axios.put(
				generateUrl(`/apps/doriath/api/v1/secret-types/${id}`),
				{ label },
			)
			const index = this.types.findIndex(t => t.id === id)
			if (index !== -1) {
				this.types.splice(index, 1, response.data)
			}
			return response.data
		},

		/**
		 * Delete a secret type by ID.
		 *
		 * @param {string} id Type ID
		 * @return {Promise<void>}
		 */
		async deleteType(id) {
			await axios.delete(
				generateUrl(`/apps/doriath/api/v1/secret-types/${id}`),
			)
			this.types = this.types.filter(t => t.id !== id)
		},
	},
})
