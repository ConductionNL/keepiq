import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Pinia store for secret types (system + global + the user's own).
 */
export const useSecretTypeStore = defineStore('secretType', {
	state: () => ({
		/** @type {Array<object>} The available secret types. */
		types: [],
		/** @type {boolean} Whether a request is in flight. */
		loading: false,
	}),

	getters: {
		/**
		 * Map of type id -> type for quick lookup.
		 *
		 * @param {object} state The store state.
		 * @return {object} The id -> type map.
		 */
		typesById: (state) => Object.fromEntries(state.types.map(t => [t.id, t])),
	},

	actions: {
		/**
		 * Fetch the secret types available to the current user.
		 *
		 * @return {Promise<void>}
		 */
		async fetchTypes() {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/doriath/api/v1/secret-types'),
				)
				this.types = response.data || []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create a custom secret type.
		 *
		 * @param {object} data The type fields (name, label, scope).
		 * @return {Promise<object>} The created type.
		 */
		async createType(data) {
			const response = await axios.post(
				generateUrl('/apps/doriath/api/v1/secret-types'),
				{ name: data.name, label: data.label, scope: data.scope || 'user' },
			)
			this.types.push(response.data)
			return response.data
		},

		/**
		 * Relabel a custom secret type.
		 *
		 * @param {string} id The type ID.
		 * @param {string} label The new label.
		 * @return {Promise<object>} The updated type.
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
		 * Delete a custom secret type (its secrets fall back to login).
		 *
		 * @param {string} id The type ID.
		 * @return {Promise<void>}
		 */
		async deleteType(id) {
			await axios.delete(generateUrl(`/apps/doriath/api/v1/secret-types/${id}`))
			this.types = this.types.filter(t => t.id !== id)
		},
	},
})
