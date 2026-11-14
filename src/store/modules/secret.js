import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import { importPublicKey, rsaDecrypt, rsaEncrypt } from '../../crypto/index.js'
import { PASSKEY_TYPE_NAME, passkeyRpId } from '../../passkey/passkey.js'
import { copyCiphertext, recipientFields } from '../../services/recipientCopy.js'
import {
	encryptSparse,
	notifyOwnerOnly,
	saveShared,
	VALUE_FIELDS,
} from '../../services/shareSave.js'
import { secretPlacement } from '../../utils/secretPlacement.js'
import { isAccessExpired } from '../../utils/shareRestriction.js'
import { BLOB_FIELDS } from '../../utils/valueFields.js'
import { useOfflineStore } from './offline.js'
import { useSecretTypeStore } from './secretType.js'
import { useSessionStore } from './session.js'
import { useShareStore } from './share.js'

/**
 * Pinia store for secrets.
 *
 * The store owns the end-to-end model: it sends RSA ciphertext to the API
 * (encrypted in the browser with the owner's public certificate) and
 * decrypts the blobs it receives using the session CryptoKey. The server
 * never sees plaintext (ADR-003).
 */

/**
 * The query that means "every secret in this vault".
 *
 * The bulk paths (export, transfer, import reconciliation) pass this
 * EXPLICITLY so they never inherit the list's stored folder / type / search
 * filters. Without it, making a bare `fetchSecrets()` reuse the stored query
 * would quietly shrink an export to whichever folder the user happened to be
 * browsing — the mirror image of the bug that motivated storing it at all.
 *
 * `state: 'kept'` is everything not in the trash: an export or an import
 * reconciliation carries archived secrets too (vault-trash-and-archive).
 *
 * @type {{folderId: null, typeId: null, search: string, state: string}}
 */
const WHOLE_VAULT = {
	folderId: null,
	typeId: null,
	search: '',
	state: 'kept',
	favourite: false,
	tag: null,
}

/**
 * Merge write-backs in flight, by secret id: `{ done, waiting, waiters, base,
 * count, updatedAt }`, removed when it settles. `done` resolves to the source
 * version it wrote (`sourceUpdatedAt`, null when it saved only the own copy),
 * or to null when it failed. `waiting` is its last wait on the partner lookup
 * limit, and `waiters` are the saves held behind it that want to hear of one.
 * `base` and `count` are the version it saves from and the pending blobs it
 * drops; `updatedAt` is the version it saved, once it did.
 *
 * @type {Map<string, {done: Promise<{sourceUpdatedAt: string|null}|null>, waiting: number|null, waiters: Set<(ms: number) => void>, base: string, count: number, updatedAt: string|null}>}
 */
const writeBacks = new Map()

/** How setListQuery stores each list filter it is given. */
const LIST_FILTERS = {
	folderId: (value) => value ?? null,
	search: (value) => value ?? '',
	typeId: (value) => value ?? null,
	state: (value) => value || 'live',
	favourite: (value) => !!value,
	tag: (value) => value || null,
}

/**
 * Whether a snapshot secret passes the list's folder, type, favourite and
 * tag filters.
 *
 * @param {object} secret A snapshot secret.
 * @param {object} query The list query.
 * @return {boolean}
 */
function inListFilters(secret, query) {
	return (
		(!query.folderId || secret.folderId === query.folderId)
		// Without the type, a type-filtered list showed the whole vault offline.
		&& (!query.typeId || secret.typeId === query.typeId)
		&& (!query.favourite || secret.favourite)
		&& (!query.tag || (secret.tags || []).includes(query.tag))
	)
}

/**
 * The snapshot secrets the list query selects; search matches the name or
 * the URL, in any case.
 *
 * @param {Array<object>} secrets The snapshot secrets.
 * @param {object} query The list query.
 * @return {Array<object>}
 */
function offlineItems(secrets, query) {
	const search = (query.search ?? '').toLowerCase()
	return secrets.filter(
		(s) =>
			inListFilters(s, query)
			&& (!search
				|| (s.name || '').toLowerCase().includes(search)
				|| (s.url || '').toLowerCase().includes(search)),
	)
}

/**
 * The server request's parameters: paging and sort, then each filter the
 * query sets. The type filter is server-side (passkey-item-type §3.3);
 * favourites and tags come from vault-favourites-tags-and-last-used.
 *
 * @param {object} query The list query.
 * @param {object} paging page, limit, sort and direction.
 * @return {object}
 */
function listParams(query, paging) {
	const params = { ...paging }
	if (query.folderId) {
		params.folderId = query.folderId
	}
	if (query.search) {
		params.search = query.search
	}
	if (query.typeId) {
		params.typeId = query.typeId
	}
	if (query.state !== 'live') {
		params.state = query.state
	}
	if (query.favourite) {
		params.favourite = 1
	}
	if (query.tag) {
		params.tag = query.tag
	}
	return params
}

/**
 * The paging and sort of a list fetch: each as passed, else as stored.
 *
 * @param {object} options The fetch options.
 * @param {object} store The secret store.
 * @return {{page: number, limit: number, sort: string, direction: string}}
 */
function pagingOf(options, store) {
	return {
		page: options.page ?? store.page,
		limit: options.limit ?? store.limit,
		sort: options.sort ?? store.sort.field,
		direction: options.direction ?? store.sort.direction,
	}
}

/**
 * Show a page of the server's list answer.
 *
 * @param {object} store The secret store.
 * @param {object} data The answer: items, total and page.
 * @return {void}
 */
function applyPage(store, data) {
	store.secrets = data.items || []
	store.totalCount = data.total || 0
	store.page = data.page || 1
}

/**
 * On a network error, serve the list from the cached snapshot rather than
 * failing (offline-readonly-cache §4.2): the offline flag can lag the actual
 * connectivity loss.
 *
 * @param {object} store The secret store.
 * @param {Error} error The failed request's error.
 * @return {boolean} Whether the snapshot was served.
 */
