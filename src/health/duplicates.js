/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Duplicate detection and the field fold of a merge, run in the browser over
 * the decrypted vault (vault-duplicate-finder). Pure: no request, no store, no
 * DOM, so it runs in the health worker next to the engine. Nothing it returns
 * carries a value or a digest, and nothing of it reaches the server.
 *
 * Exact duplicates share the address host, the username and the value; likely
 * duplicates share the host and the username but not the value. Items without
 * an address are grouped on exact name, username and value only. Recipient
 * copies of other people's secrets and machine material (passkeys, one-time
 * code seeds) are never grouped.
 *
 * @spec openspec/changes/vault-duplicate-finder/specs/vault-duplicates/spec.md#requirement-detect-duplicates-in-the-browser
 */

/**
 * The host of a stored address, lower case, or null when there is none.
 *
 * @param {string|null|undefined} url The stored address.
 * @return {string|null}
 */
export function hostOf(url) {
	if (typeof url !== 'string' || url.trim() === '') {
		return null
	}
	const trimmed = url.trim()
	try {
		const candidate = trimmed.includes('://') ? trimmed : `https://${trimmed}`
		const host = new URL(candidate).hostname.toLowerCase()
		return host.startsWith('www.') ? host.slice(4) : host || null
	} catch {
		return null
	}
}

/**
 * Whether a row takes part in duplicate detection.
 *
 * @param {object} row A decrypted health row.
 * @return {boolean}
 */
function isGroupable(row) {
	return Boolean(row)
		&& row.copy !== true
		&& row.machineMaterial !== true
		&& typeof row.value === 'string'
		&& row.value !== ''
}

/**
 * The username as compared: trimmed and case-insensitive.
 *
 * @param {string|null|undefined} login The decrypted username.
 * @return {string}
 */
function usernameOf(login) {
	return typeof login === 'string' ? login.trim().toLowerCase() : ''
}

/**
 * Bucket the groupable rows on host (or exact name when there is no address) and username.
 *
 * @param {Array<object>} rows Decrypted health rows.
 * @return {Map<string, {host: string|null, username: string, rows: Array<object>}>}
 */
function buckets(rows) {
	const out = new Map()
	for (const row of rows) {
		if (!isGroupable(row)) {
			continue
		}
		const host = hostOf(row.url)
		const username = usernameOf(row.login)
		const place = host !== null ? `host:${host}` : `name:${String(row.name ?? '')}`
		const key = `${place}\u0000${username}`
		if (!out.has(key)) {
			out.set(key, { host, username, rows: [] })
		}
		out.get(key).rows.push(row)
	}
	return out
}

/**
 * The rows of one bucket split by value, in first-seen order.
 *
 * @param {Array<object>} rows The bucket's rows.
 * @return {Array<Array<object>>}
 */
function byValue(rows) {
	const out = new Map()
	for (const row of rows) {
		if (!out.has(row.value)) {
			out.set(row.value, [])
		}
		out.get(row.value).push(row)
	}
	return [...out.values()]
}

/**
 * A group as reported: identifiers and what the person sees, never the value.
 *
 * @param {string} kind exact or likely.
 * @param {{host: string|null, username: string}} bucket The bucket.
 * @param {Array<object>} rows The grouped rows.
 * @return {{kind: string, host: string|null, username: string, items: Array<{id: string, name: string, folderId: string|null}>}}
 */
function group(kind, bucket, rows) {
	return {
		kind,
		host: bucket.host,
		username: rows[0].login ?? '',
		items: rows.map((row) => ({
			id: row.id,
			name: row.name ?? '',
			folderId: row.folderPath ?? null,
		})),
	}
}

/**
 * The exact and likely duplicate groups among the user's own secrets.
 *
 * @param {Array<object>} rows Decrypted health rows: id, name, url, login, value, copy, machineMaterial.
 * @return {{exact: Array<object>, likely: Array<object>}}
 * @spec openspec/changes/vault-duplicate-finder/specs/vault-duplicates/spec.md#requirement-detect-duplicates-in-the-browser
 */
export function duplicateGroups(rows) {
	const exact = []
	const likely = []
	for (const bucket of buckets(rows || []).values()) {
		if (bucket.rows.length < 2) {
			continue
		}
		const values = byValue(bucket.rows)
		for (const same of values) {
			if (same.length > 1) {
				exact.push(group('exact', bucket, same))
			}
		}
		// Without an address a shared name and username is too weak a signal.
		if (bucket.host !== null && values.length > 1) {
			likely.push(group('likely', bucket, bucket.rows))
		}
	}
	return { exact, likely }
}

/**
 * The additional fields of the kept secret with the others' folded in. The
 * kept secret wins on a clash; a different value under a taken name is kept
 * under that name with a number suffix; an identical value is not repeated.
 *
 * @param {object|null} kept The kept secret's additional fields (name → value).
 * @param {Array<object|null>} others The other secrets' additional fields, in order.
 * @return {object} The folded fields.
 * @spec openspec/changes/vault-duplicate-finder/specs/vault-duplicates/spec.md#requirement-merge-a-duplicate-group
 */
export function foldFields(kept, others) {
	const out = { ...(isFieldMap(kept) ? kept : {}) }
	for (const fields of others || []) {
		if (!isFieldMap(fields)) {
			continue
		}
		for (const [name, value] of Object.entries(fields)) {
			if (holdsAlready(out, name, value)) {
				continue
			}
			out[freeName(out, name)] = value
		}
	}
	return out
}

/**
 * Whether the name, or one of its numbered variants, already holds the value.
 *
 * @param {object} fields The fields so far.
 * @param {string} name The field name.
 * @param {*} value The value.
 * @return {boolean}
 */
function holdsAlready(fields, name, value) {
	return Object.entries(fields).some(
		([n, v]) => v === value && (n === name || /^ \(\d+\)$/.test(n.slice(name.length)) && n.startsWith(name)),
	)
}

/**
 * @param {*} fields A decrypted additional-fields blob.
 * @return {boolean} Whether it is a name → value map.
 */
function isFieldMap(fields) {
	return fields !== null && typeof fields === 'object' && !Array.isArray(fields)
}

/**
 * The name itself when free, else the name with the first free suffix: "name (2)", "name (3)".
 *
 * @param {object} fields The fields so far.
 * @param {string} name The wanted name.
 * @return {string}
 */
function freeName(fields, name) {
	if (!(name in fields)) {
		return name
	}
	let n = 2
	while (`${name} (${n})` in fields) {
		n++
	}
	return `${name} (${n})`
}
