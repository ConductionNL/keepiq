import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import { useOfflineStore } from './offline.js'

/**
 * Build a nested tree from a flat folder list.
 *
 * @param {Array<object>} folders The flat folder list.
 * @return {Array<object>} Root folders, each with a `children` array.
 */
function buildTree(folders) {
	const byId = {}
	folders.forEach((f) => {
		byId[f.id] = { ...f, children: [] }
	})
	const roots = []
	folders.forEach((f) => {
		if (f.parentId && byId[f.parentId]) {
			byId[f.parentId].children.push(byId[f.id])
		} else {
			roots.push(byId[f.id])
		}
	})
	return roots
}

/**
 * Pinia store for the per-user folder tree.
 */
export const useFolderStore = defineStore('folder', {
	state: () => ({
		/** @type {Array<object>} The flat folder list. */
		folders: [],
		/** @type {object|null} The currently selected folder. */
		currentFolder: null,
		/** @type {boolean} Whether a request is in flight. */
		loading: false,
	}),

	getters: {
		/**
		 * The folder list arranged as a nested tree.
		 *
		 * @param {object} state The store state.
		 * @return {Array<object>} The folder tree.
		 */
		folderTree: (state) => buildTree(state.folders),
	},

	actions: {
		/**
		 * Fetch the current user's folders.
		 *
		 * @return {Promise<void>}
		 */
		async fetchFolders() {
			this.loading = true
			const offline = useOfflineStore()
			if (offline.servedFromCache && offline.vault?.folders) {
				this.folders = offline.vault.folders
				this.loading = false
				return
			}
			try {
				const response = await axios.get(
					generateUrl('/apps/doriath/api/v1/folders'),
				)
				this.folders = response.data || []
			} catch (e) {
				// Offline fallback: serve the cached folder tree (offline-
				// readonly-cache §4.2).
				const netErr =
					e?.message === 'Network Error'
					|| e?.code === 'ERR_NETWORK'
					|| (e?.request && !e?.response)
				if (netErr && offline.vault?.folders) {
					offline.servedFromCache = true
					this.folders = offline.vault.folders
				} else {
					throw e
				}
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create a folder.
		 *
		 * @param {string} name The folder name.
		 * @param {string|null} parentId The parent folder ID (null = root).
		 * @return {Promise<object>} The created folder.
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
		 * Rename and/or move a folder.
		 *
		 * @param {string} id The folder ID.
		 * @param {object} data The change ({ name } and/or { parentId, move: true }).
		 * @return {Promise<object>} The updated folder.
		 */
		async updateFolder(id, data) {
			const response = await axios.put(
				generateUrl(`/apps/doriath/api/v1/folders/${id}`),
				data,
			)
			const index = this.folders.findIndex((f) => f.id === id)
			if (index !== -1) {
				this.folders.splice(index, 1, response.data)
			}
			return response.data
		},

		/**
		 * Fetch a folder's children for the resolution dialog.
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

		/**
		 * Delete a folder, optionally with a cascade mode or resolution body.
		 *
		 * @param {string} id The folder ID.
		 * @param {object} options { cascade } and/or { subfolders, directSecrets }.
		 * @return {Promise<void>}
		 */
		async deleteFolder(id, options = {}) {
			const config = {}
			if (options.cascade) {
				config.params = { cascade: options.cascade }
			}
			if (options.subfolders) {
				config.data = {
					subfolders: options.subfolders,
					directSecrets: options.directSecrets || 'move',
				}
			}
			await axios.delete(
				generateUrl(`/apps/doriath/api/v1/folders/${id}`),
				config,
			)
			this.folders = this.folders.filter((f) => f.id !== id)
		},
	},
})
