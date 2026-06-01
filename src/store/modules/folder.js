import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Folder store — the user's vault folder tree.
 */
export const useFolderStore = defineStore('folder', {
	state: () => ({
		/** @type {Array} Flat list of folders. */
		folders: [],
		/** @type {object|null} Currently-selected folder. */
		currentFolder: null,
		/** @type {boolean} */
		loading: false,
	}),

	getters: {
		/**
		 * Build a nested tree from the flat folder list.
		 *
		 * @param {object} state The store state.
		 * @return {Array} Root folders, each with a `children` array.
		 */
		folderTree: (state) => {
			const byId = {}
			state.folders.forEach((folder) => {
				byId[folder.id] = { ...folder, children: [] }
			})

			const roots = []
			state.folders.forEach((folder) => {
				const node = byId[folder.id]
				if (folder.parentId && byId[folder.parentId]) {
					byId[folder.parentId].children.push(node)
				} else {
					roots.push(node)
				}
			})

			return roots
		},
	},

	actions: {
		/**
		 * Fetch the current user's folders.
		 */
		async fetchFolders() {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/doriath/api/v1/folders'),
				)
				this.folders = response.data || []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create a folder.
		 *
		 * @param {object} data The folder data (name, parentId).
		 * @return {Promise<object>} The created folder.
		 */
		async createFolder(data) {
			const response = await axios.post(
				generateUrl('/apps/doriath/api/v1/folders'),
				data,
			)
			this.folders.push(response.data)
			return response.data
		},

		/**
		 * Rename or move a folder.
		 *
		 * @param {string} id   The folder ID.
		 * @param {object} data The fields to update (name and/or parentId).
		 * @return {Promise<object>} The updated folder.
		 */
		async updateFolder(id, data) {
			const response = await axios.put(
				generateUrl(`/apps/doriath/api/v1/folders/${id}`),
				data,
			)
			const index = this.folders.findIndex(f => f.id === id)
			if (index !== -1) {
				this.folders.splice(index, 1, response.data)
			}
			return response.data
		},

		/**
		 * Delete a folder, optionally with a cascade/resolution plan.
		 *
		 * @param {string} id         The folder ID.
		 * @param {object} resolution Optional { cascade, directSecrets, subfolders }.
		 */
		async deleteFolder(id, resolution = {}) {
			const config = {}
			if (resolution.cascade) {
				config.params = { cascade: resolution.cascade }
			}
			if (resolution.subfolders || resolution.directSecrets) {
				config.data = {
					subfolders: resolution.subfolders || {},
					directSecrets: resolution.directSecrets,
				}
			}

			await axios.delete(generateUrl(`/apps/doriath/api/v1/folders/${id}`), config)
			this.folders = this.folders.filter(f => f.id !== id)
		},

		/**
		 * Fetch a folder's children summary for the resolution dialog.
		 *
		 * @param {string} id The folder ID.
		 * @return {Promise<object>} { directSecretCount, subfolders }.
		 */
		async fetchChildren(id) {
			const response = await axios.get(
				generateUrl(`/apps/doriath/api/v1/folders/${id}/children`),
			)
			return response.data
		},
	},
})