function serveListFromSnapshot(store, error) {
	const offline = useOfflineStore()
	if (!(store.isNetworkError(error) && offline.vault)) {
		return false
	}
	offline.servedFromCache = true
	store.secrets = offline.vault.secrets
	store.totalCount = offline.vault.secrets.length
	store.page = 1
	return true
}

/**
 * A secret of the cached snapshot, if there is a snapshot holding it.
 *
 * @param {object} offline The offline store.
 * @param {string} id The secret ID.
 * @return {object|undefined|null}
 */
function snapshotSecret(offline, id) {
	return offline.vault && offline.vault.secrets.find((s) => s.id === id)
}

/**
 * Decrypt a secret and make it the open one.
 *
 * @param {object} store The secret store.
 * @param {object} secret The secret with ciphertext.
 * @return {Promise<object>} The open secret.
 */
async function openSecret(store, secret) {
	store.currentSecret = await store.decryptSecret(secret)
	return store.currentSecret
}

/**
 * Read a secret from the server and open it, then write back the pending
 * extra fields it merged. The write-back is not awaited: it is a share save,
 * which must not hold up opening the secret.
 *
 * @param {object} store The secret store.
 * @param {string} id The secret ID.
 * @return {Promise<object>} The open secret.
 */
async function openFromServer(store, id) {
	const runningAtSend = writeBacks.get(id) ?? null
	const response = await axios.get(
		generateUrl(`/apps/keepiq/api/v1/secrets/${id}`),
	)
	await openSecret(store, response.data)
	store.persistMergedPending(id, runningAtSend).catch(() => {})
	return store.currentSecret
}

/**
 * The extra fields as decrypted: the parsed JSON, or the text when it is not
 * JSON.
 *
 * @param {string} json The decrypted text.
 * @return {object|Array|string|number|boolean|null}
 */
function parsedOrText(json) {
	try {
		return JSON.parse(json)
	} catch {
		return json
	}
}

/**
 * Decrypt the key, login and extra fields that are set, in place.
 *
 * @param {object} decrypted A copy of the secret with ciphertext.
 * @param {CryptoKey} cryptoKey The session private key.
 * @return {Promise<void>}
 */
async function decryptValueFields(decrypted, cryptoKey) {
	if (decrypted.key) {
		decrypted.key = await rsaDecrypt(decrypted.key, cryptoKey)
	}
	if (decrypted.login) {
		decrypted.login = await rsaDecrypt(decrypted.login, cryptoKey)
	}
	if (decrypted.additionalFields) {
		decrypted.additionalFields = parsedOrText(
			await rsaDecrypt(decrypted.additionalFields, cryptoKey),
		)
	}
	await decryptBlobFields(decrypted, cryptoKey)
}

/**
 * Decrypt the one-time code and passkeys in place: plain strings, `''` when
 * not set; the passkeys stay JSON text.
 *
 * @param {object} decrypted A copy of the secret with ciphertext.
 * @param {CryptoKey} cryptoKey The session private key.
 * @return {Promise<void>}
 */
async function decryptBlobFields(decrypted, cryptoKey) {
	for (const field of BLOB_FIELDS) {
		if (decrypted[field]) {
			decrypted[field] = await rsaDecrypt(decrypted[field], cryptoKey)
		} else {
			decrypted[field] = ''
		}
	}
}

/**
 * Merge the request-filled pending blobs into the decrypted extra fields,
 * and count them as merged only when every blob merged.
 *
 * @param {object} decrypted The decrypted secret.
 * @param {CryptoKey} cryptoKey The session private key.
 * @return {Promise<void>}
 */
async function mergePendingInto(decrypted, cryptoKey) {
	const pending = decrypted.pendingAdditionalFields
	if (!Array.isArray(pending) || pending.length === 0) {
		return
	}
	const merged = await mergePendingFields(
		decrypted.additionalFields,
		pending,
		cryptoKey,
	)
	decrypted.additionalFields = merged.fields
	decrypted.mergedPending = merged.complete ? pending.length : 0
}

/**
 * Whether the open secret is `id` and merged pending blobs to write back.
 *
 * @param {object|null} current The open secret.
 * @param {string} id The secret ID.
 * @return {boolean}
 */
function hasMergedPending(current, id) {
	return Boolean(current) && current.id === id && current.mergedPending > 0
}

/**
 * The write-back of the open secret to wait for instead of starting one: the
 * one running now, or the one that ran when the open's read went out if the
 * read was on its base.
 *
 * @param {object} current The open secret.
 * @param {object|null} runningAtSend The write-back in flight when the read went out.
 * @return {object|null}
 */
function runningWriteBack(current, runningAtSend) {
	return (
		writeBacks.get(current.id)
		?? (runningAtSend?.base === current.updatedAt ? runningAtSend : null)
	)
}

/**
 * Wait for a running write-back. Read on the version it saved from, this
 * copy also merged the blobs it dropped; fills that came after stay to drop.
 *
 * @param {object} current The open secret.
 * @param {object} running The write-back in flight.
 * @return {Promise<void>}
 */
async function joinWriteBack(current, running) {
	const readAt = current.updatedAt
	const saved = await running.done
	if (saved && readAt === running.base) {
		current.mergedPending -= running.count
		current.updatedAt = running.updatedAt ?? current.updatedAt
	}
}

/**
 * The save options of a write-back. Nobody sees this save; an edit held
 * behind it shows its wait.
 *
 * @param {object} current The open secret.
 * @param {{inFlight: object, source: {updatedAt: string|null}}} run The write-back.
 * @return {object}
 */
function writeBackOptions(current, run) {
	return {
		baseUpdatedAt: current.updatedAt,
		writeBack: true,
		onWait: (ms) => {
			run.inFlight.waiting = ms
			for (const waiter of run.inFlight.waiters) {
				waiter(ms)
			}
		},
		onSourceSaved: (version) => {
			run.source.updatedAt = version
		},
	}
}

/**
 * Save the merged extra fields of the open secret. A failure leaves them
 * pending; they are merged again on the next open.
 *
 * @param {object} store The secret store.
 * @param {{id: string, current: object, inFlight: object, source: {updatedAt: string|null}}} run The write-back.
 * @return {Promise<{sourceUpdatedAt: string|null}|null>}
 */
