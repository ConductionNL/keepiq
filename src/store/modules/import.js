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
 * Commit encrypts every sensitive field (key, login, additionalFields, totp,
 * passkeys) in the browser with the owner's active EncryptionSuite public
 * certificate, using the SAME WebCrypto path as Create Secret (importPublicKey
 * + rsaEncrypt — no new crypto code), then POSTs ciphertext-only payloads in
 * chunks. The server never sees plaintext (ADR-003 / encryption-suites E2E
 * guarantee).
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import { importPublicKey, rsaEncrypt } from '../../crypto/index.js'
import { dedupeKey, folderSegments } from '../../import/model.js'
import { getParser } from '../../import/parserRegistry.js'
import { evaluateScore, fetchPolicy } from '../../policy/policy.js'
import { withDerivedFingerprint } from '../../ssh/sshKey.js'
import { BLOB_FIELDS } from '../../utils/valueFields.js'
import { useSecretStore } from './secret.js'
import { useSecretTypeStore } from './secretType.js'
import { useSessionStore } from './session.js'

// Importing the parsers module registers every format parser on the registry.
import '../../import/parsers/index.js'

/** Client-side chunk size for batch commit (design D7). */
export const COMMIT_CHUNK_SIZE = 50

/** The wizard steps in order. */
export const STEPS = [
	'pick',
	'mapping',
	'folders',
	'duplicates',
	'commit',
	'summary',
]

/**
 * The type the server files a secret under when it carries no type id
 * (`lib/Service/ImportService.php`: a null typeId resolves to `login`).
 */
const DEFAULT_TYPE_NAME = 'login'

/**
 * The vault's type ids, and the id of each type name with a system type
 * ahead of a custom type of the same name.
 *
 * @param {Array<object>} types The vault's secret types ({ id, name, scope }).
 * @return {{ids: Set<string>, idsByName: Map<string, string>}} The index.
 */
function typeIndex(types) {
	const ids = new Set()
	const idsByName = new Map()
	for (const type of systemTypesFirst(types)) {
		ids.add(type.id)
		if (type.name && !idsByName.has(type.name)) {
			idsByName.set(type.name, type.id)
		}
	}
	return { ids, idsByName }
}

/**
 * The types that have an id, system types ahead of custom ones.
 *
 * @param {Array<object>} types The vault's secret types.
 * @return {Array<object>} The ordered types.
 */
function systemTypesFirst(types) {
	const known = (Array.isArray(types) ? types : []).filter(
		(type) => type && type.id,
	)
	return [
		...known.filter((type) => type.scope === 'system'),
		...known.filter((type) => type.scope !== 'system'),
	]
}

/**
 * Resolve the type of every restored row (portability-export-choice-and-restore-fidelity D3).
 *
 * A row carrying `typeName` (a backup written by the current serializer)
 * takes, in order: the system type of that name; the vault's custom type of
 * that name; a new user-scoped custom type with the backup's name and label,
 * created once per name. A row without `typeName` (a parser, or an older
 * backup) takes its `type` as a type id the vault knows, which recovers
 * system types because their ids are the same on every instance, or else as
 * a type name. A row that names a type none of this finds falls back to the
 * default type and is listed, so the summary can warn on its row.
 *
 * @param {Array<object>} rows The rows to commit.
 * @param {object} typeStore The secret type store (types, createType).
 * @return {Promise<{typeIdFor: function(object): (string|null), created: Array<{name: string, label: string}>, fallbacks: Array<{sourceRow: number, type: string}>}>}
 *   The row-to-type-id lookup, the types created and the rows that fell back.
 * @spec openspec/changes/portability-export-choice-and-restore-fidelity/specs/export-selection-and-restore/spec.md#requirement-a-restored-backup-keeps-types-and-row-positions
 */
