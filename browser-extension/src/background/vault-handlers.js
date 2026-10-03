/**
 * Worker handlers for the popup's Vault, Generator and Send tabs.
 *
 * Built with their collaborators injected, so the tests drive them without a
 * browser or a server. The popup receives index fields for browsing and one
 * item's decrypted values only when it opens that item; blobs and keys stay
 * in the worker, as for autofill.
 *
 * @spec openspec/changes/clients-extension-generator-vault-send/specs/extension-vault/spec.md#requirement-browse-and-search-the-vault
 */

import { generateKey } from '../../../src/generator/generator.js'
import { parsePasskey } from '../../../src/passkey/passkey.js'
import { deriveAesKeyArgon2id } from '../../../src/crypto/argon2.js'
import {
	aesEncrypt,
	sealPayload,
	sendLink,
	toBase64,
} from '../../../src/send/sendCrypto.js'
import { installArgon2Wasm } from '../lib/argon2-wasm.js'
import { credentialPayload, expirySeconds, maxViewsFrom } from '../lib/send-form.js'
import { buildIndex } from '../lib/vault-index.js'
import { writeErrorMessage } from '../lib/item-form.js'
import { folderNameProblem } from '../lib/folder-rules.js'

/** Longest secret name the server stores. */
export const MAX_NAME_LENGTH = 255

/**
 * Build the handlers.
 *
 * @param {object} deps The collaborators.
 * @param {object} deps.api The API client (`lib/api.js`).
 * @param {object} deps.vault The key holder (`lib/vault.js`).
 * @param {() => Promise<object>} deps.activeAccount The active, paired account.
 * @param {(config: object, value: string, typeName: string) => Promise<string|null>} deps.policyRefusalFor Why the org policy refuses a value, or null.
 * @param {(accountId: string) => Promise<void>} [deps.touchActivity] Re-arm the idle lock.
 * @return {Record<string, (payload: object) => Promise<object>>}
 */
