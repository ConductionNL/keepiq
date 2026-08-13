/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Generic CSV import parser (secret-import D1/D4, tasks §2.3).
 *
 * Reuses the RFC 4180 `parseCsv()` shipped by secret-export-gdpr (src/export/
 * csv.js) — that parser already handles quoting, escaped quotes, and CRLF, and
 * the export side round-trips exactly through it, so the export → import path
 * needs no second CSV engine and no new dependency (papaparse was specced but
 * the in-repo parser already satisfies the requirement; see design D-deviation).
 *
 * Header auto-detection maps common column names case-insensitively onto the
 * normalized row fields. The detected mapping is returned alongside the rows so
 * the wizard's field-mapping preview can show it and let the user remap for a
 * generic CSV. A `/`-separated folder column becomes the folder path; per-row
 * fault isolation means a single bad row never aborts the import.
 */

import { parseCsv } from '../../export/csv.js'
import { makeRow, validateRow } from '../model.js'

/**
 * Header synonyms per target field (lower-cased, exact-match after trim).
 *
 * @type {Object<string, string[]>}
 */
const HEADER_SYNONYMS = {
	name: ['name', 'title', 'label', 'account', 'item'],
	url: ['url', 'uri', 'website', 'link', 'web site', 'login_uri'],
	login: ['username', 'login', 'user', 'login_username', 'email', 'e-mail'],
	password: ['password', 'pass', 'key', 'secret', 'login_password'],
	notes: ['notes', 'note', 'comment', 'comments'],
	folder: ['folder', 'group', 'grouping', 'collection', 'category'],
	type: ['type'],
}

/**
 * Auto-detect a column → target-field mapping from CSV headers.
 *
 * The first header that matches a synonym for a single-valued target (name,
 * url, login, password, folder, type) wins; unmatched headers default to
 * 'ignore'. The result is a per-column descriptor the wizard renders + edits.
 *
 * @param {Array<string>} headers The CSV header row.
 * @return {Array<object>} Per-column mapping ({ column, target }).
 */
export function detectMapping(headers) {
	const used = new Set()
	return headers.map((header) => {
		const key = String(header ?? '')
			.trim()
			.toLowerCase()
		let target = 'ignore'
		for (const [field, synonyms] of Object.entries(HEADER_SYNONYMS)) {
			if (field === 'notes') {
				continue
			}
			if (!used.has(field) && synonyms.includes(key)) {
				target = field
				used.add(field)
				break
			}
		}
		if (target === 'ignore' && HEADER_SYNONYMS.notes.includes(key)) {
			target = 'notes'
		}
		return { column: header, target }
	})
}

/**
 * Apply a column mapping to one raw CSV record, producing a normalized row.
 *
 * Columns mapped to `notes` or `additional:<label>` collect into
 * additionalFields. The `notes` mapping uses the `notes` key by convention.
 *
 * @param {object} record The raw CSV record (keyed by header).
 * @param {Array<object>} mapping The column → target mapping.
 * @param {number} sourceRow The 1-based source position.
 * @return {object} A normalized, validated row.
 */
export function applyMapping(record, mapping, sourceRow) {
	const fields = { additionalFields: null }
	const additional = {}
	for (const { column, target } of mapping) {
		const value = record[column]
		if (value == null || value === '' || target === 'ignore') {
			continue
		}
		if (target === 'notes') {
			additional.notes = value
		} else if (typeof target === 'string' && target.startsWith('additional:')) {
			additional[target.slice('additional:'.length)] = value
		} else {
			fields[target] = value
		}
	}
	if (Object.keys(additional).length > 0) {
		fields.additionalFields = additional
	}
	return validateRow(makeRow(fields, sourceRow))
}

/**
 * Parse a generic CSV document into normalized rows + the detected mapping.
 *
 * @param {string} csv The CSV document text.
 * @param {object} [options] Parse options.
 * @param {Array<object>} [options.mapping] An explicit column mapping (from the
 *   wizard remap); when omitted, the mapping is auto-detected from the headers.
 * @return {{rows: Array<object>, mapping: Array<object>, headers: Array<string>}}
 */
export function parseCsvImport(csv, options = {}) {
	const records = parseCsv(csv)
	if (records.length === 0) {
		return { rows: [], mapping: [], headers: [] }
	}
	const headers = Object.keys(records[0])
	const mapping = options.mapping ?? detectMapping(headers)

	const rows = records.map((record, index) => {
		try {
			return applyMapping(record, mapping, index + 1)
		} catch (e) {
			// Per-row fault isolation: a malformed record becomes a rejected row.
			const row = makeRow({ name: '' }, index + 1)
			row.errors.push(`Unparseable row: ${e.message}`)
			return row
		}
	})

	return { rows, mapping, headers }
}

/** The generic CSV parser descriptor (no passphrase). */
export const csvParser = {
	id: 'csv',
	label: 'Generic CSV',
	requiresPassphrase: false,
	adjustableMapping: true,
	/**
	 * Parse entry point matching the registry contract.
	 *
	 * @param {string} input The CSV text.
	 * @param {object} [options] Parse options (mapping).
	 * @return {Promise<Array<object>>} Normalized rows.
	 */
	parse: async (input, options = {}) => parseCsvImport(input, options).rows,
}
