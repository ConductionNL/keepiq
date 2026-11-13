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

import { authenticatorKeyFromParts } from '../../totp/authenticatorKey.js'
import { attachTotp } from '../loginValueFields.js'
import { joinFolderPath, makeRow, validateRow } from '../model.js'

/** Standard KeePass string keys that map onto top-level row fields. */
const STANDARD_KEYS = {
	Title: 'name',
	URL: 'url',
	UserName: 'login',
	Password:
		'password',
}

/** KeePass's own TOTP parameter strings beside `TimeOtp-Secret-Base32`. */
const TIME_OTP_PARAMS = ['TimeOtp-Period', 'TimeOtp-Length', 'TimeOtp-Algorithm']

/**
 * The KeePass strings that hold a one-time-code seed, each giving the key to
 * store and the strings it is made of: `otp` (KeePassXC, an otpauth:// URI)
 * and `TimeOtp-Secret-Base32` (KeePass 2.47+, a base32 seed folded with its
 * period, length and algorithm strings). The first seed in the entry goes on
 * the login's `totp` field (keepiq#1327); the strings of a seed that does not
 * stay additional fields.
 */
const TOTP_SEEDS = {
	otp: (strings) => ({ seed: strings.get('otp'), keys: ['otp'] }),
	'TimeOtp-Secret-Base32': (strings) => {
		let seed = ''
		try {
			seed = authenticatorKeyFromParts({
				secret: strings.get('TimeOtp-Secret-Base32'),
				period: strings.get('TimeOtp-Period'),
				digits: strings.get('TimeOtp-Length'),
				algorithm: strings.get('TimeOtp-Algorithm'),
			})
		} catch {
			// Parameters the code generator cannot use: keep the strings.
		}
		return { seed, keys: ['TimeOtp-Secret-Base32', ...TIME_OTP_PARAMS] }
	},
}

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
	const fields = {
		folder: joinFolderPath(pathSegments),
		type: 'login',
		additionalFields: null,
	}
	const strings = sortStrings(entry, fields)
	const row = makeRow(fields, sourceRow)
	attachTotpSeeds(row, strings)
	row.additionalFields = strings.size > 0 ? Object.fromEntries(strings) : null
	return validateRow(row)
}

/**
 * Read the entry's direct `<String>` children — never its `<History>`: a
 * standard key fills its row field, any other key is kept.
 *
 * @param {Element} entry The entry element.
 * @param {object} fields The row fields the standard keys fill.
 * @return {Map<string, string>} The other strings, by key.
 */
function sortStrings(entry, fields) {
	const strings = new Map()
	for (const str of childrenByTag(entry, 'String')) {
		const pair = stringPair(str)
		if (pair === null) {
			continue
		}
		if (STANDARD_KEYS[pair.key]) {
			fields[STANDARD_KEYS[pair.key]] = pair.value
		} else {
			strings.set(pair.key, pair.value)
		}
	}
	return strings
}

/**
 * The key and value of one `<String>`, or null when it has no key or an empty value.
 *
 * @param {Element} str The `<String>` element.
 * @return {{key: string, value: string}|null} The pair.
 */
function stringPair(str) {
	const keyEl = childrenByTag(str, 'Key')[0]
	const valEl = childrenByTag(str, 'Value')[0]
	if (!keyEl) {
		return null
	}
	const value = valEl ? (valEl.textContent ?? '') : ''
	if (value === '') {
		return null
	}
	return { key: keyEl.textContent ?? '', value }
}

/**
 * Put the entry's one-time-code seed on the row, consuming the strings it
 * was read from; a seed that is no authenticator key stays a string.
 *
 * @param {object} row The entry's row.
 * @param {Map<string, string>} strings The entry's other strings, by key.
 * @return {void}
 */
function attachTotpSeeds(row, strings) {
	for (const key of [...strings.keys()].filter((k) => k in TOTP_SEEDS)) {
		const { seed, keys } = TOTP_SEEDS[key](strings)
		if (seed !== '' && attachTotp(row, seed) === null) {
			keys.forEach((consumed) => strings.delete(consumed))
		}
	}
}

/**
 * Recursively walk a `<Group>`, collecting entries with their folder path.
 *
 * @param {Element} group The group element.
 * @param {{path: Array<string>, counter: {value: number}}} walk The group's
 *   folder path segments and the shared 1-based entry counter.
 * @return {Array<object>} The rows from this group and its descendants.
 */
function walkGroup(group, walk) {
	const rows = []
	for (const entry of childrenByTag(group, 'Entry')) {
		walk.counter.value += 1
		rows.push(parseEntry(entry, walk.path, walk.counter.value))
	}
	for (const child of childrenByTag(group, 'Group')) {
		rows.push(...walkGroup(child, { path: childPath(child, walk.path), counter: walk.counter }))
	}
	return rows
}

/**
 * The folder path of a nested group: its parent's, plus its trimmed name when it has one.
 *
 * @param {Element} group The group element.
 * @param {Array<string>} parentPath The enclosing path segments.
 * @return {Array<string>} The group's path segments.
 */
function childPath(group, parentPath) {
	const nameEl = childrenByTag(group, 'Name')[0]
	const name = nameEl ? (nameEl.textContent ?? '').trim() : ''
	return name ? [...parentPath, name] : parentPath
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
		throw new Error(
			'Not a KeePass 2.x XML export (missing KeePassFile root element)',
		)
	}
	const rootEl = root.querySelector('Root')
	if (!rootEl) {
		throw new Error('KeePass XML export has no Root group')
	}

	const counter = { value: 0 }
	const rows = []
	for (const group of childrenByTag(rootEl, 'Group')) {
		// The root group's name is the database title, not a user folder.
		rows.push(...walkGroup(group, { path: [], counter }))
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