function startWriteBack(store, run) {
	const { current } = run
	return store
		.updateSecret(
			run.id,
			{
				additionalFields: current.additionalFields,
				mergedPending: current.mergedPending,
			},
			writeBackOptions(current, run),
		)
		.then((saved) => {
			run.inFlight.updatedAt = saved?.updatedAt ?? null
			current.mergedPending = 0
			// Also when another open replaced it as the open secret.
			current.updatedAt = saved?.updatedAt ?? current.updatedAt
			return { sourceUpdatedAt: run.source.updatedAt }
		})
		.catch(() => null)
}

/**
 * The value of a new secret encrypted for each team-folder member.
 *
 * @param {object} fields The plain value fields (recipientFields).
 * @param {Array<{userId: string, certificate: string}>} recipients The members.
 * @return {Promise<Array<object>>} One copy per member.
 */
async function encryptCopies(fields, recipients) {
	const copies = []
	for (const recipient of recipients) {
		const blob = await useShareStore().encryptForRecipient(
			fields,
			recipient.certificate,
		)
		copies.push({
			targetUserId: recipient.userId,
			...copyCiphertext(blob),
		})
	}
	return copies
}

/** The owner row's ciphertext fields, each with its value when absent. */
const OWNER_CIPHERTEXT_DEFAULTS = {
	key: '',
	login: null,
	additionalFields: null,
	totp: null,
	passkeys: null,
}

/**
 * The owner row's ciphertext fields of an encrypted value.
 *
 * @param {object} owner The value encrypted for the folder owner.
 * @return {object}
 */
function ownerCiphertext(owner) {
	return Object.fromEntries(
		Object.entries(OWNER_CIPHERTEXT_DEFAULTS).map(([field, absent]) => [
			field,
			owner[field] ?? absent,
		]),
	)
}

/**
 * The relying party a passkey key names, or null.
 *
 * @param {object} data The new secret.
 * @return {string|null}
 */
function passkeyUrl(data) {
	return passkeyRpId(String(data.key ?? '')) ?? null
}

/**
 * The plain url of a new secret. Passkey secrets mirror the RP id into it so
 * they are matchable and searchable by site (passkey-item-type D3). Only the
 * public RP domain is mirrored, never credential material.
 *
 * @param {object} store The secret store.
 * @param {object} data The new secret.
 * @return {string|null}
 */
function secretUrl(store, data) {
	const url = data.url ?? null
	if (!url && store.isPasskeyTypeId(data.typeId)) {
		return passkeyUrl(data)
	}
	return url
}

/**
 * Whether a login was entered.
 *
 * @param {string|number|null|undefined} login The login.
 * @return {boolean}
 */
function hasLogin(login) {
	return login !== undefined && login !== null && login !== ''
}

/**
 * Encrypt the login, extra fields, one-time code and passkeys that were
 * entered into the payload. Sent only when entered: an encrypted empty
 * string reads as a value.
 *
 * @param {object} payload The create payload.
 * @param {{data: object, publicKey: CryptoKey}} input The new secret and the own public key.
 * @return {Promise<void>}
 */
async function encryptOptionalFields(payload, input) {
	const { data, publicKey } = input
	if (hasLogin(data.login)) {
		payload.login = await rsaEncrypt(String(data.login), publicKey)
	}
	if (data.additionalFields) {
		payload.additionalFields = await rsaEncrypt(
			extraFieldsText(data.additionalFields),
			publicKey,
		)
	}
	for (const field of BLOB_FIELDS) {
		if (data[field]) {
			payload[field] = await rsaEncrypt(String(data[field]), publicKey)
		}
	}
}

/**
 * Extra fields as the text that is encrypted.
 *
 * @param {object|string} fields The extra fields.
 * @return {string}
 */
function extraFieldsText(fields) {
	return typeof fields === 'string' ? fields : JSON.stringify(fields)
}

/**
 * Put a create into the sealed offline queue.
 *
 * @param {object} offline The offline store.
 * @param {object} payload The encrypted create payload.
 * @return {Promise<object>} The secret as it will be, marked pendingSync.
 */
async function queueCreate(offline, payload) {
	const secretId = crypto.randomUUID()
	await offline.enqueue({ op: 'create', secretId, body: payload })
	return { id: secretId, ...payload, pendingSync: true }
}

/**
 * Never save alongside a merge write-back of the same secret. A caller that
 * sends the versions it read takes the ones a write-back that saved wrote
 * (writeBackSettled), or the write-back makes them stale.
 *
 * @param {object} store The secret store.
 * @param {{id: string, options: object}} save The secret and the update options.
 * @return {Promise<void>}
 */
async function awaitWriteBack(store, save) {
	if (!save.options.writeBack) {
		await store.writeBackSettled(save.id, save.options.onWait ?? null)
	}
}

/**
 * The plain fields an update changes. Keeps the passkey RP-id to url mirror
 * in sync when the credential changes without an explicit url
 * (passkey-item-type D3).
 *
 * @param {object} store The secret store.
 * @param {object} data The fields to change.
 * @return {object} The patch so far.
 */
function plainPatch(store, data) {
	const payload = {}
	for (const field of ['name', 'url', 'typeId', 'folderId']) {
		if (data[field] !== undefined) {
			payload[field] = data[field]
		}
	}
	const rpId = mirroredRpId(store, data)
	if (rpId) {
		payload.url = rpId
	}
	return payload
}

/**
 * The RP id a changed passkey credential names, when the update sets no url.
 *
 * @param {object} store The secret store.
 * @param {object} data The fields to change.
 * @return {string|null}
 */
function mirroredRpId(store, data) {
	if (data.key === undefined || data.url !== undefined) {
		return null
	}
	if (!store.isPasskeyTypeId(data.typeId ?? store.currentSecret?.typeId)) {
		return null
	}
	return passkeyRpId(String(data.key ?? ''))
}

