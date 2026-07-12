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

import { decryptBackup, BACKUP_FORMAT } from '../export/backup.js'
import { registerParser } from './parserRegistry.js'

/**
 * Normalize a decrypted vault payload into import rows.
 *
 * @param {object} payload The decrypted `{ secrets, folders }` payload.
 * @return {Array<object>} Normalized rows.
 */
function toRows(payload) {
	const secrets = (payload && payload.secrets) || []
	return secrets.map(s => ({
		name: s.name ?? '',
		url: s.url ?? null,
		login: s.login ?? null,
		password: s.password ?? null,
		additionalFields: s.additionalFields ?? null,
		folder: s.folder ?? '',
		type: s.type ?? 'login',
	}))
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
	label: 'Doriath encrypted backup (.doriath-backup)',
	requiresPassphrase: true,
	parse: parseBackup,
}

// Register on import so the wizard (or its secret-import successor) sees it.
registerParser(backupParser)
