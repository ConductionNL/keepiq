import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { rsaEncrypt, importPublicKey } from '../../crypto/index.js'

/**
 * Share store — user-to-user and group secret sharing.
 *
 * All plaintext encryption is client-side (ADR-003): the browser holds the
 * decrypted secret, fetches the recipient's public certificate, encrypts with
 * their key, and POSTs only the encrypted blob. The server never sees plaintext.
 *
 * @spec openspec/changes/implement-user-sharing/specs/user-sharing/spec.md
 */
export const useShareStore = defineStore('share', {
	state: () => ({
		/** @type {object[]} Shares for the current secret (owner view) */
		shares: [],
		/** @type {object[]} Group shares for the current secret */
		groupShares: [],
		/** @type {boolean} */
		loading: false,
	}),

	actions: {
		/**
		 * Fetch the recipient list for a secret (owner/delegate only).
		 *
		 * @param {string} sourceSecretId The source secret ID
		 */
		async fetchShares(sourceSecretId) {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/doriath/api/v1/shares'),
					{ params: { sourceSecretId } },
				)
				this.shares = response.data
			} finally {
				this.loading = false
			}
		},

		/**
		 * Encrypt a plaintext secret value for a recipient's public certificate.
		 *
		 * @param {object} plaintext The decrypted secret fields
		 * @param {string} certificate The recipient's PEM certificate
		 * @return {Promise<object>} The encrypted blobs
		 */
		async encryptForRecipient(plaintext, certificate) {
			const publicKey = await importPublicKey(certificate)
			return {
				encrypted_key: plaintext.key ? await rsaEncrypt(plaintext.key, publicKey) : null,
				encrypted_login: plaintext.login ? await rsaEncrypt(plaintext.login, publicKey) : null,
				encrypted_additional_fields: plaintext.additionalFields
					? await rsaEncrypt(JSON.stringify(plaintext.additionalFields), publicKey)
					: null,
			}
		},

		/**
		 * Fetch a recipient's public certificate from their active suite.
		 *
		 * @param {string} userId The recipient user ID
		 * @return {Promise<string|null>} The PEM certificate or null
		 */
		async fetchRecipientCertificate(userId) {
			const response = await axios.get(
				generateUrl('/apps/doriath/api/v1/suites'),
				{ params: { owner_type: 'user', owner_id: userId } },
			)
			const active = (response.data || []).find(s => s.status === 'active')
			return active ? active.certificate : null
		},

		/**
		 * Share a secret with a single user (client-side encryption).
		 *
		 * @param {string} sourceSecretId The source secret ID
		 * @param {string} targetUserId The recipient user ID
		 * @param {object} plaintext The decrypted secret fields + metadata
		 */
		async createShare(sourceSecretId, targetUserId, plaintext) {
			const certificate = await this.fetchRecipientCertificate(targetUserId)
			if (!certificate) {
				throw new Error('Recipient has no active encryption suite')
			}
			const encrypted = await this.encryptForRecipient(plaintext, certificate)
			const response = await axios.post(
				generateUrl('/apps/doriath/api/v1/shares'),
				{
					sourceSecretId,
					targetUserId,
					encryptedData: {
						...encrypted,
						name: plaintext.name,
						url: plaintext.url,
						type_id: plaintext.typeId,
						folder_id: plaintext.folderId,
					},
				},
			)
			this.shares.push(response.data)
			return response.data
		},

		/**
		 * Create a batch of shares (group expansion); blobs pre-encrypted per member.
		 *
		 * @param {string} sourceSecretId The source secret ID
		 * @param {object[]} shares Array of {targetUserId, encryptedData}
		 * @param {string} groupShareId The owning group share ID
		 */
		async createBatchShares(sourceSecretId, shares, groupShareId) {
			const response = await axios.post(
				generateUrl('/apps/doriath/api/v1/shares/batch'),
				{ sourceSecretId, shares, groupShareId },
			)
			this.shares.push(...response.data)
			return response.data
		},

		/**
		 * Revoke a share (deletes the recipient's copy).
		 *
		 * @param {string} shareId The share ID
		 */
		async revokeShare(shareId) {
			await axios.delete(generateUrl(`/apps/doriath/api/v1/shares/${shareId}`))
			this.shares = this.shares.filter(s => s.id !== shareId)
		},

		/**
		 * Fetch group shares for a secret.
		 *
		 * @param {string} secretId The secret ID
		 */
		async fetchGroupShares(secretId) {
			const response = await axios.get(
				generateUrl('/apps/doriath/api/v1/group-shares'),
				{ params: { secretId } },
			)
			this.groupShares = response.data
		},

		/**
		 * Share a secret with a Nextcloud group: create the group share, then
		 * encrypt for each eligible member and POST the batch.
		 *
		 * @param {string} secretId The secret ID
		 * @param {string} groupId The Nextcloud group ID
		 * @param {object} plaintext The decrypted secret fields + metadata
		 */
		async createGroupShare(secretId, groupId, plaintext) {
			const createResponse = await axios.post(
				generateUrl('/apps/doriath/api/v1/group-shares'),
				{ secretId, groupId },
			)
			const { groupShare, eligibleMembers } = createResponse.data
			this.groupShares.push(groupShare)

			const shares = []
			for (const memberId of eligibleMembers) {
				const certificate = await this.fetchRecipientCertificate(memberId)
				if (!certificate) {
					continue
				}
				const encrypted = await this.encryptForRecipient(plaintext, certificate)
				shares.push({
					targetUserId: memberId,
					encryptedData: {
						...encrypted,
						name: plaintext.name,
						url: plaintext.url,
						type_id: plaintext.typeId,
						folder_id: plaintext.folderId,
					},
				})
			}

			if (shares.length > 0) {
				await this.createBatchShares(secretId, shares, groupShare.id)
			}
			return groupShare
		},

		/**
		 * Revoke a group share (cascade-deletes derived shares and copies).
		 *
		 * @param {string} groupShareId The group share ID
		 */
		async revokeGroupShare(groupShareId) {
			await axios.delete(generateUrl(`/apps/doriath/api/v1/group-shares/${groupShareId}`))
			this.groupShares = this.groupShares.filter(gs => gs.id !== groupShareId)
		},

		/**
		 * Submit a share request (recipient asks the owner to share with a third party).
		 *
		 * @param {string} sourceSecretId The source secret ID
		 * @param {string} targetUserId The proposed new recipient
		 */
		async submitShareRequest(sourceSecretId, targetUserId) {
			await axios.post(
				generateUrl('/apps/doriath/api/v1/share-requests'),
				{ sourceSecretId, targetUserId },
			)
		},

		/**
		 * Sync an updated secret value to all recipient copies (O(N) RSA).
		 *
		 * @param {string} sourceSecretId The source secret ID
		 * @param {object} plaintext The new decrypted secret fields
		 * @param {string|null} expectedUpdatedAt Optimistic-lock timestamp
		 */
		async syncUpdate(sourceSecretId, plaintext, expectedUpdatedAt = null) {
			await this.fetchShares(sourceSecretId)
			const updates = []
			for (const share of this.shares) {
				const certificate = await this.fetchRecipientCertificate(share.targetUserId)
				if (!certificate) {
					continue
				}
				const encrypted = await this.encryptForRecipient(plaintext, certificate)
				updates.push({ secret_id: share.secretId, ...encrypted })
			}
			await axios.put(
				generateUrl(`/apps/doriath/api/v1/secrets/${sourceSecretId}/sync`),
				{ updates, expectedUpdatedAt },
			)
		},
	},
})