/**
 * The changed value fields in plain: strings, `null` for a cleared login or
 * extra fields, `''` for a removed one-time code or passkeys (encryptSparse
 * sends that as is).
 *
 * @param {object} data The fields to change.
 * @return {object}
 */
function plainDiffOf(data) {
	const plainDiff = {}
	if (data.key !== undefined) {
		plainDiff.key = String(data.key)
	}
	if (data.login !== undefined) {
		plainDiff.login = loginDiff(data.login)
	}
	if (data.additionalFields !== undefined) {
		plainDiff.additionalFields = extraFieldsDiff(data.additionalFields)
	}
	addBlobDiff(plainDiff, data)
	return plainDiff
}

/**
 * A changed login as text, or null when cleared.
 *
 * @param {string|number|null} login The login.
 * @return {string|null}
 */
function loginDiff(login) {
	if (!login) {
		return null
	}
	return String(login)
}

/**
 * A changed one-time code or passkeys as text, `''` when removed.
 *
 * @param {string|null} value The field.
 * @return {string}
 */
function blobText(value) {
	if (!value) {
		return ''
	}
	return String(value)
}

/**
 * Changed extra fields as text, or null when cleared.
 *
 * @param {object|string|null} fields The extra fields.
 * @return {string|null}
 */
function extraFieldsDiff(fields) {
	return fields === null ? null : extraFieldsText(fields)
}

/**
 * Add the changed one-time code and passkeys to the plain diff: `''` (or
 * null) removes one.
 *
 * @param {object} plainDiff The plain diff.
 * @param {object} data The fields to change.
 * @return {void}
 */
function addBlobDiff(plainDiff, data) {
	for (const field of BLOB_FIELDS) {
		if (data[field] !== undefined) {
			plainDiff[field] = blobText(data[field])
		}
	}
}

/**
 * Encrypt a value change into the patch. The own row and every copy carry
 * the same fields with the same null-ness, so both come from one plain map.
 * When the extra-field blob holds the request-filled pending members the
 * caller's copy merged on open, the server may drop them (keepiq#750).
 *
 * @param {object} payload The patch.
 * @param {{data: object, session: object}} input The fields to change and the session.
 * @return {Promise<object>} The plain diff.
 */
async function encryptValueChange(payload, input) {
	const { data, session } = input
	if (!session.certificate) {
		throw new Error('Vault is locked')
	}
	const plainDiff = plainDiffOf(data)
	Object.assign(payload, await encryptSparse(plainDiff, session.certificate))
	if (data.additionalFields !== undefined && data.mergedPending > 0) {
		payload.mergedPending = data.mergedPending
	}
	return plainDiff
}

/**
 * Put an update into the sealed offline queue, on the cached version.
 *
 * @param {object} offline The offline store.
 * @param {object} change The update.
 * @return {Promise<object>} The secret as it will be, marked pendingSync.
 */
async function queueUpdate(offline, change) {
	const cached = offline.vault?.secrets?.find((x) => x.id === change.id)
	await offline.enqueue({
		op: 'update',
		secretId: change.id,
		baseUpdatedAt: change.given ?? cached?.updatedAt ?? null,
		sourceBaseUpdatedAt: change.sourceBase,
		body: change.payload,
	})
	return { ...(cached || { id: change.id }), ...change.payload, pendingSync: true }
}

/**
 * Save an update: a value change through the share save, anything else as a
 * plain update of the row.
 *
 * @param {object} store The secret store.
 * @param {object} change The update.
 * @return {Promise<object>} The saved row.
 */
async function saveChange(store, change) {
	if (change.valueChanged) {
		return saveValueChange(store, change)
	}
	const body = change.given
		? { ...change.payload, baseUpdatedAt: change.given }
		: change.payload
	const response = await axios.put(
		generateUrl(`/apps/keepiq/api/v1/secrets/${change.id}`),
		body,
	)
	return response.data
}

/**
 * Save a value change through the share save, which writes every copy and
 * partner share in the same transaction or nothing at all.
 *
 * @param {object} store The secret store.
 * @param {object} change The update.
 * @return {Promise<object>} The saved row.
 */
async function saveValueChange(store, change) {
	const { id, options } = change
	const result = await saveShared({
		id,
		plainDiff: change.plainDiff,
		patch: change.payload,
		baseUpdatedAt: store.baseOf(id, change.given),
		sourceBaseUpdatedAt: change.sourceBase,
		withoutUnreachable: options.withoutUnreachable ?? [],
		onWait: options.onWait ?? null,
	})
	options.onSourceSaved?.(result.sourceUpdatedAt ?? null)
	await notifyOwnerOnly(result)
	return result.secret
}

/**
 * Keep the open copy's version current, so its next edit is not refused as
 * stale.
 *
 * @param {object} store The secret store.
 * @param {{id: string, saved: object}} save The secret and the saved row.
 * @return {void}
 */
function keepOpenVersion(store, save) {
	if (store.currentSecret?.id === save.id && save.saved?.updatedAt) {
		store.currentSecret.updatedAt = save.saved.updatedAt
	}
}

/**
 * Merge request-filled extra-field blobs into the owner's own extra fields
 * (keepiq#750). Each pending blob is the JSON object of the members one fill
 * supplied; later fills win for a member they both name. A blob that cannot
 * be decrypted is skipped and makes the merge incomplete, so it is not
 * dropped from the server.
 *
 * @param {object|string|null|undefined} own The owner's decrypted extra fields.
 * @param {Array<string>} pending The pending ciphertexts, oldest first.
 * @param {CryptoKey} cryptoKey The session private key.
 * @return {Promise<{fields: object|string|null, complete: boolean}>}
 * @spec openspec/specs/secret-requests/spec.md#requirement-requestable-fields
 */
async function mergePendingFields(own, pending, cryptoKey) {
	if (
		own !== null
		&& own !== undefined
		&& (typeof own !== 'object' || Array.isArray(own))
	) {
		// Not a member object: there is nothing to merge into safely.
		return { fields: own, complete: false }
	}
	const fields = { ...(own || {}) }
	let complete = true
	for (const ciphertext of pending) {
		try {
			const members = JSON.parse(await rsaDecrypt(ciphertext, cryptoKey))
			if (members && typeof members === 'object' && !Array.isArray(members)) {
				Object.assign(fields, members)
			} else {
				complete = false
			}
		} catch {
			complete = false
		}
	}
	return { fields, complete }
}

