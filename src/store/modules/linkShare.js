import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { encryptSnapshot, generateLinkPassword } from '../../crypto/index.js'

/**
 * Pinia store for password-protected link shares.
 *
 * The store owns the browser-side encryption flow (Argon2id KDF + AES-GCM)
 * and the authenticated management API (list, create, revoke). The owner's
 * secret plaintext is decrypted by the caller (the secrets feature) and
 * handed to `createLinkShare` as a serialized snapshot — this store never
 * needs the owner's RSA key directly.
 */
export const useLinkShareStore = defineStore('linkShare', {
	state: () => ({
		/** @type {Array<object>} Link shares for the current secret (metadata only). */
		linkShares: [],
		/** @type {boolean} Whether a request is in flight. */
		loading: false,
		/** @type {string|null} One-time display of the generated password. */
		createdPassword: null,
		/** @type {string|null} One-time display of the generated link URL. */
		createdLinkUrl: null,
	}),

	actions: {
		/**
		 * Load the link shares for a secret (metadata only — no blobs).
		 *
		 * @param {string} secretId The secret ID
		 */
		async fetchLinkShares(secretId) {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl(`/apps/doriath/api/v1/secrets/${secretId}/link-shares`),
				)
				this.linkShares = response.data || []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create a link share from an already-decrypted snapshot.
		 *
		 * Generates a one-time password, derives an AES key via Argon2id,
		 * encrypts the snapshot client-side, and POSTs only the encrypted blob
		 * and salt. The password is stored transiently for a single display and
		 * is never transmitted to the server.
		 *
		 * @param {string} secretId The secret ID
		 * @param {object} snapshot The plaintext snapshot fields (key, login, additionalFields)
		 * @param {number} usageLimit The usage limit (1-10)
		 * @param {string|null} expiresAt Optional ISO-8601 expiry timestamp
		 * @return {Promise<object>} The created link share metadata
		 */
		async createLinkShare(secretId, snapshot, usageLimit = 1, expiresAt = null) {
			this.loading = true
			try {
				const password = generateLinkPassword()
				const { blob, salt } = await encryptSnapshot(JSON.stringify(snapshot), password)

				const response = await axios.post(
					generateUrl(`/apps/doriath/api/v1/secrets/${secretId}/link-shares`),
					{
						encryptedSecretSnapshot: blob,
						argon2idSalt: salt,
						usageLimit,
						expiresAt,
					},
				)

				this.linkShares.push(response.data)
				this.createdPassword = password
				this.createdLinkUrl = response.data.linkUrl
				return response.data
			} finally {
				this.loading = false
			}
		},

		/**
		 * Revoke (delete) a link share by ID.
		 *
		 * @param {string} id The link share ID
		 */
		async deleteLinkShare(id) {
			await axios.delete(generateUrl(`/apps/doriath/api/v1/link-shares/${id}`))
			this.linkShares = this.linkShares.filter(share => share.id !== id)
		},

		/**
		 * Clear the transient one-time password and link URL (on dialog close).
		 */
		clearCreatedPassword() {
			this.createdPassword = null
			this.createdLinkUrl = null
		},
	},
})
