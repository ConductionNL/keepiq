/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Export Pinia store (secret-export-gdpr, tasks §6.1).
 *
 * Owns the browser-side export flows: encrypted backup, plaintext CSV, GDPR
 * package, and account deletion. The store is handed ALREADY-DECRYPTED secrets
 * and folders by the caller (the export dialog), mirroring the link-share
 * pattern — it never needs the session RSA key directly, and it never persists
 * any plaintext, passphrase, or derived key.
 *
 * Each export action reports the completed export to the server BEFORE the
 * local Blob download is offered, and surfaces any endpoint failure rather than
 * silently skipping event emission. The event endpoint receives only the mode,
 * scope, and secret count — never secret material or the passphrase.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import { sealForRequest } from '../../crypto/cxp.js'
import { buildCxfDocument } from '../../cxf/cxf.js'
import { encryptBackup } from '../../export/backup.js'
import { generateCsv } from '../../export/csv.js'
import { assembleGdprPackage } from '../../export/gdprPackage.js'
import { serializeVault } from '../../export/serializer.js'

/**
 * Trigger a local file download from a string blob. No network involved.
 *
 * @param {string} filename The download filename.
 * @param {string} content The file content.
 * @param {string} mime The MIME type.
 * @return {void}
 */
function downloadBlob(filename, content, mime) {
	const blob = new Blob([content], { type: mime })
	const url = URL.createObjectURL(blob)
	const a = document.createElement('a')
	a.href = url
	a.download = filename
	document.body.appendChild(a)
	a.click()
	document.body.removeChild(a)
	URL.revokeObjectURL(url)
}

