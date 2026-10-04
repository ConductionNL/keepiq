/**
 * Folder rules for the folder manager: name checks, the tree, and the
 * delete plan the server's deletion protocol expects. Pure: no DOM, no
 * network.
 *
 * @spec openspec/specs/extension-vault/spec.md#requirement-manage-folders
 */

/**
 * Why a folder name cannot be used, or null.
 *
 * @param {string} name The name.
 * @return {string|null}
 */
export function folderNameProblem(name) {
	const clean = String(name ?? '').trim()
	if (clean === '') return 'Name is required'
	if (clean.includes('/')) return 'Folder names cannot contain slashes'
	if (clean.length > 255) return 'At most 255 characters'
	return null
}

/**
 * The folders as a depth-first tree, siblings sorted by name.
 *
 * @param {Array<{id: string, name: string, parentId?: string|null}>} folders The folders.
 * @return {Array<{id: string, name: string, parentId: string|null, depth: number}>}
 */
export function folderTree(folders) {
	const ids = new Set(folders.map((f) => f.id))
	const children = new Map()
	for (const folder of folders) {
		const parent =
			folder.parentId && ids.has(folder.parentId) ? folder.parentId : null
		if (!children.has(parent)) children.set(parent, [])
		children.get(parent).push(folder)
	}
	const out = []
	const seen = new Set()
	const walk = (parent, depth) => {
		const list = (children.get(parent) || [])
			.slice()
			.sort((a, b) =>
				a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }),
			)
		for (const folder of list) {
			if (seen.has(folder.id)) continue
			seen.add(folder.id)
			out.push({
				id: folder.id,
				name: folder.name,
				parentId: folder.parentId || null,
				depth,
			})
			walk(folder.id, depth + 1)
		}
	}
	walk(null, 0)
	return out
}

/**
 * Which delete dialog a folder needs, from its children summary.
 *
 * @param {{directSecretCount: number, subfolders: Array<object>}} children From `GET /folders/{id}/children`.
 * @return {'empty'|'items'|'subfolders'}
 */
export function deleteKind(children) {
	if ((children?.subfolders || []).length > 0) return 'subfolders'
	if ((children?.directSecretCount || 0) > 0) return 'items'
	return 'empty'
}

/**
 * The request for a delete: a cascade for a leaf, a plan for a folder with
 * subfolders, nothing for an empty one. Every direct subfolder must have an
 * action, as the server requires.
 *
 * @param {'empty'|'items'|'subfolders'} kind From deleteKind.
 * @param {object} choice The user's choice.
 * @param {'delete'|'move'} [choice.items] What happens to the folder's own items.
 * @param {Record<string, 'delete'|'move'|'keep'>} [choice.subfolders] Action per direct subfolder.
 * @param {Array<{id: string}>} [subfolders] The direct subfolders.
 * @return {{cascade?: string, resolution?: object}}
 */
export function deleteRequest(kind, choice = {}, subfolders = []) {
	if (kind === 'items') {
		return { cascade: choice.items === 'delete' ? 'delete' : 'move' }
	}
	if (kind === 'subfolders') {
		const plan = {}
		for (const sub of subfolders) {
			const action = choice.subfolders?.[sub.id]
			plan[sub.id] = ['delete', 'move', 'keep'].includes(action)
				? action
				: 'keep'
		}
		return {
			resolution: {
				directSecrets: choice.items === 'delete' ? 'delete' : 'move',
				subfolders: plan,
			},
		}
	}
	return {}
}
