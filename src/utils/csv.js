/**
 * RFC 4180-compliant CSV generation for client-side export
 * (add-secret-audit-trail §5.5). No server-side file generation and no
 * download endpoint — the admin audit CSV is built entirely in the browser
 * from the fetched rows, consistent with the export-stays-in-the-browser
 * house pattern.
 */

/**
 * Quote a single CSV field per RFC 4180: wrap in double quotes when it
 * contains a comma, double-quote, CR or LF, and escape embedded quotes by
 * doubling them.
 *
 * @param {*} value The field value (coerced to string; null/undefined → '').
 * @return {string} The quoted field.
 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.5
 */
export function csvField(value) {
	const str = value === null || value === undefined ? '' : String(value)
	if (/[",\r\n]/.test(str)) {
		return '"' + str.replace(/"/g, '""') + '"'
	}
	return str
}

/**
 * Build an RFC 4180 CSV document from a header row and data rows.
 *
 * @param {Array<string>} headers The header labels.
 * @param {Array<Array<*>>} rows The data rows.
 * @return {string} The CSV text (CRLF line endings).
 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.5
 */
export function buildCsv(headers, rows) {
	const lines = [headers.map(csvField).join(',')]
	rows.forEach((row) => {
		lines.push(row.map(csvField).join(','))
	})
	return lines.join('\r\n')
}

/**
 * Trigger a browser download of CSV text as a file.
 *
 * @param {string} filename The download filename.
 * @param {string} csv The CSV text.
 * @return {void}
 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.5
 */
export function downloadCsv(filename, csv) {
	const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
	const url = URL.createObjectURL(blob)
	const link = document.createElement('a')
	link.href = url
	link.download = filename
	document.body.appendChild(link)
	link.click()
	document.body.removeChild(link)
	URL.revokeObjectURL(url)
}
