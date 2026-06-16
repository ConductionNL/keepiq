/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vault serializer (secret-export-gdpr D1/D3, tasks §5.1).
 *
 * Turns the browser-decrypted set of secrets plus the folder tree into the
 * versioned `{ secrets, folders }` payload that both the encrypted backup
 * (backup.js) and the GDPR package (gdprPackage.js) carry. Folder placement is
 * recorded as relative slash-separated paths so the structure survives an
 * import into a vault with different folder IDs.
 *
 * Scope selection narrows the export to either the whole vault or a set of
 * folder subtrees. Scope selection NEVER changes the security gating of the
 * chosen export mode — it only filters which already-decrypted rows are written.
 */

/** The serialized payload format identifier. */
export const PAYLOAD_FORMAT = 'doriath-vault'

/** The serialized payload version. */
export const PAYLOAD_VERSION = 1

/**
 * Build a folderId -> relative-path map from the folder list.
 *
 * @param {Array<object>} folders Folder rows ({ id, name, parentId }).
 * @return {Map<string, string>} folderId -> "Parent/Child" path.
 */
function buildPathMap(folders) {
	const byId = new Map()
	for (const folder of folders) {
		byId.set(folder.id, folder)
	}
	const cache = new Map()
	const resolve = (id, guard = 0) => {
		if (id == null) {
			return ''
		}
		if (cache.has(id)) {
			return cache.get(id)
		}
		const folder = byId.get(id)
		if (!folder || guard > 1000) {
			return ''
		}
		const parentPath = resolve(folder.parentId, guard + 1)
		const path = parentPath ? `${parentPath}/${folder.name}` : folder.name
		cache.set(id, path)
		return path
	}
	const map = new Map()
	for (const folder of folders) {
		map.set(folder.id, resolve(folder.id))
	}
	return map
}

/**
 * Collect a folder ID and all of its descendant folder IDs.
 *
 * @param {Array<object>} folders Folder rows ({ id, parentId }).
 * @param {Array<string>} rootIds The selected folder IDs.
 * @return {Set<string>} The selected IDs plus every descendant ID.
 */
function collectSubtree(folders, rootIds) {
	const childrenOf = new Map()
	for (const folder of folders) {
		const list = childrenOf.get(folder.parentId) || []
		list.push(folder.id)
		childrenOf.set(folder.parentId, list)
	}
	const selected = new Set()
	const stack = [...rootIds]
	let guard = 0
	while (stack.length > 0 && guard < 100000) {
		guard++
		const id = stack.pop()
		if (selected.has(id)) {
			continue
		}
		selected.add(id)
		for (const childId of (childrenOf.get(id) || [])) {
			stack.push(childId)
		}
	}
	return selected
}

/**
 * Serialize decrypted secrets + folders into the versioned export payload.
 *
 * @param {Array<object>} secrets Decrypted secrets (name, url, login, key,
 *   additionalFields, type, folderId).
 * @param {Array<object>} folders Folder rows ({ id, name, parentId }).
 * @param {object} [scope] Scope selector.
 * @param {string} [scope.mode] 'vault' (default) or 'folders'.
 * @param {Array<string>} [scope.folderIds] Selected folder IDs when mode is 'folders'.
 * @return {object} The `{ format, version, secrets, folders }` payload.
 */
export function serializeVault(secrets, folders, scope = { mode: 'vault' }) {
	const pathMap = buildPathMap(folders)

	let includedFolderIds = null
	if (scope && scope.mode === 'folders') {
		includedFolderIds = collectSubtree(folders, scope.folderIds || [])
	}

	const includedSecrets = []
	for (const secret of secrets) {
		if (includedFolderIds !== null) {
			// Only secrets whose folder is within a selected subtree.
			if (secret.folderId == null || !includedFolderIds.has(secret.folderId)) {
				continue
			}
		}
		includedSecrets.push({
			name: secret.name ?? '',
			url: secret.url ?? null,
			type: secret.type ?? secret.typeId ?? 'login',
			login: secret.login ?? null,
			password: secret.key ?? null,
			additionalFields: secret.additionalFields ?? null,
			folder: secret.folderId != null ? (pathMap.get(secret.folderId) ?? '') : '',
		})
	}

	const includedFolders = []
	for (const folder of folders) {
		if (includedFolderIds !== null && !includedFolderIds.has(folder.id)) {
			continue
		}
		includedFolders.push({ path: pathMap.get(folder.id) ?? folder.name })
	}

	return {
		format: PAYLOAD_FORMAT,
		version: PAYLOAD_VERSION,
		secrets: includedSecrets,
		folders: includedFolders,
	}
}
