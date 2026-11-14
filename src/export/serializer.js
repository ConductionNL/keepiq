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

import { isUseOnly } from '../utils/shareRestriction.js'
import { BLOB_FIELDS } from '../utils/valueFields.js'

/** The serialized payload format identifier. */
export const PAYLOAD_FORMAT = 'keepiq-vault'

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
	// `ancestors` holds the folders on the path being resolved, so a parent
	// cycle ends the path at the folder it loops back to.
	const resolve = (id, ancestors = new Set()) => {
		if (id == null) {
			return ''
		}
		if (cache.has(id)) {
			return cache.get(id)
		}
		const folder = byId.get(id)
		if (!folder || ancestors.has(id) || ancestors.size > 1000) {
			return ''
		}
		ancestors.add(id)
		const parentPath = resolve(folder.parentId, ancestors)
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
 * @return {Set<string>} The selected IDs that exist plus every descendant ID.
 */
function collectSubtree(folders, rootIds) {
	const childrenOf = new Map()
	for (const folder of folders) {
		const list = childrenOf.get(folder.parentId) || []
		list.push(folder.id)
		childrenOf.set(folder.parentId, list)
	}
	const known = new Set(folders.map((folder) => folder.id))
	const selected = new Set()
	// Only folders that exist start a subtree: an unknown id selects nothing.
	const stack = rootIds.filter((id) => known.has(id))
	let guard = 0
	while (stack.length > 0 && guard < 100000) {
		guard++
		const id = stack.pop()
		if (selected.has(id)) {
			continue
		}
		selected.add(id)
		for (const childId of childrenOf.get(id) || []) {
			stack.push(childId)
		}
	}
	return selected
}

/**
 * Whether a secret goes into the export: never a use-only copy
 * (sharing-use-only-and-expiring-shares D3), and with a folder scope only a
 * secret whose folder is within a selected subtree.
 *
 * @param {object} secret A decrypted secret.
 * @param {Set<string>|null} includedFolderIds The selected subtree, or null for the whole vault.
 * @return {boolean}
 */
function isExported(secret, includedFolderIds) {
	if (isUseOnly(secret)) {
		return false
	}
	return (
		includedFolderIds === null
		|| (secret.folderId != null && includedFolderIds.has(secret.folderId))
	)
}

/**
 * The folder path of a secret, '' when it has no folder or an unknown one.
 *
 * @param {object} secret A decrypted secret.
 * @param {Map<string, string>} pathMap Folder id to path.
 * @return {string}
 */
function folderPath(secret, pathMap) {
	return secret.folderId != null ? (pathMap.get(secret.folderId) ?? '') : ''
}

/**
 * A login's own code and passkeys, only those it has.
 *
 * @param {object} secret A decrypted secret.
 * @return {object} The non-empty blob fields.
 */
function blobFields(secret) {
	return Object.fromEntries(
		BLOB_FIELDS.filter(
			(field) => typeof secret[field] === 'string' && secret[field] !== '',
		).map((field) => [field, secret[field]]),
	)
}

/**
 * One secret as an export entry, with its folder as a path.
 *
 * @param {object} secret A decrypted secret.
 * @param {Map<string, string>} pathMap Folder id to path.
 * @return {object} The entry.
 */
function exportEntry(secret, pathMap) {
	return {
		name: secret.name ?? '',
		url: secret.url ?? null,
		type: secret.type ?? secret.typeId ?? 'login',
		login: secret.login ?? null,
		password: secret.key ?? null,
		additionalFields: secret.additionalFields ?? null,
		folder: folderPath(secret, pathMap),
		...blobFields(secret),
	}
}

/**
 * Serialize decrypted secrets + folders into the versioned export payload.
 *
 * @param {Array<object>} secrets Decrypted secrets (name, url, login, key,
 *   additionalFields, totp, passkeys, type, folderId).
 * @param {Array<object>} folders Folder rows ({ id, name, parentId }).
 * @param {object} [scope] Scope selector.
 * @param {string} [scope.mode] 'vault' (default), 'folders' or 'selection'.
 * @param {Array<string>} [scope.folderIds] Selected folder IDs when mode is 'folders'.
 * @param {Array<string>} [scope.secretIds] Selected secret IDs when mode is 'selection'.
 * @return {object} The `{ format, version, secrets, folders }` payload.
 * @spec openspec/changes/portability-cxp-cross-provider-transfer/specs/portability-cxp/spec.md#requirement-choose-what-to-send
 */
export function serializeVault(secrets, folders, scope = { mode: 'vault' }) {
	const pathMap = buildPathMap(folders)
	const includedFolderIds =
		scope && scope.mode === 'folders'
			? collectSubtree(folders, scope.folderIds || [])
			: null
	const selectedIds =
		scope && scope.mode === 'selection'
			? new Set((scope.secretIds || []).map(String))
			: null

	const exported = secrets.filter(
		(secret) =>
			isExported(secret, includedFolderIds)
			&& (selectedIds === null || selectedIds.has(String(secret.id))),
	)
	// A selection carries the folders its secrets sit in, so their paths
	// travel; the folder scope carries its subtree, the vault every folder.
	const usedFolderIds =
		selectedIds === null
			? null
			: new Set(exported.map((secret) => secret.folderId).filter((id) => id !== null && id !== undefined))

	return {
		format: PAYLOAD_FORMAT,
		version: PAYLOAD_VERSION,
		secrets: exported.map((secret) => exportEntry(secret, pathMap)),
		folders: folders
			.filter(
				(folder) =>
					(includedFolderIds === null || includedFolderIds.has(folder.id))
					&& (usedFolderIds === null || usedFolderIds.has(folder.id)),
			)
			.map((folder) => ({ path: pathMap.get(folder.id) ?? folder.name })),
	}
}
