/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * KeePass 2.x XML import parser (secret-import D2, tasks §2.5).
 *
 * Parses the KeePass `File → Export → KeePass XML (2.x)` output with DOMParser
 * (no dependency). KDBX binary containers are explicitly out of scope and are
 * detected + rejected earlier in the file-pick step (model.isKdbx); this module
 * handles only the plaintext XML interchange format.
 *
 * Behaviour locked by the spec:
 *  - Recursive `<Group>` nesting → folder path segments (the root group name is
 *    dropped — it is KeePass's database title, not a user folder).
 *  - Each `<Entry>` has `<String>` children with a `<Key>`/`<Value>` pair;
 *    Title/URL/UserName/Password map onto name/url/login/password, Notes and any
 *    other custom strings become additionalFields.
 *  - `<History>` elements MUST be ignored — only the current entry value imports.
 *  - A document without a KeePass root element is a hard parse failure.
 */

import { makeRow, validateRow, joinFolderPath } from '../model.js'

/** Standard KeePass string keys that map onto top-level row fields. */
const STANDARD_KEYS = { Title: 'name', URL: 'url', UserName: 'login', Password: 'password' }

/**
 * Read the immediate child elements with a given tag name.
 *
 * @param {Element} parent The parent element.
 * @param {string} tag The child tag name.
 * @return {Array<Element>} The matching direct children.
 */
function childrenByTag(parent, tag) {
	const out = []
	for (const child of Array.from(parent.children)) {
		if (child.tagName === tag) {
			out.push(child)
		}
	}
	return out
}

/**
 * Extract one `<Entry>` into a normalized row, ignoring its `<History>`.
 *
 * @param {Element} entry The entry element.
 * @param {Array<string>} pathSegments The enclosing group path segments.
 * @param {number} sourceRow The 1-based entry counter for error reporting.
 * @return {object} A normalized, validated row.
 */
function parseEntry(entry, pathSegments, sourceRow) {
	const fields = { folder: joinFolderPath(pathSegments), type: 'login', additionalFields: null }
	const additional = {}

	// Only DIRECT <String> children — never descend into <History>.
	for (const str of childrenByTag(entry, 'String')) {
		const keyEl = childrenByTag(str, 'Key')[0]
		const valEl = childrenByTag(str, 'Value')[0]
		if (!keyEl) {
			continue
		}
		const key = keyEl.textContent ?? ''
		const value = valEl ? (valEl.textContent ?? '') : ''
		if (value === '') {
			continue
		}
		if (STANDARD_KEYS[key]) {
			fields[STANDARD_KEYS[key]] = value
		} else {
			additional[key] = value
		}
	}
	if (Object.keys(additional).length > 0) {
		fields.additionalFields = additional
	}
	return validateRow(makeRow(fields, sourceRow))
}

/**
 * Recursively walk a `<Group>`, collecting entries with their folder path.
 *
 * @param {Element} group The group element.
 * @param {Array<string>} parentPath The enclosing path segments.
 * @param {object} counter A shared { value } 1-based entry counter.
 * @param {boolean} isRoot Whether this is the top-level (database) group.
 * @return {Array<object>} The rows from this group and its descendants.
 */
function walkGroup(group, parentPath, counter, isRoot) {
	const nameEl = childrenByTag(group, 'Name')[0]
	const name = nameEl ? (nameEl.textContent ?? '').trim() : ''
	// Drop the root group name (it is the database title, not a user folder).
	const path = isRoot ? parentPath : (name ? [...parentPath, name] : parentPath)

	const rows = []
	for (const entry of childrenByTag(group, 'Entry')) {
		counter.value += 1
		rows.push(parseEntry(entry, path, counter.value))
	}
	for (const child of childrenByTag(group, 'Group')) {
		rows.push(...walkGroup(child, path, counter, false))
	}
	return rows
}

/**
 * Parse a KeePass 2.x XML export into normalized rows.
 *
 * @param {string} xml The KeePass XML export text.
 * @return {Array<object>} Normalized rows.
 * @throws {Error} When the document is not a KeePass XML export.
 */
export function parseKeepassXml(xml) {
	const doc = new DOMParser().parseFromString(xml, 'application/xml')
	if (doc.querySelector('parsererror')) {
		throw new Error('Invalid XML: the file could not be parsed')
	}
	const root = doc.querySelector('KeePassFile')
	if (!root) {
		throw new Error('Not a KeePass 2.x XML export (missing KeePassFile root element)')
	}
	const rootEl = root.querySelector('Root')
	if (!rootEl) {
		throw new Error('KeePass XML export has no Root group')
	}

	const counter = { value: 0 }
	const rows = []
	for (const group of childrenByTag(rootEl, 'Group')) {
		rows.push(...walkGroup(group, [], counter, true))
	}
	return rows
}

/** The KeePass XML parser descriptor. */
export const keepassXmlParser = {
	id: 'keepass-xml',
	label: 'KeePass 2.x XML export',
	requiresPassphrase: false,
	adjustableMapping: false,
	/**
	 * Parse entry point matching the registry contract.
	 *
	 * @param {string} input The KeePass XML text.
	 * @return {Promise<Array<object>>} Normalized rows.
	 */
	parse: async (input) => parseKeepassXml(input),
}