export async function resolveRowTypes(rows, typeStore) {
	const { ids, idsByName } = typeIndex(typeStore.types)
	const created = []
	const failedNames = new Set()
	const fallbacks = []
	const byRow = new Map()

	const createFor = async (row) => {
		if (failedNames.has(row.typeName)) {
			return null
		}
		try {
			const type = await typeStore.createType({ name: row.typeName, label: row.typeLabel, scope: 'user' })
			if (type && type.id) {
				idsByName.set(row.typeName, type.id)
				ids.add(type.id)
				created.push({ name: row.typeName, label: row.typeLabel })
				return type.id
			}
		} catch {
			// Falls back below, with a warning on the row.
		}
		failedNames.add(row.typeName)
		return null
	}

	for (const row of rows) {
		let id = null
		if (typeof row.typeName === 'string' && row.typeName !== '') {
			id = idsByName.get(row.typeName) ?? null
			if (id === null && typeof row.typeLabel === 'string' && row.typeLabel !== '') {
				id = await createFor(row)
			}
		}
		if (id === null && typeof row.type === 'string' && row.type !== '') {
			id = ids.has(row.type) ? row.type : (idsByName.get(row.type) ?? null)
		}
		if (id === null && namesAnotherType(row)) {
			fallbacks.push({ sourceRow: row.sourceRow, type: row.typeLabel || row.typeName || row.type })
		}
		byRow.set(row, id)
	}

	return { typeIdFor: (row) => byRow.get(row) ?? null, created, fallbacks }
}

/**
 * The additional fields of a row as the JSON to encrypt, or '' when there are none.
 *
 * @param {object|string|null|undefined} value The row's additionalFields.
 * @return {string} The JSON, or ''.
 */
function additionalFieldsJson(value) {
	if (value == null) {
		return ''
	}
	const json = typeof value === 'string' ? value : JSON.stringify(value)
	return json && json !== '{}' && json !== 'null' ? json : ''
}

/**
 * Encrypt a row's optional fields onto its commit item: the login, the
 * additional fields, and a login's code and passkeys. Each is sent only when
 * the row has it, since an encrypted empty string would read as a value.
 *
 * @param {object} item The commit item, added to.
 * @param {object} row The plaintext row.
 * @param {CryptoKey} publicKey The owner's imported public key.
 * @return {Promise<void>}
 */
async function encryptOptionalFields(item, row, publicKey) {
	if (row.login != null && row.login !== '') {
		item.login = await rsaEncrypt(String(row.login), publicKey)
	}
	// An SSH key's fingerprint is derived from its public key, never taken
	// from the file (vault-ssh-key-item D3).
	const json = additionalFieldsJson(
		await withDerivedFingerprint(row.type, row.additionalFields),
	)
	if (json) {
		item.additionalFields = await rsaEncrypt(json, publicKey)
	}
	for (const field of BLOB_FIELDS) {
		if (typeof row[field] === 'string' && row[field] !== '') {
			item[field] = await rsaEncrypt(row[field], publicKey)
		}
	}
}

/**
 * Whether a row names a type other than the default.
 *
 * @param {object} row The row.
 * @return {boolean}
 */
function namesAnotherType(row) {
	return [row.typeName, row.type].some(
		(type) => typeof type === 'string' && type !== '' && type !== DEFAULT_TYPE_NAME,
	)
}

/**
 * Whether the type store has no types loaded.
 *
 * @param {object} typeStore The secret type store.
 * @return {boolean}
 */
function typesMissing(typeStore) {
	return !Array.isArray(typeStore.types) || typeStore.types.length === 0
}

/**
 * The single root folder to import beneath, or '' for none. A slash would
 * split the one folder into a path, so it becomes a dash (keepiq#749).
 *
 * @param {object} options The commit() options.
 * @return {string} The root folder name.
 */
function rootFolderName(options) {
	return typeof options.rootFolder === 'string'
		? options.rootFolder.replaceAll('/', '-').trim()
		: ''
}

/**
 * Fetch the type list when a row names a type other than the default and the
 * list is not loaded yet. Best-effort: a failed fetch is not fatal.
 *
 * @param {object} typeStore The secret type store.
 * @param {Array<object>} rows The rows to import.
 * @return {Promise<void>}
 */
async function ensureTypesLoaded(typeStore, rows) {
	if (rows.some(namesAnotherType) && typesMissing(typeStore)) {
		try {
			await typeStore.fetchTypes()
		} catch {
			// Non-fatal.
		}
	}
}

/**
 * Encrypt every row into a commit item, in row order.
 *
 * @param {object} store The import store (for encryptRow).
 * @param {Array<object>} rows The accepted rows.
 * @param {object} context Encryption context.
 * @param {CryptoKey} context.publicKey The vault public key.
 * @param {Set<number>} context.dupRows Source rows to import as a copy.
 * @param {function(object): (string|null)} context.typeIdFor Maps a row to its type id.
 * @param {string} context.rootFolder Root folder name, or ''.
 * @return {Promise<Array<object>>} The encrypted items.
 */
