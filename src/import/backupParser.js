/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Backup-restore parser (secret-export-gdpr D1, tasks §6.7).
 *
 * Restores a `.doriath-backup` file through the import pipeline: prompt for the
 * passphrase → client-side decrypt (decryptBackup) → normalize the decrypted
 * vault payload into the standard import rows. A wrong passphrase throws (AES-GCM
 * tag mismatch) and NO rows enter the pipeline. The mapping is fixed; the
 * standard duplicate-detection step is applied downstream by the wizard.
 */

import { BACKUP_FORMAT, decryptBackup } from '../export/backup.js'
import { BLOB_FIELDS } from '../utils/valueFields.js'
import { registerParser } from './parserRegistry.js'

/**
 * Normalize a decrypted vault payload into import rows.
 *
 * @param {object} payload The decrypted `{ secrets, folders }` payload.
 * @return {Array<object>} Normalized rows.
 */
function toRows(payload) {
	const secrets = (payload && payload.secrets) || []
	// Number every row: duplicate choices, preview keys and rejected-row
	// reports are all keyed by sourceRow (keepiq#749).
	return secrets.map((s, index) => withBlobFields(baseRow(s, index + 1), s))
}

/** The plain fields of a restored row, with the value each takes when the secret lacks it. */
const ROW_DEFAULTS = {
	name: '',
	url: null,
	login: null,
	password: null,
	additionalFields: null,
	folder: '',
	type: 'login',
}

/**
 * The plain fields of one restored secret, each defaulted when absent.
 *
 * @param {object} s The decrypted secret.
 * @param {number} sourceRow Its 1-based position in the backup.
 * @return {object} The row.
 */
function baseRow(s, sourceRow) {
	const row = { sourceRow }
	for (const [field, fallback] of Object.entries(ROW_DEFAULTS)) {
		row[field] = s[field] ?? fallback
	}
	return row
}

/**
 * The row with the secret's non-empty value blobs (code, passkeys) added.
 *
 * @param {object} row The row.
 * @param {object} s The decrypted secret.
 * @return {object} The same row.
 */
function withBlobFields(row, s) {
	for (const field of BLOB_FIELDS) {
		if (typeof s[field] === 'string' && s[field] !== '') {
			row[field] = s[field]
		}
	}
	return row
}

/**
 * Parse a `.doriath-backup` envelope into normalized import rows.
 *
 * @param {object|string} input The envelope object, or its JSON text.
 * @param {object} options Parse options.
 * @param {string} options.passphrase The backup passphrase.
 * @return {Promise<Array<object>>} Normalized rows.
 */
export async function parseBackup(input, options = {}) {
	const envelope = typeof input === 'string' ? JSON.parse(input) : input
	const payload = await decryptBackup(envelope, options.passphrase)
	return toRows(payload)
}

/** The backup-restore parser descriptor. */
export const backupParser = {
	id: BACKUP_FORMAT,
	label: 'Keepiq encrypted backup (.doriath-backup)',
	requiresPassphrase: true,
	parse: parseBackup,
}

// Register on import so the wizard (or its secret-import successor) sees it.
registerParser(backupParser)
