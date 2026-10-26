import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import { importPublicKey, rsaDecrypt, rsaEncrypt } from '../../crypto/index.js'
import { PASSKEY_TYPE_NAME, passkeyRpId } from '../../passkey/passkey.js'
import {
	encryptSparse,
	notifyOwnerOnly,
	saveShared,
	VALUE_FIELDS,
} from '../../services/shareSave.js'
import { isAccessExpired } from '../../utils/shareRestriction.js'
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
			if ('folderId' in query) this.filters.folderId = query.folderId ?? null
			if ('search' in query) this.filters.search = query.search ?? ''
			if ('typeId' in query) this.filters.typeId = query.typeId ?? null
			if ('sort' in query && query.sort) {
				this.sort.field = query.sort
				// Last used reads newest first; every other sort keeps ascending.
				this.sort.direction = query.sort === 'last_used_at' ? 'desc' : 'asc'
			}
			if ('state' in query) this.filters.state = query.state || 'live'
			if ('favourite' in query) this.filters.favourite = !!query.favourite
			if ('tag' in query) this.filters.tag = query.tag || null
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
				// Trash and archive state (vault-trash-and-archive): live
				// unless the view names another. The offline snapshot holds
				// live secrets only, so the Trash and Archive views are empty
				// offline.
				const state =
					('state' in options ? options.state : this.filters.state)
					|| 'live'
				if (
					offline.servedFromCache
					&& offline.vault
					&& state !== 'live'
					&& state !== 'kept'
				) {
					this.secrets = []
					this.totalCount = 0
					this.page = 1
					return
				}
				if (offline.servedFromCache && offline.vault) {
					// PRESENCE, not nullishness: an explicit null means "no filter"
					// (vault root, or a bulk fetch of the whole vault), which `??`
					// would silently turn back into the stored filter.
					const folderId =
						'folderId' in options
							? options.folderId
							: this.filters.folderId
					const search = (
						('search' in options ? options.search : this.filters.search)
						?? ''
					).toLowerCase()
					// Same presence semantics as folderId, mirroring the online
					// branch's params.typeId — without it a type-filtered list
					// silently showed the whole vault while offline.
					const typeId =
						'typeId' in options ? options.typeId : this.filters.typeId
					const { favourite, tag } = this.organisationFilter(options)
					let items = offline.vault.secrets
					if (folderId) {
						items = items.filter((s) => s.folderId === folderId)
					}
					if (typeId) {
						items = items.filter((s) => s.typeId === typeId)
					}
					if (favourite) {
						items = items.filter((s) => s.favourite)
					}
					if (tag) {
						items = items.filter((s) => (s.tags || []).includes(tag))
					}
					if (search) {
						items = items.filter(
							(s) =>
								(s.name || '').toLowerCase().includes(search)
								|| (s.url || '').toLowerCase().includes(search),
						)
					}
					this.secrets = items
					this.totalCount = items.length
					this.page = 1
					return
				}

				const params = {
					page: options.page ?? this.page,
					limit: options.limit ?? this.limit,
					sort: options.sort ?? this.sort.field,
					direction: options.direction ?? this.sort.direction,
				}
				// PRESENCE, not nullishness — see the offline branch above.
				const folderId =
					'folderId' in options ? options.folderId : this.filters.folderId
				if (folderId) {
					params.folderId = folderId
				}
				const search =
					'search' in options ? options.search : this.filters.search
				if (search) {
					params.search = search
				}
				// Server-side secret-type filter (passkey-item-type §3.3):
				// lets the vault list show only one type, e.g. passkeys.
				const typeId =
					'typeId' in options ? options.typeId : this.filters.typeId
				if (typeId) {
					params.typeId = typeId
				}
				if (state !== 'live') {
					params.state = state
				}
				// Favourites and tags (vault-favourites-tags-and-last-used).
				const { favourite, tag } = this.organisationFilter(options)
				if (favourite) {
					params.favourite = 1
				}
				if (tag) {
					params.tag = tag
				}

				try {
					const response = await axios.get(
						generateUrl('/apps/keepiq/api/v1/secrets'),
						{ params },
					)
					this.secrets = response.data.items || []
					this.totalCount = response.data.total || 0
					this.page = response.data.page || 1
				} catch (e) {
					// Resilient offline fallback: if the network is unreachable and
					// a cached snapshot exists, serve the list from it rather than
					// failing (offline-readonly-cache §4.2). Covers the case where
					// the offline flag lagged the actual connectivity loss.
					if (this.isNetworkError(e) && offline.vault) {
						offline.servedFromCache = true
						this.secrets = offline.vault.secrets
						this.totalCount = offline.vault.secrets.length
						this.page = 1
						return
					}
					throw e
				}
			} finally {
				this.loading = false
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
		 * @return {Promise<object>} The secret with decrypted key/login/additionalFields.
		 * @spec openspec/specs/secrets/spec.md#requirement-read-secret
		 */
		async fetchSecret(id) {
			this.loading = true
			try {
				// Offline: open from the cached snapshot's ciphertext (decrypted
				// with the offline-unlocked private key), no server request.
				const offline = useOfflineStore()
				if (offline.servedFromCache && offline.vault) {
					const cached = offline.vault.secrets.find((s) => s.id === id)
					if (cached) {
						this.currentSecret = await this.decryptSecret(cached)
						return this.currentSecret
					}
				}

				try {
					const runningAtSend = writeBacks.get(id) ?? null
					const response = await axios.get(
						generateUrl(`/apps/keepiq/api/v1/secrets/${id}`),
					)
					const secret = response.data
					this.currentSecret = await this.decryptSecret(secret)
					// Not awaited: the write-back is a share save, which must not
					// hold up opening the secret.
					this.persistMergedPending(id, runningAtSend).catch(() => {})
					return this.currentSecret
				} catch (e) {
					if (this.isNetworkError(e) && offline.vault) {
						const cached = offline.vault.secrets.find((s) => s.id === id)
						if (cached) {
							offline.servedFromCache = true
							this.currentSecret = await this.decryptSecret(cached)
							return this.currentSecret
						}
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
			if (secret.key) {
				decrypted.key = await rsaDecrypt(secret.key, session.cryptoKey)
			}
			if (secret.login) {
				decrypted.login = await rsaDecrypt(secret.login, session.cryptoKey)
			}
			if (secret.additionalFields) {
				const json = await rsaDecrypt(
					secret.additionalFields,
					session.cryptoKey,
				)
				try {
					decrypted.additionalFields = JSON.parse(json)
				} catch {
					decrypted.additionalFields = json
				}
			}
			const pending = Array.isArray(secret.pendingAdditionalFields)
				? secret.pendingAdditionalFields
				: []
			if (pending.length > 0) {
				const merged = await mergePendingFields(
					decrypted.additionalFields,
					pending,
					session.cryptoKey,
				)
				decrypted.additionalFields = merged.fields
				decrypted.mergedPending = merged.complete ? pending.length : 0
			}
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
			if (!current || current.id !== id || !(current.mergedPending > 0)) {
				return
			}
			const running =
				writeBacks.get(id)
				?? (runningAtSend?.base === current.updatedAt ? runningAtSend : null)
			if (running) {
				const readAt = current.updatedAt
				const saved = await running.done
				// Read on the version it saved from, this copy also merged the
				// blobs it dropped; fills that came after stay to drop.
				if (saved && readAt === running.base) {
					current.mergedPending -= running.count
					current.updatedAt = running.updatedAt ?? current.updatedAt
				}
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
			let sourceUpdatedAt = null
			const done = this.updateSecret(
				id,
				{
					additionalFields: current.additionalFields,
					mergedPending: current.mergedPending,
				},
				{
					baseUpdatedAt: current.updatedAt,
					writeBack: true,
					// Nobody sees this save; an edit held behind it shows its wait.
					onWait: (ms) => {
						inFlight.waiting = ms
						for (const waiter of inFlight.waiters) {
							waiter(ms)
						}
					},
					onSourceSaved: (version) => {
						sourceUpdatedAt = version
					},
				},
			)
				.then((saved) => {
					inFlight.updatedAt = saved?.updatedAt ?? null
					current.mergedPending = 0
					// Also when another open replaced it as the open secret.
					current.updatedAt = saved?.updatedAt ?? current.updatedAt
					return { sourceUpdatedAt }
				})
				// Stays pending; merged again on the next open.
				.catch(() => null)
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
		 * @param {object} data name, url, typeId, key, login, additionalFields (plaintext).
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
			const fields = {
				key: String(data.key ?? ''),
				login: data.login ? String(data.login) : '',
				additionalFields: data.additionalFields
					? typeof data.additionalFields === 'string'
						? data.additionalFields
						: JSON.stringify(data.additionalFields)
					: '',
			}
			const shareStore = useShareStore()
			const owner = await shareStore.encryptForRecipient(
				fields,
				context.ownerCertificate,
			)
			const copies = []
			for (const recipient of context.recipients ?? []) {
				const blob = await shareStore.encryptForRecipient(
					fields,
					recipient.certificate,
				)
				copies.push({
					targetUserId: recipient.userId,
					encryptedKey: blob.key ?? '',
					encryptedLogin: blob.login ?? null,
					encryptedAdditionalFields: blob.additionalFields ?? null,
				})
			}
			const response = await axios.post(
				generateUrl(
					`/apps/keepiq/api/v1/team-folders/${teamFolderId}/secrets`,
				),
				{
					name: data.name,
					url: data.url ?? null,
					typeId: data.typeId ?? null,
					folderId: data.folderId ?? null,
					key: owner.key ?? '',
					login: owner.login ?? null,
					additionalFields: owner.additionalFields ?? null,
					copies,
				},
			)
			return response.data
		},

		/**
		 * Create a secret, encrypting the sensitive fields in the browser first.
		 *
		 * @param {object} data Plaintext fields (name, url, key, login, additionalFields, ...).
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

			// Passkey secrets mirror the RP id into the plaintext `url` so
			// they are matchable/searchable by site (passkey-item-type D3).
			// Only the public RP domain is mirrored — never credential material.
			let url = data.url ?? null
			if (!url && this.isPasskeyTypeId(data.typeId)) {
				url = passkeyRpId(String(data.key ?? '')) ?? null
			}

			const payload = {
				name: data.name,
				url,
				typeId: data.typeId ?? null,
				folderId: data.folderId ?? null,
				key: await rsaEncrypt(String(data.key ?? ''), publicKey),
			}
			if (
				data.login !== undefined
				&& data.login !== null
				&& data.login !== ''
			) {
				payload.login = await rsaEncrypt(String(data.login), publicKey)
			}
			if (data.additionalFields) {
				const json =
					typeof data.additionalFields === 'string'
						? data.additionalFields
						: JSON.stringify(data.additionalFields)
				payload.additionalFields = await rsaEncrypt(json, publicKey)
			}

			// Offline: the same payload goes into the sealed queue and is
			// replayed later (offline-edit-queue).
			const offline = useOfflineStore()
			if (offline.servedFromCache) {
				const secretId = crypto.randomUUID()
				await offline.enqueue({ op: 'create', secretId, body: payload })
				return { id: secretId, ...payload, pendingSync: true }
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
		 * @param {object} data The fields to change, with `mergedPending`: the request-filled pending blobs the edited `additionalFields` merged.
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
			// Never alongside a merge write-back of the same secret. A caller
			// that sends the versions it read takes the ones a write-back that
			// saved wrote (writeBackSettled), or the write-back makes them stale.
			if (!options.writeBack) {
				await this.writeBackSettled(id, options.onWait ?? null)
			}
			const given = options.baseUpdatedAt
			const sourceBase = options.sourceBaseUpdatedAt ?? null
			const session = useSessionStore()
			const payload = {}
			const plainDiff = {}

			for (const field of ['name', 'url', 'typeId', 'folderId']) {
				if (data[field] !== undefined) {
					payload[field] = data[field]
				}
			}

			// Keep the passkey RP-id → url mirror in sync when the credential
			// changes without an explicit url (passkey-item-type D3).
			if (
				data.key !== undefined
				&& data.url === undefined
				&& this.isPasskeyTypeId(data.typeId ?? this.currentSecret?.typeId)
			) {
				const rpId = passkeyRpId(String(data.key ?? ''))
				if (rpId) {
					payload.url = rpId
				}
			}

			const valueChanged = VALUE_FIELDS.some((f) => data[f] !== undefined)
			if (valueChanged) {
				if (!session.certificate) {
					throw new Error('Vault is locked')
				}
				if (data.key !== undefined) {
					plainDiff.key = String(data.key)
				}
				if (data.login !== undefined) {
					plainDiff.login = data.login ? String(data.login) : null
				}
				if (data.additionalFields !== undefined) {
					plainDiff.additionalFields =
						data.additionalFields === null
							? null
							: typeof data.additionalFields === 'string'
								? data.additionalFields
								: JSON.stringify(data.additionalFields)
				}
				// The own row and every copy carry the same fields with the same
				// null-ness, so both come from one plain map.
				Object.assign(
					payload,
					await encryptSparse(plainDiff, session.certificate),
				)
				// The blob holds the request-filled pending members the caller's
				// copy merged on open: let the server drop them (keepiq#750).
				if (data.additionalFields !== undefined && data.mergedPending > 0) {
					payload.mergedPending = data.mergedPending
				}
			}

			// Offline: queue the change on the cached version; the share save
			// runs at replay time, never from here (offline-edit-queue).
			const offline = useOfflineStore()
			if (offline.servedFromCache) {
				const cached = offline.vault?.secrets?.find((x) => x.id === id)
				await offline.enqueue({
					op: 'update',
					secretId: id,
					baseUpdatedAt: given ?? cached?.updatedAt ?? null,
					sourceBaseUpdatedAt: sourceBase,
					body: payload,
				})
				return { ...(cached || { id }), ...payload, pendingSync: true }
			}

			let saved
			if (valueChanged) {
				const result = await saveShared({
					id,
					plainDiff,
					patch: payload,
					baseUpdatedAt: this.baseOf(id, given),
					sourceBaseUpdatedAt: sourceBase,
					withoutUnreachable: options.withoutUnreachable ?? [],
					onWait: options.onWait ?? null,
				})
				options.onSourceSaved?.(result.sourceUpdatedAt ?? null)
				await notifyOwnerOnly(result)
				saved = result.secret
			} else {
				const response = await axios.put(
					generateUrl(`/apps/keepiq/api/v1/secrets/${id}`),
					given ? { ...payload, baseUpdatedAt: given } : payload,
				)
				saved = response.data
			}

			// Keep the open copy's version current, so its next edit is not
			// refused as stale.
			if (this.currentSecret?.id === id && saved?.updatedAt) {
				this.currentSecret.updatedAt = saved.updatedAt
			}
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