export const useSecretStore = defineStore('secret', {
	state: () => ({
		/** @type {Array<object>} The current page of secrets (metadata + ciphertext). */
		secrets: [],
		/** @type {object|null} The currently opened secret with decrypted fields. */
		currentSecret: null,
		/** @type {number} Total number of matching secrets (for pagination). */
		totalCount: 0,
		/** @type {boolean} Whether a request is in flight. */
		loading: false,
		/** @type {object} Active list filters. */
		filters: {
			folderId: null,
			search: '',
			typeId: null,
			state: 'live',
			favourite: false,
			tag: null,
		},
		/** @type {Array<{tag: string, count: number}>} The holder's tags, for the filter menu. */
		tags: [],
		/** @type {object} Active sort. */
		sort: { field: 'name', direction: 'asc' },
		/** @type {number} The current 1-based page. */
		page: 1,
		/** @type {number} The page size. */
		limit: 50,
	}),

	actions: {
		/**
		 * Record what the vault list is currently showing, so a refresh that
		 * knows nothing about the view can reproduce it.
		 *
		 * The list used to pass `folderId` / `typeId` / `search` on every call
		 * and nothing ever wrote them to `filters`, so they were permanently
		 * null. Any bare `fetchSecrets()` — the sidebar's post-edit/move/delete
		 * refresh, FolderMoveDialog's — therefore silently dropped the folder
		 * filter and replaced a folder's contents with the WHOLE vault: move a
		 * secret out of a folder and every other secret appeared in it. The
		 * sidebar's own comment claimed a bare call "reuses the store's active
		 * filters"; this is what makes that true.
		 *
		 * Sort lives here too, so a refresh does not silently reorder the list.
		 *
		 * @param {object} query The list's active query.
		 * @param {string|null} [query.folderId] Folder being browsed (null = vault root).
		 * @param {string} [query.search] Active search term.
		 * @param {string|null} [query.typeId] Active secret-type filter.
		 * @param {string} [query.sort] Active sort field.
		 * @return {void}
		 * @spec openspec/specs/secrets/spec.md#requirement-list-and-pagination
		 */
		setListQuery(query = {}) {
			for (const [field, normalise] of Object.entries(LIST_FILTERS)) {
				if (field in query) this.filters[field] = normalise(query[field])
			}
			if ('sort' in query && query.sort) {
				this.sort.field = query.sort
				// Last used reads newest first; every other sort keeps ascending.
				this.sort.direction = query.sort === 'last_used_at' ? 'desc' : 'asc'
			}
		},

		/**
		 * Fetch a page of secrets (list or search).
		 *
		 * Anything not passed falls back to the stored list query, so the
		 * sidebar and the folder dialogs can refresh with `fetchSecrets()` and
		 * get the list the user is actually looking at. See setListQuery.
		 *
		 * @param {object} options Optional overrides for filters/sort/page.
		 * @return {Promise<void>}
		 * @spec openspec/specs/secrets/spec.md#requirement-list-and-pagination
		 * @spec openspec/specs/secrets/spec.md#requirement-search
		 */
		async fetchSecrets(options = {}) {
			this.loading = true
			try {
				// Offline (served from cache): list from the decrypted snapshot
				// instead of the live API (offline-readonly-cache §4.2).
				const offline = useOfflineStore()
				const query = this.listQuery(options)
				if (offline.servedFromCache && offline.vault) {
					// The offline snapshot holds live secrets only, so the Trash
					// and Archive views are empty offline.
					const items =
						query.state === 'live' || query.state === 'kept'
							? offlineItems(offline.vault.secrets, query)
							: []
					this.secrets = items
					this.totalCount = items.length
					this.page = 1
					return
				}
				await this.fetchOnline(query, options)
			} finally {
				this.loading = false
			}
		},

		/**
		 * The list query of a fetch: each filter as passed, else as stored.
		 * PRESENCE, not nullishness: an explicit null means "no filter" (vault
		 * root, or a bulk fetch of the whole vault), which `??` would silently
		 * turn back into the stored filter. Trash and archive state
		 * (vault-trash-and-archive) is live unless the view names another.
		 *
		 * @param {object} options The fetch options.
		 * @return {{state: string, folderId: (string|null), search: (string|null), typeId: (string|null), favourite: boolean, tag: (string|null)}}
		 * @spec openspec/specs/secrets/spec.md#requirement-list-and-pagination
		 */
		listQuery(options) {
			const pick = (key) => (key in options ? options[key] : this.filters[key])
			return {
				state: pick('state') || 'live',
				folderId: pick('folderId'),
				search: pick('search'),
				typeId: pick('typeId'),
				...this.organisationFilter(options),
			}
		},

		/**
		 * Fetch a page of secrets from the server. When the network is
		 * unreachable and a cached snapshot exists, serve the list from it
		 * rather than failing (offline-readonly-cache §4.2): the offline flag
		 * can lag the actual connectivity loss.
		 *
		 * @param {object} query The list query (listQuery).
		 * @param {object} options The fetch options, for paging and sort.
		 * @return {Promise<void>}
		 * @spec openspec/specs/secrets/spec.md#requirement-list-and-pagination
		 */
		async fetchOnline(query, options) {
			const params = listParams(query, pagingOf(options, this))
			try {
				const response = await axios.get(
					generateUrl('/apps/keepiq/api/v1/secrets'),
					{ params },
				)
				applyPage(this, response.data)
			} catch (e) {
				if (!serveListFromSnapshot(this, e)) {
					throw e
				}
			}
		},

		/**
		 * Fetch the ENTIRE vault by paging within the server's per-request cap,
		 * accumulating into `this.secrets`. The bulk export/transfer flows need
		 * every secret; a single huge `limit` is rejected by the server (NC caps
		 * a page at a few hundred rows), so we page in chunks of PAGE_SIZE until
		 * the accumulated count reaches the reported total.
		 *
		 * @param {object} options Optional filters ({ folderId, typeId, search }).
		 * @return {Promise<Array<object>>} the full secret list.
		 * @spec openspec/specs/secrets/spec.md#requirement-list-and-pagination
		 */
		async fetchAllSecrets(options = {}) {
			// Offline: fetchSecrets already returns the whole cached snapshot.
			const offline = useOfflineStore()
			if (offline.servedFromCache && offline.vault) {
				await this.fetchSecrets({ ...WHOLE_VAULT, ...options, page: 1 })
				return this.secrets
			}

			// Page THROUGH fetchSecrets so it stays the single API/offline seam:
			// each call replaces `this.secrets` with one page and sets totalCount
			// to the full total; we accumulate until we have them all.
			const PAGE_SIZE = 100
			const all = []
			let page = 1
			// Defensive bound; PAGE_SIZE * 100000 covers any realistic vault.
			while (page <= 100000) {
				await this.fetchSecrets({
					...WHOLE_VAULT,
					...options,
					page,
					limit: PAGE_SIZE,
				})
				const batch = this.secrets || []
				all.push(...batch)
				if (batch.length < PAGE_SIZE || all.length >= this.totalCount) {
					break
				}
				page += 1
			}
			this.secrets = all
			this.totalCount = all.length
			this.page = 1
			return all
		},

		/**
		 * Whether an error is a browser network failure (offline).
		 *
		 * @param {Error} e The caught error.
		 * @return {boolean}
		 *
		 * @spec openspec/specs/offline-readonly-cache/spec.md#scenario-offline-unlock-opens-the-vault-for-reading
		 */
		isNetworkError(e) {
			return (
				!!e
				&& (e.message === 'Network Error'
					|| e.code === 'ERR_NETWORK'
					|| (e.request && !e.response))
			)
		},

		/**
		 * Fetch a single secret and decrypt its encrypted fields in the browser.
		 *
		 * @param {string} id The secret ID.
		 * @return {Promise<object>} The secret with decrypted key/login/additionalFields/totp/passkeys.
		 * @spec openspec/specs/secrets/spec.md#requirement-read-secret
		 */
		async fetchSecret(id) {
			this.loading = true
			try {
				// Offline: open from the cached snapshot's ciphertext (decrypted
				// with the offline-unlocked private key), no server request.
				const offline = useOfflineStore()
				const cached = offline.servedFromCache && snapshotSecret(offline, id)
				if (cached) {
					return await openSecret(this, cached)
				}
				try {
					return await openFromServer(this, id)
				} catch (e) {
					const fallback =
						this.isNetworkError(e) && snapshotSecret(offline, id)
					if (fallback) {
						offline.servedFromCache = true
						return await openSecret(this, fallback)
					}
					throw e
				}
			} finally {
				this.loading = false
			}
		},

		/**
		 * Decrypt the encrypted fields of a secret using the session CryptoKey.
		 *
		 * @param {object} secret The secret with ciphertext blobs.
		 * @return {Promise<object>} A copy of the secret with plaintext fields.
		 * @spec openspec/specs/expiring-shares/spec.md#requirement-offline-copies-respect-the-end-date
		 * @spec openspec/specs/secrets/spec.md#requirement-read-secret
		 * @spec openspec/specs/secret-requests/spec.md#requirement-requestable-fields
		 */
		async decryptSecret(secret) {
			const session = useSessionStore()
			if (!session.cryptoKey) {
				throw new Error('Vault is locked')
			}

			// A copy whose access ended is never opened, also not from an
			// offline snapshot taken before the end
			// (sharing-use-only-and-expiring-shares D5).
			if (isAccessExpired(secret)) {
				throw new Error(t('keepiq', 'Your access to this secret has ended'))
			}

			const decrypted = { ...secret }
			await decryptValueFields(decrypted, session.cryptoKey)
			await mergePendingInto(decrypted, session.cryptoKey)
			return decrypted
		},

		/**
		 * Write back an extra-field blob that now holds the request-filled
		 * pending members (keepiq#750), so the server drops them from the
		 * pending list. Best effort: a failure leaves them pending, and the
		 * next open merges them again.
		 *
		 * An open while a write-back of the secret runs waits for that one
		 * instead of starting a second, which would be refused once the
		 * first saved and hide the versions the first wrote. So does an open
		 * whose read went out while it ran and was read on its base, also
		 * when that write-back settled before the read was handled.
		 *
		 * @param {string} id The secret ID.
		 * @param {object|null} [runningAtSend] The write-back in flight when the open's read went out.
		 * @return {Promise<void>}
		 * @spec openspec/specs/secret-requests/spec.md#requirement-requestable-fields
		 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
		 */
		async persistMergedPending(id, runningAtSend = null) {
			const current = this.currentSecret
			if (!hasMergedPending(current, id)) {
				return
			}
			const running = runningWriteBack(current, runningAtSend)
			if (running) {
				await joinWriteBack(current, running)
				return
			}
			const inFlight = {
				done: null,
				waiting: null,
				waiters: new Set(),
				base: current.updatedAt,
				count: current.mergedPending,
				updatedAt: null,
			}
			const done = startWriteBack(this, {
				id,
				current,
				inFlight,
				source: { updatedAt: null },
			})
			inFlight.done = done
			writeBacks.set(id, inFlight)
			await done
			writeBacks.delete(id)
		},

		/**
		 * Wait for a merge write-back of `id` in flight. One that saved moved
		 * the secret, and through a copy the source, to versions an edit made
		 * before it may take; one that failed moved nothing.
		 *
		 * @param {string} id The secret ID.
		 * @param {((ms: number) => void)|null} [onWait] Told when the write-back waits on the partner lookup limit, also of a wait already begun.
		 * @return {Promise<{sourceUpdatedAt: string|null}|null>} The source version the write-back wrote, null when it saved only the own copy; null when none was in flight or it failed.
		 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
		 */
		async writeBackSettled(id, onWait = null) {
			const inFlight = writeBacks.get(id)
			if (!inFlight) {
				return null
			}
			if (onWait) {
				if (inFlight.waiting !== null) {
					onWait(inFlight.waiting)
				}
				inFlight.waiters.add(onWait)
			}
			try {
				return await inFlight.done
			} finally {
				inFlight.waiters.delete(onWait)
			}
		},

		/**
		 * Whether a type id resolves to the `passkey` system type.
		 *
		 * @param {string|null} typeId The secret type id.
		 * @return {boolean}
		 *
		 * @spec openspec/specs/passkey-item-type/spec.md#scenario-credential-stored-ciphertext-rp-id-in-url
		 */
		isPasskeyTypeId(typeId) {
			if (!typeId) {
				return false
			}
			const type = useSecretTypeStore().typesById[typeId]
			return Boolean(type) && type.name === PASSKEY_TYPE_NAME
		},

		/**
		 * Save a new secret into a team folder the user does not own, as a
		 * member with write access (admin-vault-policies D5). The value is
		 * encrypted in this browser for the folder owner (the owner row) and
		 * for every member, this user included. Only ciphertext is sent.
		 *
		 * @param {string} teamFolderId The team folder.
		 * @param {object} data name, url, typeId, key, login, additionalFields, totp, passkeys (plaintext).
		 * @return {Promise<object>} The stored owner row and the copy count.
		 * @spec openspec/specs/vault-policies/spec.md#requirement-write-grade-members-save-new-secrets-into-a-team-folder
		 */
		async contributeSecret(teamFolderId, data) {
			const context = (
				await axios.get(
					generateUrl(
						`/apps/keepiq/api/v1/team-folders/${teamFolderId}/contribution-context`,
					),
				)
			).data
			const fields = recipientFields(data)
			const owner = await useShareStore().encryptForRecipient(
				fields,
				context.ownerCertificate,
			)
			const copies = await encryptCopies(fields, context.recipients ?? [])
			const response = await axios.post(
				generateUrl(
					`/apps/keepiq/api/v1/team-folders/${teamFolderId}/secrets`,
				),
				{
					...secretPlacement(data),
					...ownerCiphertext(owner),
					copies,
				},
			)
			return response.data
		},

		/**
		 * Create a secret, encrypting the sensitive fields in the browser first.
		 *
		 * @param {object} data Plaintext fields (name, url, key, login, additionalFields, totp, passkeys, ...).
		 * @return {Promise<object>} The created secret (server response).
		 * @spec openspec/specs/secrets/spec.md#requirement-create-secret
		 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-offline-changes-go-into-a-sealed-local-queue
		 */
		async createSecret(data) {
			const session = useSessionStore()
			if (!session.certificate) {
				throw new Error('Vault is locked')
			}
			const publicKey = await importPublicKey(session.certificate)

			const payload = {
				name: data.name,
				url: secretUrl(this, data),
				typeId: data.typeId ?? null,
				folderId: data.folderId ?? null,
				key: await rsaEncrypt(String(data.key ?? ''), publicKey),
			}
			await encryptOptionalFields(payload, { data, publicKey })

			// Offline: the same payload goes into the sealed queue and is
			// replayed later (offline-edit-queue).
			const offline = useOfflineStore()
			if (offline.servedFromCache) {
				return queueCreate(offline, payload)
			}

			const response = await axios.post(
				generateUrl('/apps/keepiq/api/v1/secrets'),
				payload,
			)
			return response.data
		},

		/**
		 * Update a secret. Sensitive fields are re-encrypted before submission;
		 * a value change goes through the share save, which writes every copy
		 * and partner share in the same transaction or nothing at all.
		 *
		 * @param {string} id The secret ID.
		 * @param {object} data The fields to change, with `mergedPending`: the request-filled pending blobs the edited `additionalFields` merged. `totp` and `passkeys` are plain strings, `''` to remove; left out, they stay as they are.
		 * @param {object} [options] Options.
		 * @param {string} [options.baseUpdatedAt] The version the edit was made from.
		 * @param {string|null} [options.sourceBaseUpdatedAt] On a copy, the source version the edit was made from.
		 * @param {Array<string>} [options.withoutUnreachable] The partner shares (ids) the user agreed to suspend when they cannot be reached.
		 * @param {boolean} [options.writeBack] This is the merge write-back itself, so it does not wait for one.
		 * @param {(ms: number) => void} [options.onWait] Called when the save waits on the partner lookup limit, its own or that of a merge write-back it is held behind.
		 * @param {(sourceUpdatedAt: string|null) => void} [options.onSourceSaved] Called after a share save with the source version it wrote (on the owner path, the saved row's own version), null when its answer names none.
		 * @return {Promise<object>} The updated secret row.
		 * @spec openspec/specs/secrets/spec.md#requirement-update-secret
		 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-offline-changes-go-into-a-sealed-local-queue
		 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
		 */
		async updateSecret(id, data, options = {}) {
			await awaitWriteBack(this, { id, options })
			const session = useSessionStore()
			const change = {
				id,
				options,
				given: options.baseUpdatedAt,
				sourceBase: options.sourceBaseUpdatedAt ?? null,
				payload: plainPatch(this, data),
				plainDiff: {},
				valueChanged: VALUE_FIELDS.some((f) => data[f] !== undefined),
			}
			if (change.valueChanged) {
				change.plainDiff = await encryptValueChange(change.payload, {
					data,
					session,
				})
			}

			// Offline: queue the change on the cached version; the share save
			// runs at replay time, never from here (offline-edit-queue).
			const offline = useOfflineStore()
			if (offline.servedFromCache) {
				return queueUpdate(offline, change)
			}

			const saved = await saveChange(this, change)
			keepOpenVersion(this, { id, saved })
			return saved
		},

		/**
		 * The version an edit of `id` was made from: the caller's, else the
		 * open secret's, else the listed row's.
		 *
		 * @param {string} id The secret ID.
		 * @param {string|undefined} given The caller's base.
		 * @return {string}
		 * @throws {Error} When no version is known: fetching the current one
		 *   now would switch the stale check off.
		 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
		 */
		baseOf(id, given) {
			const known =
				given
				?? (this.currentSecret?.id === id
					? this.currentSecret.updatedAt
					: undefined)
				?? this.secrets.find((s) => s.id === id)?.updatedAt
			if (!known) {
				throw new Error(`No base version known for secret ${id}`)
			}
			return known
		},

		/**
		 * Delete a secret.
		 *
		 * @param {string} id The secret ID.
		 * @return {Promise<void>}
		 * @spec openspec/specs/secrets/spec.md#requirement-delete-secret
		 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-offline-changes-go-into-a-sealed-local-queue
		 */
		async deleteSecret(id) {
			const offline = useOfflineStore()
			if (offline.servedFromCache) {
				const cached = offline.vault?.secrets?.find((x) => x.id === id)
				await offline.enqueue({
					op: 'delete',
					secretId: id,
					baseUpdatedAt: cached?.updatedAt ?? null,
				})
				this.secrets = this.secrets.filter((s) => s.id !== id)
				return
			}
			await axios.delete(generateUrl(`/apps/keepiq/api/v1/secrets/${id}`))
			this.secrets = this.secrets.filter((s) => s.id !== id)
		},

		/**
		 * Move a secret between trash and archive states and drop it from the
		 * list being shown: every action takes it out of the current view.
		 *
		 * The server's answer comes back: a restored copy from another
		 * organisation carries `federatedShare` (resumed, ended, unreachable).
		 *
		 * @param {string} id The secret ID.
		 * @param {string} action restore, purge, archive or unarchive.
		 * @return {Promise<object>} The response body.
		 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-restoring-and-purging-trashed-secrets
		 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-archiving-a-secret
		 * @spec openspec/specs/federated-sharing/spec.md#scenario-the-owner-revoked-the-share-meanwhile
		 */
		async changeSecretState(id, action) {
			const url = generateUrl(`/apps/keepiq/api/v1/secrets/${id}/${action}`)
			const response =
				action === 'purge' ? await axios.delete(url) : await axios.post(url)
			this.secrets = this.secrets.filter((s) => s.id !== id)
			this.totalCount = Math.max(0, this.totalCount - 1)
			return response?.data ?? {}
		},

		/**
		 * The favourite and tag filters a fetch uses: the options' own when
		 * present, else the stored list query.
		 *
		 * @param {object} options The fetch options.
		 * @return {{favourite: boolean, tag: string|null}}
		 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
		 */
		organisationFilter(options = {}) {
			return {
				favourite: !!('favourite' in options
					? options.favourite
					: this.filters.favourite),
				tag: ('tag' in options ? options.tag : this.filters.tag) || null,
			}
		},

		/**
		 * Star or unstar one of the user's secrets and mark the row in place.
		 * In the Favourites view an unstarred row leaves the list.
		 *
		 * @param {string} id The secret ID.
		 * @param {boolean} favourite The new star.
		 * @return {Promise<void>}
		 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-favourite-items-per-holder
		 */
		async setFavourite(id, favourite) {
			await axios.put(
				generateUrl(`/apps/keepiq/api/v1/secrets/${id}/favourite`),
				{ favourite },
			)
			if (!favourite && this.filters.favourite) {
				const before = this.secrets.length
				this.secrets = this.secrets.filter((s) => s.id !== id)
				this.totalCount = Math.max(
					0,
					this.totalCount - (before - this.secrets.length),
				)
				return
			}
			this.secrets = this.secrets.map((s) =>
				s.id === id ? { ...s, favourite } : s,
			)
			if (this.currentSecret?.id === id) {
				this.currentSecret = { ...this.currentSecret, favourite }
			}
		},

		/**
		 * Replace the tags on one of the user's secrets, keep the set the
		 * server stored (trimmed, lowercase), and reload the tag list.
		 *
		 * @param {string} id The secret ID.
		 * @param {Array<string>} tags The tags.
		 * @return {Promise<Array<string>>} The stored tags.
		 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
		 */
		async setTags(id, tags) {
			const response = await axios.put(
				generateUrl(`/apps/keepiq/api/v1/secrets/${id}/tags`),
				{ tags },
			)
			const stored = response.data?.tags || []
			this.secrets = this.secrets.map((s) =>
				s.id === id ? { ...s, tags: stored } : s,
			)
			await this.fetchTags()
			return stored
		},

		/**
		 * Add one tag to, or remove it from, a selection of secrets. Rows that
		 * already have (or lack) it are left alone.
		 *
		 * @param {Array<string>} ids The selected secret IDs.
		 * @param {string} tag The tag.
		 * @param {boolean} add True to add, false to remove.
		 * @return {Promise<number>} How many secrets changed.
		 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
		 */
		async changeTagInBulk(ids, tag, add) {
			const wanted = (tag || '').trim().toLowerCase()
			if (!wanted) return 0
			let changed = 0
			for (const id of ids) {
				const row = this.secrets.find((s) => s.id === id)
				// Only rows on screen: their current tags are known, so the
				// PUT cannot wipe tags this view never loaded.
				if (!row) continue
				const current = row.tags || []
				const has = current.includes(wanted)
				if (has === add) continue
				const next = add
					? [...current, wanted]
					: current.filter((t) => t !== wanted)
				const response = await axios.put(
					generateUrl(`/apps/keepiq/api/v1/secrets/${id}/tags`),
					{ tags: next },
				)
				const stored = response.data?.tags || next
				this.secrets = this.secrets.map((s) =>
					s.id === id ? { ...s, tags: stored } : s,
				)
				changed++
			}
			return changed
		},

		/**
		 * Load the user's tags with their counts, for the filter menu.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
		 */
		async fetchTags() {
			const response = await axios.get(generateUrl('/apps/keepiq/api/v1/tags'))
			this.tags = response.data?.tags || []
		},

		/**
		 * Search secrets by name or url (fuzzy).
		 *
		 * @param {string} term The search term.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/secrets/spec.md#requirement-search
		 */
		async searchSecrets(term) {
			this.filters.search = term
			this.page = 1
			await this.fetchSecrets({ search: term, page: 1 })
		},
	},
})
