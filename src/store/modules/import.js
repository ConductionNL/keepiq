/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Import Pinia store (secret-import D1/D6/D7/D8, tasks §3).
 *
 * Owns the browser-side import pipeline: parse → map → resolve duplicates →
 * commit → summarise. The plaintext rows live ONLY in this store's in-memory
 * state — never localStorage/sessionStorage/IndexedDB (same persistence-leak
 * guard as the link-share + export stores) — and reset() releases them when the
 * wizard closes.
 *
 * Commit encrypts every sensitive field (key/login/additionalFields) in the
 * browser with the owner's active EncryptionSuite public certificate, using the
 * SAME WebCrypto path as Create Secret (importPublicKey + rsaEncrypt — no new
 * crypto code), then POSTs ciphertext-only payloads in chunks. The server never
 * sees plaintext (ADR-003 / encryption-suites E2E guarantee).
 */

import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { importPublicKey, rsaEncrypt } from '../../crypto/index.js'
import { useSessionStore } from './session.js'
import { useSecretStore } from './secret.js'
import { useSecretTypeStore } from './secretType.js'
import { dedupeKey, folderSegments } from '../../import/model.js'
import { getParser } from '../../import/parserRegistry.js'
// Importing the parsers module registers every format parser on the registry.
import '../../import/parsers/index.js'

/** Client-side chunk size for batch commit (design D7). */
export const COMMIT_CHUNK_SIZE = 50

/** The wizard steps in order. */
export const STEPS = ['pick', 'mapping', 'folders', 'duplicates', 'commit', 'summary']