async function encryptRows(store, rows, { publicKey, dupRows, typeIdFor, rootFolder }) {
	const items = []
	for (const row of rows) {
		const asCopy = dupRows.has(row.sourceRow)
		const item = await store.encryptRow(
			row,
			publicKey,
			asCopy,
			typeIdFor(row),
		)
		if (rootFolder !== '') {
			item.folderPath = [rootFolder, ...item.folderPath]
		}
		items.push(item)
	}
	return items
}

/**
 * The distinct, non-empty folder paths a chunk's items live in.
 *
 * @param {Array<object>} chunk The chunk's items.
 * @return {Array<Array<string>>} Folder paths as segment arrays.
 */
function chunkFolders(chunk) {
	return [
		...new Set(
			chunk
				.map((it) => it.folderPath.join('/'))
				.filter((p) => p !== ''),
		),
	].map((p) => p.split('/'))
}

/**
 * Fold one chunk's server result into the rejected list and the set of
 * created folders. A null result (the chunk failed twice) rejects all its rows.
 *
 * @param {Array<object>} rejected The rejected list, appended to.
 * @param {Array<object>} chunk The chunk's items.
 * @param {object|null} result The server result.
 * @param {Set<string>} foldersCreated Created folders, added to.
 * @return {number} The number of items created.
 */
function foldChunkResult(rejected, chunk, result, foldersCreated) {
	if (result === null) {
		rejectChunk(rejected, chunk)
		return 0
	}
	let created = 0
	for (const r of result.results || []) {
		if (r.status === 'created') {
			created += 1
		} else {
			rejected.push(serverRejection(chunk[r.index], r))
		}
	}
	for (const f of result.foldersCreated || []) {
		foldersCreated.add(f)
	}
	return created
}

/**
 * Reject every item of a chunk the server failed on.
 *
 * @param {Array<object>} rejected The rejected list, appended to.
 * @param {Array<object>} chunk The chunk's items.
 * @return {void}
 */
function rejectChunk(rejected, chunk) {
	for (const it of chunk) {
		rejected.push({
			sourceRow: it.sourceRow,
			reason: 'server error',
			name: it.name,
		})
	}
}

/**
 * Parse text with a parser: the detailed parse (rows, mapping and headers)
 * for a parser whose mapping can be adjusted, the plain parse otherwise.
 *
 * @param {object} parser The format parser.
 * @param {string} text The file text.
 * @param {object} options The parser options.
 * @return {Promise<object>} The rows, mapping, headers, adjustableMapping and sourceText.
 */
async function readWithParser(parser, text, options) {
	if (parser.adjustableMapping === true && typeof parser.parseDetailed === 'function') {
		const detailed = await parser.parseDetailed(text, options)
		return { rows: detailed.rows, mapping: detailed.mapping, headers: detailed.headers, adjustableMapping: true, sourceText: text }
	}
	const rows = await parser.parse(text, options)
	return { rows, mapping: [], headers: [], adjustableMapping: false, sourceText: null }
}

/**
 * The fields a partial backup left out, from its rows' markers.
 *
 * @param {Array<object>} rows The parsed rows.
 * @return {Array<string>} The left-out fields, in first-seen order.
 * @spec openspec/changes/portability-export-choice-and-restore-fidelity/specs/export-selection-and-restore/spec.md#requirement-a-restored-backup-keeps-types-and-row-positions
 */
function partialFieldsOf(rows) {
	const fields = new Set()
	for (const row of rows) {
		for (const field of Array.isArray(row.omittedFields) ? row.omittedFields : []) {
			fields.add(field)
		}
	}
	return [...fields]
}

/**
 * Whether a parsed row carries no errors.
 *
 * @param {object} row The parsed row.
 * @return {boolean}
 */
function rowIsClean(row) {
	return !row.errors || row.errors.length === 0
}

/**
 * The rejection entry for a row the parser could not read.
 *
 * @param {object} row The parsed row with errors.
 * @return {object} The rejection ({ sourceRow, reason, name }).
 */
function parseRejection(row) {
	return { sourceRow: row.sourceRow, reason: row.errors.join('; '), name: row.name }
}

/**
 * Maps a row to the name of its type, for the password policy: the vault's
 * name for a known type id, else the row's own type, else the default.
 *
 * @param {Array<object>} types The vault's secret types ({ id, name }).
 * @return {function(object): string} Row to type name.
 */
function typeNameResolver(types) {
	const nameById = new Map(
		(Array.isArray(types) ? types : []).map((type) => [type.id, type.name]),
	)
	return (row) => row.typeName || nameById.get(row.type) || row.type || DEFAULT_TYPE_NAME
}

