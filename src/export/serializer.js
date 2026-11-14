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
 * (sharing-use-only-and-expiring-shares D3), and only a secret every filter
 * of the scope lets through (portability-export-choice-and-restore-fidelity
 * D1): its folder within a selected subtree, its type among the chosen
 * types, itself among the selected secrets. A filter that is null lets every
 * secret through.
 *
 * @param {object} secret A decrypted secret.
 * @param {object} filters The scope's filters.
 * @param {Set<string>|null} filters.folderIds The selected subtree, or null.
 * @param {Set<string>|null} filters.typeIds The chosen type ids, or null.
 * @param {Set<string>|null} filters.secretIds The selected secret ids, or null.
 * @return {boolean}
 * @spec openspec/changes/portability-export-choice-and-restore-fidelity/specs/export-selection-and-restore/spec.md#requirement-choose-what-an-export-contains
 */
function isExported(secret, { folderIds, typeIds, secretIds }) {
	if (isUseOnly(secret)) {
		return false
	}
	return (
		(folderIds === null || (secret.folderId != null && folderIds.has(secret.folderId)))
		&& (typeIds === null || typeIds.has(secretTypeId(secret)))
		&& (secretIds === null || secretIds.has(secret.id))
	)
}

/**
 * The type id of a decrypted secret: the server's `typeId`, else the `type` it was built with.
 *
 * @param {object} secret A decrypted secret.
 * @return {string} The type id, '' when it has none.
 */
function secretTypeId(secret) {
	return String(secret.typeId ?? secret.type ?? '')
}

/**
 * The fields a user may leave out of an export (D2). The name and the value
 * always go in: a backup without values is not a backup.
 */
export const OMITTABLE_FIELDS = Object.freeze(['login', 'additionalFields', 'totp'])

/**
 * The scope's filters as sets, null for a filter the scope does not set.
 * The legacy `{ mode: 'folders', folderIds }` shape is a folder filter.
 *
 * @param {object} scope The scope.
 * @param {Array<object>} folders Folder rows ({ id, parentId }).
 * @return {{folderIds: Set<string>|null, typeIds: Set<string>|null, secretIds: Set<string>|null}}
 */
function scopeFilters(scope, folders) {
	const listed = (key) =>
		scope && Array.isArray(scope[key]) && scope[key].length > 0
			? scope[key].map(String)
			: null
	return {
		folderIds: folderFilter(scope, folders),
		typeIds: listed('typeIds') === null ? null : new Set(listed('typeIds')),
		secretIds: listed('secretIds') === null ? null : new Set(listed('secretIds')),
	}
}

/**
 * The folder filter. The legacy `{ mode: 'folders', folderIds }` keeps its
 * meaning exactly (no ids listed selects nothing); any other `mode` is the
 * whole vault; a scope without `mode` filters on `folderIds` when it lists any.
 *
 * @param {object} scope The scope.
 * @param {Array<object>} folders Folder rows ({ id, parentId }).
 * @return {Set<string>|null} The selected subtree, or null for every folder.
 */
function folderFilter(scope, folders) {
	if (!scope) {
		return null
	}
	if (scope.mode === 'folders') {
		return collectSubtree(folders, scope.folderIds || [])
	}
	if (scope.mode !== undefined || !Array.isArray(scope.folderIds) || scope.folderIds.length === 0) {
		return null
	}
	return collectSubtree(folders, scope.folderIds)
}

/**
 * The entry with the fields the user left out cleared. Leaving out the
 * one-time code seed drops the login's own code and a `totp` kept among the
 * additional fields.
 *
 * @param {object} entry The export entry.
 * @param {Array<string>} omitted The fields left out.
 * @return {object} The same entry.
 */
function withoutFields(entry, omitted) {
	if (omitted.includes('login')) {
		entry.login = null
	}
	if (omitted.includes('additionalFields')) {
		entry.additionalFields = null
	}
	if (omitted.includes('totp')) {
		delete entry.totp
		const af = entry.additionalFields
		if (af && typeof af === 'object' && !Array.isArray(af) && 'totp' in af) {
			const rest = { ...af }
			delete rest.totp
			entry.additionalFields = rest
		}
	}
	return entry
}

/**
 * The type name, and for a custom type its label, of a secret (D3), so a
 * restore on another instance can find or recreate the type.
 *
 * @param {object} secret A decrypted secret.
 * @param {Map<string, object>} typesById The vault's types by id.
 * @return {object} `{ typeName, typeLabel? }`, or {} for an unknown type.
 */
function typeNames(secret, typesById) {
	const type = typesById.get(secretTypeId(secret))
	if (!type || !type.name) {
		return {}
	}
	if (type.scope === 'system' || !type.label) {
		return { typeName: type.name }
	}
	return { typeName: type.name, typeLabel: type.label }
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
 * @param {Array<object>} secrets Decrypted secrets (id, name, url, login, key,
 *   additionalFields, totp, passkeys, typeId, folderId).
 * @param {Array<object>} folders Folder rows ({ id, name, parentId }).
 * @param {object} [scope] The scope (D1): `folderIds` (with their
 *   subfolders), `typeIds`, `secretIds`; the filters combine, and none set is
 *   the whole vault. `{ mode: 'folders', folderIds }` still reads as a folder scope.
 * @param {object} [options] Options.
 * @param {Array<object>} [options.types] The vault's types ({ id, name, label, scope }), for `typeName`/`typeLabel`.
 * @param {Array<string>} [options.omitFields] Fields to leave out, from OMITTABLE_FIELDS (D2).
 * @return {object} The `{ format, version, secrets, folders }` payload, with
 *   `partial: true` and `omittedFields` when fields were left out.
 * @spec openspec/changes/portability-export-choice-and-restore-fidelity/specs/export-selection-and-restore/spec.md#requirement-choose-what-an-export-contains
 */
export function serializeVault(secrets, folders, scope = { mode: 'vault' }, options = {}) {
	const pathMap = buildPathMap(folders)
	const filters = scopeFilters(scope, folders)
	const typesById = new Map(
		(Array.isArray(options.types) ? options.types : [])
			.filter((type) => type && type.id)
			.map((type) => [String(type.id), type]),
	)
	const omitted = OMITTABLE_FIELDS.filter((field) =>
		(options.omitFields || []).includes(field),
	)

	const payload = {
		format: PAYLOAD_FORMAT,
		version: PAYLOAD_VERSION,
		secrets: secrets
			.filter((secret) => isExported(secret, filters))
			.map((secret) =>
				withoutFields(
					{ ...exportEntry(secret, pathMap), ...typeNames(secret, typesById) },
					omitted,
				),
			),
		folders: folders
			.filter(
				(folder) =>
					filters.folderIds === null || filters.folderIds.has(folder.id),
			)
			.map((folder) => ({ path: pathMap.get(folder.id) ?? folder.name })),
	}
	if (omitted.length > 0) {
		payload.partial = true
		payload.omittedFields = omitted
	}
	return payload
}

/**
 * The scope name the export event reports: `vault` for an export with no
 * filter, `folders` for any narrowed one.
 *
 * @param {object} scope The scope.
 * @return {string} `vault` or `folders`.
 */
export function reportedScope(scope) {
	if (!scope) {
		return 'vault'
	}
	if (scope.mode !== undefined) {
		return scope.mode === 'folders' ? 'folders' : 'vault'
	}
	const narrowed = ['folderIds', 'typeIds', 'secretIds'].some(
		(key) => Array.isArray(scope[key]) && scope[key].length > 0,
	)
	return narrowed ? 'folders' : 'vault'
}
