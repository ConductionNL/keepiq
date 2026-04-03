import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export const useShareStore = defineStore('share', {
	state: () => ({
		/** @type {object[]} List of user shares for the current secret */
		shares: [],
		/** @type {object[]} List of group shares for the current secret */
		groupShares: [],
		/** @type {boolean} Loading state */
		loading: false,
	}),

	actions: {
		/**
		 * Fetch all user shares for a secret.
		 *
		 * @param {string} secretId Secret ID
		 * @return {Promise<void>}
		 */
		async fetchShares(secretId) {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/doriath/api/v1/shares'),
					{ params: { secretId } },
				)
				this.shares = response.data ?? []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the public key for a target user by fetching their active suite.
		 *
		 * @param {string} targetUserId The user ID to fetch the public key for
		 * @return {Promise<string>} The public key PEM string
		 */
		async fetchUserPublicKey(targetUserId) {
			const response = await axios.get(
				generateUrl(`/apps/doriath/api/v1/suites/public-key/${targetUserId}`),
			)
			if (!response.data?.publicKey) {
				throw new Error(`No active encryption suite found for user: ${targetUserId}`)
			}
			return response.data.publicKey
		},

		/**
		 * Create a share. Accepts pre-encrypted data and posts to the API.
		 *
		 * @param {string} sourceSecretId The secret being shared
		 * @param {string} targetUserId The recipient user
		 * @param {object} encryptedData Pre-encrypted fields (key, login, additionalFields)
		 * @return {Promise<object>} The created share
		 */
		async createShare(sourceSecretId, targetUserId, encryptedData) {
			console.debug('Doriath createShare store:', {
				sourceSecretId,
				targetUserId,
				hasKey: !!encryptedData.key,
				keyLength: encryptedData.key?.length,
				hasLogin: !!encryptedData.login,
				hasName: !!encryptedData.name,
			})
			const response = await axios.post(
				generateUrl('/apps/doriath/api/v1/shares'),
				{
					sourceSecretId,
					targetUserId,
					encryptedKey: encryptedData.key || '',
					encryptedLogin: encryptedData.login || null,
					encryptedAdditionalFields: encryptedData.additionalFields || null,
					name: encryptedData.name || '',
					url: encryptedData.url || null,
					typeId: encryptedData.typeId || '',
				},
			)
			return response.data
		},

		/**
		 * Create multiple shares in a batch (e.g. for group share members).
		 *
		 * @param {string} sourceSecretId The secret being shared
		 * @param {object[]} shares Array of {targetUserId, ...encryptedData}
		 * @param {string} groupShareId The group share these belong to
		 * @return {Promise<object>} The batch result
		 */
		async createBatchShares(sourceSecretId, shares, groupShareId) {
			const response = await axios.post(
				generateUrl('/apps/doriath/api/v1/shares/batch'),
				{ sourceSecretId, shares, groupShareId },
			)
			return response.data
		},

		/**
		 * Revoke a user share by ID.
		 *
		 * @param {string} shareId The share ID to revoke
		 * @return {Promise<void>}
		 */
		async revokeShare(shareId) {
			await axios.delete(
				generateUrl(`/apps/doriath/api/v1/shares/${shareId}`),
			)
			this.shares = this.shares.filter(s => s.id !== shareId)
		},

		/**
		 * Fetch all group shares for a secret.
		 *
		 * @param {string} secretId Secret ID
		 * @return {Promise<void>}
		 */
		async fetchGroupShares(secretId) {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/doriath/api/v1/group-shares'),
					{ params: { secretId } },
				)
				this.groupShares = response.data ?? []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create a group share. The API returns a member list with public keys.
		 * For each member, encrypt the secret and post a batch of shares.
		 *
		 * @param {string} secretId The secret ID to share
		 * @param {string} groupId The group ID to share with
		 * @param {object} plainSecret Plaintext secret fields {key, login, additionalFields}
		 * @param {Function} encryptForKey Async fn(plaintext, publicKeyPem) => ciphertext
		 * @return {Promise<object>} The created group share
		 */
		async createGroupShare(secretId, groupId, plainSecret, encryptForKey) {
			const response = await axios.post(
				generateUrl('/apps/doriath/api/v1/group-shares'),
				{ secretId, groupId },
			)
			const groupShare = response.data
			const members = groupShare.members ?? []

			if (members.length > 0 && typeof encryptForKey === 'function') {
				const batchShares = []
				for (const member of members) {
					if (!member.publicKey) continue
					const encryptedData = {}
					if (plainSecret.key) {
						encryptedData.key = await encryptForKey(plainSecret.key, member.publicKey)
					}
					if (plainSecret.login) {
						encryptedData.login = await encryptForKey(plainSecret.login, member.publicKey)
					}
					if (plainSecret.additionalFields && typeof plainSecret.additionalFields === 'object') {
						const encFields = {}
						for (const [field, value] of Object.entries(plainSecret.additionalFields)) {
							encFields[field] = value ? await encryptForKey(value, member.publicKey) : value
						}
						encryptedData.additionalFields = encFields
					}
					batchShares.push({ targetUserId: member.userId, ...encryptedData })
				}

				if (batchShares.length > 0) {
					await this.createBatchShares(secretId, batchShares, groupShare.id)
				}
			}

			await this.fetchGroupShares(secretId)
			return groupShare
		},

		/**
		 * Revoke a group share by ID.
		 *
		 * @param {string} groupShareId The group share ID to revoke
		 * @return {Promise<void>}
		 */
		async revokeGroupShare(groupShareId) {
			await axios.delete(
				generateUrl(`/apps/doriath/api/v1/group-shares/${groupShareId}`),
			)
			this.groupShares = this.groupShares.filter(gs => gs.id !== groupShareId)
		},

		/**
		 * Sync updated encrypted data for all existing shares of a secret.
		 *
		 * @param {string} secretId The secret ID
		 * @param {object} updates Map of shareId => encryptedData
		 * @return {Promise<object>} The sync result
		 */
		async syncUpdate(secretId, updates) {
			const response = await axios.put(
				generateUrl(`/apps/doriath/api/v1/shares/sync/${secretId}`),
				{ updates },
			)
			return response.data
		},
	},
})