/**
 * A row's password as text, '' when it has none.
 *
 * @param {object} row The row.
 * @return {string}
 */
function rowPassword(row) {
	return String(row.password ?? '')
}

/**
 * The commit items in chunks of COMMIT_CHUNK_SIZE.
 *
 * @param {Array<object>} items The commit items.
 * @return {Array<Array<object>>} The chunks.
 */
function inChunks(items) {
	const chunks = []
	for (let i = 0; i < items.length; i += COMMIT_CHUNK_SIZE) {
		chunks.push(items.slice(i, i + COMMIT_CHUNK_SIZE))
	}
	return chunks
}

/**
 * How many duplicates were skipped rather than imported as a copy.
 *
 * @param {Array<object>} duplicates The detected duplicates.
 * @param {object} resolutions The resolution per source row.
 * @return {number}
 */
function skippedDuplicateCount(duplicates, resolutions) {
	return duplicates.filter((d) => resolutions[d.sourceRow] !== 'copy').length
}

/**
 * Refuse to commit while the vault is locked.
 *
 * @param {object} session The session store.
 * @return {void}
 * @throws {Error} When the vault is locked.
 */
function assertUnlocked(session) {
	if (!session.certificate || session.isLocked) {
		throw new Error('Vault is locked')
	}
}

/**
 * The rejection entry for one item the server did not create.
 *
 * @param {object|undefined} item The chunk item at the result's index, if any.
 * @param {object} r The server's per-item result.
 * @return {object} The rejection ({ sourceRow, reason, name }).
 */
