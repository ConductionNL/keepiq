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
import { sealPayload, sendLink } from '../../../src/send/sendCrypto.js'
import { credentialPayload, expirySeconds, maxViewsFrom } from '../lib/send-form.js'
import { buildIndex } from '../lib/vault-index.js'

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
}) {
	// Per account: the last listed rows (with blobs), keyed by id.
	const rowCache = new Map()

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

	/**
	 * A row of the active account, from the last list or fetched fresh.
	 *
	 * @param {object} account The account.
	 * @param {string} id The secret id.
	 * @return {Promise<object>}
	 */
	async function rowOf(account, id) {
		const cached = rowCache.get(account.id)?.get(id)
		return cached || api.getSecret(account, id)
	}

	return {
		/**
		 * The vault index, folders and types (no values).
		 *
		 * @spec openspec/changes/clients-extension-generator-vault-send/specs/extension-vault/spec.md#requirement-browse-and-search-the-vault
		 */
		'vault-list': async () => {
			const account = await unlockedAccount()
			const [rows, folders, types] = await Promise.all([
				api.listSecrets(account),
				api.listFolders(account),
				api.listTypes(account),
			])
			rowCache.set(account.id, new Map(rows.map((r) => [r.id, r])))
			await touchActivity(account.id)
			return {
				accountId: account.id,
				items: buildIndex(rows, types, folders),
				folders: folders.map((f) => ({
					id: f.id,
					name: f.name,
					parentId: f.parentId || null,
				})),
				types: types.map((t) => ({ id: t.id, name: t.name || t.slug })),
			}
		},

		/**
		 * One item's decrypted login and value, for the detail view.
		 *
		 * @spec openspec/changes/clients-extension-generator-vault-send/specs/extension-vault/spec.md#requirement-item-detail-with-copy-and-reveal
		 */
		'vault-item': async (payload) => {
			const account = await unlockedAccount()
			const row = await rowOf(account, payload.id)
			if (!row || row.blocked === true) {
				throw new Error(
					'This item cannot be opened here. Open it in the web app.',
				)
			}
			const { login, secret } = await vault.decryptSecret(account.id, row)
			await touchActivity(account.id)
			return {
				id: row.id,
				name: row.name || '',
				url: row.url || '',
				folderId: row.folderId || null,
				typeId: row.typeId || null,
				login,
				secret,
			}
		},

		/**
		 * Create or update an item: encrypted here, saved as ciphertext.
		 *
		 * @spec openspec/changes/clients-extension-generator-vault-send/specs/extension-vault/spec.md#requirement-add-edit-and-delete-items
		 */
		'vault-save': async (payload) => {
			const account = await unlockedAccount()
			const name = String(payload.name || '').trim()
			if (name === '') {
				throw new Error('Give the item a name')
			}
			if (name.length > MAX_NAME_LENGTH) {
				throw new Error(`A name has at most ${MAX_NAME_LENGTH} characters`)
			}
			const secret = String(payload.secret || '')
			const refusal = await policyRefusalFor(
				account,
				secret,
				payload.typeName || 'login',
			)
			if (refusal !== null) {
				throw new Error(refusal)
			}
			const body = {
				name,
				url: String(payload.url || ''),
				folderId: payload.folderId || null,
				key: await vault.encryptField(account.id, secret),
				login: await vault.encryptField(
					account.id,
					String(payload.login || ''),
				),
				encryptionSuiteId: vault.activeSuiteId(account.id),
			}
			const saved = payload.id
				? await api.updateSecret(account, payload.id, body)
				: await api.createSecret(account, {
						...body,
						...(payload.typeId ? { typeId: payload.typeId } : {}),
					})
			rowCache.delete(account.id)
			await touchActivity(account.id)
			return { ok: true, id: saved?.id || payload.id || null }
		},

		/**
		 * Move an item to the trash.
		 *
		 * @spec openspec/changes/clients-extension-generator-vault-send/specs/extension-vault/spec.md#requirement-add-edit-and-delete-items
		 */
		'vault-trash': async (payload) => {
			const account = await unlockedAccount()
			await api.trashSecret(account, payload.id)
			rowCache.get(account.id)?.delete(payload.id)
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
			const send = await api.createSend(account, {
				encryptedPayload,
				payloadType,
				maxViews: views.maxViews,
				ttlSeconds: expiry.ttlSeconds,
				hasPassword: false,
			})
			await touchActivity(account.id)
			return {
				id: send?.id || null,
				link: sendLink(api.publicBase(account), send.token, rawKey),
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
