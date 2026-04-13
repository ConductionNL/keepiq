/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Build a human-readable path string from a folder ID by walking up the
 * parentId chain. Returns '/' for root (null/undefined folderId).
 *
 * @param {string|null} folderId The folder to build a path for
 * @param {object} foldersById Map of folder ID → folder object
 * @return {string} Path string, e.g. '/folder1/media'
 */
export function folderIdToPath(folderId, foldersById) {
	if (!folderId) return '/'

	const segments = []
	let current = foldersById[folderId]
	while (current) {
		segments.unshift(current.name)
		current = current.parentId ? foldersById[current.parentId] : null
	}

	return '/' + segments.join('/')
}

/**
 * Resolve a path string back to a folder ID by walking down the tree from
 * root. Matching is case-insensitive (consistent with isDuplicateName).
 *
 * @param {string} dirPath The path to resolve, e.g. '/folder1/media'
 * @param {object[]} folders Flat array of all folders
 * @return {string|null|undefined} Folder ID, null for root, undefined if not found
 */
export function pathToFolderId(dirPath, folders) {
	if (!dirPath || dirPath === '/') return null

	const segments = dirPath.split('/').filter(Boolean)
	if (segments.length === 0) return null

	let currentParentId = null
	let resolvedId = null

	for (const name of segments) {
		const folder = folders.find(
			f => (f.parentId ?? null) === currentParentId
				&& f.name.toLowerCase() === name.toLowerCase(),
		)
		if (!folder) return undefined
		resolvedId = folder.id
		currentParentId = folder.id
	}

	return resolvedId
}

/**
 * Convenience function that returns a route-ready query object for a folder.
 *
 * @param {string|null} folderId Folder ID, or null for root
 * @param {object} foldersById Map of folder ID → folder object
 * @return {object} Query object, e.g. { dir: '/folder1/media' }
 */
export function folderDirQuery(folderId, foldersById) {
	return { dir: folderId ? folderIdToPath(folderId, foldersById) : '/' }
}
