/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Plaintext CSV export + parse (secret-export-gdpr D2, tasks §5.3).
 *
 * Produces the obvious plaintext columns
 * (`name,url,login,password,notes,folder,type,totp`) with RFC 4180 quoting, in
 * a layout the secret-import generic CSV auto-detection round-trips. A
 * login's passkeys have no column and are left out. This module
 * is pure string transformation — the SECURITY gating (warning acknowledgement
 * + master-password re-auth) lives in the export store/dialog, never here.
 *
 * The CSV is generated in the browser and never transmitted to the server.
 */

/** The fixed column order, matching the generic CSV import mapping. */
export const CSV_COLUMNS = [
	'name',
	'url',
	'login',
	'password',
	'notes',
	'folder',
	'type',
	'totp',
]

/**
 * Quote a single CSV field per RFC 4180.
 *
 * A field is wrapped in double quotes when it contains a comma, double quote,
 * CR, or LF; embedded double quotes are doubled.
 *
 * @param {*} value The field value.
 * @return {string} The RFC 4180 field.
 */
function quoteField(value) {
	const str = value == null ? '' : String(value)
	if (/[",\r\n]/.test(str)) {
		return `"${str.replace(/"/g, '""')}"`
	}
	return str
}

/**
 * Pull the notes value out of a secret's additionalFields, if present.
 *
 * @param {object} secret The serialized secret row.
 * @return {string} The notes string (empty when absent).
 */
function notesOf(secret) {
	const af = secret.additionalFields
	if (af == null) {
		return ''
	}
	if (typeof af === 'string') {
		return af
	}
	if (typeof af === 'object') {
		if (typeof af.notes === 'string') {
			return af.notes
		}
		return JSON.stringify(af)
	}
	return ''
}

/**
 * Generate an RFC 4180 CSV string from serialized vault secrets.
 *
 * @param {Array<object>} secrets Serialized secrets (from serializeVault).
 * @return {string} The CSV document (CRLF line endings).
 */
export function generateCsv(secrets) {
	const lines = [CSV_COLUMNS.join(',')]
	for (const secret of secrets) {
		const row = [
			secret.name,
			secret.url,
			secret.login,
			secret.password,
			notesOf(secret),
			secret.folder,
			secret.type,
			secret.totp,
		]
		lines.push(row.map(quoteField).join(','))
	}
	return lines.join('\r\n')
}

/**
 * Close the field the tokenizer is reading.
 *
 * @param {object} state The tokenizer state.
 * @return {void}
 */
function endField(state) {
	state.record.push(state.field)
	state.field = ''
}

/**
 * Close the field and the record the tokenizer is reading.
 *
 * @param {object} state The tokenizer state.
 * @return {void}
 */
function endRecord(state) {
	endField(state)
	state.rows.push(state.record)
	state.record = []
}

/**
 * Read one character inside a quoted field. A doubled quote is a literal
 * quote; a single one closes the quoted part.
 *
 * @param {object} state The tokenizer state.
 * @return {number} The number of characters consumed.
 */
function quotedStep(state) {
	const ch = state.csv[state.i]
	if (ch !== '"') {
		state.field += ch
		return 1
	}
	if (state.csv[state.i + 1] === '"') {
		state.field += '"'
		return 2
	}
	state.inQuotes = false
	return 1
}

/**
 * Read one character outside a quoted field. CRLF, CR and LF each end a
 * record.
 *
 * @param {object} state The tokenizer state.
 * @return {number} The number of characters consumed.
 */
function plainStep(state) {
	const ch = state.csv[state.i]
	if (ch === '"') {
		state.inQuotes = true
		return 1
	}
	if (ch === ',') {
		endField(state)
		return 1
	}
	if (ch === '\n') {
		endRecord(state)
		return 1
	}
	if (ch === '\r') {
		endRecord(state)
		return state.csv[state.i + 1] === '\n' ? 2 : 1
	}
	state.field += ch
	return 1
}

/**
 * Split an RFC 4180 CSV string into records of raw field strings.
 *
 * @param {string} csv The CSV document.
 * @return {Array<Array<string>>} The records, header included.
 */
function csvRecords(csv) {
	const state = { csv, i: 0, field: '', record: [], rows: [], inQuotes: false }
	while (state.i < csv.length) {
		state.i += state.inQuotes ? quotedStep(state) : plainStep(state)
	}
	// Flush trailing field/record if the document does not end with a newline.
	if (state.field !== '' || state.record.length > 0) {
		endRecord(state)
	}
	return state.rows
}

/**
 * Key one record by the header columns; a missing cell is ''.
 *
 * @param {Array<string>} header The header record.
 * @param {Array<string>} record The data record.
 * @return {object} The row keyed by header column.
 */
function recordObject(header, record) {
	const obj = {}
	header.forEach((col, idx) => {
		obj[col] = record[idx] ?? ''
	})
	return obj
}

/**
 * Parse an RFC 4180 CSV string back into row objects (round-trip helper /
 * used by the import path).
 *
 * @param {string} csv The CSV document.
 * @return {Array<object>} Rows keyed by header column.
 */
export function parseCsv(csv) {
	const rows = csvRecords(csv)
	if (rows.length === 0) {
		return []
	}
	const header = rows[0]
	return rows
		.slice(1)
		.filter((r) => r.length > 1 || (r.length === 1 && r[0] !== ''))
		.map((r) => recordObject(header, r))
}
