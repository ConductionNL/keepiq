/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Plaintext CSV export + parse (secret-export-gdpr D2, tasks §5.3).
 *
 * Produces the obvious plaintext columns
 * (`name,url,login,password,notes,folder,type`) with RFC 4180 quoting, in a
 * layout the secret-import generic CSV auto-detection round-trips. This module
 * is pure string transformation — the SECURITY gating (warning acknowledgement
 * + master-password re-auth) lives in the export store/dialog, never here.
 *
 * The CSV is generated in the browser and never transmitted to the server.
 */

/** The fixed column order, matching the generic CSV import mapping. */
export const CSV_COLUMNS = ['name', 'url', 'login', 'password', 'notes', 'folder', 'type']

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
		]
		lines.push(row.map(quoteField).join(','))
	}
	return lines.join('\r\n')
}

/**
 * Parse an RFC 4180 CSV string back into row objects (round-trip helper /
 * used by the import path).
 *
 * @param {string} csv The CSV document.
 * @return {Array<object>} Rows keyed by header column.
 */
export function parseCsv(csv) {
	const rows = []
	let field = ''
	let record = []
	let inQuotes = false
	let i = 0

	const pushField = () => {
		record.push(field)
		field = ''
	}
	const pushRecord = () => {
		pushField()
		rows.push(record)
		record = []
	}

	while (i < csv.length) {
		const ch = csv[i]
		if (inQuotes) {
			if (ch === '"') {
				if (csv[i + 1] === '"') {
					field += '"'
					i += 2
					continue
				}
				inQuotes = false
				i++
				continue
			}
			field += ch
			i++
			continue
		}
		if (ch === '"') {
			inQuotes = true
			i++
			continue
		}
		if (ch === ',') {
			pushField()
			i++
			continue
		}
		if (ch === '\r') {
			if (csv[i + 1] === '\n') {
				i++
			}
			pushRecord()
			i++
			continue
		}
		if (ch === '\n') {
			pushRecord()
			i++
			continue
		}
		field += ch
		i++
	}
	// Flush trailing field/record if the document does not end with a newline.
	if (field !== '' || record.length > 0) {
		pushRecord()
	}

	if (rows.length === 0) {
		return []
	}
	const header = rows[0]
	return rows.slice(1)
		.filter(r => r.length > 1 || (r.length === 1 && r[0] !== ''))
		.map(r => {
			const obj = {}
			header.forEach((col, idx) => {
				obj[col] = r[idx] ?? ''
			})
			return obj
		})
}