export const useExportStore = defineStore('export', {
	state: () => ({
		/** @type {boolean} Whether an export/deletion is in flight. */
		loading: false,
		/** @type {string|null} The last surfaced error. */
		error: null,
	}),

	actions: {
		/**
		 * Report a completed export to the server (emits SecretExportedEvent).
		 * Throws on failure so the caller can block the download.
		 *
		 * @param {string} mode 'encrypted-backup' | 'plaintext-csv'
		 * @param {string} scope 'vault' | 'folders'
		 * @param {number} secretCount The number of secrets exported.
		 * @return {Promise<void>}
		 * @spec openspec/changes/secret-export-gdpr/specs/secret-export/spec.md
		 */
		async reportExport(mode, scope, secretCount) {
			await axios.post(generateUrl('/apps/keepiq/api/v1/export/events'), {
				mode,
				scope,
				secretCount,
			})
		},

		/**
		 * Export an encrypted `.doriath-backup` (Argon2id + AES-256-GCM).
		 *
		 * @param {Array<object>} secrets Decrypted secrets.
		 * @param {Array<object>} folders Folder rows.
		 * @param {string} passphrase The backup passphrase.
		 * @param {object} [scope] Scope selector ({ mode, folderIds }).
		 * @return {Promise<void>}
		 * @spec openspec/changes/secret-export-gdpr/specs/secret-export/spec.md
		 */
		async exportBackup(secrets, folders, passphrase, scope = { mode: 'vault' }) {
			this.loading = true
			this.error = null
			try {
				const payload = serializeVault(secrets, folders, scope)
				const envelope = await encryptBackup(payload, passphrase)
				// Report BEFORE offering the download; a failure aborts the export.
				await this.reportExport(
					'encrypted-backup',
					scope.mode || 'vault',
					payload.secrets.length,
				)
				downloadBlob(
					'vault.doriath-backup',
					JSON.stringify(envelope, null, 2),
					'application/octet-stream',
				)
			} catch (e) {
				this.error = e.message || 'Backup export failed'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Export a plaintext CSV. The warning + master-password re-auth gates are
		 * enforced by the dialog before this is called; this only generates and
		 * downloads. Never sends plaintext to the server.
		 *
		 * @param {Array<object>} secrets Decrypted secrets.
		 * @param {Array<object>} folders Folder rows.
		 * @param {object} [scope] Scope selector.
		 * @return {Promise<void>}
		 * @spec openspec/changes/secret-export-gdpr/specs/secret-export/spec.md
		 */
		async exportCsv(secrets, folders, scope = { mode: 'vault' }) {
			this.loading = true
			this.error = null
			try {
				const payload = serializeVault(secrets, folders, scope)
				const csv = generateCsv(payload.secrets)
				await this.reportExport(
					'plaintext-csv',
					scope.mode || 'vault',
					payload.secrets.length,
				)
				downloadBlob('vault.csv', csv, 'text/csv')
			} catch (e) {
				this.error = e.message || 'CSV export failed'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Export a FIDO CXF document (cxf-import-export §3). A CXF file is
		 * PLAINTEXT — the dialog enforces the same warning + master-password
		 * re-auth gates as the CSV path before calling this. The document is
		 * assembled entirely client-side; the unmapped-item report (values
		 * with no CXF home) is returned so the dialog can show it BEFORE the
		 * download when `dryRun` is set. Never sends plaintext to the server.
		 *
		 * @param {Array<object>} secrets Decrypted secrets.
		 * @param {Array<object>} folders Folder rows.
		 * @param {object} [scope] Scope selector.
		 * @param {object} [options] Options.
		 * @param {object} [options.typeNamesById] typeId → type-name map.
		 * @param {boolean} [options.dryRun] Build + report only, no download.
		 * @return {Promise<{unmapped: Array<string>, itemCount: number}>}
		 * @spec openspec/specs/cxf-import-export/spec.md#requirement-re-auth-gated-client-side-cxf-export
		 * @spec openspec/specs/cxf-import-export/spec.md#requirement-unmapped-item-report
		 */
		async exportCxf(secrets, folders, scope = { mode: 'vault' }, options = {}) {
			this.loading = true
			this.error = null
			try {
				const payload = serializeVault(secrets, folders, scope)
				const { document, unmapped, itemCount } = buildCxfDocument(
					payload.secrets,
					{
						typeNamesById: options.typeNamesById,
					},
				)
				if (options.dryRun) {
					return { unmapped, itemCount }
				}
				// Report BEFORE offering the download; a failure aborts.
				await this.reportExport('cxf', scope.mode || 'vault', itemCount)
				downloadBlob(
					'vault.cxf',
					JSON.stringify(document, null, 2),
					'application/json',
				)
				return { unmapped, itemCount }
			} catch (e) {
				this.error = e.message || 'CXF export failed'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * CXP (FIDO Credential Exchange Protocol) export: assemble the CXF payload
		 * via the EXISTING export path, then HPKE-seal it for a CXP request's
		 * public key. Returns ONLY the sealed envelope — no plaintext file is ever
		 * written (cxp-transfer §4.1). Reports the transfer with mode `cxp`.
		 *
		 * The caller MUST have already gated on the fresh master-password re-auth
		 * (cxf-import-export D5), exactly as the file-based CXF export does.
		 *
		 * @param {object} request The peer's CXP request { v, requesterPublicKey, nonce, requestedFormat }
		 * @param {Array} secrets The vault secrets
		 * @param {Array} folders The folders
		 * @param {object} scope The export scope
		 * @param {object} options Options ({ dryRun, typeNamesById })
		 * @return {Promise<{ envelope: object|null, unmapped: Array, itemCount: number }>}
		 */
		async exportCxpSealed(
			request,
			secrets,
			folders,
			scope = { mode: 'vault' },
			options = {},
		) {
			this.loading = true
			this.error = null
			try {
				const payload = serializeVault(secrets, folders, scope)
				const { document, unmapped, itemCount } = buildCxfDocument(
					payload.secrets,
					{
						typeNamesById: options.typeNamesById,
					},
				)
				if (options.dryRun) {
					return { envelope: null, unmapped, itemCount }
				}
				// Seal the assembled CXF payload for the requester — in-memory only.
				const cxfBytes = new TextEncoder().encode(JSON.stringify(document))
				const envelope = await sealForRequest(request, cxfBytes)
				// Report BEFORE handing back the envelope; a failure aborts.
				await this.reportExport('cxp', scope.mode || 'vault', itemCount)
				return { envelope, unmapped, itemCount }
			} catch (e) {
				this.error = e.message || 'CXP transfer failed'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Produce a GDPR Art. 15 package. Fetches the server metadata half, merges
		 * the client vault half when provided (unlocked), and downloads locally.
		 * The metadata endpoint emits GdprExportPerformedEvent.
		 *
		 * @param {Array<object>|null} secrets Decrypted secrets, or null when locked.
		 * @param {Array<object>} folders Folder rows.
		 * @return {Promise<void>}
		 * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
		 */
		async exportGdprPackage(secrets, folders) {
			this.loading = true
			this.error = null
			try {
				const includesVault = secrets != null
				const response = await axios.get(
					generateUrl('/apps/keepiq/api/v1/gdpr/metadata'),
					{ params: { includesVault: includesVault ? 'true' : 'false' } },
				)
				const vaultPayload = includesVault
					? serializeVault(secrets, folders, { mode: 'vault' })
					: null
				const pkg = assembleGdprPackage(response.data, vaultPayload)
				downloadBlob(
					'keepiq-gdpr-export.json',
					JSON.stringify(pkg, null, 2),
					'application/json',
				)
			} catch (e) {
				this.error = e.message || 'GDPR export failed'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Delete all of the user's Keepiq data (GDPR Art. 17). The
		 * master-password re-auth is verified client-side by the dialog before
		 * this is called; the typed confirmation phrase is the server gate. The
		 * password is NEVER sent.
		 *
		 * @param {string} confirmation The typed confirmation phrase.
		 * @return {Promise<object>} The deletion report.
		 * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
		 */
		async deleteAccountData(confirmation) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.delete(
					generateUrl('/apps/keepiq/api/v1/gdpr/account-data'),
					{ data: { confirmation } },
				)
				return response.data
			} catch (e) {
				this.error = e.message || 'Account deletion failed'
				throw e
			} finally {
				this.loading = false
			}
		},
	},
})