function serverRejection(item, r) {
	return {
		sourceRow: item ? item.sourceRow : r.index,
		reason: r.error || 'rejected by server',
		name: item ? item.name : '',
	}
}

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
		/** @type {Array<string>} The fields a partial backup left out; empty for a complete file. */
		partialFields: [],
		/** @type {Array<object>} The detected/adjusted CSV column mapping. */
		mapping: [],
		/** @type {Array<string>} The CSV header row, for the mapping step. */
		headers: [],
		/** @type {boolean} Whether the parsed format lets the user remap columns. */
		adjustableMapping: false,
		/** @type {string|null} The file text, kept only to re-parse after a remap; cleared on reset. */
		sourceText: null,
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
		 * Whether some column is mapped to the secret name. Always true for a
		 * format without an adjustable mapping.
		 *
		 * @param {object} state The store state.
		 * @return {boolean}
		 * @spec openspec/specs/portability-import-mapping/spec.md#requirement-adjustable-csv-mapping
		 */
		mappingHasName: (state) =>
			!state.adjustableMapping
			|| state.mapping.some((entry) => entry.target === 'name'),

		/**
		 * The rows that will be committed (accepted, non-duplicate, plus
		 * duplicates resolved as "import as copy").
		 *
		 * @param {object} state The store state.
		 * @return {Array<object>} The committable rows.
		 */
		acceptedRows: (state) => {
			const dupRows = new Set(state.duplicates.map((d) => d.sourceRow))
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
		 * @spec openspec/specs/secret-import/spec.md#requirement-supported-import-formats
		 */
		async parseFile(text, format, options = {}) {
			this.loading = true
			this.error = null
			try {
				const parser = getParser(format)
				if (!parser) {
					throw new Error(`Unknown import format: ${format}`)
				}
				const read = await readWithParser(parser, text, options)
				this.mapping = read.mapping
				this.headers = read.headers
				this.adjustableMapping = read.adjustableMapping
				this.sourceText = read.sourceText
				this.format = format
				this.partialFields = partialFieldsOf(read.rows)
				this.rows = read.rows.filter(rowIsClean)
				this.rejected = read.rows
					.filter((r) => !rowIsClean(r))
					.map(parseRejection)
			} catch (e) {
				this.error = e.message || 'Could not parse the file'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Detect duplicates by comparing accepted rows against the existing vault
		 * on normalized name + url. Uses only the plaintext list metadata the
		 * secret list API already returns — never decrypts the vault (design D6).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/secret-import/spec.md#requirement-duplicate-detection
		 */
		async detectDuplicates() {
			const secretStore = useSecretStore()
			// Pull the full vault metadata (names/urls are plaintext), paged
			// within the server's per-request cap.
			await secretStore.fetchAllSecrets()
			const existing = new Set(secretStore.secrets.map((s) => dedupeKey(s)))

			this.duplicates = this.rows.filter((row) => existing.has(dedupeKey(row)))
			// Default resolution: skip.
			const resolutions = {}
			for (const dup of this.duplicates) {
				resolutions[dup.sourceRow] =
					this.duplicateResolutions[dup.sourceRow] ?? 'skip'
			}
			this.duplicateResolutions = resolutions
		},

		/**
		 * Set the resolution for a single duplicate row.
		 *
		 * @param {number} sourceRow The duplicate row's source position.
		 * @param {string} resolution 'skip' or 'copy'.
		 * @return {void}
		 * @spec openspec/specs/secret-import/spec.md#requirement-duplicate-detection
		 */
		resolveDuplicate(sourceRow, resolution) {
			this.duplicateResolutions = {
				...this.duplicateResolutions,
				[sourceRow]: resolution,
			}
		},

		/**
		 * Bulk-apply a resolution to every duplicate row.
		 *
		 * @param {string} resolution 'skip' or 'copy'.
		 * @return {void}
		 * @spec openspec/specs/secret-import/spec.md#requirement-duplicate-detection
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
		 * @param {string|null} typeId The vault type id resolved for the row's
		 *   `type` (see resolveRowTypes), or null for the server's default type.
		 * @return {Promise<object>} The ciphertext-only item.
		 * @spec openspec/specs/secret-import/spec.md#requirement-client-side-parsing-and-e2e-guarantee
		 * @spec openspec/changes/add-totp-secrets/specs/secrets/spec.md#requirement-secret-types
		 * @spec openspec/changes/portability-export-choice-and-restore-fidelity/specs/export-selection-and-restore/spec.md#requirement-a-restored-backup-keeps-types-and-row-positions
		 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-import-and-export-keep-a-logins-code-and-passkeys-on-it
		 */
		async encryptRow(row, publicKey, asCopy, typeId = null) {
			const name = asCopy ? `${row.name} (imported)` : row.name
			const item = {
				sourceRow: row.sourceRow,
				name,
				url: row.url ?? null,
				folderPath: folderSegments(row.folder),
				key: await rsaEncrypt(String(row.password ?? ''), publicKey),
			}
			// Stamp the resolved type id so the server files the secret under
			// its own type: an authenticator seed, a passkey credential or a
			// card payload rides the encrypted `key` like any other value, and
			// the type stays a UI hint only.
			if (typeId) {
				item.typeId = typeId
			}
			await encryptOptionalFields(item, row, publicKey)
			return item
		},

		/**
		 * Hold every row to the organisation password policy, as the create
		 * and edit dialogs do (keepiq#746). The server cannot check a value it
		 * only sees encrypted, so the check runs here, before encryption. A
		 * row below the policy is taken out of `rows` (in place) and listed
		 * as rejected with the policy's reason.
		 *
		 * @param {Array<object>} rows The rows about to be committed (mutated).
		 * @param {Array<object>} types The vault's secret types ({ id, name }).
		 * @return {Promise<void>}
		 * @spec openspec/specs/secret-import/spec.md#requirement-chunked-batch-commit
		 */
		async dropPolicyRejected(rows, types) {
			const policy = await fetchPolicy()
			if (!policy || policy.policy_enabled !== true) {
				return
			}
			const typeNameOf = typeNameResolver(types)
			const kept = []
			for (const row of rows) {
				const verdict = evaluateScore(policy, typeNameOf(row), rowPassword(row))
				if (verdict.compliant) {
					kept.push(row)
				} else {
					this.rejected.push({
						sourceRow: row.sourceRow,
						reason: verdict.reason,
						name: row.name,
					})
				}
			}
			rows.splice(0, rows.length, ...kept)
		},

		/**
		 * Commit the accepted rows: encrypt client-side, POST in chunks of 50 with
		 * one retry per failed chunk, fold per-index + chunk failures into the
		 * rejected list, and build the transient summary (design D7/D8).
		 *
		 * @param {object} [options] Options.
		 * @param {string} [options.rootFolder] Import everything beneath one new
		 *   folder with this name; the source folders keep their hierarchy
		 *   below it (keepiq#749).
		 * @return {Promise<void>}
		 * @spec openspec/specs/secret-import/spec.md#requirement-chunked-batch-commit
		 * @spec openspec/changes/portability-export-choice-and-restore-fidelity/specs/export-selection-and-restore/spec.md#requirement-a-restored-backup-keeps-types-and-row-positions
		 */
		async commit(options = {}) {
			const rootFolder = rootFolderName(options)
			const session = useSessionStore()
			assertUnlocked(session)
			this.loading = true
			this.error = null
			this.step = 'commit'

			const publicKey = await importPublicKey(session.certificate)
			const rows = this.acceptedRows
			const dupRows = new Set(this.duplicates.map((d) => d.sourceRow))

			// Resolve every row's type to a type id this vault knows: a type id
			// from a backup, or a type name from a parser or an older backup
			// (keepiq#749; add-totp-secrets D6, passkey-item-type D5,
			// card-identity-items §5.1). Without the type list every row
			// imports under the default type.
			const typeStore = useSecretTypeStore()
			await ensureTypesLoaded(typeStore, rows)
			await this.dropPolicyRejected(rows, typeStore.types)
			// A backup's type names find, or create, each row's type (D3).
			const { typeIdFor, created: createdTypes, fallbacks: typeFallbacks } = await resolveRowTypes(rows, typeStore)

			// Encrypt every row client-side BEFORE any request leaves the browser.
			const items = await encryptRows(
				this,
				rows,
				{ publicKey, dupRows, typeIdFor, rootFolder },
			)

			const chunks = inChunks(items)
			this.totalChunks = chunks.length
			this.committedChunks = 0

			let created = 0
			const foldersCreated = new Set()

			for (const chunk of chunks) {
				const result = await this.postChunk({
					folders: chunkFolders(chunk),
					items: chunk,
				})
				created += foldChunkResult(this.rejected, chunk, result, foldersCreated)
				this.committedChunks += 1
			}

			this.summary = {
				imported: created,
				skippedDuplicates: skippedDuplicateCount(this.duplicates, this.duplicateResolutions),
				rejected: this.rejected.length,
				foldersCreated: foldersCreated.size,
				rejectedRows: [...this.rejected],
				createdTypes,
				typeFallbacks,
				omittedFields: [...this.partialFields],
			}
			this.step = 'summary'
			this.loading = false
		},

		/**
		 * POST a single chunk with one retry on a network/5xx failure.
		 *
		 * @param {object} body The chunk body ({ folders, items }).
		 * @return {Promise<object|null>} The response data, or null after two failures.
		 * @spec openspec/specs/secret-import/spec.md#requirement-chunked-batch-commit
		 */
		async postChunk(body) {
			for (let attempt = 0; attempt < 2; attempt++) {
				try {
					const response = await axios.post(
						generateUrl('/apps/keepiq/api/v1/secrets/import-batch'),
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
		 * @spec openspec/specs/secret-import/spec.md#requirement-malformed-row-rejection
		 */
		rejectedCsv() {
			const lines = ['row,name,reason']
			for (const r of this.rejected) {
				const cells = [r.sourceRow, r.name ?? '', r.reason ?? ''].map(
					(v) => {
						const s = String(v)
						return /[",\r\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s
					},
				)
				lines.push(cells.join(','))
			}
			return lines.join('\r\n')
		},

		/**
		 * Advance / move to a wizard step.
		 *
		 * @param {string} step The target step.
		 * @return {void}
		 * @spec openspec/specs/secret-import/spec.md#requirement-field-mapping-preview
		 */
		goToStep(step) {
			this.step = step
		},

		/**
		 * Remap the columns of the parsed CSV and parse it again, so the
		 * preview and the import both use the new mapping.
		 *
		 * @param {Array<{column: string, target: string}>} mapping The new mapping.
		 * @return {Promise<void>}
		 * @spec openspec/specs/portability-import-mapping/spec.md#requirement-adjustable-csv-mapping
		 */
		async applyMapping(mapping) {
			if (!this.adjustableMapping || this.sourceText === null) {
				return
			}
			await this.parseFile(this.sourceText, this.format, { mapping })
		},

		/**
		 * Release ALL plaintext rows + reset the wizard. Called on wizard
		 * close/destroy so no plaintext survives (encryption-suites Session
		 * Mechanism / spec persistence rule).
		 *
		 * @return {void}
		 * @spec openspec/specs/secret-import/spec.md#requirement-client-side-parsing-and-e2e-guarantee
		 */
		reset() {
			this.step = 'pick'
			this.format = null
			this.rows = []
			this.rejected = []
			this.partialFields = []
			this.mapping = []
			this.headers = []
			this.adjustableMapping = false
			this.sourceText = null
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
