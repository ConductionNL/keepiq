/**
 * The vault index the popup browses: names, addresses, types and folders,
 * never a decrypted value. Pure: no DOM, no network.
 *
 * @spec openspec/changes/clients-extension-generator-vault-send/specs/extension-vault/spec.md#requirement-browse-and-search-the-vault
 */

/**
 * One list entry from a server row, without its blobs.
 *
 * @param {object} row A secret as `GET /api/v1/secrets` returns it.
 * @param {Map<string, string>} typeNames Type id to type name.
 * @param {Map<string, string>} folderNames Folder id to folder name.
 * @return {object}
 */
export function toIndexEntry(row, typeNames, folderNames) {
	return {
		id: row.id,
		name: row.name || '',
		url: row.url || '',
		typeName: typeNames.get(row.typeId) || 'login',
		folderId: row.folderId || null,
		folderName: row.folderId ? folderNames.get(row.folderId) || '' : '',
		blocked: row.blocked === true,
		favourite: row.favourite === true,
	}
}

/**
 * Build the index from the server's rows, leaving out trashed and archived
 * secrets, sorted alphabetically by name.
 *
 * @param {Array<object>} rows The secrets.
 * @param {Array<object>} types The secret types ({id, name}).
 * @param {Array<object>} folders The folders ({id, name}).
 * @return {Array<object>}
 */
export function buildIndex(rows, types, folders) {
	const typeNames = new Map(types.map((t) => [t.id, t.name || t.slug]))
	const folderNames = new Map(folders.map((f) => [f.id, f.name]))
	return rows
		.filter((r) => !r.trashedAt && !r.archivedAt)
		.map((r) => toIndexEntry(r, typeNames, folderNames))
		.sort(byName)
}

/**
 * Alphabetical, case- and accent-insensitive.
 *
 * @param {{name: string}} a First entry.
 * @param {{name: string}} b Second entry.
 * @return {number}
 */
export function byName(a, b) {
	return (
		a.name.localeCompare(b.name, undefined, { sensitivity: 'base' })
		// Same name: a fixed order, so the list does not shuffle.
		|| String(a.id).localeCompare(String(b.id))
	)
}

/** The folder filter value for items in no folder. */
export const NO_FOLDER = '__none__'

/**
 * What the list should say: loading, an empty vault, nothing matching, every
 * item blocked, or the items.
 *
 * @param {Array<object>|null} index The whole index, or null while loading.
 * @param {Array<object>} shown The entries after filtering.
 * @return {'loading'|'empty'|'no-match'|'all-blocked'|'items'}
 * @spec openspec/changes/clients-extension-gaps/specs/extension-list-and-settings/spec.md#requirement-a-list-that-says-what-it-shows
 */
export function listState(index, shown) {
	if (index === null) return 'loading'
	if (index.length === 0) return 'empty'
	if (shown.length === 0) return 'no-match'
	if (index.every((e) => e.blocked)) return 'all-blocked'
	return 'items'
}

/**
 * The entries that match the search, folder and type filters.
 *
 * @param {Array<object>} entries The index.
 * @param {object} [filters] The filters.
 * @param {string} [filters.query] Matched against name and address, case-insensitive.
 * @param {string|null} [filters.folderId] Only this folder ('' or null: all).
 * @param {string|null} [filters.typeName] Only this type ('' or null: all).
 * @return {Array<object>}
 */
export function filterIndex(
	entries,
	{ query = '', folderId = null, typeName = null } = {},
) {
	const needle = query.trim().toLowerCase()
	return entries.filter(
		(e) =>
			(!folderId
				|| (folderId === NO_FOLDER ? !e.folderId : e.folderId === folderId))
			&& (!typeName || e.typeName === typeName)
			&& (needle === ''
				|| e.name.toLowerCase().includes(needle)
				|| e.url.toLowerCase().includes(needle)),
	)
}

/**
 * The type names present in the index, for the filter chips.
 *
 * @param {Array<object>} entries The index.
 * @return {string[]}
 */
export function presentTypes(entries) {
	return [...new Set(entries.map((e) => e.typeName))].sort()
}

/**
 * Folder choices as indented paths ("Work / Clients"), sorted by path.
 *
 * @param {Array<{id: string, name: string, parentId?: string|null}>} folders The folders.
 * @return {Array<{id: string, label: string}>}
 */
export function folderChoices(folders) {
	const byId = new Map(folders.map((f) => [f.id, f]))
	const pathOf = (folder, seen = new Set()) => {
		if (!folder.parentId || seen.has(folder.id) || !byId.has(folder.parentId)) {
			return folder.name
		}
		seen.add(folder.id)
		return `${pathOf(byId.get(folder.parentId), seen)} / ${folder.name}`
	}
	return folders
		.map((f) => ({ id: f.id, label: pathOf(f) }))
		.sort((a, b) =>
			a.label.localeCompare(b.label, undefined, { sensitivity: 'base' }),
		)
}