export const useImportStore = defineStore('import', {
	state: () => ({
		/** @type {string} The current wizard step. */
		step: 'pick',
		/** @type {string|null} The selected parser/format id. */
		format: null,
		/** @type {Array<object>} Parsed accepted rows (plaintext, in-memory only). */
		rows: [],
		/** @type {Array<object>} Rejected rows ({ sourceRow, reason }). */
		rejected: [],
		/** @type {Array<object>} The detected/adjusted CSV column mapping. */
		mapping: [],
		/** @type {Object<number,string>} Per-row duplicate resolution: 'skip'|'copy'. */
		duplicateResolutions: {},
		/** @type {Array<object>} Rows detected as duplicates of an existing secret. */
		duplicates: [],
		/** @type {number} Commit progress: chunks completed. */
		committedChunks: 0,
		/** @type {number} Commit progress: total chunks. */
		totalChunks: 0,
		/** @type {object|null} The transient summary report. */
		summary: null,
		/** @type {boolean} Whether a parse/commit is in flight. */
		loading: false,
		/** @type {string|null} The last surfaced error. */
		error: null,
	}),

	getters: {
		/**
		 * The rows that will be committed (accepted, non-duplicate, plus
		 * duplicates resolved as "import as copy").
		 *
		 * @param {object} state The store state.
		 * @return {Array<object>} The committable rows.
		 */
		acceptedRows: (state) => {
			const dupRows = new Set(state.duplicates.map(d => d.sourceRow))
			return state.rows.filter((row) => {
				if (row.errors && row.errors.length > 0) {
					return false
				}
				if (dupRows.has(row.sourceRow)) {
					return state.duplicateResolutions[row.sourceRow] === 'copy'
				}
				return true
			})
		},
	},

	actions: {
		/**
		 * Parse a file's text content with the selected format parser.
		 *
		 * @param {string} text The file text content (already read client-side).
		 * @param {string} format The parser/format id.
		 * @param {object} [options] Parser options (CSV mapping, backup passphrase).
		 * @return {Promise<void>}
		 * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-supported-import-formats
		 */
		async parseFile(text, format, options = {}) {
			this.loading = true
			this.error = null
			try {
				const parser = getParser(format)
				if (!parser) {
					throw new Error(`Unknown import format: ${format}`)
				}
				const parsed = await parser.parse(text, options)
				this.format = format
				this.rows = this.expandTotpRows(
					parsed.filter(r => !r.errors || r.errors.length === 0),
				)
				this.rejected = parsed
					.filter(r => r.errors && r.errors.length > 0)
					.map(r => ({ sourceRow: r.sourceRow, reason: r.errors.join('; '), name: r.name }))
			} catch (e) {
				this.error = e.message || 'Could not parse the file'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Split a TOTP/otp seed carried on an imported login row into its own
		 * `totp`-typed secret so migrating users keep working authenticator codes
		 * (add-totp-secrets D6). Bitwarden and KeePass parsers stash the seed in
		 * `additionalFields.totp`; this routes that seed into the `password`
		 * field (which becomes the encrypted `key` at commit) of a new `totp` row
		 * and strips it from the login row's additional fields. The seed is
		 * encrypted in the browser at commit like every other field — plaintext
		 * never reaches the server.
		 *
		 * @param {Array<object>} rows The accepted normalized rows.
		 * @return {Array<object>} The rows plus any extracted `totp` rows.
		 * @spec openspec/changes/add-totp-secrets/specs/secrets/spec.md#requirement-secret-types
		 */
		expandTotpRows(rows) {
			const out = []
			let nextSourceRow = rows.reduce((max, r) => Math.max(max, r.sourceRow || 0), 0)
			for (const row of rows) {
				const seed = row.additionalFields && row.additionalFields.totp
				if (typeof seed === 'string' && seed.trim() !== '') {
					// Remove the seed from the login row's additional fields.
					const rest = { ...row.additionalFields }
					delete rest.totp
					row.additionalFields = Object.keys(rest).length > 0 ? rest : null
					nextSourceRow += 1
					out.push(row)
					out.push({
						sourceRow: nextSourceRow,
						name: `${row.name} (TOTP)`,
						url: row.url ?? null,
						login: row.login ?? null,
						password: seed,
						additionalFields: null,
						folder: row.folder ?? '',
						type: 'totp',
						errors: [],
					})
				} else {
					out.push(row)
				}
			}
			return out
		},

		/**
		 * Detect duplicates by comparing accepted rows against the existing vault
		 * on normalized name + url. Uses only the plaintext list metadata the
		 * secret list API already returns — never decrypts the vault (design D6).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-duplicate-detection
		 */
		async detectDuplicates() {
			const secretStore = useSecretStore()
			// Pull the full vault metadata (names/urls are plaintext), paged
			// within the server's per-request cap.
			await secretStore.fetchAllSecrets()
			const existing = new Set(secretStore.secrets.map(s => dedupeKey(s)))

			this.duplicates = this.rows.filter(row => existing.has(dedupeKey(row)))
			// Default resolution: skip.
			const resolutions = {}
			for (const dup of this.duplicates) {
				resolutions[dup.sourceRow] = this.duplicateResolutions[dup.sourceRow] ?? 'skip'
			}
			this.duplicateResolutions = resolutions
		},

		/**
		 * Set the resolution for a single duplicate row.
		 *
		 * @param {number} sourceRow The duplicate row's source position.
		 * @param {string} resolution 'skip' or 'copy'.
		 * @return {void}
		 * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-duplicate-detection
		 */
		resolveDuplicate(sourceRow, resolution) {
			this.duplicateResolutions = { ...this.duplicateResolutions, [sourceRow]: resolution }
		},

		/**
		 * Bulk-apply a resolution to every duplicate row.
		 *
		 * @param {string} resolution 'skip' or 'copy'.
		 * @return {void}
		 * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-duplicate-detection
		 */
		resolveAllDuplicates(resolution) {
			const resolutions = {}
			for (const dup of this.duplicates) {
				resolutions[dup.sourceRow] = resolution
			}
			this.duplicateResolutions = resolutions
		},

		/**
		 * Encrypt a single row's sensitive fields with the owner's public key,
		 * producing a ciphertext-only commit item. Reuses the Create Secret path.
		 *
		 * @param {object} row The plaintext row.
		 * @param {CryptoKey} publicKey The owner's imported public key.
		 * @param {boolean} asCopy Whether to apply the "(imported)" copy suffix.
		 * @param {string|null} totpTypeId The resolved `totp` type id, stamped on
		 *   `totp` rows so an imported seed lands as an Authenticator secret.
		 * @return {Promise<object>} The ciphertext-only item.
		 * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-client-side-parsing-and-e2e-guarantee
		 * @spec openspec/changes/add-totp-secrets/specs/secrets/spec.md#requirement-secret-types
		 */
		async encryptRow(row, publicKey, asCopy, totpTypeId = null, passkeyTypeId = null, typedIds = {}) {
			const name = asCopy ? `${row.name} (imported)` : row.name
			const item = {
				sourceRow: row.sourceRow,
				name,
				url: row.url ?? null,
				folderPath: folderSegments(row.folder),
				key: await rsaEncrypt(String(row.password ?? ''), publicKey),
			}
			// A `totp` row carries its seed in `password` (now ciphertext in
			// `key`); stamp the resolved totp type id so the server files it as
			// an Authenticator secret (add-totp-secrets D6). The seed stays
			// ciphertext — the type is a UI hint only.
			if (row.type === 'totp' && totpTypeId) {
				item.typeId = totpTypeId
			}
			// A `passkey` row carries its canonical credential JSON in
			// `password` (now ciphertext in `key`); stamp the resolved type id
			// so the server files it as a Passkey (passkey-item-type D5).
			if (row.type === 'passkey' && passkeyTypeId) {
				item.typeId = passkeyTypeId
			}
			// `card` / `identity` rows carry their composite JSON payload in
			// `password` (now ciphertext in `key`); the type is a UI hint
			// only (card-identity-items §5.1).
			if ((row.type === 'card' || row.type === 'identity') && typedIds[row.type]) {
				item.typeId = typedIds[row.type]
			}
			if (row.login != null && row.login !== '') {
				item.login = await rsaEncrypt(String(row.login), publicKey)
			}
			if (row.additionalFields != null) {
				const json = typeof row.additionalFields === 'string'
					? row.additionalFields
					: JSON.stringify(row.additionalFields)
				if (json && json !== '{}' && json !== 'null') {
					item.additionalFields = await rsaEncrypt(json, publicKey)
				}
			}
			return item
		},

		/**
		 * Commit the accepted rows: encrypt client-side, POST in chunks of 50 with
		 * one retry per failed chunk, fold per-index + chunk failures into the
		 * rejected list, and build the transient summary (design D7/D8).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-chunked-batch-commit
		 */
		async commit() {
			const session = useSessionStore()
			if (!session.certificate || session.isLocked) {
				throw new Error('Vault is locked')
			}
			this.loading = true
			this.error = null
			this.step = 'commit'

			const publicKey = await importPublicKey(session.certificate)
			const rows = this.acceptedRows
			const dupRows = new Set(this.duplicates.map(d => d.sourceRow))

			// Resolve the `totp` type id once so imported authenticator seeds are
			// filed as Authenticator secrets (add-totp-secrets D6). Best-effort:
			// if the type list is unavailable the seed still imports as a login.
			let totpTypeId = null
			if (rows.some((row) => row.type === 'totp')) {
				const typeStore = useSecretTypeStore()
				if (!Array.isArray(typeStore.types) || typeStore.types.length === 0) {
					try {
						await typeStore.fetchTypes()
					} catch {
						// Non-fatal.
					}
				}
				const types = Array.isArray(typeStore.types) ? typeStore.types : []
				const totpType = types.find((type) => type && type.name === 'totp')
				totpTypeId = totpType ? totpType.id : null
			}

			// Resolve the `passkey` type id the same way (passkey-item-type D5).
			let passkeyTypeId = null
			if (rows.some((row) => row.type === 'passkey')) {
				const typeStore = useSecretTypeStore()
				if (!Array.isArray(typeStore.types) || typeStore.types.length === 0) {
					try {
						await typeStore.fetchTypes()
					} catch {
						// Non-fatal.
					}
				}
				const types = Array.isArray(typeStore.types) ? typeStore.types : []
				const passkeyType = types.find((type) => type && type.name === 'passkey')
				passkeyTypeId = passkeyType ? passkeyType.id : null
			}

			// Resolve `card` / `identity` type ids (card-identity-items §5.1).
			const typedIds = {}
			if (rows.some((row) => row.type === 'card' || row.type === 'identity')) {
				const typeStore = useSecretTypeStore()
				if (!Array.isArray(typeStore.types) || typeStore.types.length === 0) {
					try {
						await typeStore.fetchTypes()
					} catch {
						// Non-fatal.
					}
				}
				const types = Array.isArray(typeStore.types) ? typeStore.types : []
				for (const name of ['card', 'identity']) {
					const match = types.find((type) => type && type.name === name)
					if (match) {
						typedIds[name] = match.id
					}
				}
			}

			// Encrypt every row client-side BEFORE any request leaves the browser.
			const items = []
			const itemRowByIndex = []
			for (const row of rows) {
				const asCopy = dupRows.has(row.sourceRow)
				items.push(await this.encryptRow(row, publicKey, asCopy, totpTypeId, passkeyTypeId, typedIds))
				itemRowByIndex.push(row)
			}

			const chunks = []
			for (let i = 0; i < items.length; i += COMMIT_CHUNK_SIZE) {
				chunks.push(items.slice(i, i + COMMIT_CHUNK_SIZE))
			}
			this.totalChunks = chunks.length
			this.committedChunks = 0

			let created = 0
			const foldersCreated = new Set()

			for (const chunk of chunks) {
				const folders = [...new Set(chunk.map(it => it.folderPath.join('/')).filter(p => p !== ''))]
					.map(p => p.split('/'))
				const result = await this.postChunk({ folders, items: chunk })
				if (result === null) {
					// Chunk failed twice: reject all its rows.
					for (const it of chunk) {
						this.rejected.push({ sourceRow: it.sourceRow, reason: 'server error', name: it.name })
					}
				} else {
					for (const r of (result.results || [])) {
						const item = chunk[r.index]
						if (r.status === 'created') {
							created += 1
						} else {
							this.rejected.push({
								sourceRow: item ? item.sourceRow : r.index,
								reason: r.error || 'rejected by server',
								name: item ? item.name : '',
							})
						}
					}
					for (const f of (result.foldersCreated || [])) {
						foldersCreated.add(f)
					}
				}
				this.committedChunks += 1
			}

			this.summary = {
				imported: created,
				skippedDuplicates: this.duplicates.filter(
					d => this.duplicateResolutions[d.sourceRow] !== 'copy',
				).length,
				rejected: this.rejected.length,
				foldersCreated: foldersCreated.size,
				rejectedRows: [...this.rejected],
			}
			this.step = 'summary'
			this.loading = false
		},

		/**
		 * POST a single chunk with one retry on a network/5xx failure.
		 *
		 * @param {object} body The chunk body ({ folders, items }).
		 * @return {Promise<object|null>} The response data, or null after two failures.
		 * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-chunked-batch-commit
		 */
		async postChunk(body) {
			for (let attempt = 0; attempt < 2; attempt++) {
				try {
					const response = await axios.post(
						generateUrl('/apps/doriath/api/v1/secrets/import-batch'),
						body,
					)
					return response.data
				} catch (e) {
					if (attempt === 1) {
						return null
					}
				}
			}
			return null
		},

		/**
		 * Build a client-side CSV of the rejected rows for download. Generated in
		 * the browser; never uploaded (design D8). Rejected rows may carry
		 * plaintext-adjacent data, so this stays local.
		 *
		 * @return {string} The rejected-rows CSV text.
		 * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-malformed-row-rejection
		 */
		rejectedCsv() {
			const lines = ['row,name,reason']
			for (const r of this.rejected) {
				const cells = [r.sourceRow, r.name ?? '', r.reason ?? ''].map((v) => {
					const s = String(v)
					return /[",\r\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s
				})
				lines.push(cells.join(','))
			}
			return lines.join('\r\n')
		},

		/**
		 * Advance / move to a wizard step.
		 *
		 * @param {string} step The target step.
		 * @return {void}
		 * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-field-mapping-preview
		 */
		goToStep(step) {
			this.step = step
		},

		/**
		 * Release ALL plaintext rows + reset the wizard. Called on wizard
		 * close/destroy so no plaintext survives (encryption-suites Session
		 * Mechanism / spec persistence rule).
		 *
		 * @return {void}
		 * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-client-side-parsing-and-e2e-guarantee
		 */
		reset() {
			this.step = 'pick'
			this.format = null
			this.rows = []
			this.rejected = []
			this.mapping = []
			this.duplicateResolutions = {}
			this.duplicates = []
			this.committedChunks = 0
			this.totalChunks = 0
			this.summary = null
			this.loading = false
			this.error = null
		},
	},
})
