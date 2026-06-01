import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Secret-type store — system, global and user-scoped types available to the
 * current user.
 */
export const useSecretTypeStore = defineStore('secretType', {
	state: () => ({
		/** @type {Array} Available types (system + global + own). */
		types: [],
		/** @type {boolean} */
		loading: false,
	}),

	getters: {
		/**
		 * The `login` system type (the default).
		 *
		 * @param {object} state The store state.
		 * @return {object|undefined} The login type.
		 */
		loginType: (state) => state.types.find(t => t.name === 'login'),
	},

	actions: {
		/**
		 * Fetch the types available to the current user.
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
		 * Create a custom type.
		 *
		 * @param {object} data The type data (name, label, scope).
		 * @return {Promise<object>} The created type.
		 */
		async createType(data) {
			const response = await axios.post(
				generateUrl('/apps/doriath/api/v1/secret-types'),
				data,
			)
			this.types.push(response.data)
			return response.data
		},

		/**
		 * Update a custom type's label.
		 *
		 * @param {string} id    The type ID.
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
		 * Delete a custom type (its secrets fall back to the login type).
		 *
		 * @param {string} id The type ID.
		 */
		async deleteType(id) {
			await axios.delete(generateUrl(`/apps/doriath/api/v1/secret-types/${id}`))
			this.types = this.types.filter(t => t.id !== id)
		},
	},
})
