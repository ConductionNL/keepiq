import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export const useFolderStore = defineStore('folder', {
	state: () => ({
		/** @type {object[]} Flat list of all folders */
		folders: [],
		/** @type {object|null} Currently selected folder */
		currentFolder: null,
		/** @type {boolean} */
		loading: false,
	}),

	getters: {
		/**
		 * Computed tree structure from the flat folder list, grouped by parentId.
		 *
		 * @param {object} state Pinia state
		 * @return {object[]} Root-level folders with nested children arrays
		 */
		folderTree: (state) => {
			const map = {}
			state.folders.forEach(folder => {
				map[folder.id] = { ...folder, children: [] }
			})

			const roots = []
			state.folders.forEach(folder => {
				if (folder.parentId && map[folder.parentId]) {
					map[folder.parentId].children.push(map[folder.id])
				} else {
					roots.push(map[folder.id])
				}
			})

			return roots
		},
	},

	actions: {
		/**
		 * Fetch all folders for the current user.
		 *
		 * @return {Promise<void>}
		 */
		async fetchFolders() {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/doriath/api/v1/folders'),
				)
				this.folders = response.data.results ?? response.data
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create a new folder.
		 *
		 * @param {string} name Folder name
		 * @param {string|null} parentId Parent folder ID, or null for root
		 * @return {Promise<object>} The created folder
		 */
		async createFolder(name, parentId = null) {
			const response = await axios.post(
				generateUrl('/apps/doriath/api/v1/folders'),
				{ name, parentId },
			)
			this.folders.push(response.data)
			return response.data
		},

		/**
		 * Update an existing folder's name or parent.
		 *
		 * @param {string} id Folder ID
		 * @param {string} name Updated folder name
		 * @param {string|null} parentId Updated parent folder ID
		 * @return {Promise<object>} The updated folder
		 */
		async updateFolder(id, name, parentId = null) {
			const response = await axios.put(
				generateUrl(`/apps/doriath/api/v1/folders/${id}`),
				{ name, parentId },
			)
			const index = this.folders.findIndex(f => f.id === id)
			if (index !== -1) {
				this.folders.splice(index, 1, response.data)
			}
			return response.data
		},

		/**
		 * Delete a folder, optionally cascading to subfolders and secrets.
		 *
		 * @param {string} id Folder ID
		 * @param {boolean} cascade Whether to delete subfolders and secrets
		 * @param {object|null} resolution Resolution instructions for secrets/subfolders
		 * @return {Promise<void>}
		 */
		async deleteFolder(id, cascade = false, resolution = null) {
			const params = cascade ? { cascade: true } : {}
			await axios.delete(
				generateUrl(`/apps/doriath/api/v1/folders/${id}`),
				{ params, data: resolution ? { resolution } : undefined },
			)
			this.folders = this.folders.filter(f => f.id !== id)
			if (this.currentFolder?.id === id) {
				this.currentFolder = null
			}
		},

		/**
		 * Fetch the direct children of a folder.
		 *
		 * @param {string} id Parent folder ID
		 * @return {Promise<object[]>} Array of child folders
		 */
		async fetchChildren(id) {
			const response = await axios.get(
				generateUrl(`/apps/doriath/api/v1/folders/${id}/children`),
			)
			return response.data.results ?? response.data
		},
	},
})