export function buildVaultHandlers({
	api,
	vault,
	activeAccount,
	policyRefusalFor,
	touchActivity = async () => {},
	sync = null,
}) {
	// Per account: the last listed rows (with blobs), keyed by id.
	const rowCache = new Map()

	/**
	 * After a write: sync, so the list and the cache show it at once.
	 *
	 * @param {object} account The account.
	 * @return {Promise<void>}
	 */
	async function afterWrite(account) {
		rowCache.delete(account.id)
		if (sync) await sync().sync(account, { force: true })
	}

	/**
	 * The active account, refused when its vault is locked.
	 *
	 * @return {Promise<object>}
	 */
	async function unlockedAccount() {
		const account = await activeAccount()
		if (!vault.isUnlocked(account.id)) {
			throw new Error('vault is locked')
		}
		return account
	}

	// Per account: the secret types, id to name, fetched once.
	const typeCache = new Map()

	/**
	 * The name of a secret type, or 'login'.
	 *
	 * @param {object} account The account.
	 * @param {string|null} typeId The type id.
	 * @return {Promise<string>}
	 */
	async function typeNameOf(account, typeId) {
		if (!typeCache.has(account.id)) {
			const types = await api.listTypes(account).catch(() => [])
			typeCache.set(
				account.id,
				new Map(types.map((t) => [t.id, t.name || t.slug])),
			)
		}
		return typeCache.get(account.id).get(typeId) || 'login'
	}

	return {
		/**
		 * The vault index, folders and types (no values).
		 *
		 * @spec openspec/changes/clients-extension-generator-vault-send/specs/extension-vault/spec.md#requirement-browse-and-search-the-vault
		 */
		'vault-list': async () => {
			const account = await unlockedAccount()
			let rows
			let folders
			let types
			let status = null
			const snapshot = sync
				? await (async () => {
						// Popup open with an old snapshot: sync first (cheaply).
						if (await sync().isStale(account.id))
							await sync().sync(account)
						status = sync().statusOf(account.id)
						return sync().snapshotOf(account.id)
					})()
				: null
			if (snapshot) {
				;({ secrets: rows, folders, types } = snapshot)
			} else {
				;[rows, folders, types] = await Promise.all([
					api.listSecrets(account),
					api.listFolders(account),
					api.listTypes(account),
				])
			}
			rowCache.set(account.id, new Map(rows.map((r) => [r.id, r])))
			await touchActivity(account.id)
			return {
				accountId: account.id,
				webAppUrl: api.publicBase(account).replace(/\/public$/, '/'),
				items: buildIndex(rows, types, folders),
				folders: folders.map((f) => ({
					id: f.id,
					name: f.name,
					parentId: f.parentId || null,
				})),
				types: types.map((t) => ({ id: t.id, name: t.name || t.slug })),
				sync: status,
			}
		},

		/**
		 * Sync now, whatever the snapshot's age.
		 *
		 * @spec openspec/changes/clients-extension-complete/specs/extension-vault-sync/spec.md#requirement-sync-when-it-matters-and-cheaply
		 */
		'vault-sync-now': async () => {
			const account = await unlockedAccount()
			return sync ? sync().sync(account, { force: true }) : { syncedAt: null }
		},

		/**
		 * One item, fetched fresh and decrypted, for the detail view and the
		 * form: a stale list row never seeds an edit. A blocked item is not
		 * decrypted; its reason is returned instead.
		 *
		 * @spec openspec/changes/clients-extension-generator-vault-send/specs/extension-vault/spec.md#requirement-item-detail-with-copy-and-reveal
		 * @spec openspec/changes/clients-extension-complete/specs/extension-vault/spec.md#requirement-detail-sections-for-every-kind-of-item
		 * @spec openspec/changes/clients-extension-complete/specs/extension-vault/spec.md#requirement-a-passkeys-private-key-stays-in-the-worker
		 */
		'vault-item': async (payload) => {
			const account = await unlockedAccount()
			let row
			let fromCache = false
			try {
				row = await api.getSecret(account, payload.id)
			} catch (e) {
				// Offline: read the item from the snapshot instead.
				const cached = rowCache.get(account.id)?.get(payload.id)
				if (!cached || (e?.status && e.status < 500)) throw e
				row = cached
				fromCache = true
			}
			if (!row) {
				throw new Error('This item no longer exists')
			}
			const meta = {
				id: row.id,
				name: row.name || '',
				url: row.url || '',
				folderId: row.folderId || null,
				typeId: row.typeId || null,
				typeName: await typeNameOf(account, row.typeId),
				createdAt: row.createdAt || null,
				updatedAt: row.updatedAt || null,
				expiresAt: row.expiresAt || null,
			}
			if (row.blocked === true) {
				return {
					...meta,
					blocked: true,
					blockedReason:
						row.blockedReason || 'This item cannot be opened here.',
					migrationError: row.migrationError || null,
				}
			}
			const decrypted = await vault.decryptSecret(account.id, row)
			const login = decrypted.login
			let secret = decrypted.secret
			// A passkey's private key stays in the worker: the popup gets only
			// what it shows, so the key never reaches a page or its DOM.
			let passkey
			if (meta.typeName === 'passkey') {
				const credential = parsePasskey(secret)
				passkey = credential
					? {
							rpId: credential.rpId,
							rpName: credential.rpName,
							userName: credential.userName,
							userDisplayName: credential.userDisplayName,
							createdAt: credential.createdAt,
						}
					: null
				secret = ''
			}
			let additionalFields = null
			let additionalFieldsError = false
			if (row.additionalFields) {
				const json = await vault.decryptField(
					account.id,
					row.additionalFields,
				)
				try {
					const parsed = JSON.parse(json)
					if (
						parsed
						&& typeof parsed === 'object'
						&& !Array.isArray(parsed)
					) {
						additionalFields = parsed
					} else {
						additionalFieldsError = true
					}
				} catch {
					additionalFieldsError = true
				}
			}
			await touchActivity(account.id)
			return {
				...meta,
				blocked: false,
				fromCache,
				login,
				secret,
				...(passkey !== undefined ? { passkey } : {}),
				additionalFields,
				additionalFieldsError,
			}
		},

		/**
		 * Create an item, or update only the parts that changed. Values are
		 * encrypted here; the popup never sends ciphertext or receives keys.
		 *
		 * @spec openspec/changes/clients-extension-generator-vault-send/specs/extension-vault/spec.md#requirement-add-edit-and-delete-items
		 * @spec openspec/changes/clients-extension-complete/specs/extension-vault/spec.md#requirement-edit-every-kind-of-item
		 * @spec openspec/changes/clients-extension-complete/specs/extension-vault/spec.md#requirement-a-passkeys-private-key-stays-in-the-worker
		 */
		'vault-save': async (payload) => {
			const account = await unlockedAccount()
			const changes = payload.changes || {}
			const creating = !payload.id
			// A passkey is made and updated by the website that uses it; here
			// only its name, address, folder and notes change.
			if (payload.typeName === 'passkey' && (creating || 'key' in changes)) {
				throw new Error(
					'A passkey is created by the website that uses it, and its key cannot be edited',
				)
			}
			if (creating || 'name' in changes) {
				const name = String(changes.name ?? '').trim()
				if (name === '') {
					throw new Error('Give the item a name')
				}
				if (name.length > MAX_NAME_LENGTH) {
					throw new Error(
						`A name has at most ${MAX_NAME_LENGTH} characters`,
					)
				}
			}
			if ('key' in changes) {
				const refusal = await policyRefusalFor(
					account,
					String(changes.key ?? ''),
					payload.typeName || 'login',
				)
				if (refusal !== null) {
					throw new Error(refusal)
				}
			}
			const body = {}
			for (const field of ['name', 'url', 'folderId']) {
				if (field in changes)
					body[field] =
						field === 'name'
							? String(changes.name).trim()
							: (changes[field] ?? null)
			}
			if ('key' in changes || creating)
				body.key = await vault.encryptField(
					account.id,
					String(changes.key ?? ''),
				)
			if ('login' in changes || creating)
				body.login = await vault.encryptField(
					account.id,
					String(changes.login ?? ''),
				)
			if ('additionalFields' in changes) {
				body.additionalFields = changes.additionalFields
					? await vault.encryptField(
							account.id,
							JSON.stringify(changes.additionalFields),
						)
					: null
			}
			if (
				body.key !== undefined
				|| body.login !== undefined
				|| body.additionalFields
			) {
				body.encryptionSuiteId = vault.activeSuiteId(account.id)
			}
			let saved
			try {
				saved = creating
					? await api.createSecret(account, {
							...body,
							...(payload.typeId ? { typeId: payload.typeId } : {}),
						})
					: await api.updateSecret(account, payload.id, body)
			} catch (e) {
				throw new Error(writeErrorMessage(e), { cause: e })
			}
			await afterWrite(account)
			await touchActivity(account.id)
			return { ok: true, id: saved?.id || payload.id || null }
		},

		/**
		 * Move an item to another folder: only its folder changes.
		 *
		 * @spec openspec/changes/clients-extension-complete/specs/extension-vault/spec.md#requirement-clone-and-move
		 */
		'vault-move': async (payload) => {
			const account = await unlockedAccount()
			try {
				await api.updateSecret(account, payload.id, {
					folderId: payload.folderId || null,
				})
			} catch (e) {
				throw new Error(writeErrorMessage(e), { cause: e })
			}
			await afterWrite(account)
			return { ok: true }
		},

		/**
		 * Create a folder.
		 *
		 * @spec openspec/changes/clients-extension-complete/specs/extension-vault/spec.md#requirement-manage-folders
		 */
		'folder-create': async (payload) => {
			const account = await unlockedAccount()
			const problem = folderNameProblem(payload.name)
			if (problem) throw new Error(problem)
			try {
				const folder = await api.createFolder(account, {
					name: String(payload.name).trim(),
					parentId: payload.parentId || null,
				})
				await afterWrite(account)
				return { ok: true, id: folder?.id || null }
			} catch (e) {
				throw new Error(writeErrorMessage(e), { cause: e })
			}
		},

		/**
		 * Rename a folder.
		 *
		 * @spec openspec/changes/clients-extension-complete/specs/extension-vault/spec.md#requirement-manage-folders
		 */
		'folder-rename': async (payload) => {
			const account = await unlockedAccount()
			const problem = folderNameProblem(payload.name)
			if (problem) throw new Error(problem)
			try {
				await api.renameFolder(
					account,
					payload.id,
					String(payload.name).trim(),
				)
				await afterWrite(account)
				return { ok: true }
			} catch (e) {
				throw new Error(writeErrorMessage(e), { cause: e })
			}
		},

		/**
		 * What a folder holds, to choose how to delete it.
		 *
		 * @spec openspec/changes/clients-extension-complete/specs/extension-vault/spec.md#requirement-manage-folders
		 */
		'folder-children': async (payload) => {
			const account = await unlockedAccount()
			try {
				return await api.folderChildren(account, payload.id)
			} catch (e) {
				throw new Error(writeErrorMessage(e), { cause: e })
			}
		},

		/**
		 * Delete a folder with the user's choice for what it holds.
		 *
		 * @spec openspec/changes/clients-extension-complete/specs/extension-vault/spec.md#requirement-manage-folders
		 */
		'folder-delete': async (payload) => {
			const account = await unlockedAccount()
			try {
				await api.deleteFolder(account, payload.id, {
					cascade: payload.cascade,
					resolution: payload.resolution,
				})
				await afterWrite(account)
				return { ok: true }
			} catch (e) {
				throw new Error(writeErrorMessage(e), { cause: e })
			}
		},

		/**
		 * Move an item to the trash.
		 *
		 * @spec openspec/changes/clients-extension-generator-vault-send/specs/extension-vault/spec.md#requirement-add-edit-and-delete-items
		 */
		'vault-trash': async (payload) => {
			const account = await unlockedAccount()
			try {
				await api.trashSecret(account, payload.id)
			} catch (e) {
				throw new Error(writeErrorMessage(e), { cause: e })
			}
			await afterWrite(account)
			return { ok: true }
		},

		/**
		 * A strong password for a sign-up field on a page, under the org policy.
		 *
		 * @spec openspec/changes/clients-extension-generator-vault-send/specs/extension-generator/spec.md#requirement-suggest-a-strong-password-in-a-sign-up-field
		 */
		'generate-for-field': async () => {
			let policy = null
			try {
				policy = await api.fetchPolicy(await activeAccount())
			} catch {
				// No account or no answer: the generator's own defaults apply.
			}
			return { value: generateKey({ length: 20 }, policy) }
		},

		/**
		 * Encrypt and create a send; the link carries the key in its fragment.
		 *
		 * @spec openspec/changes/clients-extension-generator-vault-send/specs/extension-send/spec.md#requirement-create-a-send-from-the-popup
		 * @spec openspec/changes/clients-extension-complete/specs/extension-send/spec.md#requirement-password-protected-sends
		 */
		'send-create': async (payload) => {
			const account = await unlockedAccount()
			const views = maxViewsFrom(payload.maxViews)
			if (views.error) throw new Error(views.error)
			const expiry = expirySeconds(payload.expiry, payload.customHours)
			if (expiry.error) throw new Error(expiry.error)
			const payloadType =
				payload.payloadType === 'credential' ? 'credential' : 'text'
			const plaintext =
				payloadType === 'credential'
					? credentialPayload(
							String(payload.username || ''),
							String(payload.password || ''),
						)
					: String(payload.text || '')
			if (plaintext.trim() === '') {
				throw new Error('There is nothing to send')
			}
			const { encryptedPayload, rawKey } = await sealPayload(plaintext)
			const body = {
				encryptedPayload,
				payloadType,
				maxViews: views.maxViews,
				ttlSeconds: expiry.ttlSeconds,
				hasPassword: false,
			}
			// With a password the content key is wrapped under an Argon2id key
			// from it, as the web app does; the link then carries no key.
			const sendPassword = String(payload.sendPassword || '')
			if (sendPassword !== '') {
				installArgon2Wasm()
				const salt = crypto.getRandomValues(new Uint8Array(16))
				const kek = await deriveAesKeyArgon2id(sendPassword, salt)
				body.hasPassword = true
				body.wrappedKey = await aesEncrypt(kek, rawKey)
				body.argon2idSalt = toBase64(salt)
			}
			const send = await api.createSend(account, body)
			await touchActivity(account.id)
			return {
				id: send?.id || null,
				link: sendLink(
					api.publicBase(account),
					send.token,
					body.hasPassword ? null : rawKey,
				),
				hasPassword: body.hasPassword,
			}
		},

		/**
		 * The account's sends (metadata only).
		 *
		 * @spec openspec/changes/clients-extension-generator-vault-send/specs/extension-send/spec.md#requirement-list-and-end-my-sends
		 */
		'send-list': async () => {
			const account = await unlockedAccount()
			const sends = await api.listSends(account)
			return {
				sends: sends.map((s) => ({
					id: s.id,
					payloadType: s.payloadType,
					createdAt: s.createdAt,
					expiresAt: s.expiresAt || null,
					viewCount: s.viewCount ?? 0,
					maxViews: s.maxViews ?? 1,
					status: s.status || null,
				})),
			}
		},

		/**
		 * End a send.
		 *
		 * @spec openspec/changes/clients-extension-generator-vault-send/specs/extension-send/spec.md#requirement-list-and-end-my-sends
		 */
		'send-revoke': async (payload) => {
			const account = await unlockedAccount()
			await api.revokeSend(account, payload.id)
			return { ok: true }
		},
	}
}
