/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vault-list helpers (restyle Stage 6): the subfolder rows the secret list
 * shows above the secrets (file-manager style), and path labels for the
 * move dialogs' folder pickers so same-named folders at different depths
 * stay distinguishable.
 */

/**
 * Marker prefix for the pseudo-row ids, so a folder row's `id` can never
 * collide with a secret id in CnIndexPage's `rowKey` space.
 *
 * @type {string}
 */
const FOLDER_ROW_PREFIX = 'folder:'

/**
 * The direct subfolders of `selectedFolderId` (or the top-level vaults when
 * null), as list pseudo-rows: filtered by the inline search term
 * (case-insensitive contains, matching the team decision that everything
 * visible in the list is searchable), sorted by name as one group.
 *
 * @param {Array<object>} folders The flat folder list (`{id, name, parentId}`).
 * @param {string|null} selectedFolderId The current folder (null = vault root).
 * @param {string} [searchTerm] The inline search term ('' = no filter).
 * @return {Array<{id: string, folderId: string, name: string, isFolder: true}>}
 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
 */
export function subfolderRows(folders, selectedFolderId, searchTerm = '') {
	const needle = String(searchTerm || '')
		.trim()
		.toLowerCase()
	return (folders || [])
		.filter(
			(folder) =>
				folder
				&& (selectedFolderId
					? folder.parentId === selectedFolderId
					: !folder.parentId),
		)
		.filter(
			(folder) =>
				needle === ''
				|| String(folder.name || '')
					.toLowerCase()
					.includes(needle),
		)
		.sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')))
		.map((folder) => ({
			id: `${FOLDER_ROW_PREFIX}${folder.id}`,
			folderId: folder.id,
			name: folder.name,
			isFolder: true,
			// Vault personalization keys (restyle Stage 9) ride along so the
			// root rows can render the picked icon + color.
			customIcon: folder.customIcon ?? null,
			customColor: folder.customColor ?? null,
		}))
}

/**
 * The "A / B / C" path label of a folder, walked root-first through
 * `parentId`. Guarded against parentId cycles (seen-set) and runaway depth;
 * an unknown id yields ''. Used by the move dialogs so a nested "Production"
 * is distinguishable from a top-level one.
 *
 * @param {Array<object>} folders The flat folder list (`{id, name, parentId}`).
 * @param {string} folderId The folder whose path to render.
 * @return {string} The slash-joined path, deepest folder last.
 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-folder-and-move-a-secret
 */
export function folderPathLabel(folders, folderId) {
	const byId = new Map((folders || []).map((f) => [f.id, f]))
	const names = []
	const seen = new Set()
	let current = byId.get(folderId)
	while (current && !seen.has(current.id) && names.length < 32) {
		seen.add(current.id)
		names.unshift(current.name)
		current = current.parentId ? byId.get(current.parentId) : null
	}
	return names.join(' / ')
}
